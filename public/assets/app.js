/*
 * Redis Admin UI. Plain Alpine.js, no build step.
 *
 * Values from the API are either a string (valid UTF-8) or {"$b64": "..."}
 * for binary data, and are sent back in the same shape.
 */
document.addEventListener('alpine:init', () => {
    const TTL_PRESETS = [['1 min', 60], ['1 hour', 3600], ['1 day', 86400], ['1 week', 604800], ['30 days', 2592000]];
    const GLOB = /[*?[\]]/;

    const isBinary = (value) => value !== null && typeof value === 'object' && '$b64' in value;

    const utf8ToBase64 = (text) => {
        const bytes = new TextEncoder().encode(text);
        let binary = '';
        bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
        return btoa(binary);
    };

    const base64ToUtf8 = (b64) => {
        const binary = atob(b64);
        const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0));
        return new TextDecoder('utf-8', { fatal: true }).decode(bytes);
    };

    Alpine.data('redisAdmin', () => ({
        csrf: document.querySelector('meta[name="csrf-token"]').content,
        session: { label: '', databases: 16, prefix: null },
        types: ['string', 'hash', 'list', 'set', 'zset', 'stream'],
        ttlPresets: TTL_PRESETS,
        databases: [],
        db: 0,

        query: '',
        type: '',
        pattern: '*',
        keys: [],
        cursor: '0',
        loading: false,
        selected: [],

        current: null,
        view: 'edit',
        draft: { text: '', base64: false, original: '' },
        itemCursor: '0',
        cursorStack: [],

        modal: null,
        modalTitle: '',
        form: {},
        confirmation: {},
        busy: false,
        info: null,
        importResult: null,

        toasts: [],
        signedOut: false,

        async init() {
            try {
                this.session = await this.api('session');
                this.db = this.session.db;
                this.setDatabases([]);

                if (this.session.prefix) {
                    this.query = this.session.prefix.replace(/[*?[\]\\]/g, '\\$&') + '*';
                }

                await Promise.all([this.search(), this.refreshDatabases()]);
            } catch (error) {
                this.fail(error);
            }
        },

        // ---- API ---------------------------------------------------------

        async api(action, { query = {}, body = null } = {}) {
            const params = new URLSearchParams({ action });
            Object.entries(query).forEach(([name, value]) => {
                if (value !== null && value !== undefined && value !== '') {
                    params.set(name, value);
                }
            });

            const options = body === null
                ? { headers: { Accept: 'application/json' } }
                : {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': this.csrf },
                    body: JSON.stringify(body),
                };

            const response = await fetch('api.php?' + params.toString(), { credentials: 'same-origin', ...options });
            return this.unwrap(response);
        },

        async unwrap(response) {
            let payload = null;

            try {
                payload = await response.json();
            } catch {
                throw new Error(`Unexpected response from the server (${response.status}).`);
            }

            if (response.status === 401) {
                this.signedOut = true;
            }

            if (!response.ok) {
                throw new Error(payload.error || `Request failed (${response.status}).`);
            }

            return payload.data;
        },

        async run(task, success = null) {
            this.busy = true;

            try {
                const result = await task();

                if (success) {
                    this.notify(success);
                }

                return result;
            } catch (error) {
                this.fail(error);
                return undefined;
            } finally {
                this.busy = false;
            }
        },

        // ---- Key list ----------------------------------------------------

        buildPattern() {
            const text = this.query.trim();

            if (text === '') {
                return '*';
            }

            return GLOB.test(text) ? text : '*' + text.replace(/\\/g, '\\\\') + '*';
        },

        async search() {
            this.pattern = this.buildPattern();
            this.cursor = '0';
            this.keys = [];
            this.selected = [];
            await this.loadMore(true);
        },

        async loadMore(first = false) {
            if (this.loading || (!first && this.cursor === '0')) {
                return;
            }

            this.loading = true;

            try {
                const page = await this.api('scan', { query: { pattern: this.pattern, type: this.type, cursor: this.cursor } });
                const known = new Set(this.keys.map((key) => key.id));
                this.keys.push(...page.keys.filter((key) => !known.has(key.id)));
                this.cursor = page.cursor;
            } catch (error) {
                this.fail(error);
            } finally {
                this.loading = false;
            }
        },

        statusLine() {
            const count = this.keys.length.toLocaleString();
            return this.cursor === '0' ? `${count} key${this.keys.length === 1 ? '' : 's'}` : `${count}+ keys (scan in progress)`;
        },

        toggleAll(checked) {
            this.selected = checked ? this.keys.map((key) => key.id) : [];
        },

        upsertListed(meta) {
            const index = this.keys.findIndex((key) => key.id === meta.id);
            const row = { id: meta.id, key: meta.key, label: meta.label, type: meta.type, ttl: meta.ttl, length: meta.length, memory: meta.memory };

            if (index >= 0) {
                this.keys.splice(index, 1, row);
            } else {
                this.keys.unshift(row);
            }
        },

        dropListed(ids) {
            const gone = new Set(ids);
            this.keys = this.keys.filter((key) => !gone.has(key.id));
            this.selected = this.selected.filter((id) => !gone.has(id));

            if (this.current && gone.has(this.current.id)) {
                this.current = null;
            }
        },

        // ---- Databases -----------------------------------------------------

        setDatabases(counts) {
            const byDb = new Map(counts.map((entry) => [entry.db, entry]));
            this.databases = Array.from({ length: this.session.databases }, (_, db) => byDb.get(db) || { db, keys: 0, expires: 0 });
        },

        async refreshDatabases() {
            try {
                this.info = await this.api('info');
                this.setDatabases(this.info.databases);
            } catch {
                // The selector still works without counts.
            }
        },

        async selectDb() {
            await this.run(async () => {
                await this.api('select-db', { body: { db: this.db } });
                this.current = null;
                await this.search();
            });
        },

        // ---- Key detail ----------------------------------------------------

        async open(id, page = {}) {
            const detail = await this.run(() => this.api('key', { query: { id, ...page } }));

            if (!detail) {
                if (this.current && this.current.id === id) {
                    this.current = null;
                }
                return;
            }

            if (!this.current || this.current.id !== id) {
                this.view = 'edit';
                this.cursorStack = [];
            }

            this.current = detail;
            this.itemCursor = page.cursor || '0';
            this.upsertListed(detail);

            if (detail.type === 'string') {
                this.resetDraft();
            }
        },

        reload() {
            if (this.current) {
                const page = this.usesCursor() ? { cursor: this.itemCursor } : { offset: this.current.offset || 0 };
                return this.open(this.current.id, page);
            }

            return undefined;
        },

        resetDraft() {
            const value = this.current.value;
            const text = isBinary(value) ? value.$b64 : value;
            this.draft = { text, base64: isBinary(value), original: text };
        },

        toggleDraftBase64() {
            try {
                this.draft.text = this.draft.base64 ? utf8ToBase64(this.draft.text) : base64ToUtf8(this.draft.text);
                this.draft.original = this.draft.text;
            } catch {
                this.draft.base64 = true;
                this.notify('That value is not valid UTF-8 text, so it can only be edited as base64.', 'error');
            }
        },

        draftChanged() {
            return this.draft.text !== this.draft.original;
        },

        formatDraftJson() {
            try {
                this.draft.text = JSON.stringify(JSON.parse(this.draft.text), null, 2);
            } catch {
                this.notify('The value is not valid JSON.', 'error');
            }
        },

        encodeInput(text, base64) {
            return base64 ? { $b64: text.replace(/\s+/g, '') } : text;
        },

        async saveString() {
            const done = await this.run(
                () => this.api('string.set', { body: { id: this.current.id, value: this.encodeInput(this.draft.text, this.draft.base64) } }),
                'Saved.',
            );

            if (done) {
                await this.reload();
            }
        },

        // ---- Collections -------------------------------------------------

        usesCursor() {
            return this.current && ['hash', 'set', 'stream'].includes(this.current.type);
        },

        itemNoun() {
            return { hash: 'field', set: 'member', zset: 'member' }[this.current.type] || 'item';
        },

        pageLabel() {
            const total = this.current.length.toLocaleString();

            if (this.usesCursor()) {
                return `${this.current.items.length.toLocaleString()} shown of ${total}`;
            }

            const from = (this.current.offset || 0) + 1;
            const to = (this.current.offset || 0) + this.current.items.length;
            return this.current.items.length ? `${from.toLocaleString()}–${to.toLocaleString()} of ${total}` : `0 of ${total}`;
        },

        hasPrev() {
            return this.usesCursor() ? this.cursorStack.length > 0 : (this.current.offset || 0) > 0;
        },

        hasNext() {
            if (this.usesCursor()) {
                return this.current.cursor && this.current.cursor !== '0';
            }

            return (this.current.offset || 0) + this.current.items.length < this.current.length;
        },

        page(direction) {
            if (this.usesCursor()) {
                if (direction > 0) {
                    this.cursorStack.push(this.itemCursor);
                    return this.open(this.current.id, { cursor: this.current.cursor });
                }

                return this.open(this.current.id, { cursor: this.cursorStack.pop() || '0' });
            }

            const offset = Math.max(0, (this.current.offset || 0) + direction * 100);
            return this.open(this.current.id, { offset });
        },

        openItem(item) {
            const type = this.current.type;
            const valueOf = (value) => (isBinary(value) ? value.$b64 : value);
            const main = item === null ? '' : valueOf(item.value !== undefined ? item.value : item.member);

            this.form = {
                original: item,
                field: item && type === 'hash' ? valueOf(item.field) : '',
                score: item && type === 'zset' ? item.score : '0',
                value: main,
                base64: item !== null && isBinary(item.value !== undefined ? item.value : item.member),
                side: 'tail',
                readonly: Boolean(item && item.truncated),
                fields: [{ field: '', value: '' }],
            };

            if (type === 'hash' && item && isBinary(item.field)) {
                this.form.readonly = true;
            }

            const verb = item === null ? (type === 'list' ? 'Push value' : 'Add ' + this.itemNoun()) : (this.form.readonly ? 'View ' : 'Edit ') + this.itemNoun();
            this.showModal('item', type === 'stream' ? 'Add stream entry' : verb);
        },

        async saveItem() {
            const { type, id } = this.current;
            const form = this.form;
            const original = form.original;
            const value = this.encodeInput(form.value, form.base64);
            let action;
            let body;

            switch (type) {
                case 'hash':
                    action = 'hash.set';
                    body = { id, field: form.field, value, original: original ? original.field : null };
                    break;
                case 'list':
                    action = original ? 'list.set' : 'list.push';
                    body = original ? { id, index: original.index, hash: original.hash, value } : { id, value, side: form.side };
                    break;
                case 'set':
                    action = 'set.set';
                    body = { id, member: value, original: original ? original.member : null };
                    break;
                case 'zset':
                    action = 'zset.set';
                    body = { id, member: value, score: form.score, original: original ? original.member : null };
                    break;
                case 'stream':
                    action = 'stream.add';
                    body = { id, fields: form.fields.filter((pair) => pair.field !== '') };
                    break;
                default:
                    return;
            }

            const done = await this.run(() => this.api(action, { body }), 'Saved.');

            if (done) {
                this.closeModal();
                await this.reload();
            }
        },

        confirmDeleteItem(item) {
            const { type, id } = this.current;
            const noun = { hash: 'field', list: 'element', set: 'member', zset: 'member', stream: 'entry' }[type];
            const request = {
                hash: () => ['hash.delete', { id, field: item.field }],
                list: () => ['list.delete', { id, index: item.index, hash: item.hash }],
                set: () => ['set.delete', { id, member: item.member }],
                zset: () => ['zset.delete', { id, member: item.member }],
                stream: () => ['stream.delete', { id, entry: item.id }],
            }[type]();

            this.confirm({
                message: `Delete this ${noun}?`,
                run: async () => {
                    await this.api(request[0], { body: request[1] });
                    this.closeModal();
                    this.notify(`Deleted the ${noun}.`);
                    await this.reload();
                },
            });
        },

        // ---- Create / rename / TTL / delete ------------------------------

        openCreate() {
            this.form = { key: this.pattern.endsWith('*') && !GLOB.test(this.pattern.slice(0, -1)) ? this.pattern.slice(0, -1) : '', type: 'string', field: '', member: '', score: '0', value: '', ttl: '' };
            this.showModal('create', 'New key');
            this.$nextTick(() => this.$refs.createKey.focus());
        },

        async create() {
            const form = this.form;
            const result = await this.run(() => this.api('create', {
                body: { key: form.key, type: form.type, field: form.field, member: form.member, score: form.score, value: form.value, ttl: form.ttl || null },
            }), 'Key created.');

            if (result) {
                this.closeModal();
                await this.open(result.id);
            }
        },

        openRename() {
            const key = this.current.key;

            if (isBinary(key)) {
                this.notify('Keys that are not valid text cannot be renamed here.', 'error');
                return;
            }

            this.form = { key, overwrite: false };
            this.showModal('rename', 'Rename key');
        },

        async rename() {
            const oldId = this.current.id;
            const result = await this.run(() => this.api('rename', { body: { id: oldId, newKey: this.form.key, overwrite: this.form.overwrite } }), 'Renamed.');

            if (result) {
                this.closeModal();
                this.dropListed([oldId]);
                await this.open(result.id);
            }
        },

        openTtl() {
            this.form = { ttl: this.current.ttl >= 0 ? Math.ceil(this.current.ttl / 1000) : '' };
            this.showModal('ttl', 'Expiry');
        },

        async saveTtl() {
            const ttl = this.form.ttl === '' || this.form.ttl === null ? null : Number(this.form.ttl);
            const done = await this.run(() => this.api('expire', { body: { id: this.current.id, ttl } }), ttl ? 'TTL set.' : 'Expiry removed.');

            if (done) {
                this.closeModal();
                await this.reload();
            }
        },

        confirmDeleteCurrent() {
            const { id, label } = this.current;
            this.confirm({
                message: `Delete the key “${label}”? This cannot be undone.`,
                run: async () => {
                    await this.api('delete', { body: { ids: [id] } });
                    this.closeModal();
                    this.dropListed([id]);
                    this.notify('Key deleted.');
                },
            });
        },

        confirmDeleteSelected() {
            const ids = [...this.selected];
            this.confirm({
                message: `Delete ${ids.length.toLocaleString()} selected key${ids.length === 1 ? '' : 's'}? This cannot be undone.`,
                run: async () => {
                    const result = await this.api('delete', { body: { ids } });
                    this.closeModal();
                    this.dropListed(ids);
                    this.notify(`Deleted ${result.deleted.toLocaleString()} key${result.deleted === 1 ? '' : 's'}.`);
                },
            });
        },

        confirmDeleteMatching() {
            const pattern = this.pattern;
            const type = this.type;
            this.confirm({
                message: `Delete every key in db${this.db} matching ${pattern}${type ? ` of type ${type}` : ''}? This cannot be undone.`,
                phrase: pattern === '*' ? `delete all in db${this.db}` : 'delete',
                action: 'Delete matching keys',
                run: async () => {
                    let cursor = '0';
                    let deleted = 0;

                    do {
                        const step = await this.api('delete-matching', { body: { pattern, type, cursor } });
                        deleted += step.deleted;
                        cursor = step.cursor;
                        this.confirmation.progress = `${deleted.toLocaleString()} deleted so far…`;
                    } while (cursor !== '0');

                    this.closeModal();
                    this.notify(`Deleted ${deleted.toLocaleString()} key${deleted === 1 ? '' : 's'}.`);
                    this.current = null;
                    await Promise.all([this.search(), this.refreshDatabases()]);
                },
            });
        },

        // ---- Export / import ---------------------------------------------

        exportSelected(format) {
            this.exportIds(this.selected, format);
        },

        exportIds(ids, format) {
            const form = this.$refs.exportForm;
            form.elements.csrf.value = this.csrf;
            form.elements.ids.value = ids.join(',');
            form.elements.format.value = format;
            form.submit();
        },

        exportMatching(format) {
            const params = new URLSearchParams({ pattern: this.pattern, format });

            if (this.type) {
                params.set('type', this.type);
            }

            window.location.href = 'export.php?' + params.toString();
        },

        openImport() {
            this.form = { mode: 'skip' };
            this.importResult = null;
            this.showModal('import', 'Import keys');
        },

        async importFile() {
            const file = this.$refs.importFile.files[0];

            if (!file) {
                return;
            }

            const data = new FormData();
            data.append('file', file);
            data.append('mode', this.form.mode);

            const result = await this.run(async () => {
                const response = await fetch('import.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-CSRF-Token': this.csrf },
                    body: data,
                });
                return this.unwrap(response);
            });

            if (result) {
                this.importResult = result;
                this.notify(`Imported ${result.imported.toLocaleString()} key${result.imported === 1 ? '' : 's'}.`);
                await Promise.all([this.search(), this.refreshDatabases()]);
            }
        },

        // ---- Server info ---------------------------------------------------

        async openInfo() {
            this.showModal('info', 'Server');
            await this.refreshDatabases();
        },

        infoRows() {
            if (!this.info || !this.info.available) {
                return [];
            }

            const f = this.info.fields;
            const hits = Number(f.keyspace_hits || 0);
            const misses = Number(f.keyspace_misses || 0);
            const rows = [
                ['Version', `${f.redis_version || '?'} (${f.redis_mode || 'standalone'})`],
                ['Uptime', f.uptime_in_seconds ? this.ttlLong(Number(f.uptime_in_seconds) * 1000) : '—'],
                ['Memory used', f.used_memory_human || '—'],
                ['Peak memory', f.used_memory_peak_human || '—'],
                ['Memory limit', Number(f.maxmemory || 0) > 0 ? `${f.maxmemory_human} (${f.maxmemory_policy})` : 'None'],
                ['Fragmentation', f.mem_fragmentation_ratio || '—'],
                ['Clients', f.connected_clients || '0'],
                ['Ops/sec', f.instantaneous_ops_per_sec || '0'],
                ['Hit rate', hits + misses > 0 ? `${((hits / (hits + misses)) * 100).toFixed(1)}%` : '—'],
                ['Expired keys', Number(f.expired_keys || 0).toLocaleString()],
                ['Evicted keys', Number(f.evicted_keys || 0).toLocaleString()],
            ];

            return rows;
        },

        // ---- Modals and notices --------------------------------------------

        showModal(name, title) {
            this.modal = name;
            this.modalTitle = title;
        },

        closeModal() {
            if (this.busy && this.modal === 'confirm') {
                return;
            }

            this.modal = null;
            this.confirmation = {};
        },

        confirm(options) {
            this.confirmation = { progress: '', ...options };
            this.form = { phrase: '' };
            this.showModal('confirm', options.title || 'Are you sure?');
        },

        async runConfirmation() {
            await this.run(() => this.confirmation.run());
        },

        notify(message, kind = 'success') {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, message, kind });
            setTimeout(() => this.dismiss(id), kind === 'error' ? 7000 : 3500);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },

        fail(error) {
            this.notify(error && error.message ? error.message : String(error), 'error');
        },

        shortcut(event) {
            const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target.tagName);

            if (typing || this.modal || event.metaKey || event.ctrlKey || event.altKey) {
                return;
            }

            if (event.key === '/') {
                event.preventDefault();
                this.$refs.search.focus();
            } else if (event.key === 'n') {
                event.preventDefault();
                this.openCreate();
            }
        },

        // ---- Formatting ----------------------------------------------------

        show(value) {
            if (value === undefined || value === null) {
                return '';
            }

            if (isBinary(value)) {
                return '[binary] ' + value.$b64.slice(0, 200) + (value.$b64.length > 200 ? '…' : '');
            }

            const text = String(value);
            return text.length > 500 ? text.slice(0, 500) + '…' : text;
        },

        typeLabel(type) {
            return { string: 'str', hash: 'hash', list: 'list', set: 'set', zset: 'zset', stream: 'strm' }[type] || type;
        },

        lengthLabel(key) {
            const n = key.length.toLocaleString();
            return {
                string: this.bytes(key.length),
                hash: `${n} field${key.length === 1 ? '' : 's'}`,
                list: `${n} element${key.length === 1 ? '' : 's'}`,
                set: `${n} member${key.length === 1 ? '' : 's'}`,
                zset: `${n} member${key.length === 1 ? '' : 's'}`,
                stream: `${n} entr${key.length === 1 ? 'y' : 'ies'}`,
            }[key.type] || '';
        },

        bytes(size) {
            if (size === null || size === undefined) {
                return '—';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            let value = size;
            let unit = 0;

            while (value >= 1024 && unit < units.length - 1) {
                value /= 1024;
                unit++;
            }

            return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`;
        },

        ttlShort(ms) {
            const s = Math.ceil(ms / 1000);

            if (s < 60) return `${s}s`;
            if (s < 3600) return `${Math.floor(s / 60)}m`;
            if (s < 86400) return `${Math.floor(s / 3600)}h`;
            return `${Math.floor(s / 86400)}d`;
        },

        ttlLong(ms) {
            let s = Math.ceil(ms / 1000);
            const parts = [];

            [['d', 86400], ['h', 3600], ['m', 60], ['s', 1]].forEach(([unit, size]) => {
                if (s >= size || (unit === 's' && parts.length === 0)) {
                    parts.push(`${Math.floor(s / size)}${unit}`);
                    s %= size;
                }
            });

            return parts.slice(0, 2).join(' ');
        },
    }));
});
