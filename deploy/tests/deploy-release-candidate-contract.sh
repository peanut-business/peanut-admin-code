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
# 合同测试只依赖已跟踪的正式资源登记，不依赖维护者本机的临时提案。
jq empty "$ROOT_DIR/resources/project-resources.json"

legacy_helper="$ROOT_DIR/deploy/legacy-env-migrate"
[[ -x "$legacy_helper" ]] || fail 'legacy environment helper is not executable'
bash -n "$legacy_helper"
legacy_fixture="$(mktemp -d /private/tmp/peanut-legacy-layout-contract.XXXXXX)"
trap 'rm -rf -- "$legacy_fixture"' EXIT

run_legacy_helper() {
  local root_env="$1" target="$2" root_output="$3" backend_output="$4"
  local port project database_resource database_name deployment_mode platform_hosts tenant_hosts owner_mode scheme hosts
  shift 4
  port="$1"; project="$2"; database_resource="$3"; database_name="$4"; deployment_mode="$5"
  platform_hosts="$6"; tenant_hosts="$7"; owner_mode="$8"; scheme="$9"; hosts="${10}"
  shift 10
  "$legacy_helper" --target "$target" --root-env "$root_env" \
    --expected-port "$port" --expected-project "$project" --expected-database-resource "$database_resource" \
    --expected-database-name "$database_name" --expected-mode "$deployment_mode" \
    --expected-platform-hosts "$platform_hosts" --expected-tenant-admin-hosts "$tenant_hosts" \
    --expected-owner-invitation-mode "$owner_mode" --public-scheme "$scheme" --trusted-hosts "$hosts" \
    --allow-secret-rotation --root-output "$root_output" --backend-output "$backend_output" "$@"
}

prod_root="$legacy_fixture/production-root.env"
prod_output_root="$legacy_fixture/production-staged.env"
prod_output_backend="$legacy_fixture/production-staged-backend.env"
{
  printf '%s\n' \
    'APP_ENV=production' 'APP_DEBUG=false' \
    'PEANUT_DEPLOYMENT_TARGET=production' \
    'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-production-bundled-mysql84' \
    'DEPLOYMENT_MODE=standalone' 'DB_HOST=mysql' 'DB_PORT=3306' \
    'DB_NAME=peanut_admin' 'DB_USER=peanut_admin' \
    'DB_PASS=legacy-db-secret' 'MYSQL_ROOT_PASSWORD=legacy-root-secret' \
    'JWT_SECRET=legacy-jwt-secret' \
    'TENANT_IDENTIFIER_HMAC_KEY=legacy-tenant-hmac' \
    'PLATFORM_IDENTIFIER_HMAC_KEY=legacy-platform-hmac' \
    'COMPOSE_PROFILES=bundled-db' 'HTTP_PORT=18092' \
    'PHP_IMAGE=peanut-admin-php:3.0.5' 'NGINX_IMAGE=peanut-admin-nginx:3.0.5'
} >"$prod_root"
chmod 600 "$prod_root"
prod_output="$(run_legacy_helper "$prod_root" production "$prod_output_root" "$prod_output_backend" \
  18092 peanut-admin peanut-admin-production-bundled-mysql84 peanut_admin standalone '' '' auto https \
  peanut-admin.007345.xyz)" || fail 'root-only legacy layout was rejected'
[[ "$prod_output" == 'legacy_layout=converted' ]] || fail 'root-only helper output is not sanitized'
! rg -q '^(APP_|DB_|JWT_|DEPLOYMENT_MODE=|PEANUT_DEPLOYMENT_TARGET=|MYSQL_ROOT_PASSWORD)' "$prod_output_root" \
  || fail 'root-only conversion left backend configuration in the orchestration file'
rg -Fq 'DB_ROOT_PASS=legacy-root-secret' "$prod_output_backend" \
  || fail 'MYSQL_ROOT_PASSWORD was not mapped to DB_ROOT_PASS'
! rg -Fq 'MYSQL_ROOT_PASSWORD' "$prod_output_backend" || fail 'legacy MYSQL_ROOT_PASSWORD leaked into backend output'
! rg -Fq 'legacy-root-secret' <<<"$prod_output" || fail 'helper output exposed a secret'

