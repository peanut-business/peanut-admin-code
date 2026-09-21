#!/usr/bin/env bash

set -Eeuo pipefail

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
ROOT_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd)"
WEB_DIR="$ROOT_DIR/web"
PLATFORM_DIR="$ROOT_DIR/platform"
PC_DIR="$ROOT_DIR/pc"
UNIAPP_DIR="$ROOT_DIR/uniapp"
SERVER_DIR="$ROOT_DIR/server"
COMPOSER_BIN="$ROOT_DIR/scripts/project-composer"
CORE_WEB_CANDIDATES="$ROOT_DIR/packages/core-web"
SKIP_CLIENT_BUILD=0
OUTPUT_DIR="$ROOT_DIR/release/peanut-admin"
if [[ $# -gt 0 && "$1" != --* ]]; then OUTPUT_DIR="$1"; shift; fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --skip-client-build) SKIP_CLIENT_BUILD=1; shift ;;
    --core-web-candidates=*) CORE_WEB_CANDIDATES="${1#*=}"; shift ;;
    *) printf 'package-release: unsupported argument: %s\n' "$1" >&2; exit 2 ;;
  esac
done
if [[ "$OUTPUT_DIR" != /* ]]; then
  OUTPUT_DIR="$ROOT_DIR/$OUTPUT_DIR"
fi

die() {
  printf 'package-release: %s\n' "$*" >&2
  exit 1
}

require_command() {
  command -v "$1" >/dev/null 2>&1 || die "missing required command: $1"
}

[[ -d "$WEB_DIR" ]] || die "web directory not found: $WEB_DIR"
[[ -d "$PLATFORM_DIR" ]] || die "platform directory not found: $PLATFORM_DIR"
[[ -d "$PC_DIR" ]] || die "pc directory not found: $PC_DIR"
[[ -d "$UNIAPP_DIR" ]] || die "uniapp directory not found: $UNIAPP_DIR"
[[ -d "$SERVER_DIR" ]] || die "server directory not found: $SERVER_DIR"
[[ -d "$ROOT_DIR/plugins" && -f "$ROOT_DIR/plugins.lock" ]] || die 'plugin source or lock is missing'
[[ "$CORE_WEB_CANDIDATES" == /* ]] || CORE_WEB_CANDIDATES="$ROOT_DIR/$CORE_WEB_CANDIDATES"
[[ -d "$CORE_WEB_CANDIDATES" ]] || die "Core Web candidate directory not found: $CORE_WEB_CANDIDATES"
[[ "$(find "$CORE_WEB_CANDIDATES" -mindepth 1 -maxdepth 1 -type f -name '*.tgz' | wc -l | tr -d ' ')" == 6 ]] \
  || die 'Core Web candidate directory must contain exactly six tgz archives'
[[ ! -e "$OUTPUT_DIR" ]] || die "output already exists; choose a new path or remove it explicitly: $OUTPUT_DIR"
[[ ! -e "$OUTPUT_DIR.tar.gz" ]] || die "archive already exists; choose a new path or remove it explicitly: $OUTPUT_DIR.tar.gz"

require_command tar
require_command jq
require_command rg
require_command shasum
require_command php
[[ "$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')" == '8.3' ]] || die "PHP 8.3 is required (found $(php -r 'echo PHP_VERSION;'))"
for package_name in client vue ui-vue nuxt uniapp testing; do
  identity="$(jq -c --arg name "@peanut-admin/$package_name" '.core_web.packages[$name] // empty' "$ROOT_DIR/release-versions.json")"
  version="$(jq -r '.version // empty' <<<"$identity")"
  [[ -n "$version" ]] || die "Core Web version contract is missing @peanut-admin/$package_name"
  archive="$CORE_WEB_CANDIDATES/$(basename "$(jq -r '.archive' <<<"$identity")")"
  [[ -f "$archive" && ! -L "$archive" ]] || die "Core Web candidate archive is missing: $archive"
  [[ "$(shasum -a 256 "$archive" | awk '{print $1}')" == "$(jq -r '.sha256' <<<"$identity")" ]] || die "Core Web candidate hash mismatch: $archive"
  package_json="$(tar -xOf "$archive" package/package.json)" || die "Core Web candidate archive is invalid: $archive"
  [[ "$(jq -r '[.name,.version] | @tsv' <<<"$package_json")" == $'@peanut-admin/'"$package_name"$'\t'"$version" ]] || die "Core Web candidate identity mismatch: $archive"
  while IFS=$'\t' read -r peer peer_version; do
    [[ -z "$peer" ]] && continue
    expected_peer="$(jq -r --arg name "$peer" '.core_web.packages[$name].version // empty' "$ROOT_DIR/release-versions.json")"
    [[ -n "$expected_peer" && "$peer_version" == "$expected_peer" ]] || die "Core Web candidate peer identity mismatch: $package_name -> $peer@$peer_version"
  done < <(jq -r '(.peerDependencies // {}) | to_entries[] | select(.key | startswith("@peanut-admin/")) | [.key,.value] | @tsv' <<<"$package_json")
done
for client_dir in "$WEB_DIR" "$PLATFORM_DIR" "$PC_DIR" "$UNIAPP_DIR"; do
  while IFS=$'\t' read -r package specifier; do
    archive_path="$(jq -r --arg name "$package" '.core_web.packages[$name].archive // empty' "$ROOT_DIR/release-versions.json")"
    expected_specifier="file:../$archive_path"
    [[ "$specifier" == "$expected_specifier" ]] || die "$(basename "$client_dir") $package must consume $expected_specifier"
  done < <(jq -r '.dependencies | to_entries[] | select(.key | startswith("@peanut-admin/")) | [.key,.value] | @tsv' "$client_dir/package.json")
done

if [[ "$SKIP_CLIENT_BUILD" != "1" ]]; then
  require_command node
  require_command pnpm

  node_version="$(node -p 'process.versions.node')"
  [[ "$node_version" == 22.22.* ]] || die "Node.js 22.22.x is required (found v$node_version)"

  printf '%s\n' 'Installing and building the four locked client graphs...'
  pnpm --dir "$WEB_DIR" install --frozen-lockfile
  pnpm --dir "$WEB_DIR" build
  npm --prefix "$PLATFORM_DIR" ci
  npm --prefix "$PLATFORM_DIR" run build
  npm --prefix "$PC_DIR" ci
  npm --prefix "$PC_DIR" run generate
  npm --prefix "$UNIAPP_DIR" ci
  npm --prefix "$UNIAPP_DIR" run build:h5
fi

[[ -f "$WEB_DIR/dist/index.html" ]] || die "frontend build output is missing: $WEB_DIR/dist/index.html"
[[ -f "$PLATFORM_DIR/dist/index.html" ]] || die "platform build output is missing: $PLATFORM_DIR/dist/index.html"
[[ -f "$PC_DIR/.output/public/index.html" ]] || die "pc build output is missing: $PC_DIR/.output/public/index.html"
[[ -f "$UNIAPP_DIR/dist/build/h5/index.html" ]] || die "mobile build output is missing: $UNIAPP_DIR/dist/build/h5/index.html"
require_command rsync
[[ -x "$COMPOSER_BIN" ]] || die "project Composer entry point is unavailable: $COMPOSER_BIN"

output_parent="$(dirname -- "$OUTPUT_DIR")"
mkdir -p "$output_parent"
stage_dir="$(mktemp -d "$output_parent/.peanut-admin-release.XXXXXX")"
cleanup() {
  rm -rf -- "$stage_dir"
}
trap cleanup EXIT

mkdir -p "$stage_dir/server/public/"{admin,platform,pc,mobile}

# Copy PHP code without local runtime logs, secrets, or frontend dependencies.
# vendor/ is installed into the stage from the locked Composer graph below.
rsync -a \
  --exclude='public/' \
  --exclude='runtime/' \
  --exclude='vendor/' \
  --exclude='node_modules/' \
  --include='.env.example' \
  --exclude='.env' \
  --exclude='.env.*' \
  "$SERVER_DIR/" "$stage_dir/server/"

# Start public/ from the backend tree, then place the SPA below /admin/.
# The backend public root is never used as the frontend destination.
rsync -a "$SERVER_DIR/public/" "$stage_dir/server/public/"
for client_output in "$WEB_DIR/dist" "$PLATFORM_DIR/dist" "$PC_DIR/.output/public" "$UNIAPP_DIR/dist/build/h5"; do
  for protected_path in index.php router.php .htaccess storage; do
    [[ ! -e "$client_output/$protected_path" ]] || die "client output contains protected backend path: $client_output/$protected_path"
  done
done
rsync -a --ignore-existing --exclude='storage/' "$WEB_DIR/dist/" "$stage_dir/server/public/admin/"
rsync -a --ignore-existing --exclude='storage/' "$PLATFORM_DIR/dist/" "$stage_dir/server/public/platform/"
rsync -a --ignore-existing --exclude='storage/' "$PC_DIR/.output/public/" "$stage_dir/server/public/pc/"
rsync -a --ignore-existing --exclude='storage/' "$UNIAPP_DIR/dist/build/h5/" "$stage_dir/server/public/mobile/"
rsync -a "$ROOT_DIR/plugins/" "$stage_dir/plugins/"
mkdir -p "$stage_dir/packages/core-web"
rsync -a --include='*.tgz' --exclude='*' "$CORE_WEB_CANDIDATES/" "$stage_dir/packages/core-web/"
cp "$ROOT_DIR/plugins.lock" "$ROOT_DIR/release-versions.json" "$ROOT_DIR/release-versions.schema.json" "$stage_dir/"

printf '%s\n' 'Installing production Composer dependencies into the release...'
build_env="$stage_dir/server/.env.package-build"
umask 077
printf '%s\n' \
  'APP_ENV=production' \
  'APP_DEBUG=false' \
  'DEPLOYMENT_MODE=standalone' \
  'DB_HOST=build-only.invalid' \
  'DB_PORT=3306' \
  'DB_NAME=build_only' \
  'DB_USER=build_only' \
  'DB_PASS=build-only' \
  'DB_PREFIX=pa_' > "$build_env"
PEANUT_SERVER_ENV_FILE="$build_env" "$COMPOSER_BIN" install \
  --working-dir="$stage_dir/server" \
  --no-dev \
  --prefer-dist \
  --no-interaction \
  --no-progress \
  --optimize-autoloader
rm -f -- "$build_env"

[[ -f "$stage_dir/server/public/index.php" ]] || die 'server/public/index.php was not preserved'
[[ -f "$stage_dir/server/public/router.php" ]] || die 'server/public/router.php was not preserved'
[[ -f "$stage_dir/server/public/.htaccess" ]] || die 'server/public/.htaccess was not preserved'
[[ -d "$stage_dir/server/public/storage" ]] || die 'server/public/storage was not preserved'
[[ -f "$stage_dir/server/public/admin/index.html" ]] || die 'server/public/admin/index.html was not built'
[[ -f "$stage_dir/server/public/platform/index.html" ]] || die 'server/public/platform/index.html was not built'
[[ -f "$stage_dir/server/public/pc/index.html" ]] || die 'server/public/pc/index.html was not built'
[[ -f "$stage_dir/server/public/mobile/index.html" ]] || die 'server/public/mobile/index.html was not built'
[[ -f "$stage_dir/server/vendor/autoload.php" ]] || die 'production Composer dependencies are missing'
[[ -f "$stage_dir/plugins.lock" && -d "$stage_dir/plugins" ]] || die 'plugin delivery is incomplete'

if [[ -n "$(find "$stage_dir" -type f -name '.env*' ! -name '.env.example' -print -quit)" ]]; then
  die 'release contains a runtime or temporary environment file'
fi

if [[ -n "$(find "$stage_dir" -type d \( -name node_modules -o -name web \) -print -quit)" ]]; then
  die 'release contains node_modules or a web source directory'
fi

commit="unknown"
if command -v git >/dev/null 2>&1; then
  commit="$(git -C "$ROOT_DIR" rev-parse --short HEAD 2>/dev/null || printf '%s' unknown)"
fi
{
  printf 'product=Peanut Admin\n'
  printf 'commit=%s\n' "$commit"
  printf 'built_at_utc=%s\n' "$(date -u '+%Y-%m-%dT%H:%M:%SZ')"
  printf 'layout=server-public-four-client-bundles-with-locked-plugins\n'
} > "$stage_dir/release-manifest.txt"

mv -- "$stage_dir" "$OUTPUT_DIR"
trap - EXIT
printf 'release: %s\n' "$OUTPUT_DIR"
tar -C "$(dirname -- "$OUTPUT_DIR")" -czf "$OUTPUT_DIR.tar.gz" "$(basename -- "$OUTPUT_DIR")"
printf 'archive: %s.tar.gz\n' "$OUTPUT_DIR"
