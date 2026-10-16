#!/usr/bin/env bash
# Build a development source or server-only release from an application.
set -Eeuo pipefail
# Release dependencies belong to the isolated build, never caller overrides.
unset COMPOSER COMPOSER_VENDOR_DIR COMPOSER_BIN_DIR
SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)"
ROOT_DIR="$(CDPATH= cd -- "$SCRIPT_DIR/.." && pwd -P)"
APPLICATION_ROOT="$ROOT_DIR"
OUTPUT_DIR="$ROOT_DIR/release/peanut-admin"
DIRECTORY_ONLY=0
GENERATED_TEMPLATE=0
INTERNAL_CANDIDATE=0
SERVER_ONLY=0
SERVER_OUTPUT=""
usage() {
  cat <<'USAGE'
Usage: scripts/package-release.sh [/absolute/output] --application-root=/absolute/application [--directory-only] [--server-only|--server-output=/absolute/output]
The existing build-edition-installers uses --generated-template for the initial
Code-derived edition; a customized APP must be a clean committed Git worktree.
The default package retains APP development source and its upstream baseline.
--server-only emits the production server/ tree, its generated release identity
and built browser assets. Build dependencies remain in an isolated temp directory.
--internal-candidate accepts the sealed seven-archive projection and builds all
four existing clients; it emits an internal APP, never a formal/server-only release.
USAGE
}
die() { printf 'package-release: %s\n' "$*" >&2; exit 1; }
if [[ $# -gt 0 && "$1" != --* ]]; then OUTPUT_DIR="$1"; shift; fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --application-root=*) APPLICATION_ROOT="${1#*=}"; shift ;;
    --directory-only) DIRECTORY_ONLY=1; shift ;;
    --generated-template) GENERATED_TEMPLATE=1; shift ;;
    --internal-candidate) INTERNAL_CANDIDATE=1; shift ;;
    --server-only) SERVER_ONLY=1; shift ;;
    --server-output=*) SERVER_OUTPUT="${1#*=}"; shift ;;
    --help|-h) usage; exit 0 ;;
    --skip-client-build|--core-web-candidates=*) die 'unbound prebuilt files and historical Core tgz are not release inputs; use fixed published dependencies' ;;
    *) die "unsupported argument: $1" ;;
  esac