split_root="$legacy_fixture/candidate-root.env"
split_backend="$legacy_fixture/candidate-server.env"
split_output_root="$legacy_fixture/candidate-staged.env"
split_output_backend="$legacy_fixture/candidate-staged-backend.env"
{
  printf '%s\n' 'COMPOSE_PROFILES=bundled-db' 'COMPOSE_PROJECT_NAME=peanut-admin-candidate' \
    'HTTP_PORT=18093' 'MYSQL_ROOT_PASSWORD=split-root-secret' \
    'PHP_IMAGE=peanut-admin-php:3.0.14' 'NGINX_IMAGE=peanut-admin-nginx:3.0.14'
} >"$split_root"
{
  printf '%s\n' \
    'APP_ENV=production' 'APP_DEBUG=false' \
    'PEANUT_DEPLOYMENT_TARGET=production-candidate' \
    'PEANUT_DATABASE_RESOURCE_ID=peanut-admin-production-candidate-mysql84' \
    'DEPLOYMENT_MODE=multi-tenant' 'DB_HOST=mysql' 'DB_PORT=3306' \
    'DB_NAME=peanut_admin_candidate' 'DB_USER=peanut_admin_candidate' \
    'DB_PASS=split-db-secret' 'DB_ROOT_PASS=split-root-secret' \
    'JWT_SECRET=split-jwt-secret' \
    'TENANT_IDENTIFIER_HMAC_KEY=split-tenant-hmac' \
    'PLATFORM_IDENTIFIER_HMAC_KEY=split-platform-hmac' \
    'PEANUT_STORAGE_CREDENTIAL_MASTER_KEY=split-storage-key' \
    'PLATFORM_HOSTS=pa-platform.007345.xyz' \
    'TENANT_ADMIN_HOSTS=pa-admin.007345.xyz' \
    'OWNER_INVITATION_DELIVERY_MODE=manual'
} >"$split_backend"
chmod 600 "$split_root" "$split_backend"
split_output="$(run_legacy_helper "$split_root" production-candidate "$split_output_root" "$split_output_backend" \
  18093 peanut-admin-candidate peanut-admin-production-candidate-mysql84 peanut_admin_candidate multi-tenant \
  pa-platform.007345.xyz pa-admin.007345.xyz manual https \
  pa-platform.007345.xyz,pa-admin.007345.xyz,pa-tenant-a.007345.xyz,pa-tenant-b.007345.xyz \
  --backend-env "$split_backend")" || fail 'split legacy layout was rejected'
[[ "$split_output" == 'legacy_layout=converted' ]] || fail 'split helper output is not sanitized'
! rg -q '^(DB_|JWT_|DEPLOYMENT_MODE=|PEANUT_DEPLOYMENT_TARGET=|MYSQL_ROOT_PASSWORD)' "$split_output_root" \
  || fail 'split conversion left backend configuration in the orchestration file'
rg -Fq 'DB_ROOT_PASS=split-root-secret' "$split_output_backend" \
  || fail 'matching MYSQL_ROOT_PASSWORD/DB_ROOT_PASS pair was not retained'

expect_legacy_fail() {
  local name="$1" pattern="$2" root_env="$3" output_root="$4" output_backend="$5"
  shift 5
  local before after output rc
  before="$(shasum -a 256 "$root_env" | awk '{print $1}')"
  set +e
  output="$(run_legacy_helper "$root_env" "$@" "$output_root" "$output_backend" 2>&1)"
  rc=$?
  set -e
  [[ $rc -ne 0 ]] || fail "$name unexpectedly succeeded"
  [[ "$output" == *"$pattern"* ]] || fail "$name did not report $pattern"
  after="$(shasum -a 256 "$root_env" | awk '{print $1}')"
  [[ "$before" == "$after" ]] || fail "$name modified the source environment"
  [[ ! -e "$output_root" && ! -e "$output_backend" ]] || fail "$name wrote output files after rejection"
  printf 'passed=%s\n' "$name"
}

duplicate_root="$legacy_fixture/duplicate.env"
cp "$prod_root" "$duplicate_root"
printf '%s\n' 'HTTP_PORT=18092' >>"$duplicate_root"
chmod 600 "$duplicate_root"
expect_legacy_fail 'duplicate legacy key' 'duplicate key HTTP_PORT' "$duplicate_root" \
  "$legacy_fixture/duplicate-out.env" "$legacy_fixture/duplicate-backend.env" \
  production 18092 peanut-admin peanut-admin-production-bundled-mysql84 peanut_admin standalone '' '' auto https \
  peanut-admin.007345.xyz

