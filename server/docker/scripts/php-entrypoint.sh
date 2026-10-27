#!/bin/sh
set -eu
SERVER_ROOT=/run/peanut-owner/server
HTTP_CHROOT=/var/www/peanut-http
CONTEXT="$HTTP_CHROOT/run/peanut-http/context.json"
[ "$(id -u)" = 0 ] || { echo "native owner must be root" >&2; exit 1; }
[ ! -L "$HTTP_CHROOT" ] && [ ! -L "$HTTP_CHROOT/run" ] || exit 1
rm -f "$CONTEXT"
TOKEN_FILE="$SERVER_ROOT/docker/secrets/install-token"
DOCKER_ENV="$SERVER_ROOT/docker/.env"
BOOTSTRAP_ENV="$SERVER_ROOT/.env.bootstrap"
INSTALLING_ENV="$SERVER_ROOT/.env.installing"
FINAL_ENV="$SERVER_ROOT/.env"
INSTALLED="$SERVER_ROOT/private/installation/installed.json"

cd "$SERVER_ROOT"
[ ! -L private ] && [ ! -L public ] && [ ! -L docker ] && [ ! -L docker/secrets ] || {
    echo "server parent path is a symlink" >&2; exit 1;
}
for path in runtime runtime/upgrade public/storage private/storage private/installation private/resources private/resources/pending runtime/cache runtime/log runtime/session runtime/temp runtime/storage runtime/generator runtime/file; do
    [ ! -L "$path" ] || { echo "runtime path is a symlink: $path" >&2; exit 1; }
    mkdir -p "$path"
