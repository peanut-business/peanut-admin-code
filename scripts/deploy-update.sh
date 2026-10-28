#!/usr/bin/env bash
# Update an installed product in its existing Git checkout.
set -Eeuo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$ROOT"
fail() { printf 'deploy-update: %s\n' "$*" >&2; exit 1; }

main() {
  target=''
  clients=''
  compose_project='-'
  for argument in "$@"; do
    case "$argument" in
      --target=*) target="${argument#*=}" ;;
      --clients=*) clients="${argument#*=}" ;;
      --compose-project=*) compose_project="${argument#*=}" ;;
      *) fail 'usage: scripts/deploy-update.sh [--target=<commit>] [--clients=admin|admin,platform] [--compose-project=<project>]' ;;
    esac
  done
  [[ -z "$target" || "$target" =~ ^[a-f0-9]{40}$ ]] || fail 'target must be a full commit hash'
  [[ "$compose_project" == - || "$compose_project" =~ ^[a-z0-9][a-z0-9_-]{0,62}$ ]] || fail 'invalid Compose project'
  edition="$(python3 -c 'import json; print(json.load(open(".peanut/application-manifest.json"))["application"]["edition"])')"
  case "$edition" in standalone) clients="${clients:-admin}" ;; multi-tenant) clients="${clients:-admin,platform}" ;; *) fail 'invalid application Edition' ;; esac
  [[ "$clients" == admin || "$clients" == admin,platform ]] || fail 'select admin or admin,platform; PC/H5 updates require their own reviewed scope'
  [[ "$edition" == multi-tenant || "$clients" == admin ]] || fail 'standalone has no platform client'
  [[ "$(git rev-parse --show-toplevel)" == "$ROOT" ]] || fail 'run from the product Git checkout'
  [[ "$(git branch --show-current)" == main ]] || fail 'the product checkout must be on main'
  [[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail 'tracked files have uncommitted changes'

  state="$ROOT/.peanut/deploy-update.pending"
  [[ ! -L "$ROOT/.peanut" && ! -L "$state" ]] || fail 'update state must not be a symlink'
  current_head="$(git rev-parse HEAD)"
  if [[ -e "$state" ]]; then
    [[ -f "$state" ]] || fail 'update state is not a regular file'
    read -r old_head new_head saved_edition saved_clients saved_project extra < "$state"
    [[ "$old_head" =~ ^[a-f0-9]{40}$ && "$new_head" =~ ^[a-f0-9]{40}$ && -z "$extra" ]] || fail 'invalid pending update state'
    [[ "$saved_edition" == "$edition" && "$saved_clients" == "$clients" && "$saved_project" == "$compose_project" ]] || fail 'resume with the recorded Edition, clients and Compose project'
    [[ "$current_head" == "$old_head" || "$current_head" == "$new_head" ]] || fail 'checkout differs from the pending update'
    [[ -z "$target" || "$target" == "$new_head" ]] || fail 'finish the recorded pending update before selecting another target'
    printf '[deploy-update] Resuming %s -> %s\n' "$old_head" "$new_head"
  else
    old_head="$current_head"
    git fetch --quiet origin main
    new_head="$(git rev-parse origin/main^{commit})"
    [[ -z "$target" || "$target" == "$new_head" ]] || fail 'origin/main changed from the reviewed target'
    if [[ "$old_head" == "$new_head" ]]; then
      printf '[deploy-update] Already at %s\n' "$new_head"
      exit 0
    fi
    git merge-base --is-ancestor "$old_head" "$new_head" || fail 'origin/main is not a fast-forward'
  fi
  changed="$(git diff --name-only "$old_head" "$new_head")"
  target_edition="$(git show "$new_head:.peanut/application-manifest.json" | python3 -c 'import json,sys; print(json.load(sys.stdin)["application"]["edition"])')"
  [[ "$target_edition" == "$edition" ]] || fail 'an update cannot change the installed Edition'

  compose=()
  if [[ "$compose_project" != - ]]; then
    compose_lines="$(python3 - "$ROOT/server" <<'PY'
import importlib.util, pathlib, sys
sys.dont_write_bytecode = True
server = pathlib.Path(sys.argv[1])
spec = importlib.util.spec_from_file_location('native_compose', server / 'docker/scripts/configure-collaboration.py')
module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
print('\n'.join(module.compose_arguments(server)))
PY
  )"
    while IFS= read -r argument; do compose+=("$argument"); done <<< "$compose_lines"
    compose+=(-p "$compose_project")
    compose_run config --quiet
    python3 server/docker/scripts/vendor-state.py check --server "$ROOT/server"
    # Native start deliberately refuses installed vendor repair. Do not bypass it.
    if grep -Eq '^server/composer\.(json|lock)$' <<<"$changed"; then
      fail 'Docker dependency changes require the native installed-source vendor update entry before adoption'
    fi
  fi
  if [[ ! -e "$state" ]]; then
    (umask 077; set -o noclobber; printf '%s %s %s %s %s\n' "$old_head" "$new_head" "$edition" "$clients" "$compose_project" > "$state")
  fi
  if [[ "$compose_project" != - ]]; then compose_run stop nginx php; fi
  git merge --ff-only "$new_head"
  if [[ "$compose_project" == - ]] && grep -Eq '^server/composer\.(json|lock)$' <<<"$changed"; then
    composer install --working-dir=server --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
    php server/docker/scripts/prepare-vendor.php
  fi
  composition_changed=false
  if grep -Eq '^(plugins/|plugins\.lock$|server/app/modules/[^/]+/[^/]+/module\.json$)' <<<"$changed"; then
    composition_changed=true
  fi
  if $composition_changed || grep -Eq '^(web/|pnpm-lock.yaml$|packages/core-web/)' <<<"$changed"; then
    static_target admin
    if [[ ! -d web/node_modules ]] || grep -Eq '^web/(package.json|pnpm-lock.yaml)$|^packages/core-web/' <<<"$changed"; then
      (cd web && pnpm install --frozen-lockfile)
    fi
    (cd web && pnpm run build --mode "$edition" --outDir ../server/public/admin --emptyOutDir)
  fi
  if [[ "$clients" == admin,platform ]]; then
    if $composition_changed || grep -Eq '^(platform/|packages/core-web/)' <<<"$changed"; then
      static_target platform
      if [[ ! -d platform/node_modules ]] || grep -Eq '^platform/(package.json|package-lock.json)$|^packages/core-web/' <<<"$changed"; then
        (cd platform && npm ci)
      fi
      (cd platform && npm run build -- --outDir ../server/public/platform --emptyOutDir)
    fi
  fi
  backend module:compile
  backend migrate:run
  backend clear --cache
  if [[ "$compose_project" != - ]]; then
    sh server/docker/scripts/start.sh -p "$compose_project"
  fi
  rm -- "$state"
  printf '[deploy-update] Updated %s -> %s\n' "$old_head" "$new_head"
}

compose_run() { (cd "$ROOT/server/docker"; "${compose[@]}" "$@"); }
backend() {
  if [[ "$compose_project" == - ]]; then php server/think "$@"
  else compose_run run -T --rm --no-deps --entrypoint php php think "$@"; fi
}
static_target() {
  local destination="server/public/$1"
  [[ ! -L server && ! -L server/public && ! -L "$destination" ]] || fail 'static destination must not be linked'
  [[ ! -e "$destination" || -d "$destination" ]] || fail 'static destination is not a directory'
  [[ -z "$(git ls-files -- "$destination")" ]] || fail 'static destination contains tracked files'
}

# Parse the whole operation before Git updates this script's own tracked file.
main "$@"