done
[[ "$INTERNAL_CANDIDATE" != 1 || ( "$GENERATED_TEMPLATE" == 0 && "$SERVER_ONLY" == 0 && -z "$SERVER_OUTPUT" ) ]] || die 'internal candidate requires the complete four-client APP'
[[ "$SERVER_ONLY" != 1 || -z "$SERVER_OUTPUT" ]] || die 'choose --server-only or --server-output'
[[ -z "$SERVER_OUTPUT" || "$DIRECTORY_ONLY" == 1 ]] || die '--server-output is for the edition installer directory assembly'
[[ "$APPLICATION_ROOT" == /* && -d "$APPLICATION_ROOT" && ! -L "$APPLICATION_ROOT" ]] || die 'application root must be an absolute real directory'
[[ -f "$APPLICATION_ROOT/.peanut/application-manifest.json" ]] || die 'missing generated application baseline; use the existing build-edition-installers entry, not a raw source copy'
[[ "$OUTPUT_DIR" == /* ]] || OUTPUT_DIR="$ROOT_DIR/$OUTPUT_DIR"
if [[ -n "$SERVER_OUTPUT" ]]; then
  [[ "$SERVER_OUTPUT" == /* && "$SERVER_OUTPUT" != "$OUTPUT_DIR" ]] || die 'server output must be a distinct absolute path'
  [[ ! -e "$SERVER_OUTPUT" && ! -L "$SERVER_OUTPUT" && ! -e "$SERVER_OUTPUT.tar.gz" && ! -L "$SERVER_OUTPUT.tar.gz" \
     && ! -e "$SERVER_OUTPUT.tar.gz.manifest.json" && ! -L "$SERVER_OUTPUT.tar.gz.manifest.json" ]] || die 'server output already exists'
fi
[[ ! -e "$OUTPUT_DIR" && ! -L "$OUTPUT_DIR" && ! -e "$OUTPUT_DIR.tar.gz" && ! -L "$OUTPUT_DIR.tar.gz" \
   && ! -e "$OUTPUT_DIR.tar.gz.manifest.json" && ! -L "$OUTPUT_DIR.tar.gz.manifest.json" ]] || die 'output already exists'
[[ "$INTERNAL_CANDIDATE" != 1 || ( ! -e "$OUTPUT_DIR.dependency-proof.json" && ! -L "$OUTPUT_DIR.dependency-proof.json" ) ]] || die 'internal dependency proof already exists'
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
package_completed=0
cleanup() {
  local status=$?
  # A signal/early exit is never success merely because the previous command
  # returned zero. Only the end-of-pipeline marker permits cleanup.
  rmdir -- "$lock_dir" || { if [[ "$status" == 0 ]]; then status=1; fi; }
  if [[ "$INTERNAL_CANDIDATE" == 1 && -n "$work" && ( "$status" != 0 || "$package_completed" != 1 ) ]]; then
    # mktemp created this directory as 0700. Retain the real snapshot/build,
    # native PM locks, installed dependencies and origin-proof attempt; none
    # are source or distributable release inputs. Do not print environment.
    (umask 077; printf 'status=failed-unqualified-private-diagnostics\nexit_code=%s\ncompleted=%s\nowner_uid=%s\nowner_pid=%s\nbuild=%s\nlifecycle_owner=invoking-build-Session-owner; retain-until-explicit-diagnosis-and-cleanup\n' \
      "$status" "$package_completed" "$UID" "$$" "${build:-not-created}" > "$work/failure-context.txt") || true
    printf 'package-release: FAILURE_RETAINED: %s; owner_uid=%s; owner_pid=%s; cleanup=invoking-build-Session-owner-after-diagnosis\n' \
      "$work" "$UID" "$$" >&2
  else
    [[ -z "$work" ]] || rm -rf -- "$work"
  fi
  return "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
work="$(mktemp -d "$output_parent/.peanut-package.XXXXXX")"
if [[ "$INTERNAL_CANDIDATE" == 1 ]]; then
  printf 'package-release: PRIVATE_STAGING: %s; owner_uid=%s; owner_pid=%s; lifecycle_owner=invoking-build-Session-owner\n' \
    "$work" "$UID" "$$" >&2
fi
build="$work/build"
stage="$work/assembled"
server_stage="$work/assembled-server"
template_option=()
if [[ "$GENERATED_TEMPLATE" == 1 ]]; then template_option+=(--generated-template); fi
dependency_option=()
if [[ "$INTERNAL_CANDIDATE" == 1 ]]; then
  template_option+=(--internal-candidate)
  dependency_option+=(--internal-candidate)
fi
if [[ "$SERVER_ONLY" == 1 ]]; then template_option+=(--server-only); fi
python3 "$SCRIPT_DIR/package-release-files.py" snapshot --application-root "$APPLICATION_ROOT" --target "$build" "${template_option[@]}"
# Frozen native installs prove availability and lock consistency. No installation
# scripts touch the original checkout, runtime secrets or application database.
"$build/scripts/project-composer" prepare
# Formal product candidates intentionally pin the published PHP Core exactly.
# Composer reports exact require constraints as a general warning; keep schema
# validation here, while frozen install + the --installed gate below prove the
# actual dependency/lock closure.
"$build/scripts/project-composer" validate --working-dir="$build/server"
"$build/scripts/project-composer" install --working-dir="$build/server" --no-scripts --no-interaction --no-progress --prefer-dist
install_option=()
if [[ "$INTERNAL_CANDIDATE" == 1 ]]; then install_option+=(--ignore-scripts); fi
HUSKY=0 pnpm --dir "$build/web" install --frozen-lockfile "${install_option[@]}"
for client in platform pc uniapp; do HUSKY=0 npm --prefix "$build/$client" ci "${install_option[@]}"; done
# 原生安装完成后，核对实际锁与已安装包，不只检查包名里的版本字符串。
node "$SCRIPT_DIR/release-dependency-locks.mjs" "$build" --installed "${dependency_option[@]}" > "$work/dependency-proof.json"
edition="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["application"]["edition"])' "$build/.peanut/application-manifest.json")"
case "$edition" in standalone|multi-tenant) ;; *) die 'unknown generated application edition' ;; esac
(cd "$build/web" && PEANUT_CLIENT_ENV_FILE="$build/web/.env.$edition" pnpm run build)
npm --prefix "$build/platform" run build
# A static package needs Nuxt's SPA-generated HTML entry. An SSR build's
# .output/public contains assets only and cannot be served as a complete app.
printf 'NUXT_PC_RENDER_MODE=spa\n' > "$work/pc-spa.env"
PEANUT_CLIENT_ENV_FILE="$work/pc-spa.env" npm --prefix "$build/pc" run generate
npm --prefix "$build/uniapp" run build:h5
# Recheck all source bytes after native install/build; reject unrecorded source
# mutations rather than silently altering the existing upgrade baseline.
python3 "$SCRIPT_DIR/package-release-files.py" assemble --application-root "$APPLICATION_ROOT" --build-root "$build" --target "$stage" "${template_option[@]}"
if [[ "$INTERNAL_CANDIDATE" == 1 ]]; then
  # Proof belongs to this isolated native install; no dependency directory is shipped.
  cp "$work/dependency-proof.json" "$work/dependency-proof-output.json"
fi
if [[ -n "$SERVER_OUTPUT" ]]; then
  python3 "$SCRIPT_DIR/package-release-files.py" assemble --application-root "$APPLICATION_ROOT" --build-root "$build" --target "$server_stage" "${template_option[@]}" --server-only
fi
if [[ "$DIRECTORY_ONLY" != 1 ]]; then
  # Reuse the project's deterministic archive writer, not a new archive format.
  php -r 'require $argv[1]; (new app\common\infrastructure\scaffold\DeterministicEditionArchive())->write($argv[2],$argv[3],$argv[4]);' \
    "$ROOT_DIR/server/app/common/infrastructure/scaffold/DeterministicEditionArchive.php" \
    "$stage" "$(basename -- "$OUTPUT_DIR")" "$work/product.tar.gz"
fi
if [[ "$DIRECTORY_ONLY" != 1 && "$SERVER_ONLY" == 1 ]]; then
  python3 - "$stage/server/.peanut/release-identity.json" "$work/product.tar.gz" "$work/product-manifest.json" "$(basename -- "$OUTPUT_DIR.tar.gz")" <<'PY'
import hashlib, json, pathlib, sys
identity_path, archive_path, output_path = map(pathlib.Path, sys.argv[1:4])
identity = json.loads(identity_path.read_text())
with archive_path.open('rb') as stream:
    archive_sha256 = hashlib.file_digest(stream, 'sha256').hexdigest()
payload = {
    'schema_version': 1, 'protocol': 'peanut.server-archive.v1',
    'application': identity['application'], 'upstream': identity['upstream'],
    'identity_sha256': hashlib.sha256(identity_path.read_bytes()).hexdigest(),
    'archive': {'filename': sys.argv[4], 'sha256': archive_sha256,
                'bytes': archive_path.stat().st_size},
}
output_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + '\n')
PY
fi
[[ ! -e "$OUTPUT_DIR" && ! -e "$OUTPUT_DIR.tar.gz" && ! -e "$OUTPUT_DIR.tar.gz.manifest.json" ]] || die 'output changed during build'
[[ "$INTERNAL_CANDIDATE" != 1 || ( ! -e "$OUTPUT_DIR.dependency-proof.json" && ! -L "$OUTPUT_DIR.dependency-proof.json" ) ]] || die 'internal dependency proof changed during build'
if [[ -n "$SERVER_OUTPUT" ]]; then
  [[ ! -e "$SERVER_OUTPUT" && ! -e "$SERVER_OUTPUT.tar.gz" && ! -e "$SERVER_OUTPUT.tar.gz.manifest.json" ]] || die 'server output changed during build'
fi
mv -- "$stage" "$OUTPUT_DIR"
if [[ "$INTERNAL_CANDIDATE" == 1 ]]; then mv -- "$work/dependency-proof-output.json" "$OUTPUT_DIR.dependency-proof.json"; fi
if [[ -n "$SERVER_OUTPUT" ]]; then
  mkdir -p "$(dirname -- "$SERVER_OUTPUT")"
  mv -- "$server_stage" "$SERVER_OUTPUT"
fi
if [[ "$DIRECTORY_ONLY" != 1 ]]; then
  mv -- "$work/product.tar.gz" "$OUTPUT_DIR.tar.gz"
  if [[ "$SERVER_ONLY" == 1 ]]; then
    mv -- "$work/product-manifest.json" "$OUTPUT_DIR.tar.gz.manifest.json"
  fi
fi
printf 'release bundle: %s\nValidate the external release manifest and archive SHA-256 before deployment. Product acceptance is separate.\n' "$OUTPUT_DIR"
package_completed=1
