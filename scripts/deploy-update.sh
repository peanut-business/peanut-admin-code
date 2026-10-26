#!/usr/bin/env bash
# Update an installed product in its existing Git checkout.
set -Eeuo pipefail

ROOT="$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)"
cd "$ROOT"
fail() { printf 'deploy-update: %s\n' "$*" >&2; exit 1; }

target=''
if [[ $# -eq 1 && "$1" == --target=* ]]; then
  target="${1#--target=}"
  [[ "$target" =~ ^[a-f0-9]{40}$ ]] || fail 'target must be a full commit hash'
elif [[ $# -ne 0 ]]; then
  fail 'usage: scripts/deploy-update.sh [--target=<commit>]'
fi
[[ "$(git rev-parse --show-toplevel)" == "$ROOT" ]] || fail 'run from the product Git checkout'
[[ "$(git branch --show-current)" == main ]] || fail 'the product checkout must be on main'
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail 'tracked files have uncommitted changes'

state="$ROOT/.peanut/deploy-update.pending"
[[ ! -L "$ROOT/.peanut" && ! -L "$state" ]] || fail 'update state must not be a symlink'
current_head="$(git rev-parse HEAD)"
if [[ -e "$state" ]]; then
  [[ -f "$state" ]] || fail 'update state is not a regular file'
  read -r old_head new_head extra < "$state"
  [[ "$old_head" =~ ^[a-f0-9]{40}$ && "$new_head" =~ ^[a-f0-9]{40}$ && -z "$extra" ]] || fail 'invalid pending update state'
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
  mkdir -p "$ROOT/.peanut"
  (umask 077; set -o noclobber; printf '%s %s\n' "$old_head" "$new_head" > "$state")
fi
git merge --ff-only "$new_head"
changed="$(git diff --name-only "$old_head" "$new_head")"
if grep -Eq '^server/composer\.(json|lock)$' <<<"$changed"; then
  composer install --working-dir=server --no-dev --no-scripts --no-plugins --no-interaction --prefer-dist
fi
composition_changed=false
if grep -Eq '^(plugins/|plugins\.lock$|server/app/modules/[^/]+/[^/]+/module\.json$)' <<<"$changed"; then
  composition_changed=true
fi
if $composition_changed || grep -Eq '^(web/|pnpm-lock.yaml$)' <<<"$changed"; then
  (cd web && pnpm install --frozen-lockfile && pnpm run build)
fi
for client in platform pc uniapp; do
  if $composition_changed || grep -q "^${client}/" <<<"$changed"; then
    case "$client" in
      pc) (cd pc && npm ci && NUXT_PC_RENDER_MODE=spa npm run generate) ;;
      uniapp) (cd uniapp && npm ci && npm run build:h5) ;;
      platform) (cd platform && npm ci && npm run build) ;;
    esac
  fi
done
php server/think module:compile
php server/think migrate:run
php server/think clear --cache
rm -- "$state"
printf '[deploy-update] Updated %s -> %s\n' "$old_head" "$new_head"
