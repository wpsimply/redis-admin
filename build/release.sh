#!/bin/sh
#
# Build the release archive: only what a server needs to run Redis Admin.
#
# Usage: build/release.sh <version> [output-dir]
#
# Produces <output-dir>/redis-admin-<version>.zip (and a .sha256 next to it)
# with a single top-level redis-admin/ directory. Tests, examples, CI and
# every git file are left out; the storage directories are created empty.
set -eu

version="${1:?usage: build/release.sh <version> [output-dir]}"
version="${version#v}"
out_dir="${2:-dist}"

case "$version" in
    *[!0-9A-Za-z.+-]*|'') echo "Invalid version: $version" >&2; exit 1 ;;
esac

root="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$out_dir"
out_dir="$(cd "$out_dir" && pwd)"
stage="$(mktemp -d)"
trap 'rm -rf "$stage"' EXIT

app="$stage/redis-admin"
mkdir -p "$app"

# The runtime, and the files that tell someone how to configure it.
for path in bootstrap.php src public .env.example config.example.php LICENSE README.md; do
    cp -R "$root/$path" "$app/"
done

printf '%s\n' "$version" > "$app/VERSION"

# Writable at runtime, empty in the archive.
mkdir -p "$app/storage/sessions" "$app/storage/sso-tokens"
chmod 700 "$app/storage/sessions" "$app/storage/sso-tokens"

# Nothing from a developer's machine or git may ride along.
find "$app" \( -name '.git*' -o -name '.DS_Store' -o -name '*.swp' \) -exec rm -rf {} +

archive="$out_dir/redis-admin-$version.zip"
rm -f "$archive"
(cd "$stage" && zip -qrX "$archive" redis-admin)

(cd "$out_dir" && if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$(basename "$archive")"
else
    shasum -a 256 "$(basename "$archive")"
fi > "$(basename "$archive").sha256")

echo "$archive"
