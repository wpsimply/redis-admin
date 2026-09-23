<?php

declare(strict_types=1);

use RedisAdmin\Session;

$config = require dirname(__DIR__).'/bootstrap.php';

redis_admin_headers();
header('Content-Type: text/html; charset=utf-8');

$session = new Session($config);
$grant = $session->grant();
$e = static fn (?string $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$title = (string) $config->get('title');
$version = trim((string) @file_get_contents(dirname(__DIR__).'/VERSION')) ?: 'dev';
$asset = static fn (string $path): string => $path.'?v='.rawurlencode($version);
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $e($grant !== null ? $grant['label'].' · '.$title : $title) ?></title>
    <link rel="icon" href="<?= $e($asset('assets/icon.svg')) ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= $e($asset('assets/app.css')) ?>">
<?php if ($grant !== null) { ?>
    <meta name="csrf-token" content="<?= $e($session->csrf()) ?>">
    <script src="<?= $e($asset('assets/app.js')) ?>" defer></script>
    <script src="<?= $e($asset('assets/vendor/alpine.min.js')) ?>" defer></script>
<?php } ?>
</head>
<body>
<?php if ($grant === null) { ?>
    <main class="signed-out">
        <div class="card">
            <h1><?= $e($title) ?></h1>
            <p>You are not signed in, or your session has ended.</p>
            <p class="muted">Open <?= $e($title) ?> again from your control panel to start a new session.</p>
            <?php if (is_string($config->get('panel_url')) && $config->get('panel_url') !== '') { ?>
                <a class="button primary" href="<?= $e($config->get('panel_url')) ?>" rel="noopener">Go to the control panel</a>
            <?php } ?>
        </div>
    </main>
<?php } else { ?>
<div class="app" x-data="redisAdmin" x-cloak x-effect="syncUrl()" @keydown.window="shortcut($event)">
    <header class="topbar">
        <div class="brand">
            <img src="<?= $e($asset('assets/icon.svg')) ?>" alt="" width="22" height="22">
            <strong><?= $e($title) ?></strong>
            <span class="target" x-text="session.label"></span>
        </div>
        <div class="topbar-actions">
            <label class="db-select">
                <span class="sr-only">Database</span>
                <select x-model.number="db" @change="selectDb()" :disabled="busy">
                    <template x-for="database in databases" :key="database.db">
                        <option :value="database.db" x-text="'db' + database.db + (database.keys ? ' · ' + database.keys.toLocaleString() : '')"></option>
                    </template>
                </select>
            </label>
            <button type="button" class="button ghost" @click="openInfo()" :disabled="busy">Server</button>
            <button type="button" class="button ghost" @click="openImport()" :disabled="busy">Import</button>
            <form method="post" action="logout.php">
                <input type="hidden" name="csrf" :value="csrf">
                <button type="submit" class="button ghost" :disabled="busy">Sign out</button>
            </form>
        </div>
    </header>

    <main class="layout" :class="{ 'has-detail': current }">
        <aside class="sidebar">
            <form class="search" @submit.prevent="submitSearch()">
                <input type="search" x-ref="search" x-model="query" placeholder="Search keys, or a glob like user:*" aria-label="Search keys" autocomplete="off" spellcheck="false">
                <select x-model="type" @change="submitSearch()" :disabled="busy" aria-label="Filter by type">
                    <option value="">All types</option>
                    <template x-for="option in types" :key="option">
                        <option :value="option" x-text="option"></option>
                    </template>
                </select>
            </form>

            <div class="toolbar">
                <button type="button" class="button primary small" @click="openCreate()" :disabled="busy">New key</button>
                <button type="button" class="button small" @click="submitSearch()" :disabled="busy" :class="{ 'is-loading': loading }" title="Reload">Refresh</button>
                <div class="menu" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="button small" @click="open = !open" :disabled="busy">Bulk</button>
                    <div class="menu-items" x-show="open" x-transition.opacity @click="open = false">
                        <button type="button" @click="exportMatching('json')">Export matching (JSON)</button>
                        <button type="button" @click="exportMatching('redis')">Export matching (redis-cli)</button>
                        <hr>
                        <button type="button" class="danger" @click="confirmDeleteMatching()">Delete all matching…</button>
                    </div>
                </div>
            </div>

            <div class="list-status">
                <label class="check">
                    <input type="checkbox" :checked="keys.length > 0 && selected.length === keys.length" @change="toggleAll($event.target.checked)" aria-label="Select all loaded keys">
                    <span x-text="statusLine()"></span>
                </label>
                <span class="pattern" x-text="pattern" :title="'SCAN MATCH ' + pattern"></span>
            </div>

            <div class="selection" x-show="selected.length" x-transition>
                <span x-text="selected.length + ' selected'"></span>
                <button type="button" class="link" @click="exportSelected('json')" :disabled="busy">Export</button>
                <button type="button" class="link danger" @click="confirmDeleteSelected()" :disabled="busy">Delete</button>
                <button type="button" class="link" @click="selected = []">Clear</button>
            </div>

            <ul class="keys" role="listbox" aria-label="Keys" :aria-busy="loading ? 'true' : 'false'">
                <template x-for="item in keys" :key="item.id">
                    <li :class="{ active: current && current.id === item.id }" role="option" :aria-selected="current && current.id === item.id">
                        <input type="checkbox" :value="item.id" x-model="selected" :aria-label="'Select ' + item.label">
                        <button type="button" class="key-row" @click="open(item.id)" :disabled="busy">
                            <span class="badge" :class="'t-' + item.type" x-text="typeLabel(item.type)"></span>
                            <span class="key-name" x-text="item.label"></span>
                            <span class="key-ttl" x-show="item.ttl >= 0 && keyLoading !== item.id" x-text="ttlShort(item.ttl)" :title="'Expires in ' + ttlLong(item.ttl)"></span>
                            <span class="spinner small" x-show="keyLoading === item.id" aria-label="Loading"></span>
                        </button>
                    </li>
                </template>
            </ul>

            <div class="list-footer">
                <p class="empty" x-show="!loading && keys.length === 0">No keys match.</p>
                <button type="button" class="button small" x-show="cursor !== '0'" @click="loadMore()" :disabled="busy" :class="{ 'is-loading': loading }">Load more</button>
                <span class="spinner" x-show="loading" aria-label="Loading"></span>
            </div>
        </aside>

        <section class="detail" aria-live="polite">
            <template x-if="!current">
                <div class="placeholder">
                    <span class="spinner" x-show="keyLoading" aria-label="Loading"></span>
                    <p x-show="!keyLoading">Select a key to view it, or create a new one.</p>
                    <p class="muted small" x-show="!keyLoading">Press <kbd>/</kbd> to search, <kbd>n</kbd> for a new key.</p>
                </div>
            </template>

            <template x-if="current">
                <div class="key-detail" :class="{ 'is-loading': keyLoading === current.id }" :aria-busy="keyLoading === current.id ? 'true' : 'false'">
                    <div class="detail-head">
                        <button type="button" class="button ghost small back" @click="current = null">← Keys</button>
                        <h2 class="key-title" x-text="current.label"></h2>
                        <div class="meta">
                            <span class="badge" :class="'t-' + current.type" x-text="current.type"></span>
                            <span x-show="current.encoding" x-text="current.encoding"></span>
                            <span x-text="lengthLabel(current)"></span>
                            <span x-show="current.memory !== null" x-text="bytes(current.memory) + ' in memory'"></span>
                            <span x-text="current.ttl >= 0 ? 'Expires in ' + ttlLong(current.ttl) : 'No expiry'"></span>
                        </div>
                        <div class="detail-actions">
                            <button type="button" class="button small" @click="! busy && reload()" :disabled="busy" :class="{ 'is-loading': keyLoading === current.id }">Refresh</button>
                            <button type="button" class="button small" @click="openRename()" :disabled="busy">Rename</button>
                            <button type="button" class="button small" @click="openTtl()" :disabled="busy">TTL</button>
                            <button type="button" class="button small" @click="exportIds([current.id], 'json')" :disabled="busy">Export</button>
                            <button type="button" class="button small danger" @click="confirmDeleteCurrent()" :disabled="busy">Delete</button>
                        </div>
                    </div>

                    <!-- String -->
                    <template x-if="current.type === 'string'">
                        <div class="value">
                            <div class="value-bar">
                                <div class="tabs" role="tablist">
                                    <button type="button" role="tab" :aria-selected="view === 'edit'" @click="view = 'edit'">Value</button>
                                    <button type="button" role="tab" x-show="current.pretty" :aria-selected="view === 'pretty'" @click="view = 'pretty'" x-text="current.format === 'serialized' ? 'PHP (decoded)' : 'JSON (formatted)'"></button>
                                </div>
                                <span class="format" x-text="current.format"></span>
                                <label class="check small" x-show="view === 'edit'">
                                    <input type="checkbox" x-model="draft.base64" :disabled="current.format === 'binary'" @change="toggleDraftBase64()">
                                    Base64
                                </label>
                            </div>
                            <p class="notice" x-show="current.truncated">This value is larger than the preview limit and is shown truncated. It cannot be edited here; export the key to get all of it.</p>
                            <textarea x-show="view === 'edit'" class="editor" x-model="draft.text" :readonly="current.truncated" spellcheck="false" aria-label="Value"></textarea>
                            <pre x-show="view === 'pretty'" class="editor pretty" x-text="current.pretty"></pre>
                            <div class="value-actions" x-show="view === 'edit' && !current.truncated">
                                <button type="button" class="button small" x-show="current.format === 'json'" @click="formatDraftJson()">Format JSON</button>
                                <button type="button" class="button primary" :disabled="busy || !draftChanged()" :class="{ 'is-loading': action === 'save' }" @click="saveString()">Save</button>
                            </div>
                        </div>
                    </template>

                    <!-- Collections -->
                    <template x-if="['hash', 'list', 'set', 'zset', 'stream'].includes(current.type)">
                        <div class="value">
                            <div class="value-bar">
                                <button type="button" class="button small primary" @click="openItem(null)" :disabled="busy" x-text="current.type === 'list' ? 'Push value' : current.type === 'stream' ? 'Add entry' : 'Add ' + itemNoun()"></button>
                                <span class="muted small" x-text="pageLabel()"></span>
                            </div>
                            <div class="table-wrap">
                                <table class="items">
                                    <thead>
                                        <tr>
                                            <th x-show="current.type === 'list'">#</th>
                                            <th x-show="current.type === 'zset'">Score</th>
                                            <th x-show="current.type === 'stream'">Entry</th>
                                            <th x-show="current.type === 'hash'">Field</th>
                                            <th x-text="current.type === 'set' || current.type === 'zset' ? 'Member' : current.type === 'stream' ? 'Fields' : 'Value'"></th>
                                            <th class="actions"><span class="sr-only">Actions</span></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(item, i) in current.items" :key="i + ':' + (item.id || item.index || '')">
                                            <tr>
                                                <td x-show="current.type === 'list'" class="num" x-text="item.index"></td>
                                                <td x-show="current.type === 'zset'" class="num" x-text="item.score"></td>
                                                <td x-show="current.type === 'stream'" class="mono nowrap" x-text="item.id"></td>
                                                <td x-show="current.type === 'hash'" class="mono cell value-text" x-text="show(item.field)"></td>
                                                <td class="mono cell">
                                                    <template x-if="current.type !== 'stream'">
                                                        <span>
                                                            <span class="value-text" x-text="show(item.value !== undefined ? item.value : item.member)"></span>
                                                            <em class="truncated" x-show="item.truncated">(truncated)</em>
                                                        </span>
                                                    </template>
                                                    <template x-if="current.type === 'stream'">
                                                        <dl class="fields">
                                                            <template x-for="(pair, j) in item.fields" :key="j">
                                                                <div><dt x-text="show(pair.field)"></dt><dd class="value-text" x-text="show(pair.value)"></dd></div>
                                                            </template>
                                                        </dl>
                                                    </template>
                                                </td>
                                                <td class="actions">
                                                    <button type="button" class="link" x-show="current.type !== 'stream'" @click="openItem(item)" :disabled="busy" x-text="item.truncated ? 'View' : 'Edit'"></button>
                                                    <button type="button" class="link danger" @click="confirmDeleteItem(item)" :disabled="busy">Delete</button>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                                <p class="empty" x-show="current.items.length === 0">Nothing on this page.</p>
                            </div>
                            <div class="pager">
                                <button type="button" class="button small" x-show="hasPrev()" @click="page(-1)" :disabled="busy">Previous</button>
                                <button type="button" class="button small" x-show="hasNext()" @click="page(1)" :disabled="busy" x-text="usesCursor() ? 'Load more' : 'Next'"></button>
                            </div>
                        </div>
                    </template>

                    <template x-if="current.supported === false">
                        <p class="notice">This key's type is not supported by the viewer. It can still be renamed, expired, exported or deleted.</p>
                    </template>
                </div>
            </template>
        </section>
    </main>

    <!-- Modal -->
    <div class="modal-backdrop" x-show="modal" x-transition.opacity @click.self="closeModal()" @keydown.escape.window="closeModal()">
        <div class="modal" role="dialog" aria-modal="true" :aria-label="modalTitle" x-show="modal">
            <header>
                <h3 x-text="modalTitle"></h3>
                <button type="button" class="link" @click="closeModal()" :disabled="action === 'confirm' || action === 'import'" aria-label="Close">✕</button>
            </header>

            <!-- Create key -->
            <form x-show="modal === 'create'" @submit.prevent="create()">
                <label>Key name <input type="text" x-model="form.key" required spellcheck="false" x-ref="createKey"></label>
                <label>Type
                    <select x-model="form.type">
                        <template x-for="option in types" :key="option"><option :value="option" x-text="option"></option></template>
                    </select>
                </label>
                <label x-show="['hash', 'stream'].includes(form.type)">Field <input type="text" x-model="form.field" spellcheck="false"></label>
                <label x-show="['set', 'zset'].includes(form.type)">Member <input type="text" x-model="form.member" spellcheck="false"></label>
                <label x-show="form.type === 'zset'">Score <input type="text" inputmode="decimal" x-model="form.score"></label>
                <label x-show="['string', 'hash', 'list', 'stream'].includes(form.type)">Value <textarea x-model="form.value" rows="6" spellcheck="false"></textarea></label>
                <label>TTL in seconds <input type="number" min="1" x-model="form.ttl" placeholder="No expiry"></label>
                <footer><button type="button" class="button" @click="closeModal()">Cancel</button><button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'create' }">Create</button></footer>
            </form>

            <!-- Collection item -->
            <form x-show="modal === 'item'" @submit.prevent="saveItem()">
                <template x-if="current && current.type === 'stream'">
                    <div class="stream-fields">
                        <template x-for="(pair, j) in form.fields" :key="j">
                            <div class="row">
                                <input type="text" x-model="pair.field" placeholder="field" aria-label="Field" spellcheck="false">
                                <input type="text" x-model="pair.value" placeholder="value" aria-label="Value" spellcheck="false">
                            </div>
                        </template>
                        <button type="button" class="link" @click="form.fields.push({ field: '', value: '' })">+ Add field</button>
                    </div>
                </template>
                <label x-show="current && current.type === 'hash'">Field <input type="text" x-model="form.field" spellcheck="false" :readonly="form.readonly"></label>
                <label x-show="current && current.type === 'zset'">Score <input type="text" inputmode="decimal" x-model="form.score" :readonly="form.readonly"></label>
                <label x-show="current && current.type === 'list' && form.original === null">Position
                    <select x-model="form.side"><option value="tail">Append to the end</option><option value="head">Prepend to the start</option></select>
                </label>
                <label x-show="current && ['hash', 'list', 'set', 'zset'].includes(current.type)">
                    <span x-text="current && ['set', 'zset'].includes(current.type) ? 'Member' : 'Value'"></span>
                    <textarea x-model="form.value" rows="10" spellcheck="false" :readonly="form.readonly"></textarea>
                </label>
                <label class="check small" x-show="current && current.type !== 'stream'">
                    <input type="checkbox" x-model="form.base64" :disabled="form.readonly"> Value is base64 (binary)
                </label>
                <p class="notice" x-show="form.readonly">This value is truncated and is shown read-only.</p>
                <footer><button type="button" class="button" @click="closeModal()">Cancel</button><button type="submit" class="button primary" x-show="!form.readonly" :disabled="busy" :class="{ 'is-loading': action === 'item' }">Save</button></footer>
            </form>

            <!-- Rename -->
            <form x-show="modal === 'rename'" @submit.prevent="rename()">
                <label>New name <input type="text" x-model="form.key" required spellcheck="false"></label>
                <label class="check small"><input type="checkbox" x-model="form.overwrite"> Overwrite a key that already has this name</label>
                <footer><button type="button" class="button" @click="closeModal()">Cancel</button><button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'rename' }">Rename</button></footer>
            </form>

            <!-- TTL -->
            <form x-show="modal === 'ttl'" @submit.prevent="saveTtl()">
                <label>Expire after (seconds) <input type="number" min="1" x-model="form.ttl" placeholder="Leave empty for no expiry"></label>
                <div class="presets">
                    <template x-for="preset in ttlPresets" :key="preset[1]">
                        <button type="button" class="button small" @click="form.ttl = preset[1]" x-text="preset[0]"></button>
                    </template>
                </div>
                <footer><button type="button" class="button" @click="closeModal()">Cancel</button><button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'ttl' }" x-text="form.ttl ? 'Set TTL' : 'Remove expiry'"></button></footer>
            </form>

            <!-- Confirm -->
            <div x-show="modal === 'confirm'">
                <p x-text="confirmation.message"></p>
                <label x-show="confirmation.phrase"><span>Type <code x-text="confirmation.phrase"></code> to confirm</span>
                    <input type="text" x-model="form.phrase" autocomplete="off" spellcheck="false">
                </label>
                <p class="progress" x-show="confirmation.progress" x-text="confirmation.progress"></p>
                <footer>
                    <button type="button" class="button" @click="closeModal()" :disabled="action === 'confirm'">Cancel</button>
                    <button type="button" class="button danger-solid" :disabled="busy || (Boolean(confirmation.phrase) && form.phrase !== confirmation.phrase)" :class="{ 'is-loading': action === 'confirm' }" @click="runConfirmation()" x-text="confirmation.action || 'Delete'"></button>
                </footer>
            </div>

            <!-- Import -->
            <form x-show="modal === 'import'" @submit.prevent="importFile()">
                <p class="muted small">Import a JSON file exported by Redis Admin into <strong x-text="'db' + db"></strong>.</p>
                <label>File <input type="file" x-ref="importFile" accept=".json,application/json" required :disabled="action === 'import'"></label>
                <fieldset>
                    <legend>Keys that already exist</legend>
                    <label class="check small"><input type="radio" value="skip" x-model="form.mode"> Keep them (skip)</label>
                    <label class="check small"><input type="radio" value="replace" x-model="form.mode"> Replace them</label>
                </fieldset>
                <div class="result" x-show="importResult">
                    <p x-text="importResult && (importResult.imported + ' imported, ' + importResult.skipped + ' skipped, ' + importResult.failed.length + ' failed')"></p>
                    <ul class="failures" x-show="importResult && importResult.failed.length">
                        <template x-for="(failure, k) in (importResult ? importResult.failed.slice(0, 50) : [])" :key="k">
                            <li><code x-text="failure.key"></code> <span x-text="failure.error"></span></li>
                        </template>
                    </ul>
                </div>
                <footer><button type="button" class="button" @click="closeModal()" :disabled="action === 'import'">Close</button><button type="submit" class="button primary" :disabled="busy" :class="{ 'is-loading': action === 'import' }">Import</button></footer>
            </form>

            <!-- Server info -->
            <div x-show="modal === 'info'" class="info" :aria-busy="infoLoading ? 'true' : 'false'">
                <p class="loading-line" x-show="infoLoading"><span class="spinner small"></span> Loading server details…</p>
                <p class="notice" x-show="info && !info.available">Server details are not available to this user. Key counts below may be incomplete.</p>
                <dl class="info-grid" x-show="info && info.available">
                    <template x-for="row in infoRows()" :key="row[0]">
                        <div><dt x-text="row[0]"></dt><dd x-text="row[1]"></dd></div>
                    </template>
                </dl>
                <h4>Databases</h4>
                <table class="items compact">
                    <thead><tr><th>Database</th><th class="num">Keys</th><th class="num">With TTL</th></tr></thead>
                    <tbody>
                        <template x-for="database in (info ? info.databases.filter(d => d.keys || d.db === db) : [])" :key="database.db">
                            <tr><td x-text="'db' + database.db"></td><td class="num" x-text="database.keys.toLocaleString()"></td><td class="num" x-text="database.expires.toLocaleString()"></td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form x-ref="exportForm" method="post" action="export.php" class="sr-only" aria-hidden="true">
        <input type="hidden" name="csrf" :value="csrf">
        <input type="hidden" name="ids">
        <input type="hidden" name="format">
        <input type="hidden" name="db">
    </form>

    <div class="toasts" aria-live="assertive">
        <template x-for="toast in toasts" :key="toast.id">
            <div class="toast" :class="toast.kind" x-text="toast.message" @click="dismiss(toast.id)"></div>
        </template>
    </div>

    <div class="session-ended" x-show="signedOut">
        <div class="card">
            <h3>Your session has ended</h3>
            <p>Open <?= $e($title) ?> again from your control panel to continue.</p>
        </div>
    </div>
</div>
<?php } ?>
</body>
</html>
