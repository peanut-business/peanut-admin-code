#!/bin/sh
set -eu
SERVER_ROOT=/var/www/peanut-admin/server
TOKEN_FILE="$SERVER_ROOT/docker/secrets/install-token"
ROOT_SECRET="$SERVER_ROOT/docker/secrets/mysql-root-password"
BOOTSTRAP_ENV="$SERVER_ROOT/.env.bootstrap"
INSTALLING_ENV="$SERVER_ROOT/.env.installing"
FINAL_ENV="$SERVER_ROOT/.env"
INSTALLED="$SERVER_ROOT/private/installation/installed.json"

cd "$SERVER_ROOT"
for path in runtime public/storage private/storage private/installation private/resources; do
    [ ! -L "$path" ] || { echo "runtime path is a symlink: $path" >&2; exit 1; }
    mkdir -p "$path"
done
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
      $identity = app\common\value\installation\ServerReleaseIdentity::load(getcwd());
      $app = $identity->applicationIdentity();
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
    tmp="$SERVER_ROOT/.env.bootstrap.tmp-$$"
    umask 077
    {
        printf 'APP_ENV=production\nAPP_DEBUG=false\n'
        printf 'DEPLOYMENT_MODE=%s\nPEANUT_DEPLOYMENT_TARGET=production\n' "$edition"
        printf 'PEANUT_INSTALLATION_MODE=guided\nPEANUT_INSTALLATION_SETUP_TOKEN=%s\n' "$token"
        printf 'PEANUT_DATABASE_RESOURCE_ID=bootstrap-unconfigured\nPEANUT_DATABASE_ENDPOINT_ID=bootstrap-unconfigured\nPEANUT_DATABASE_CONSUMER=container\n'
        printf 'DB_DRIVER=mysql\nDB_TYPE=mysql\nDB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME=bootstrap_unconfigured\nDB_USER=bootstrap\nDB_PASS=bootstrap_not_used\nDB_CHARSET=utf8mb4\nDB_PREFIX=pa_\n'
        printf 'JWT_SECRET=%s\nTENANT_IDENTIFIER_HMAC_KEY=%s\nPLATFORM_IDENTIFIER_HMAC_KEY=%s\n' "$(secret)" "$(secret)" "$(secret)"
        printf 'PEANUT_STORAGE_CREDENTIAL_MASTER_KEY=%s\nASYNC_SIGNING_KEY=%s\n' "$(secret)" "$(secret)"
        printf 'PEANUT_DEMO_MODE=disabled\nDEFAULT_LANG=zh-cn\nPROJECT_VERSION=%s\n' "$version"
    } > "$tmp"
    chmod 600 "$tmp"
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
    mv "$tmp" "$INSTALLING_ENV"
}

run_monitored_fpm() {
    phase="$1"
    php-fpm -F &
    fpm_pid=$!
    trap 'kill -TERM "$fpm_pid" 2>/dev/null || true; wait "$fpm_pid" 2>/dev/null || true; exit 143' TERM
    trap 'kill -INT "$fpm_pid" 2>/dev/null || true; wait "$fpm_pid" 2>/dev/null || true; exit 130' INT
    while kill -0 "$fpm_pid" 2>/dev/null; do
        if [ "$phase" = bootstrap ] && [ -f "$FINAL_ENV" ] && [ -f private/resources/project-resources.json ]; then
            sleep 2
            kill -QUIT "$fpm_pid" 2>/dev/null || true
            wait "$fpm_pid" || true
            rm -f "$BOOTSTRAP_ENV"
            exec "$0"
        fi
        if [ "$phase" = installing ] && [ -f "$INSTALLED" ]; then
            sleep 2
            kill -QUIT "$fpm_pid" 2>/dev/null || true
            wait "$fpm_pid" || true
            rm -f "$INSTALLING_ENV" "$TOKEN_FILE"
            exec "$0"
        fi
        sleep 1
    done
    wait "$fpm_pid"
}

if [ -f "$INSTALLED" ] && [ ! -L "$INSTALLED" ]; then
    rm -f "$BOOTSTRAP_ENV" "$INSTALLING_ENV" "$TOKEN_FILE"
    [ -f "$FINAL_ENV" ] && [ ! -L "$FINAL_ENV" ] || { echo "installed instance is missing server/.env" >&2; exit 1; }
    export PEANUT_SERVER_ENV_FILE="$FINAL_ENV"
    exec php-fpm -F
fi

if [ ! -e "$FINAL_ENV" ]; then
    write_bootstrap_env
    export PEANUT_SERVER_ENV_FILE="$BOOTSTRAP_ENV"
    run_monitored_fpm bootstrap
    exit $?
fi

[ -f "$FINAL_ENV" ] && [ ! -L "$FINAL_ENV" ] || { echo "server/.env is unsafe" >&2; exit 1; }
installation_mode=$(sed -n 's/^PEANUT_INSTALLATION_MODE=//p' "$FINAL_ENV" | tail -n 1)
case "$installation_mode" in
  guided)
    if [ ! -f private/resources/database-provisioned.json ]; then
        php docker/scripts/provision-database.php --server-root="$SERVER_ROOT" --root-secret="$ROOT_SECRET"
    fi
    write_installing_env
    export PEANUT_SERVER_ENV_FILE="$INSTALLING_ENV"
    run_monitored_fpm installing
    ;;
  automatic)
    export PEANUT_SERVER_ENV_FILE="$FINAL_ENV"
    php database/environment-guard.php --wait=60
    php database/install.php --skip-if-installed
    php database/environment-guard.php --current
    exec php-fpm -F
    ;;
  *) echo "PEANUT_INSTALLATION_MODE must be guided or automatic" >&2; exit 1 ;;
esac