wrong_endpoint="$legacy_fixture/wrong-endpoint.env"
sed 's/^DB_HOST=mysql$/DB_HOST=unregistered-host/' "$prod_root" >"$wrong_endpoint"
chmod 600 "$wrong_endpoint"
expect_legacy_fail 'wrong endpoint selector' 'registered selector mismatch: DB_HOST' "$wrong_endpoint" \
  "$legacy_fixture/wrong-out.env" "$legacy_fixture/wrong-backend.env" \
  production 18092 peanut-admin peanut-admin-production-bundled-mysql84 peanut_admin standalone '' '' auto https \
  peanut-admin.007345.xyz

symlink_root="$legacy_fixture/symlink.env"
ln -s "$prod_root" "$symlink_root"
set +e
symlink_output="$(run_legacy_helper "$symlink_root" production "$legacy_fixture/symlink-out.env" "$legacy_fixture/symlink-backend.env" \
  18092 peanut-admin peanut-admin-production-bundled-mysql84 peanut_admin standalone '' '' auto https peanut-admin.007345.xyz 2>&1)"
symlink_rc=$?
set -e
[[ $symlink_rc -ne 0 && "$symlink_output" == *'not a symlink'* ]] || fail 'symlink environment was accepted'

conflict_backend="$legacy_fixture/conflict-server.env"
sed 's/^DB_ROOT_PASS=split-root-secret$/DB_ROOT_PASS=conflicting-root-secret/' "$split_backend" >"$conflict_backend"
chmod 600 "$conflict_backend"
set +e
conflict_output="$(run_legacy_helper "$split_root" production-candidate "$legacy_fixture/conflict-out.env" "$legacy_fixture/conflict-backend.env" \
  18093 peanut-admin-candidate peanut-admin-production-candidate-mysql84 peanut_admin_candidate multi-tenant \
  pa-platform.007345.xyz pa-admin.007345.xyz manual https \
  pa-platform.007345.xyz,pa-admin.007345.xyz,pa-tenant-a.007345.xyz,pa-tenant-b.007345.xyz \
  --backend-env "$conflict_backend" 2>&1)"
conflict_rc=$?
set -e
[[ $conflict_rc -ne 0 && "$conflict_output" == *'MYSQL_ROOT_PASSWORD and DB_ROOT_PASS conflict'* ]] \
  || fail 'conflicting root/database root credentials were accepted'
printf 'passed=legacy-secret-conflict\n'

candidate_commit="$(git rev-parse HEAD)"
candidate_tree="$(git rev-parse HEAD^{tree})"
candidate_sha="$(printf '%s' "$candidate_commit" | cut -c1-12)"

expect_fail 'candidate tree identity mismatch' 'candidate commit tree differs from --expected-tree' \
  --candidate-commit="$candidate_commit" --expected-tree="$(printf '0%.0s' {1..40})" \
  --target production --update --dry-run

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

expect_fail 'fresh requires manifest binding' 'exact paired backup manifest SHA-256' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --fresh --confirm-destroy=production --paired-backup=exact-backup \
  --paired-backup-manifest-sha256=not-a-sha --dry-run

expect_fail 'candidate rejects formal overlay' 'Edition and overlay inputs are formal-release-only' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --install --overlay /tmp/no-overlay.tar --dry-run

dirty_marker="$ROOT_DIR/deploy/.candidate-contract-dirty-marker"
touch "$dirty_marker"
expect_fail 'dirty source is rejected' 'source checkout has untracked files' \
  --candidate-commit="$candidate_commit" --expected-tree="$candidate_tree" \
  --target production --update --dry-run
rm -f "$dirty_marker"

valid_env="$(mktemp /tmp/peanut-candidate-contract-env.XXXXXX)"
trap 'rm -rf -- "$legacy_fixture"; rm -f -- "$valid_env"' EXIT
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
[[ "$valid_output" == *'schema_source=4.0.0-dev'* ]] || fail 'candidate scaffold migration target was not preserved'
[[ "$valid_output" != *'schema_source=3.1.0'* ]] || fail 'candidate dry-run used the old release metadata as its migration target'
rg -Fq 'release-versions.scaffold_template' "$SCRIPT" \
  || fail 'candidate receipt does not identify the scaffold migration authority'
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
