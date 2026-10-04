#!/bin/sh

set -eu

repo_dir=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
remote=mac-14
remote_dir=/Users/xing/.config/peanut-admin
remote_env="$remote_dir/development-db.env"
remote_compose="$remote_dir/docker-compose.remote-development.yml"
local_env="$repo_dir/server/.env"
local_state=$(dirname "$local_env")
allow_empty_initialize=0
repair_backup_dir=
repair_lease=
repair_owner=

command=${1:-}
[ $# -gt 0 ] && shift
while [ $# -gt 0 ]; do
    case "$1" in
        --backend-env) [ $# -ge 2 ] || { printf '%s\n' '--backend-env requires a value' >&2; exit 2; }; local_env=$2; local_state=$(dirname "$local_env"); shift 2 ;;
        --initialize-empty) allow_empty_initialize=1; shift ;;
        --backup-dir) [ $# -ge 2 ] || { printf '%s\n' '--backup-dir requires a value' >&2; exit 2; }; repair_backup_dir=$2; shift 2 ;;
        --lease) [ $# -ge 2 ] || { printf '%s\n' '--lease requires a value' >&2; exit 2; }; repair_lease=$2; shift 2 ;;
        --owner) [ $# -ge 2 ] || { printf '%s\n' '--owner requires a value' >&2; exit 2; }; repair_owner=$2; shift 2 ;;
        *) printf 'unsupported argument: %s\n' "$1" >&2; exit 2 ;;
    esac
done

normalize_remote_credentials() {
    ssh -o BatchMode=yes "$remote" /bin/sh -s -- "$remote_env" <<'REMOTE'
set -eu
file=$1
[ -f "$file" ] && [ ! -L "$file" ] || { printf '%s\n' 'registered database credential file is missing or unsafe' >&2; exit 1; }
mode=$(/usr/bin/stat -f %Lp "$file")
owner=$(/usr/bin/stat -f %u "$file")
links=$(/usr/bin/stat -f %l "$file")
[ "$mode" = 600 ] && [ "$owner" = "$(/usr/bin/id -u)" ] && [ "$links" = 1 ] || {
    printf '%s\n' 'registered database credential file permissions or ownership are unsafe' >&2
    exit 1
}
canonical=$(awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }' "$file")
legacy=$(awk -F= '$1 == "DB_ROOT_PASS" { sub(/^[^=]*=/, ""); print; exit }' "$file")
if [ -n "$canonical" ] && [ -n "$legacy" ] && [ "$canonical" != "$legacy" ]; then
    printf '%s\n' 'registered database root credential aliases conflict' >&2
    exit 1
fi
root_password=$canonical
[ -n "$root_password" ] || root_password=$legacy
[ -n "$root_password" ] || { printf '%s\n' 'registered database root credential is missing' >&2; exit 1; }
if [ -n "$legacy" ]; then
    backup="${file}.before-mysql-root-password-migration"
    if [ ! -e "$backup" ]; then
        cp -p "$file" "$backup"
        chmod 600 "$backup"
    fi
fi
temporary="${file}.normalize.$$"
trap 'rm -f "$temporary"' EXIT HUP INT TERM
awk -F= '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD" { print }' "$file" > "$temporary"
printf 'MYSQL_ROOT_PASSWORD=%s\n' "$root_password" >> "$temporary"
chmod 600 "$temporary"
mv "$temporary" "$file"
trap - EXIT HUP INT TERM
REMOTE
}

