#!/usr/bin/env bash
# Package an existing generated application, retaining its upgrade ownership baseline.
set -Eeuo pipefail
SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
ROOT_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)"
APPLICATION_ROOT="$ROOT_DIR"
OUTPUT_DIR="$ROOT_DIR/release/peanut-admin"
DIRECTORY_ONLY=0
usage() {
  cat <<'USAGE'
Usage: scripts/package-release.sh [/absolute/output] --application-root=/absolute/generated-application [--directory-only]
The existing build-edition-installers creates the application and calls this step.
Build dependencies exist only in an isolated temporary directory. The package
retains all manifest-owned sources, locks and upgrade baselines, plus browser
assets under public/admin, platform, pc and mobile. The new instance installs
its dependencies; Nuxt SSR is rebuilt there. No installed dependency is shipped.
USAGE
}
die() { printf 'package-release: %s\n' "$*" >&2; exit 1; }
if [[ $# -gt 0 && "$1" != --* ]]; then OUTPUT_DIR="$1"; shift; fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --application-root=*) APPLICATION_ROOT="${1#*=}"; shift ;;
    --directory-only) DIRECTORY_ONLY=1; shift ;;
    --help|-h) usage; exit 0 ;;
    --skip-client-build|--core-web-candidates=*) die 'unbound prebuilt files and historical Core tgz are not release inputs; use fixed published dependencies' ;;
    *) die "unsupported argument: $1" ;;
  esac
done
[[ "$APPLICATION_ROOT" == /* && -d "$APPLICATION_ROOT" && ! -L "$APPLICATION_ROOT" ]] || die 'application root must be an absolute real directory'
[[ -f "$APPLICATION_ROOT/.peanut/application-manifest.json" ]] || die 'missing generated application baseline; use the existing build-edition-installers entry, not a raw source copy'
[[ "$OUTPUT_DIR" == /* ]] || OUTPUT_DIR="$ROOT_DIR/$OUTPUT_DIR"
[[ ! -e "$OUTPUT_DIR" && ! -L "$OUTPUT_DIR" && ! -e "$OUTPUT_DIR.tar.gz" && ! -L "$OUTPUT_DIR.tar.gz" ]] || die 'output already exists'
for executable in python3 node npm pnpm php; do
  command -v "$executable" >/dev/null 2>&1 || die "missing build command: $executable"
done
node -e 'const [a,b]=process.versions.node.split(".").map(Number);process.exit(a===22&&b>=12?0:1)' || die 'Node 22.12+ within major 22 is required'
[[ "$(php -r 'echo PHP_MAJOR_VERSION,".",PHP_MINOR_VERSION;')" == '8.3' ]] || die 'the actual application build requires PHP 8.3'
output_parent="$(dirname -- "$OUTPUT_DIR")"
mkdir -p "$output_parent"
lock_dir="$OUTPUT_DIR.packaging-lock"
mkdir "$lock_dir" || die 'another packaging operation owns this output'
work=""
cleanup() {
  [[ -z "$work" ]] || rm -rf -- "$work"
  rmdir -- "$lock_dir"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
work="$(mktemp -d "$output_parent/.peanut-package.XXXXXX")"
build="$work/build"
stage="$work/assembled"
python3 "$SCRIPT_DIR/package-release-files.py" snapshot --application-root "$APPLICATION_ROOT" --target "$build"
# Frozen native installs prove availability and lock consistency. No installation
# scripts touch the original checkout, runtime secrets or application database.
"$build/scripts/project-composer" prepare
"$build/scripts/project-composer" validate --working-dir="$build/server" --strict
"$build/scripts/project-composer" install --working-dir="$build/server" --no-scripts --no-interaction --no-progress --prefer-dist
HUSKY=0 pnpm --dir "$build/web" install --frozen-lockfile
for client in platform pc uniapp; do HUSKY=0 npm --prefix "$build/$client" ci; done
edition="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["application"]["edition"])' "$build/.peanut/application-manifest.json")"
case "$edition" in standalone|multi-tenant) ;; *) die 'unknown generated application edition' ;; esac
(cd "$build/web" && PEANUT_CLIENT_ENV_FILE="$build/web/.env.$edition" pnpm run build)
npm --prefix "$build/platform" run build
npm --prefix "$build/pc" run build
npm --prefix "$build/uniapp" run build:h5
# Recheck all source bytes after native install/build; reject unrecorded source
# mutations rather than silently altering the existing upgrade baseline.
python3 "$SCRIPT_DIR/package-release-files.py" assemble --application-root "$APPLICATION_ROOT" --build-root "$build" --target "$stage"
if [[ "$DIRECTORY_ONLY" != 1 ]]; then
  # Reuse the project's deterministic archive writer, not a new archive format.
  php -r 'require $argv[1]; (new app\common\infrastructure\scaffold\DeterministicEditionArchive())->write($argv[2],$argv[3],$argv[4]);' \
    "$ROOT_DIR/server/app/common/infrastructure/scaffold/DeterministicEditionArchive.php" \
    "$stage" "$(basename -- "$OUTPUT_DIR")" "$work/product.tar.gz"
fi
[[ ! -e "$OUTPUT_DIR" && ! -e "$OUTPUT_DIR.tar.gz" ]] || die 'output changed during build'
mv -- "$stage" "$OUTPUT_DIR"
if [[ "$DIRECTORY_ONLY" != 1 ]]; then mv -- "$work/product.tar.gz" "$OUTPUT_DIR.tar.gz"; fi
printf 'release source bundle: %s\nDependencies must be installed on the new instance. Product acceptance is a separate explicit check.\n' "$OUTPUT_DIR"