done
close_startup_traffic() {
    [ ! -L runtime/upgrade ] || { echo 'startup guard directory is a symlink' >&2; return 1; }
    rm -f runtime/upgrade/.traffic-ready
}
cleanup() {
    cleanup_status=$?
    trap - EXIT HUP TERM INT
    rm -f "$CONTEXT"
    close_startup_traffic >/dev/null 2>&1 || true
    if [ -n "${fpm_pid:-}" ]; then
        kill -TERM "$fpm_pid" 2>/dev/null || true
        wait "$fpm_pid" 2>/dev/null || true
    fi
    exit "$cleanup_status"
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 143' TERM
trap 'exit 130' INT
close_startup_traffic
for marker in runtime/upgrade/maintenance.json runtime/upgrade/current-update.json; do
    if [ -e "$marker" ] || [ -L "$marker" ]; then
        echo "OWNER_MAINTENANCE_REQUIRED: unresolved previous upgrade state: $marker" >&2
        exit 1
    fi
done
if [ -e private/installation/update-verification.key ] || [ -L private/installation/update-verification.key ]; then
    echo 'OWNER_MAINTENANCE_REQUIRED: complete the previous fixed-tool transaction and retire its legacy verification key before adopting readonly runtime' >&2
    exit 1
fi
sh docker/scripts/prepare-install-permissions.sh --readonly-http
[ ! -L runtime/upgrade/.mount-ready ] || { echo "upgrade guard sentinel is a symlink" >&2; exit 1; }
if [ ! -e runtime/upgrade/.mount-ready ]; then
    printf '%s\n' 'peanut.server-update-guard.v1' > runtime/upgrade/.mount-ready
fi
[ -f runtime/upgrade/.mount-ready ] || { echo "upgrade guard sentinel is unavailable" >&2; exit 1; }
chmod 1775 runtime
chmod 0755 runtime/upgrade
chmod 0644 runtime/upgrade/.mount-ready
[ -f vendor/autoload.php ] || { echo "composer dependencies are unavailable" >&2; exit 1; }

secret() { php -r 'echo bin2hex(random_bytes(32));'; }

read_token() {
    [ -f "$TOKEN_FILE" ] && [ ! -L "$TOKEN_FILE" ] || { echo "installation token is unavailable" >&2; exit 1; }
    token=$(tr -d '\r\n' < "$TOKEN_FILE")
    printf '%s' "$token" | grep -Eq '^[a-f0-9]{64}$' || { echo "installation token has an invalid format" >&2; exit 1; }
    printf '%s' "$token"
}

release_field() {
    field="$1"
    php -r '
      require "vendor/autoload.php";
      $serverRoot = getcwd();
      $identityPath = $serverRoot . "/.peanut/release-identity.json";
      if (is_file($identityPath) || is_link($identityPath)) {
          $identity = app\common\value\installation\ServerReleaseIdentity::load($serverRoot);
          $app = $identity->applicationIdentity();
      } else {
          $identity = app\common\value\installation\ApplicationSourceIdentity::load($serverRoot);
          $app = $identity->applicationIdentity();
      }
      $key = $argv[1];
      $value = $app[$key] ?? null;
      if (!is_string($value) || $value === "" || preg_match("/[\\r\\n\\x00]/", $value)) exit(2);
      echo $value;
    ' "$field"
}

write_bootstrap_env() {
    token=$(read_token)
    edition=$(release_field edition)
    version=$(release_field version)
    case "$edition" in standalone|multi-tenant) ;; *) echo "server release edition is invalid" >&2; exit 1 ;; esac
    bootstrap_environment=production
    bootstrap_target=production
    # release_field already strictly verified the runtime declaration above.
    if [ -e "$SERVER_ROOT/.peanut/development-runtime.json" ] || [ -L "$SERVER_ROOT/.peanut/development-runtime.json" ]; then
        bootstrap_environment=development
        bootstrap_target=local-development
    fi
    tmp="$SERVER_ROOT/.env.bootstrap.tmp-$$"
    umask 077
    {
        printf 'APP_ENV=%s\nAPP_DEBUG=false\n' "$bootstrap_environment"
        printf 'DEPLOYMENT_MODE=%s\nPEANUT_DEPLOYMENT_TARGET=%s\n' "$edition" "$bootstrap_target"
        printf 'PEANUT_INSTALLATION_MODE=guided\nPEANUT_INSTALLATION_SETUP_TOKEN=%s\n' "$token"
        printf 'PEANUT_DATABASE_RESOURCE_ID=bootstrap-unconfigured\nPEANUT_DATABASE_ENDPOINT_ID=bootstrap-unconfigured\nPEANUT_DATABASE_CONSUMER=container\n'
        printf 'DB_DRIVER=mysql\nDB_TYPE=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=bootstrap_unconfigured\nDB_USER=bootstrap\nDB_PASS=bootstrap_not_used\nDB_CHARSET=utf8mb4\nDB_PREFIX=pa_\n'
        printf 'JWT_SECRET=%s\nTENANT_IDENTIFIER_HMAC_KEY=%s\nPLATFORM_IDENTIFIER_HMAC_KEY=%s\n' "$(secret)" "$(secret)" "$(secret)"
        printf 'PEANUT_STORAGE_CREDENTIAL_MASTER_KEY=%s\nASYNC_SIGNING_KEY=%s\n' "$(secret)" "$(secret)"
        printf 'PEANUT_DEMO_MODE=disabled\nDEFAULT_LANG=zh-cn\nPROJECT_VERSION=%s\n' "$version"
    } > "$tmp"
    chmod 600 "$tmp"
    chown www-data:www-data "$tmp"
    mv "$tmp" "$BOOTSTRAP_ENV"
}

write_installing_env() {
    token=$(read_token)
    [ -f "$FINAL_ENV" ] && [ ! -L "$FINAL_ENV" ] || { echo "configured server/.env is unavailable" >&2; exit 1; }
    tmp="$SERVER_ROOT/.env.installing.tmp-$$"
    umask 077
    cat "$FINAL_ENV" > "$tmp"
    printf 'PEANUT_INSTALLATION_SETUP_TOKEN=%s\n' "$token" >> "$tmp"
    chmod 600 "$tmp"
    chown www-data:www-data "$tmp"
    mv "$tmp" "$INSTALLING_ENV"
}

