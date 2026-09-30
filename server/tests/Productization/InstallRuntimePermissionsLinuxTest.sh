#!/bin/sh
# Root-only synthetic Linux UID check. Argument must be an explicit task temp root.
set -eu
[ "$(id -u)" -eq 0 ] || { echo 'run as root in the fixed Linux PHP image' >&2; exit 2; }
[ "$#" -eq 1 ] && [ -d "$1" ] && [ ! -L "$1" ] || exit 2
command -v setpriv >/dev/null 2>&1 || { echo 'setpriv is required' >&2; exit 2; }
task_root=$(cd "$1" && pwd -P)
case "$task_root" in /tmp/*|/var/tmp/*) ;; *) echo 'task root must be under /tmp or /var/tmp' >&2; exit 2 ;; esac
source_root=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd -P)
helper="$source_root/server/docker/scripts/prepare-install-permissions.sh"
fixture=$(mktemp -d "$task_root/phase1-c-XXXXXX")
# Only the synthetic parent is traversable; private files retain their modes.
chmod 0755 "$fixture"
trap 'rm -rf -- "$fixture"' EXIT HUP INT TERM
app_uid=$(id -u www-data)
app_gid=$(id -g www-data)
mkdir -p "$fixture/server/docker/secrets" "$fixture/server/private/installation" \
    "$fixture/server/private/resources" "$fixture/server/private/storage" \
    "$fixture/server/runtime/upgrade" "$fixture/server/public/storage"
chmod 0700 "$fixture/server/docker/secrets"
printf 'synthetic-token\n' > "$fixture/server/docker/secrets/install-token"
chmod 0600 "$fixture/server/docker/secrets/"*
printf 'MYSQL_ROOT_PASSWORD=synthetic-root-secret\n' > "$fixture/server/docker/.env"
chmod 0600 "$fixture/server/docker/.env"
printf 'managed-code\n' > "$fixture/server/index.php"
chmod 0644 "$fixture/server/index.php"
chmod 0755 "$fixture/server"
before=$(stat -c '%u:%g:%a' "$fixture/server")
PEANUT_PERMISSION_TEST=1 PEANUT_SERVER_ROOT="$fixture/server" sh "$helper"
[ "$(stat -c %a "$fixture/server")" = 1775 ]
[ "$(stat -c %u "$fixture/server/docker/secrets/install-root-permissions")" = 0 ]

PEANUT_TEST_ROOT="$fixture/server" setpriv --reuid="$app_uid" --regid="$app_gid" --clear-groups \
    php "$source_root/server/tests/Productization/InstallRuntimeConfigurationUidTest.php"
PEANUT_TEST_ROOT="$fixture/server" setpriv --reuid="$app_uid" --regid="$app_gid" --clear-groups sh -ec '
  cd "$PEANUT_TEST_ROOT"
  (umask 077; printf "ok\n" > private/storage/new-file)
  ! cat docker/.env >/dev/null 2>&1
  ! cat docker/secrets/install-token >/dev/null 2>&1
  ! printf tampered > index.php 2>/dev/null
  ! rm index.php 2>/dev/null
'
PEANUT_PERMISSION_TEST=1 PEANUT_SERVER_ROOT="$fixture/server" sh "$helper"
[ "$(stat -c '%u:%g:%a' "$fixture/server")" = "$before" ]
[ ! -e "$fixture/server/docker/secrets/install-root-permissions" ]
[ "$(stat -c %a "$fixture/server/.env")" = 600 ]
env_sha=$(sha256sum "$fixture/server/.env" | cut -d ' ' -f 1)
PEANUT_TEST_ROOT="$fixture/server" setpriv --reuid="$app_uid" --regid="$app_gid" --clear-groups sh -ec '
  (umask 077; printf "synthetic-installed\n" > "$PEANUT_TEST_ROOT/private/installation/installed.json")
'
PEANUT_PERMISSION_TEST=1 PEANUT_SERVER_ROOT="$fixture/server" sh "$helper"
[ "$(stat -c '%u:%g:%a' "$fixture/server")" = "$before" ]
[ "$(sha256sum "$fixture/server/.env" | cut -d ' ' -f 1)" = "$env_sha" ]

# A damaged file or linked target cannot reopen or alter the instance.
rm "$fixture/server/private/resources/configuration.json"
if PEANUT_PERMISSION_TEST=1 PEANUT_SERVER_ROOT="$fixture/server" sh "$helper" >/dev/null 2>&1; then
    echo 'partial configuration was accepted' >&2; exit 1
fi
[ "$(stat -c '%u:%g:%a' "$fixture/server")" = "$before" ]
ln -s "$fixture/server/index.php" "$fixture/server/private/resources/configuration.json"
if PEANUT_PERMISSION_TEST=1 PEANUT_SERVER_ROOT="$fixture/server" sh "$helper" >/dev/null 2>&1; then
    echo 'linked configuration was accepted' >&2; exit 1
fi
printf 'INSTALL-RUNTIME-PERMISSIONS-LINUX passed (uid=%s gid=%s)\n' "$app_uid" "$app_gid"
