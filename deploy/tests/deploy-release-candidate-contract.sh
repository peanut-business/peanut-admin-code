#!/usr/bin/env bash

set -Eeuo pipefail

ROOT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)"
SCRIPT="$ROOT_DIR/scripts/deploy-release"

fail() { printf 'candidate-contract: %s\n' "$1" >&2; exit 1; }
expect_fail() {
  local name="$1" pattern="$2"
  shift 2
  local output rc
  set +e
  output="$($SCRIPT "$@" 2>&1)"
  rc=$?
  set -e
  [[ $rc -ne 0 ]] || fail "$name unexpectedly succeeded"
  [[ "$output" == *"$pattern"* ]] || fail "$name did not report $pattern"
  printf 'passed=%s\n' "$name"
}

cd "$ROOT_DIR"
[[ -x "$SCRIPT" ]] || fail 'deploy-release is not executable'
bash -n "$SCRIPT"
jq empty "$ROOT_DIR/.local/deploy-tool-resource-proposal.json"

candidate_commit="$(git rev-parse HEAD)"
candidate_tree="$(git rev-parse HEAD^{tree})"
candidate_sha="$(printf '%s' "$candidate_commit" | cut -c1-12)"

expect_fail 'candidate requires tree' 'candidate deployment requires --expected-tree' \
  --candidate-commit="$candidate_commit" --target production --fresh \
  --confirm-destroy=production --paired-backup backup-id \
  --paired-backup-manifest-sha256="$(printf 'a%.0s' {1..64})" --dry-run

expect_fail 'unknown target' 'target must be production or production-candidate' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target unknown --install --dry-run

expect_fail 'candidate tag is mutually exclusive' 'candidate deployment cannot carry a formal release tag' \
  v4.0.0 --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --install --dry-run

expect_fail 'fresh requires exact confirmation' 'requires --confirm-destroy production' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --fresh --dry-run

expect_fail 'fresh requires exact backup' 'safe exact --paired-backup identifier' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --fresh --confirm-destroy=production --paired-backup='../latest' \
  --paired-backup-manifest-sha256="$(printf 'a%.0s' {1..64})" --dry-run

expect_fail 'candidate rejects formal overlay' 'Edition and overlay inputs are formal-release-only' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --install --overlay /tmp/no-overlay.tar --dry-run

valid_env="$(mktemp /tmp/peanut-candidate-contract-env.XXXXXX)"
trap 'rm -f "$valid_env"' EXIT
chmod 600 "$valid_env"
printf '%s\n' \
  'PEANUT_GENERATED_ADMIN_EMAIL=admin@example.test' \
  'PEANUT_GENERATED_ADMIN_PASSWORD=contract-test-password' \
  'PEANUT_GENERATED_PLATFORM_EMAIL=platform@example.test' \
  'PEANUT_GENERATED_PLATFORM_PASSWORD=contract-test-password' >"$valid_env"
valid_output="$($SCRIPT --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --fresh --confirm-destroy=production \
  --paired-backup=20260922T034505Z-e2519bc1f90a-dual \
  --paired-backup-manifest-sha256="$(printf 'a%.0s' {1..64})" \
  --env-file="$valid_env" --dry-run 2>&1)" || fail 'valid candidate dry-run was rejected'
[[ "$valid_output" == *'deployment_identity=candidate'* ]] || fail 'candidate identity was not printed'
[[ "$valid_output" == *'product_version=4.0.0-dev'* ]] || fail 'candidate product version was not preserved'
[[ "$valid_output" == *'schema_source=3.1.0'* ]] || fail 'stable schema source was not separated'
[[ "$valid_output" != *'RELEASE_METADATA3.1.0'* ]] || fail 'candidate dry-run reported the old release metadata as a new release'
printf 'passed=valid-candidate-dry-run\n'

compose_file="$ROOT_DIR/deploy/docker-compose.prod.yml"
nginx_file="$ROOT_DIR/deploy/nginx/peanut-admin.conf"
for required in \
  'PC_IMAGE' 'PEANUT_PUBLIC_SCHEME' 'PEANUT_TRUSTED_HOSTS' \
  'NUXT_UPSTREAM_ORIGIN: http://nginx' 'proxy_set_header Host $http_host' \
  'proxy_set_header X-Forwarded-Proto $scheme' \
  'DEPLOYMENT_RECEIPT_FILE'; do
  case "$required" in
    proxy_*) rg -Fq "$required" "$nginx_file" || fail "Nginx SSR contract missing $required" ;;
    *) rg -Fq "$required" "$compose_file" || fail "Compose contract missing $required" ;;
  esac
done
rg -Fq 'wildcard host' "$SCRIPT" || fail 'remote SSR wildcard rejection is missing'
rg -Fq 'candidate image IDs are not immutable Docker IDs' "$SCRIPT" || fail 'three-image identity gate is missing'
rg -Fq 'host and running PHP deployment receipts differ' "$SCRIPT" || fail 'host/runtime receipt equality gate is missing'
printf 'passed=ssr-pc-receipt-static-contract\n'

printf 'candidate-contract: all checks passed (commit12=%s)\n' "$candidate_sha"
