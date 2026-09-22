#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"
mode="${1:-}"
shift || true
BACKEND_ENV=""
if [[ "${1:-}" == --env-file && $# -ge 2 ]]; then
  BACKEND_ENV="$2"
  shift 2
fi
[[ $# -eq 0 && "$BACKEND_ENV" == /* ]] || { echo 'ERROR: --env-file /absolute/path is required' >&2; exit 2; }
[[ -f "$BACKEND_ENV" ]] || { echo "ERROR: backend environment is missing: $BACKEND_ENV" >&2; exit 2; }
export PEANUT_SERVER_ENV_FILE="$BACKEND_ENV"

run_php_test() {
  # Class-based suites must be executed by PHPUnit, not only required as PHP declarations.
  php "$ROOT/scripts/run-php-test" "--env-file=$BACKEND_ENV" "$1"
}

if [[ "$mode" != '--fast' && "$mode" != '--full' && "$mode" != '--mysql' ]]; then
  echo 'ERROR: ci-server-check.sh requires --fast, --full, or --mysql' >&2
  exit 2
fi

php scripts/check-admin-api-permissions.php
php scripts/check-test-integrity

if [[ "$mode" == '--mysql' ]]; then
  mysql_resource_mode="$(printenv PEANUT_MYSQL_RESOURCE_MODE 2>/dev/null || true)"
  if [[ -z "$mysql_resource_mode" ]]; then
    mysql_resource_mode='--registered'
  fi
  [[ "$mysql_resource_mode" == '--registered' || "$mysql_resource_mode" == '--ci-service' ]] \
    || { echo 'ERROR: PEANUT_MYSQL_RESOURCE_MODE is invalid' >&2; exit 2; }
  "$ROOT/scripts/tests/run-registered-mysql-tests" "$mysql_resource_mode" --env-file "$BACKEND_ENV"
  exit 0
fi

lint_php() {
  local path
  for path in "$@"; do
    php -l "$path"
  done
}

if [[ "$mode" == '--full' ]]; then
  php_files=()
  while IFS= read -r -d '' path; do
    php_files+=("$path")
  done < <(find server/app server/config server/database server/route -type f -name '*.php' -print0)
  lint_php "${php_files[@]}"

  tests=(
    server/tests/Productization/FreshSchemaBaselineTest.php
    server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php
    server/tests/Productization/OAuthChannelHostTest.php
    server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
    server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
    server/tests/Productization/MemberFinanceHostTest.php
    server/tests/Productization/PluginArtifactContractTest.php
    server/tests/Productization/PluginModuleContractTest.php
    server/tests/Productization/OfficialArticleModuleContractTest.php
    server/tests/Productization/PluginLifecycleMigrationContractTest.php
  )
  for test_file in "${tests[@]}"; do
    run_php_test "$test_file"
  done
  php server/tests/Ablation/DataIsolationAblationTest.php
  php server/tests/Ablation/LazyDiPerformanceAblationTest.php
  php server/tests/Ablation/ErgonomicsAblationTest.php
  exit 0
fi

base="${CI_BASE_REF:-}"
if [[ -z "$base" ]]; then
  echo 'ERROR: CI_BASE_REF is required for --fast' >&2
  exit 2
fi

# A dev-to-main promotion is an integration pointer movement, not a feature
# slice. Its behavior groups have already passed on their individual dev PRs
# and in the fixed-candidate qualification; repeating every historical matcher
# here both violates gate ownership and can select obsolete baseline tests.
promotion=0
if [[ "${CI_BASE_BRANCH:-}" == main && "${CI_HEAD_BRANCH:-}" == dev ]]; then
  promotion=1
fi

changed_file="$(mktemp "${TMPDIR:-/tmp}/peanut-admin-changed-server.XXXXXX")"
changed_php_file="$(mktemp "${TMPDIR:-/tmp}/peanut-admin-changed-php.XXXXXX")"
selected_file="$(mktemp "${TMPDIR:-/tmp}/peanut-admin-focused-tests.XXXXXX")"
trap 'rm -f -- "$changed_file" "$changed_php_file" "$selected_file"' EXIT
git diff --name-only "$base...HEAD" -- server plugins plugins.lock resources/project-resources.json scripts/check-admin-api-permissions.php scripts/check-test-integrity scripts/run-php-test scripts/ci-server-check.sh scripts/tests/run-registered-mysql-tests scripts/consumer-module-reference-chain scripts/project-resource-registry scripts/project-resource-lease > "$changed_file"

select_test() {
  local path="$1"
  if [[ -f "$path" ]]; then
    printf '%s\n' "$path" >> "$selected_file"
  fi
}

is_registered_mysql_suite() {
  case "$1" in
    server/tests/Modules/Official/Integration/IntegrationSecurityMysqlTest.php|\
    server/tests/Modules/Official/Notification/mysql-harness.php|\
    server/tests/Multitenancy/MemberSessionTenantIsolationTest.php)
      return 0
      ;;
  esac
  return 1
}

integrity_checker_changed=0

while IFS= read -r path; do
  [[ -z "$path" ]] && continue
  # Deleted PHP files are valid convergence changes, but cannot be linted.
  if [[ "$path" == *.php && -f "$path" ]]; then
    printf '%s\n' "$path" >> "$changed_php_file"
  fi
  if [[ "$path" == server/tests/*.php || "$path" == server/tests/*/*.php ]]; then
    if is_registered_mysql_suite "$path"; then
      # Real MySQL suites are owned by the explicit --mysql Gate. A changed
      # test file must not make the ordinary fast Unit gate connect to MySQL.
      :
    else
      select_test "$path"
    fi
  fi

  if [[ "$path" == server/tests/Support/RegisteredMysqlTestResource.php \
    || "$path" == scripts/tests/run-registered-mysql-tests \
    || "$path" == resources/project-resources.json \
    || "$path" == scripts/project-resource-registry \
    || "$path" == scripts/project-resource-lease ]]; then
    select_test server/tests/Unit/RegisteredMysqlTestResourceTest.php
    select_test server/tests/Unit/RegisteredMysqlSchemaBoundaryTest.php
    select_test server/tests/Unit/RegisteredMysqlRunnerEnvironmentTest.php
  fi

  if [[ "$path" == server/app/adminapi/services/generator/* || "$path" == server/app/adminapi/service/generator/* ]]; then
    select_test server/tests/Productization/ThinkPhpArchitectureBehaviorMatrixTest.php
    select_test server/tests/Unit/GeneratorDeclaredCrudTemplateTest.php
    select_test server/tests/Unit/GeneratorRuntimeAssemblyTest.php
    select_test server/tests/Unit/GeneratorSoftDeleteContractTest.php
  fi

  if [[ "$path" == server/app/BaseController.php || "$path" == server/app/common/validate/* || "$path" == server/app/common/traits/CrudTrait.php ]]; then
    select_test server/tests/Unit/ControllerDeclaredDependencyTest.php
    select_test server/tests/Unit/ControllerDependencyResolutionTest.php
    select_test server/tests/Unit/InputValidatorPolicyTest.php
    select_test server/tests/Productization/MemberJwtContractTest.php
  fi

  if [[ "$path" == scripts/consumer-module-reference-chain ]]; then
    select_test server/tests/Productization/CreateApplicationTest.php
    select_test server/tests/Productization/ModuleBundleLifecycleTest.php
    select_test server/tests/Productization/ModuleDeliveryOperationTest.php
  fi

  if [[ "$path" == server/app/command/OpsModuleTask.php ]]; then
    select_test server/tests/Productization/OpsModuleTaskWiringTest.php
  fi

  if [[ "$path" == scripts/run-php-test || "$path" == server/tests/Support/CiBootstrap.php || "$path" == scripts/ci-server-check.sh ]]; then
    select_test server/tests/Unit/PhpTestRunnerTest.php
  fi

  case "$path" in
    scripts/check-test-integrity)
      integrity_checker_changed=1
      ;;
    server/app/platform/*/plugin/*|server/app/command/Plugin*.php|server/app/modules/fixture/delivery_record/*|server/app/modules/official/*|plugins/*|plugins.lock|server/config/modules.php|server/resources/schemas/plugin.schema.json)
      select_test server/tests/Productization/PluginArtifactContractTest.php
      select_test server/tests/Productization/PluginModuleContractTest.php
      select_test server/tests/Productization/PluginLifecycleMigrationContractTest.php
      select_test server/tests/Productization/OfficialArticleModuleContractTest.php
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      ;;
    server/app/platform/controller/PlatformTenantController.php|server/app/platform/services/PlatformTenantQueryService.php|server/tests/Multitenancy/PlatformTenantReadApiTest.php)
      select_test server/tests/Multitenancy/PlatformTenantReadApiTest.php
      ;;
    server/app/platform/service/PlatformRuntimeFactory.php)
      select_test server/tests/Multitenancy/PlatformTenantModuleHttpWiringTest.php
      select_test server/tests/Multitenancy/PlatformTenantReadApiTest.php
      select_test server/tests/Multitenancy/PlatformOperatorBoundaryTest.php
      ;;
    server/app/common/service/external/*|server/app/api/controller/PaymentNotifyController.php|server/app/api/controller/OfficialAccountController.php|server/app/api/controller/OAuthController.php|server/app/api/services/OAuthApplicationService.php|server/app/api/services/OfficialAccountApplicationService.php|server/app/api/services/PaymentCallbackApplicationService.php)
      select_test server/tests/Multitenancy/ExternalCallbackTenantRoutingTest.php
      ;;
    *member*|*Member*|*account_log*|*AccountLog*)
      select_test server/tests/Productization/MemberFinanceHostTest.php
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      ;;
    *dict*|*Dict*|*article*|*decoration*|*Decoration*|*notice*|*notification*|*crontab*|*hot_search*|*HotSearch*|*operation_log*|*/audit/*|*file*|*File*|*cache*|*Cache*|*lock*|*Lock*)
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      ;;
    *tenant*|*Tenant*|server/app/platform/*)
      select_test server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      select_test server/tests/Multitenancy/TenantGovernanceTest.php
      select_test server/tests/Multitenancy/PlatformOperatorBoundaryTest.php
      ;;
    server/app/adminapi/*auth*|server/app/common/*auth*|server/app/common/*permission*)
      select_test server/tests/Productization/AdminPermissionHostTest.php
      select_test server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
      ;;
    server/config/admin_api_access.php|server/route/*.php|scripts/check-admin-api-permissions.php)
      select_test server/tests/Productization/AdminPermissionHostTest.php
      select_test server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
      ;;
    server/database/install.php|server/database/environment-guard.php|server/database/init.sql)
      select_test server/tests/Productization/FreshSchemaBaselineTest.php
      select_test server/tests/Multitenancy/NativeAdminIdentityRuntimeContractTest.php
      select_test server/tests/Multitenancy/OfficialCapabilityTenantQualificationTest.php
      ;;
    server/database/migrations/*.sql)
      select_test server/tests/Productization/FreshSchemaBaselineTest.php
      ;;
  esac

  if [[ "$path" == server/app/modules/official/oauth/* \
    || "$path" == server/app/api/application/OAuthApplicationService.php \
    || "$path" == server/app/api/application/RechargeApplicationService.php \
    || "$path" == server/app/common/service/oauth/* ]]; then
    select_test server/tests/Productization/OAuthChannelHostTest.php
  fi
done < "$changed_file"

if [[ "$integrity_checker_changed" == 1 ]]; then
  echo 'Focused server gates: check-test-integrity changed; always-on integrity gate executed'
fi

while IFS= read -r path; do
  [[ -n "$path" ]] && php -l "$path"
done < "$changed_php_file"

if [[ "$promotion" == 1 ]]; then
  echo 'Focused server gates: dev-to-main promotion; PHP lint only'
  exit 0
fi

if [[ ! -s "$selected_file" ]]; then
  echo 'Focused server gates: no behavior test mapped; syntax and manifest checks only'
  exit 0
fi

test_count=0
while IFS= read -r test_file; do
  [[ -z "$test_file" ]] && continue
  if [[ "$test_file" == 'server/tests/Multitenancy/TenantGovernanceTest.php' ]]; then
    php "$test_file"
  else
    run_php_test "$test_file"
  fi
  test_count=$((test_count + 1))
done < <(sort -u "$selected_file")

echo "Focused server gates: ${test_count} test file(s)"