case "$command" in
    provision)
        ssh "$remote" "umask 077; mkdir -p '$remote_dir'; if [ ! -f '$remote_env' ]; then { printf '%s\n' 'DB_NAME=peanut_admin_development' 'DB_USER=peanut_admin_development'; printf 'DB_PASS=%s\n' \"\$(openssl rand -hex 24)\"; printf 'MYSQL_ROOT_PASSWORD=%s\n' \"\$(openssl rand -hex 24)\"; } > '$remote_env'; chmod 600 '$remote_env'; fi"
        normalize_remote_credentials
        ssh "$remote" "for name in DB_NAME DB_USER DB_PASS MYSQL_ROOT_PASSWORD; do grep -q \"^\${name}=.\" '$remote_env' || { printf 'registered database credential is missing: %s\\n' \"\$name\" >&2; exit 1; }; done"
        if ! ssh -o BatchMode=yes "$remote" "/usr/local/bin/docker volume inspect peanut-admin-mysql84-development-data >/dev/null 2>&1"; then
            [ "$allow_empty_initialize" -eq 1 ] || {
                printf '%s\n' 'registered persistent database volume is missing; refusing to create an empty replacement without --initialize-empty' >&2
                exit 1
            }
        fi
        scp -q "$repo_dir/deploy/docker-compose.remote-development.yml" "$remote:$remote_compose"
        ssh "$remote" "/usr/local/bin/docker compose --env-file '$remote_env' -f '$remote_compose' up -d --wait"
        "$0" sync-credentials --backend-env "$local_env"
        ;;
    repair-root-credential)
        normalize_remote_credentials
        [ -n "$repair_backup_dir" ] || { printf '%s\n' 'repair-root-credential requires --backup-dir' >&2; exit 2; }
        [ -n "$repair_lease" ] || { printf '%s\n' 'repair-root-credential requires --lease' >&2; exit 2; }
        [ -n "$repair_owner" ] || { printf '%s\n' 'repair-root-credential requires --owner' >&2; exit 2; }
        lease_info="$("$repo_dir/scripts/project-resource-lease" show --lease "$repair_lease")" || {
            printf '%s\n' 'development database recovery lease is unavailable' >&2
            exit 1
        }
        printf '%s\n' "$lease_info" | /usr/bin/grep -Fqx "status	ACTIVE" || {
            printf '%s\n' 'development database recovery lease is not active' >&2
            exit 1
        }
        printf '%s\n' "$lease_info" | /usr/bin/grep -Fqx "owner	$repair_owner" || {
            printf '%s\n' 'development database recovery lease owner mismatch' >&2
            exit 1
        }
        printf '%s\n' "$lease_info" | /usr/bin/grep -Fqx "gate	development-db-recovery" || {
            printf '%s\n' 'development database recovery lease gate mismatch' >&2
            exit 1
        }
        for resource in             "mysql-resource	peanut-admin-mysql84-development"             "docker-volume	peanut-admin-mysql84-development-data"             "docker-container	peanut-admin-mysql84-development"             "port	20183"             "backup-dir	$repair_backup_dir"; do
            printf '%s\n' "$lease_info" | /usr/bin/grep -Fqx "$resource" || {
                printf '%s\n' "development database recovery lease resource mismatch: $resource" >&2
                exit 1
            }
        done
        unset lease_info
        case "$repair_backup_dir" in
            "$remote_dir"/backups/*) ;;
            *) printf '%s\n' 'repair backup directory must stay under the registered remote backup root' >&2; exit 2 ;;
        esac
        ssh -o BatchMode=yes -o StrictHostKeyChecking=yes -o ConnectTimeout=10 "$remote" \
            /bin/sh -s -- "$remote_env" "$repair_backup_dir" <<'REMOTE'
set -eu
credential=$1
backup_dir=$2
docker=/usr/local/bin/docker
container=peanut-admin-mysql84-development
expected_image='mysql:8.4.10@sha256:8dbcf531a03aade657e181b9cf2f1d1803ce621a1d55610cb44cb531ab7d7db6'

test -f "$credential"
test ! -L "$credential"
test "$(/usr/bin/stat -f %u "$credential")" = "$(/usr/bin/id -u)"
test "$(/usr/bin/stat -f %Lp "$credential")" = 600
test "$(/usr/bin/stat -f %l "$credential")" = 1
for name in DB_NAME DB_USER DB_PASS; do
    /usr/bin/grep -q "^$name=." "$credential" || {
        printf 'registered database credential is missing: %s\n' "$name" >&2
        exit 1
    }
done

identity="$("$docker" inspect "$container" --format '{{.Config.Image}}|{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')"
[ "$identity" = "$expected_image|running|healthy" ] || {
    printf '%s\n' 'registered development MySQL allocation is not the expected healthy container' >&2
    exit 1
}
verified="$("$docker" exec "$container" /bin/sh -ceu '
    test -n "$MYSQL_ROOT_PASSWORD"
    export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"
    unset MYSQL_ROOT_PASSWORD
    exec /usr/bin/mysql --protocol=SOCKET --user=root --batch --raw --skip-column-names -e "SELECT 1"
')"
[ "$verified" = 1 ] || {
    printf '%s\n' 'registered container root credential failed verification' >&2
    exit 1
}

env_dump="$("$docker" inspect "$container" --format '{{range .Config.Env}}{{println .}}{{end}}')"
count="$(printf '%s\n' "$env_dump" | /usr/bin/awk -F= '$1 == "MYSQL_ROOT_PASSWORD" && length(substr($0, index($0, "=") + 1)) > 0 { count++ } END { print count + 0 }')"
[ "$count" -eq 1 ] || {
    printf '%s\n' 'registered container root credential is missing or ambiguous' >&2
    exit 1
}
root="$(printf '%s\n' "$env_dump" | /usr/bin/awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }')"
test -n "$root"
current="$(/usr/bin/awk -F= '$1 == "MYSQL_ROOT_PASSWORD" { sub(/^[^=]*=/, ""); print; exit }' "$credential")"
if [ "$current" = "$root" ]; then
    unset root current env_dump
    printf '%s\n' 'development-db-root-credential=already-current'
    exit 0
fi

umask 077
/bin/mkdir -p "$backup_dir"
/bin/chmod 700 "$backup_dir"
test -d "$backup_dir"
test ! -L "$backup_dir"
test "$(/usr/bin/stat -f %u "$backup_dir")" = "$(/usr/bin/id -u)"
backup="$backup_dir/development-db.env.before-root-credential-repair"
if [ ! -e "$backup" ]; then
    /bin/cp -p "$credential" "$backup"
    /bin/chmod 600 "$backup"
fi
test -f "$backup"
test ! -L "$backup"
test "$(/usr/bin/stat -f %Lp "$backup")" = 600

tmp="$(/usr/bin/mktemp "${credential}.repair.XXXXXX")"
trap 'rm -f -- "$tmp"' EXIT HUP INT TERM
/usr/bin/awk -F= '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD" { print }' "$credential" > "$tmp"
printf 'MYSQL_ROOT_PASSWORD=%s\n' "$root" >> "$tmp"
/bin/chmod 600 "$tmp"
/bin/mv -f "$tmp" "$credential"
trap - EXIT HUP INT TERM
unset root current env_dump

test -f "$credential"
test ! -L "$credential"
test "$(/usr/bin/stat -f %Lp "$credential")" = 600
test "$(/usr/bin/stat -f %l "$credential")" = 1
/usr/bin/grep -q '^MYSQL_ROOT_PASSWORD=.' "$credential"
printf '%s\n' 'development-db-root-credential=repaired'
REMOTE
        ;;
    sync-credentials)
        normalize_remote_credentials
        mkdir -p "$local_state"
        umask 077
        [ -f "$local_env" ] || : > "$local_env"
        temporary=$(mktemp "$local_state/company-db.XXXXXX")
        ssh "$remote" "awk -F= '\$1 == \"DB_NAME\" || \$1 == \"DB_USER\" || \$1 == \"DB_PASS\" { print }' '$remote_env'" > "$temporary"
        for name in DB_NAME DB_USER DB_PASS; do
            value=$(awk -F= -v name="$name" '$1 == name { sub(/^[^=]*=/, ""); print; exit }' "$temporary")
            [ -n "$value" ] || { rm -f "$temporary"; printf 'registered database credential is missing: %s\n' "$name" >&2; exit 1; }
            merged=$(mktemp "$local_state/stack.env.XXXXXX")
            awk -F= -v name="$name" '$1 != name { print }' "$local_env" > "$merged"
            printf '%s=%s\n' "$name" "$value" >> "$merged"
            chmod 600 "$merged"
            mv "$merged" "$local_env"
        done
        merged=$(mktemp "$local_state/stack.env.XXXXXX")
        awk -F= '$1 != "DB_ROOT_PASS" && $1 != "MYSQL_ROOT_PASSWORD" { print }' "$local_env" > "$merged"
        chmod 600 "$merged"
        mv "$merged" "$local_env"
        rm -f "$temporary"
        printf 'Company development database credentials synchronized to %s\n' "$local_env"
        ;;
    status)
        ssh -o BatchMode=yes "$remote" "/usr/local/bin/docker inspect peanut-admin-mysql84-development --format 'image={{.Config.Image}} status={{.State.Status}} health={{.State.Health.Status}}'"
        ;;
    *)
        printf 'Usage: %s {provision|repair-root-credential|sync-credentials|status} [--backend-env path] [--initialize-empty] [--backup-dir path] [--lease id] [--owner owner]\n' "$0" >&2
        exit 2
        ;;
esac