run_monitored_fpm() {
    phase="$1"
    rm -f "$CONTEXT"
    php -r 'require "vendor/autoload.php"; app\common\infrastructure\installation\ReadonlyHttpMount::initialize(getcwd());'
    # The prepared image supplies OS files to the chroot; runtime DNS is container-specific.
    for file in hosts resolv.conf hostname; do
        cp "/etc/$file" "$HTTP_CHROOT/etc/$file"
        chown 0:0 "$HTTP_CHROOT/etc/$file"
        chmod 0644 "$HTTP_CHROOT/etc/$file"
    done
    case "$phase" in bootstrap) http_env=bootstrap ;; installing) http_env=installing ;; installed) http_env= ;; *) exit 1 ;; esac
    printf '[www]\nenv[PHP_ENV_NAME] = "%s"\n' "$http_env" > /usr/local/etc/php-fpm.d/zzz-peanut-runtime.conf
    chmod 0600 /usr/local/etc/php-fpm.d/zzz-peanut-runtime.conf
    php-fpm -F &
    fpm_pid=$!
    if ! php -r 'require "vendor/autoload.php"; app\common\infrastructure\installation\ReadonlyHttpMount::assertWorkers((int)$argv[1]);' "$fpm_pid"; then
        rm -f "$CONTEXT"
        close_startup_traffic
        kill -TERM "$fpm_pid" 2>/dev/null || true
        wait "$fpm_pid" 2>/dev/null || true
        exit 1
    fi
    php -r 'require "vendor/autoload.php"; app\common\infrastructure\installation\ReadonlyHttpMount::publish(getcwd());'
    while kill -0 "$fpm_pid" 2>/dev/null; do
        if [ "$phase" = bootstrap ] && { [ -e private/resources/pending/configuration.json ] || [ -L private/resources/pending/configuration.json ]; }; then
            close_startup_traffic
            rm -f "$CONTEXT"
            sleep 2
            kill -QUIT "$fpm_pid" 2>/dev/null || true
            wait "$fpm_pid" || true
            fpm_pid=
            php -r '
              require "vendor/autoload.php";
              $path = "docker/secrets/install-token";
              if (!is_file($path) || is_link($path) || (fileperms($path) & 0777) !== 0600) {
                  throw new RuntimeException("INSTALL_CONFIGURATION_SETUP_TOKEN_INVALID");
              }
              $app = new think\App(getcwd());
              (new app\common\services\installation\InstallationConfigurationHost(getcwd()))->publishPending(trim(file_get_contents($path)));
            '
            rm -f "$BOOTSTRAP_ENV"
            exec "$0"
        fi
        if [ "$phase" = installing ] && [ -f "$INSTALLED" ]; then
            close_startup_traffic
            rm -f "$CONTEXT"
            sleep 2
            kill -QUIT "$fpm_pid" 2>/dev/null || true
            wait "$fpm_pid" || true
            fpm_pid=
            rm -f "$INSTALLING_ENV" "$TOKEN_FILE"
            exec "$0"
        fi
        sleep 1
    done
    result=0
    wait "$fpm_pid" || result=$?
    fpm_pid=
    rm -f "$CONTEXT"
    close_startup_traffic
    return "$result"
}

if [ -f "$INSTALLED" ] && [ ! -L "$INSTALLED" ]; then
    rm -f "$BOOTSTRAP_ENV" "$INSTALLING_ENV" "$TOKEN_FILE"
    [ -f "$FINAL_ENV" ] && [ ! -L "$FINAL_ENV" ] || { echo "installed instance is missing server/.env" >&2; exit 1; }
    export PHP_ENV_NAME=''
    run_monitored_fpm installed
    exit $?
fi

if [ ! -e "$FINAL_ENV" ]; then
    write_bootstrap_env
    export PHP_ENV_NAME=bootstrap
    run_monitored_fpm bootstrap
    exit $?
fi

[ -f "$FINAL_ENV" ] && [ ! -L "$FINAL_ENV" ] || { echo "server/.env is unsafe" >&2; exit 1; }
installation_mode=$(sed -n 's/^PEANUT_INSTALLATION_MODE=//p' "$FINAL_ENV" | tail -n 1)
case "$installation_mode" in
  guided)
    database_source=$(sed -n 's/^PEANUT_DATABASE_SOURCE=//p' "$FINAL_ENV" | tail -n 1)
    case "$database_source" in
      bundled)
        if [ ! -f private/resources/database-provisioned.json ]; then
            php docker/scripts/provision-database.php --server-root="$SERVER_ROOT" --docker-env="$DOCKER_ENV"
        fi ;;
      registered) : ;; # The existing account/database is checked by the real application PDO.
      *) echo "database source must be bundled or registered" >&2; exit 1 ;;
    esac
    write_installing_env
    export PHP_ENV_NAME=installing
    php database/environment-guard.php --wait=60
    run_monitored_fpm installing
    ;;
  automatic)
    export PHP_ENV_NAME=''
    php database/environment-guard.php --wait=60
    php database/install.php --skip-if-installed
    php database/environment-guard.php --current
    run_monitored_fpm installed
    ;;
  *) echo "PEANUT_INSTALLATION_MODE must be guided or automatic" >&2; exit 1 ;;
esac
