#!/usr/bin/env bash
# Exercise the worker archives against files. Replace only the container boundary.
set -euo pipefail
# Linux workers use GNU tar; omit macOS AppleDouble sidecars in this fixture.
export COPYFILE_DISABLE=1
source "$(dirname "$0")/../ops-storage-archives.sh"
fixture="$(mktemp -d)"
trap 'rm -rf -- "$fixture"' EXIT
sudo() { [[ "$1" == -n ]]; shift; "$@"; }
docker() {
  local mount
  while [[ "$1" != --mount ]]; do shift; done
  mount="${2#type=bind,source=}"
  [[ "$mount" == "$fixture/"*,target=/restore ]]
  tar -xf - -C "${mount%,target=/restore}"
}

server="$fixture/source"
backup="$fixture/backup"
mkdir -p "$backup" "$server/storage/public/photos" "$server/storage/private" "$server/public/uploads" "$server/private/installation"
printf 'public\0image' > "$server/storage/public/photos/item"
printf 'private\0file' > "$server/storage/private/item"
printf 'legacy upload' > "$server/public/uploads/item"
printf '{}' > "$server/private/installation/installed.json"
tar -czf "$backup/php-private-storage.tar.gz" -C "$server/storage/private" .
tar -czf "$backup/php-installation.tar.gz" -C "$server/private/installation" .
archive="$backup/php-storage.tar.gz"
for layout in storage flat public; do
  case "$layout" in
    storage)
      mkdir "$fixture/legacy"
      cp -R "$server/storage/public" "$fixture/legacy/storage"
      cp -R "$server/public/uploads" "$fixture/legacy/uploads"
      tar -czf "$archive" -C "$fixture/legacy" storage uploads
      ;;
    flat) tar -czf "$archive" -C "$server/storage/public" . ;;
    public) pack_public_archive "$server" "$archive" ;;
  esac
  verify_storage_archives "$backup" "$layout"
  target="$fixture/$layout/server"
  if [[ "$layout" == storage ]]; then
    public_path=public/storage
    private_path=private/storage
  else
    public_path=storage/public
    private_path=storage/private
  fi
  jq -n --arg private "server/$private_path" '{runtime:{private_storage_directory:$private}}' > "$backup/manifest.json"
  printf "CREATE TABLE storage_space (name TEXT, local_path TEXT); INSERT INTO storage_space VALUES ('public','%s'),('private','%s');" \
    "$public_path" "$private_path" | gzip > "$backup/database.sql.gz"
  restore_storage_archives "$backup" "$layout" "$target" "${target%/server}/$(jq -er .runtime.private_storage_directory "$backup/manifest.json")" fixture-image
  if [[ "$layout" == storage ]]; then
    [[ -d "$target/public/storage" && ! -L "$target/public/storage" && ! -e "$target/storage/private" ]]
  else
    [[ "$(readlink "$target/public/storage")" == ../storage/public ]]
  fi
  cmp "$server/storage/public/photos/item" "$target/public/storage/photos/item"
  # Resolve each key from the SQL paired with this archive, as the local File driver does.
  python3 - "$backup/database.sql.gz" "$target" <<'PY'
import gzip, pathlib, sqlite3, sys
server = pathlib.Path(sys.argv[2])
with sqlite3.connect(":memory:") as database, gzip.open(sys.argv[1], "rt") as dump:
    database.executescript(dump.read())
    roots = dict(database.execute("SELECT name, local_path FROM storage_space"))
assert (server / roots["public"] / "photos/item").read_bytes() == b"public\0image"
assert (server / roots["private"] / "item").read_bytes() == b"private\0file"
PY
  if [[ "$layout" != flat ]]; then
    cmp "$server/public/uploads/item" "$target/public/uploads/item"
  fi
done

# Wrapped archives admit only the two data directories under the document root.
touch "$fixture/legacy/index.php"
tar -czf "$archive" -C "$fixture/legacy" storage index.php
if verify_storage_archives "$backup" storage 2>"$fixture/rejected"; then exit 1; fi
grep -q 'public archive includes program files' "$fixture/rejected"
echo 'paired storage archive fixtures passed (legacy v4, flat v4, v5, rejected program entry)'
