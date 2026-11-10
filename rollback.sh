#!/usr/bin/env bash
# Emergency rollback: put traffic back on the previous release.
#
# Deliberately self-contained. It does not source deploy.sh, because the one
# moment you need this is the moment that tree may be the broken one.
#
#   /var/www/fasalbabajimina/current/rollback.sh
#   /var/www/fasalbabajimina/current/rollback.sh --list
#   ROLLBACK_TO=releases/20260101120000_abc1234 /var/www/fasalbabajimina/current/rollback.sh
#
# deploy.sh keeps KEEP_RELEASES (default 5) releases and a `previous` symlink.
# This does not touch the database. If the bad deploy ran a migration, restore
# the snapshot first:
#   gunzip -c shared/db-backups/<release-id>.sql.gz | mysql -u <user> -p <db>
set -eEuo pipefail

APP_DIR="${APP_DIR:-/var/www/fasalbabajimina}"
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"

RELEASES_DIR="${APP_DIR}/releases"
SHARED_DIR="${APP_DIR}/shared"
CURRENT_LINK="${APP_DIR}/current"
PREVIOUS_LINK="${APP_DIR}/previous"

cd "$APP_DIR"

LOCK_FILE="${LOCK_FILE:-${APP_DIR}/.deploy.lock}"
if command -v flock >/dev/null 2>&1; then
  exec 9>"${LOCK_FILE}"
  if ! flock -w "${LOCK_WAIT:-300}" 9; then
    echo "ERROR: a deploy is running (lock ${LOCK_FILE}). Wait for it, or kill it first."
    exit 1
  fi
fi

list_releases() {
  echo "Releases in ${RELEASES_DIR} (newest first):"
  local current previous dir marker
  current="$(readlink -f "${CURRENT_LINK}" 2>/dev/null || true)"
  previous="$(readlink -f "${PREVIOUS_LINK}" 2>/dev/null || true)"
  # shellcheck disable=SC2012
  for dir in $(ls -1dt "${RELEASES_DIR}"/* 2>/dev/null); do
    marker=""
    [ "$(readlink -f "${dir}")" = "${current}" ] && marker="  <-- current (live)"
    [ "$(readlink -f "${dir}")" = "${previous}" ] && marker="  <-- previous"
    echo "  ${dir}${marker}"
  done
  echo
  echo "Database snapshots in ${SHARED_DIR}/db-backups:"
  # shellcheck disable=SC2012
  ls -1t "${SHARED_DIR}/db-backups"/*.sql.gz 2>/dev/null | head -n 10 | sed 's/^/  /' \
    || echo "  (none)"
}

if [ "${1:-}" = "--list" ] || [ "${1:-}" = "-l" ]; then
  list_releases
  exit 0
fi

promote_link() {
  local target="$1"
  local dest="$2"
  local tmp="${dest}.new.$$"

  ln -sfn "${target}" "${tmp}"
  if [ -L "${dest}" ] || [ ! -e "${dest}" ]; then
    mv -fT "${tmp}" "${dest}"
    return 0
  fi
  if [ -d "${dest}" ]; then
    local bak="${dest}.pre-release-$(date -u +%Y%m%d%H%M%S)"
    mv "${dest}" "${bak}"
    mv -fT "${tmp}" "${dest}"
    return 0
  fi
  rm -f "${dest}"
  mv -fT "${tmp}" "${dest}"
}

publish_release() {
  local release="$1"
  promote_link "${release}/public" "${APP_DIR}/public"
}

reload_php_fpm() {
  local svc pidfile pid
  for svc in php8.4-fpm php8.3-fpm php8.2-fpm php-fpm; do
    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet "$svc" 2>/dev/null; then
      if systemctl reload "$svc" 2>/dev/null || sudo -n systemctl reload "$svc" 2>/dev/null; then
        echo "==> Reloaded ${svc}"
        return 0
      fi
    fi
  done
  for pidfile in /run/php/php*-fpm.pid /run/php-fpm.pid; do
    [ -f "$pidfile" ] || continue
    pid="$(cat "$pidfile" 2>/dev/null || true)"
    if [ -n "${pid}" ] && kill -USR2 "$pid" 2>/dev/null; then
      echo "==> Reloaded PHP-FPM (pid ${pid})"
      return 0
    fi
  done
  echo "==> WARNING: PHP-FPM was NOT reloaded; the old code may still be cached in opcache"
  return 0
}

CURRENT_RELEASE="$(readlink -f "${CURRENT_LINK}" 2>/dev/null || true)"
TARGET=""

if [ -n "${ROLLBACK_TO:-}" ]; then
  case "${ROLLBACK_TO}" in
    /*) TARGET="${ROLLBACK_TO}" ;;
    *)  TARGET="${APP_DIR}/${ROLLBACK_TO}" ;;
  esac
elif [ -L "${PREVIOUS_LINK}" ]; then
  TARGET="$(readlink -f "${PREVIOUS_LINK}")"
fi

if [ -z "${TARGET}" ] || [ ! -d "${TARGET}" ]; then
  echo "ERROR: no rollback target. ${PREVIOUS_LINK} is missing or broken."
  echo
  list_releases
  echo
  echo "Choose one and re-run: ROLLBACK_TO=releases/<id> $0"
  exit 1
fi

TARGET="$(readlink -f "${TARGET}")"

if [ "${TARGET}" = "${CURRENT_RELEASE}" ]; then
  echo "ERROR: ${TARGET} is already live. Nothing to roll back to."
  echo
  list_releases
  exit 1
fi

if [ ! -f "${TARGET}/public/index.php" ] || [ ! -f "${TARGET}/vendor/autoload.php" ]; then
  echo "ERROR: ${TARGET} is not a complete release (missing public/index.php or vendor/)."
  exit 1
fi

echo "==> Rolling back"
echo "    from: ${CURRENT_RELEASE:-<unknown>}"
echo "      to: ${TARGET}"

publish_release "${TARGET}"
promote_link "${TARGET}" "${CURRENT_LINK}"

if [ -n "${CURRENT_RELEASE}" ] && [ -d "${CURRENT_RELEASE}" ]; then
  promote_link "${CURRENT_RELEASE}" "${PREVIOUS_LINK}"
fi

if [ "$(readlink -f "${APP_DIR}/public")" != "$(readlink -f "${TARGET}/public")" ]; then
  echo "ERROR: public/ did not switch. Check permissions on ${APP_DIR}."
  exit 1
fi

reload_php_fpm

if id "${WEB_USER}" >/dev/null 2>&1 && [ "$(id -u)" -eq 0 ]; then
  chown -R "${WEB_USER}:${WEB_GROUP}" \
    "${TARGET}/bootstrap/cache" \
    "${TARGET}/storage/framework/views" \
    "${TARGET}/storage/framework/cache" 2>/dev/null || true
fi

echo "==> Verifying"
if command -v curl >/dev/null 2>&1 && [ -f "${APP_DIR}/.env" ]; then
  app_url="$(grep -E '^APP_URL=' "${APP_DIR}/.env" | tail -n1 | cut -d= -f2- \
    | tr -d '"' | tr -d "'" | tr -d '\r')"
  hostport="$(printf '%s' "${app_url}" | sed -E 's#^https?://##' | cut -d/ -f1)"
  host="${hostport%%:*}"
  scheme="http"; port=80
  case "${app_url}" in https://*) scheme="https"; port=443 ;; esac
  case "${hostport}" in *:*) port="${hostport##*:}" ;; esac

  if [ -n "${host}" ]; then
    for path in /up /admin/login; do
      for attempt in 1 2 3 4 5; do
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 -k \
          --resolve "${host}:${port}:127.0.0.1" "${scheme}://${host}${path}" || true)"
        code="${code:-000}"
        echo "==> HTTP ${code} ${scheme}://${host}${path} (attempt ${attempt}/5)"
        case "${code}" in
          200|301|302|303|307|308) break ;;
        esac
        [ "${attempt}" -lt 5 ] && sleep 3
      done
    done
  fi
fi

echo
echo "==> Rollback complete. Live release: ${TARGET}"
echo "==> If the bad deploy migrated the database, restore the snapshot:"
echo "    ls -1t ${SHARED_DIR}/db-backups/*.sql.gz | head"
echo "==> The next push to main will deploy again. Revert the bad commit first."
