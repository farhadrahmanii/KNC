#!/usr/bin/env bash
# Zero-downtime production deploy.
# Builds a new release beside the live site, then atomically switches the
# public/ symlink. If anything fails before or after the switch, traffic stays
# on (or rolls back to) the last good release. Never git-reset the live tree.
#
# Shared across releases: .env, storage/app (uploads and the Word template),
# logs, and sessions. Compiled Blade and the framework cache stay inside the
# release. After the switch, curl /up, /, and /admin/login must return
# 2xx/3xx or public/ is pointed back at the previous release.
#
# One-time server layout (nginx document root is $APP_DIR/public):
#   /var/www/fasalbabajimina/.git
#   /var/www/fasalbabajimina/.env          production env, never in git
#   /var/www/fasalbabajimina/storage       uploads survive every release
# The first successful run turns public/ from a directory into a symlink.
set -eEuo pipefail

APP_DIR="${APP_DIR:-/var/www/fasalbabajimina}"
BRANCH="${DEPLOY_BRANCH:-main}"
# Exact commit to deploy. The workflow resolves the branch tip to a SHA once,
# has the release gate verify *that* SHA, and pins it here. Without it this
# script re-reads the branch tip and can deploy a commit that landed after the
# gate passed. Empty = fall back to the tip of BRANCH.
DEPLOY_REF="${DEPLOY_REF:-}"
PHP_BIN="${PHP_BIN:-php}"
WEB_USER="${WEB_USER:-www-data}"
WEB_GROUP="${WEB_GROUP:-www-data}"
DEPLOY_USER="${DEPLOY_USER:-}"
DEPLOY_GROUP="${DEPLOY_GROUP:-}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
KEEP_DB_BACKUPS="${KEEP_DB_BACKUPS:-10}"
MIN_FREE_MB="${MIN_FREE_MB:-1024}"
DB_BACKUP_REQUIRED="${DB_BACKUP_REQUIRED:-1}"

cd "$APP_DIR"

# The workflow's concurrency group only serialises GitHub Actions. It does not
# stop someone running deploy.sh by hand at the same time.
LOCK_FILE="${LOCK_FILE:-${APP_DIR}/.deploy.lock}"
if command -v flock >/dev/null 2>&1; then
  exec 9>"${LOCK_FILE}"
  if ! flock -w "${LOCK_WAIT:-1800}" 9; then
    echo "ERROR: another deploy holds ${LOCK_FILE}; giving up after ${LOCK_WAIT:-1800}s"
    exit 1
  fi
else
  echo "==> WARNING: flock not available; concurrent deploys are not prevented"
fi

resolve_deploy_identity() {
  if [ -n "${DEPLOY_USER}" ]; then
    DEPLOY_GROUP="${DEPLOY_GROUP:-${DEPLOY_USER}}"
    return 0
  fi

  if [ "$(id -u)" -ne 0 ]; then
    DEPLOY_USER="$(id -un)"
    DEPLOY_GROUP="$(id -gn)"
    return 0
  fi

  if [ -e "${APP_DIR}" ]; then
    DEPLOY_USER="$(stat -c '%U' "${APP_DIR}" 2>/dev/null || true)"
    DEPLOY_GROUP="$(stat -c '%G' "${APP_DIR}" 2>/dev/null || true)"
  fi

  DEPLOY_USER="${DEPLOY_USER:-root}"
  DEPLOY_GROUP="${DEPLOY_GROUP:-${DEPLOY_USER}}"
}

resolve_deploy_identity

RELEASES_DIR="${APP_DIR}/releases"
SHARED_DIR="${APP_DIR}/shared"
CURRENT_LINK="${APP_DIR}/current"
PREVIOUS_LINK="${APP_DIR}/previous"

RELEASE_DIR=""
RELEASE_ID=""
PREVIOUS_RELEASE=""
SWITCHED=0
DEPLOY_OK=0

echo "==> Deploy started: $(date -Is)"
echo "==> Target branch: ${BRANCH}${DEPLOY_REF:+ (pinned to ${DEPLOY_REF})}"
echo "==> Deploy user for artisan: ${DEPLOY_USER} (PHP-FPM: ${WEB_USER})"

# Armed on ERR *and* EXIT: bash does not run an ERR trap for an explicit
# `exit N`, so an ERR-only trap silently skipped rollback on exactly the paths
# that call exit directly.
fail_keep_production() {
  local exit_code=$?
  trap - ERR EXIT

  if [ "${DEPLOY_OK}" = "1" ] || [ "${exit_code}" -eq 0 ]; then
    exit "${exit_code}"
  fi

  echo "==> Deploy failed (exit ${exit_code}); production stays on the last good release"

  if [ "${SWITCHED}" = "1" ] && [ -n "${PREVIOUS_RELEASE}" ] && [ -d "${PREVIOUS_RELEASE}" ]; then
    echo "==> Rolling back document root to ${PREVIOUS_RELEASE}"
    publish_release "${PREVIOUS_RELEASE}" || true
    promote_link "${PREVIOUS_RELEASE}" "${CURRENT_LINK}" || true
    reload_php_fpm || true
  fi

  if [ "${SWITCHED}" != "1" ] && [ -n "${RELEASE_DIR}" ] && [ -d "${RELEASE_DIR}" ]; then
    case "${RELEASE_DIR}" in
      "${RELEASES_DIR}"/*) rm -rf "${RELEASE_DIR}" ;;
    esac
  fi

  echo "==> To return traffic to the previous release manually: ${APP_DIR}/current/rollback.sh"

  exit "${exit_code}"
}
trap fail_keep_production ERR EXIT

install_php_upload_ini() {
  local src="${1:-}"
  local ver sapi dest
  if [ ! -f "${src}" ]; then
    echo "==> WARNING: ${src} missing; PHP upload limits unchanged"
    return 0
  fi
  ver="$(${PHP_BIN} -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)"
  if [ -z "${ver}" ]; then
    echo "==> WARNING: could not read PHP version; PHP upload limits unchanged"
    return 0
  fi
  for sapi in fpm cli; do
    dest="/etc/php/${ver}/${sapi}/conf.d/99-uploads.ini"
    if [ ! -d "$(dirname "${dest}")" ]; then
      continue
    fi
    if cp "${src}" "${dest}" 2>/dev/null || sudo -n cp "${src}" "${dest}" 2>/dev/null; then
      echo "==> Installed PHP upload limits at ${dest}"
    else
      echo "==> WARNING: cannot write ${dest} (need root or a sudoers line)."
      echo "==>          Sale document uploads need upload_max_filesize=32M and post_max_size=36M."
      echo "==>          ${DEPLOY_USER} ALL=(root) NOPASSWD: /usr/bin/cp, /bin/systemctl reload php${ver}-fpm"
    fi
  done
  return 0
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
  echo "==> WARNING: PHP-FPM was NOT reloaded (no permission or service not found)."
  echo "==>          With opcache.validate_timestamps=0 the old code keeps serving."
  echo "==>          Grant the deploy user a sudoers line, e.g.:"
  echo "==>          ${DEPLOY_USER} ALL=(root) NOPASSWD: /bin/systemctl reload php8.2-fpm"
  if [ "${PHP_FPM_RELOAD_REQUIRED:-0}" = "1" ]; then
    echo "ERROR: PHP_FPM_RELOAD_REQUIRED=1 and the reload failed"
    return 1
  fi
  return 0
}

# The hourly inspire command, and any backup schedule, need cron.
# It must belong to DEPLOY_USER. A root crontab writes root-owned files into
# shared storage, and PHP-FPM then cannot append the log.
ensure_scheduler_cron() {
  local cron_line="* * * * * cd ${APP_DIR}/current && ${PHP_BIN} artisan schedule:run >> /dev/null 2>&1"
  local -a read_cmd write_cmd

  if ! command -v crontab >/dev/null 2>&1; then
    echo "==> crontab not available; install for ${DEPLOY_USER}: ${cron_line}"
    return 0
  fi

  if [ "$(id -un)" = "${DEPLOY_USER}" ]; then
    read_cmd=(crontab -l)
    write_cmd=(crontab -)
  elif [ "$(id -u)" -eq 0 ]; then
    read_cmd=(crontab -u "${DEPLOY_USER}" -l)
    write_cmd=(crontab -u "${DEPLOY_USER}" -)
  else
    echo "==> Cannot manage ${DEPLOY_USER}'s crontab as $(id -un); ensure this line exists:"
    echo "    ${cron_line}"
    return 0
  fi

  if [ "$(id -u)" -eq 0 ] && [ "${DEPLOY_USER}" != "root" ] \
    && crontab -l 2>/dev/null | grep -F "artisan schedule:run" >/dev/null; then
    echo "==> WARNING: root's crontab also runs 'artisan schedule:run'."
    echo "==>          Remove it (crontab -e as root) or every scheduled job fires twice."
  fi

  if "${read_cmd[@]}" 2>/dev/null | grep -F "artisan schedule:run" >/dev/null; then
    echo "==> Scheduler cron already present for ${DEPLOY_USER}"
    return 0
  fi

  if ("${read_cmd[@]}" 2>/dev/null; echo "${cron_line}") | "${write_cmd[@]}"; then
    echo "==> Installed scheduler cron for ${DEPLOY_USER}"
  else
    echo "==> Could not install scheduler cron; add for ${DEPLOY_USER}:"
    echo "    ${cron_line}"
  fi
  return 0
}

fix_storage_permissions() {
  local release="${1:-}"

  echo "==> Fixing storage permissions for ${WEB_USER}:${WEB_GROUP}"

  mkdir -p \
    "${APP_DIR}/storage/app" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/storage/framework/sessions"

  if [ -n "${release}" ]; then
    mkdir -p \
      "${release}/storage/framework/views" \
      "${release}/storage/framework/cache/data" \
      "${release}/bootstrap/cache"
  fi

  if id "${WEB_USER}" >/dev/null 2>&1; then
    if ! chown -R "${WEB_USER}:${WEB_GROUP}" \
      "${APP_DIR}/storage" \
      ${release:+"${release}/bootstrap/cache"} \
      ${release:+"${release}/storage/framework/views"} \
      ${release:+"${release}/storage/framework/cache"}; then
      chgrp -R "${WEB_GROUP}" "${APP_DIR}/storage/logs" 2>/dev/null || true
      if [ "$(id -u)" -eq 0 ]; then
        echo "ERROR: chown to ${WEB_USER}:${WEB_GROUP} failed"
        exit 1
      fi
      echo "WARNING: chown failed. On the VPS run:"
      echo "  sudo chown -R ${WEB_USER}:${WEB_GROUP} ${APP_DIR}/storage"
    fi
  else
    echo "WARNING: user ${WEB_USER} not found; skipping chown"
  fi

  chmod -R ug+rwx \
    "${APP_DIR}/storage" \
    ${release:+"${release}/bootstrap/cache"} \
    ${release:+"${release}/storage/framework/views"} \
    ${release:+"${release}/storage/framework/cache"} 2>/dev/null || true

  find "${APP_DIR}/storage" \
    ${release:+"${release}/storage/framework/cache"} \
    ${release:+"${release}/storage/framework/views"} \
    -type d -exec chmod g+s {} + 2>/dev/null || true

  chmod 2775 "${APP_DIR}/storage/logs" 2>/dev/null || true
  find "${APP_DIR}/storage/logs" -type f -name 'laravel*.log' -exec chmod 0664 {} \; 2>/dev/null || true
  find "${APP_DIR}/storage/logs" -type f -name 'laravel*.log' -exec chgrp "${WEB_GROUP}" {} \; 2>/dev/null || true
}

# Artisan must run as DEPLOY_USER (owns .env), not WEB_USER (php-fpm). When deploy
# is invoked as root, drop privileges so new files are not root-owned.
run_artisan() {
  local release="$1"
  shift
  # env is part of the command so a privilege drop still sees VIEW_COMPILED_PATH.
  # config:cache bakes that path into the release; without it a shared path in
  # .env would make every release compile views into the same directory.
  local -a runner=("${PHP_BIN}" "$@")
  if [ -n "${VIEW_COMPILED_PATH:-}" ]; then
    runner=(env "VIEW_COMPILED_PATH=${VIEW_COMPILED_PATH}" "${PHP_BIN}" "$@")
  fi

  if [ "$(id -un)" != "${DEPLOY_USER}" ] && id "${DEPLOY_USER}" >/dev/null 2>&1; then
    if [ "$(id -u)" -eq 0 ] && command -v runuser >/dev/null 2>&1; then
      runuser -u "${DEPLOY_USER}" -- /bin/sh -c 'cd "$1" && shift && exec "$@"' _ "${release}" "${runner[@]}"
      return
    fi
    if command -v sudo >/dev/null 2>&1; then
      sudo -u "${DEPLOY_USER}" /bin/sh -c 'cd "$1" && shift && exec "$@"' _ "${release}" "${runner[@]}"
      return
    fi
  fi

  (cd "${release}" && "${runner[@]}")
}

# Replace dest with a symlink to target. Atomic when dest is already a symlink.
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
    local bak="${dest}.pre-release"
    if [ -e "${bak}" ]; then
      bak="${dest}.pre-release-$(date -u +%Y%m%d%H%M%S)"
    fi
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

share_into_release() {
  local src="$1"
  local dest="$2"

  mkdir -p "${src}" "$(dirname "${dest}")"
  rm -rf "${dest}"
  ln -sfn "${src}" "${dest}"
}

link_shared_into_release() {
  local release="$1"

  rm -f "${release}/.env"
  ln -sfn "${APP_DIR}/.env" "${release}/.env"

  mkdir -p \
    "${APP_DIR}/storage/app" \
    "${APP_DIR}/storage/logs" \
    "${APP_DIR}/storage/framework/sessions" \
    "${release}/storage/framework/views" \
    "${release}/storage/framework/cache/data" \
    "${release}/storage/framework/testing"

  share_into_release "${APP_DIR}/storage/app" "${release}/storage/app"
  share_into_release "${APP_DIR}/storage/logs" "${release}/storage/logs"
  share_into_release "${APP_DIR}/storage/framework/sessions" "${release}/storage/framework/sessions"

  mkdir -p "${release}/bootstrap/cache"
}

# An absent key yields an empty string, never a failure. grep exits 1 on no
# match and `set -o pipefail` would otherwise abort the deploy.
env_value() {
  local key="$1"
  [ -f "${APP_DIR}/.env" ] || return 0
  grep -E "^${key}=" "${APP_DIR}/.env" 2>/dev/null \
    | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '\r' || true
}

require_free_disk() {
  local free_mb mount
  free_mb="$(df -Pm "${APP_DIR}" 2>/dev/null | awk 'NR==2 {print $4}')"
  mount="$(df -P "${APP_DIR}" 2>/dev/null | awk 'NR==2 {print $6}')"
  if [ -z "${free_mb}" ]; then
    echo "==> WARNING: could not read free disk for ${APP_DIR}"
    return 0
  fi
  echo "==> Free disk on ${mount}: ${free_mb} MB (need ${MIN_FREE_MB} MB)"
  if [ "${free_mb}" -lt "${MIN_FREE_MB}" ]; then
    echo "ERROR: only ${free_mb} MB free on ${mount}; need ${MIN_FREE_MB} MB."
    echo "       Free space or lower KEEP_RELEASES (currently ${KEEP_RELEASES})."
    return 1
  fi
  return 0
}

# migrate --force cannot be undone by pointing public/ back. Snapshot first.
backup_database() {
  if [ "${DB_BACKUP_SKIP:-0}" = "1" ]; then
    if [ "${DB_BACKUP_REQUIRED}" = "1" ]; then
      echo "ERROR: DB_BACKUP_SKIP conflicts with DB_BACKUP_REQUIRED=1"
      return 1
    fi
    echo "==> Database backup skipped (DB_BACKUP_SKIP=1)"
    return 0
  fi

  local connection host port name user pass
  connection="$(env_value DB_CONNECTION)"
  connection="${connection:-mysql}"

  local missing=""
  if [ "${connection}" != "mysql" ] && [ "${connection}" != "mariadb" ]; then
    missing="DB_CONNECTION=${connection} is not mysql/mariadb"
  elif ! command -v mysqldump >/dev/null 2>&1; then
    missing="mysqldump is not installed"
  elif ! command -v gzip >/dev/null 2>&1; then
    missing="gzip is not installed"
  fi

  if [ -n "${missing}" ]; then
    if [ "${DB_BACKUP_REQUIRED:-0}" = "1" ]; then
      echo "ERROR: cannot back up the database (${missing}) and DB_BACKUP_REQUIRED=1"
      return 1
    fi
    echo "==> WARNING: skipping database backup (${missing})."
    echo "==>          A failed migration will NOT be recoverable from this deploy."
    return 0
  fi

  host="$(env_value DB_HOST)"; host="${host:-127.0.0.1}"
  port="$(env_value DB_PORT)"; port="${port:-3306}"
  name="$(env_value DB_DATABASE)"
  user="$(env_value DB_USERNAME)"
  pass="$(env_value DB_PASSWORD)"

  if [ -z "${name}" ] || [ -z "${user}" ]; then
    if [ "${DB_BACKUP_REQUIRED:-0}" = "1" ]; then
      echo "ERROR: DB_DATABASE or DB_USERNAME missing from .env and DB_BACKUP_REQUIRED=1"
      return 1
    fi
    echo "==> WARNING: DB_DATABASE/DB_USERNAME missing from .env; skipping backup"
    return 0
  fi

  local backup_dir out cnf
  backup_dir="${SHARED_DIR}/db-backups"
  mkdir -p "${backup_dir}"
  chmod 700 "${backup_dir}"
  out="${backup_dir}/${RELEASE_ID}.sql.gz"

  # A defaults file, not --password=: a command line is world-readable in ps.
  cnf="$(mktemp)"
  chmod 600 "${cnf}"
  {
    printf '[client]\n'
    printf 'host=%s\n' "${host}"
    printf 'port=%s\n' "${port}"
    printf 'user=%s\n' "${user}"
    printf 'password=%s\n' "${pass}"
  } > "${cnf}"

  echo "==> Backing up ${name} to ${out}"
  local dump_ok=0
  if mysqldump --defaults-extra-file="${cnf}" \
      --single-transaction --quick --no-tablespaces \
      --routines --triggers "${name}" 2>"${cnf}.err" | gzip -c > "${out}"; then
    dump_ok=1
  else
    echo "==> Full dump failed, retrying without routines/triggers"
    if mysqldump --defaults-extra-file="${cnf}" \
        --single-transaction --quick --no-tablespaces \
        "${name}" 2>"${cnf}.err" | gzip -c > "${out}"; then
      dump_ok=1
    fi
  fi

  if [ "${dump_ok}" != "1" ]; then
    echo "ERROR: database backup failed:"
    tail -n 10 "${cnf}.err" 2>/dev/null || true
    rm -f "${cnf}" "${cnf}.err" "${out}"
    if [ "${DB_BACKUP_REQUIRED:-0}" = "1" ]; then
      return 1
    fi
    echo "==> WARNING: continuing without a backup (set DB_BACKUP_REQUIRED=1 to make this fatal)"
    return 0
  fi

  rm -f "${cnf}" "${cnf}.err"
  gzip -t "${out}"
  echo "==> Backup complete: ${out} ($(du -h "${out}" | cut -f1))"
  echo "==> Restore with: gunzip -c ${out} | mysql -u ${user} -p ${name}"

  # shellcheck disable=SC2012
  ls -1t "${backup_dir}"/*.sql.gz 2>/dev/null \
    | tail -n +$((KEEP_DB_BACKUPS + 1)) \
    | while read -r old; do
        echo "==> Pruning old backup ${old}"
        rm -f "${old}"
      done || true

  return 0
}

build_front_end() {
  local release="$1"

  if ! command -v npm >/dev/null 2>&1 || ! command -v node >/dev/null 2>&1; then
    echo "ERROR: node and npm must be on PATH for ${DEPLOY_USER}."
    echo "       The Filament admin theme is compiled with Vite. Without public/build, /admin returns 500."
    echo "       Install Node 20 or 22, then redeploy."
    return 1
  fi

  echo "==> Building front end (npm ci && npm run build)"
  # Vite is a devDependency. A production NODE_ENV would omit it.
  (
    cd "${release}"
    NODE_ENV=development npm ci
    npm run build
  )
  rm -rf "${release}/node_modules"

  if [ ! -f "${release}/public/build/manifest.json" ]; then
    echo "ERROR: npm run build did not write public/build/manifest.json"
    return 1
  fi
}

healthcheck_release() {
  local release="$1"
  test -f "${release}/public/index.php"
  test -f "${release}/vendor/autoload.php"
  test -f "${release}/public/build/manifest.json"
  test -f "${release}/bootstrap/cache/config.php"
  test -d "${release}/storage/framework/views"
  run_artisan "${release}" artisan about --no-interaction >/dev/null
}

healthcheck_http() {
  if [ "${HEALTHCHECK_SKIP:-0}" = "1" ]; then
    echo "==> HTTP healthcheck skipped (HEALTHCHECK_SKIP=1)"
    return 0
  fi

  if ! command -v curl >/dev/null 2>&1; then
    echo "ERROR: curl is required for the live HTTP healthcheck"
    return 1
  fi

  local app_url hostport host scheme port
  app_url="$(env_value APP_URL)"
  hostport="$(printf '%s' "${app_url}" | sed -E 's#^https?://##' | cut -d/ -f1)"
  host="${hostport%%:*}"
  if [ -z "${host}" ]; then
    echo "ERROR: APP_URL is missing from ${APP_DIR}/.env"
    return 1
  fi

  scheme="http"
  port=80
  case "${app_url}" in
    https://*)
      scheme="https"
      port=443
      ;;
  esac
  case "${hostport}" in
    *:*) port="${hostport##*:}" ;;
  esac

  local path code body log attempt attempts delay ok
  body="$(mktemp)"
  log="${APP_DIR}/storage/logs/laravel.log"
  attempts="${HEALTHCHECK_ATTEMPTS:-5}"
  delay="${HEALTHCHECK_DELAY:-3}"

  for path in /up / /admin/login; do
    ok=0
    code="000"
    for ((attempt = 1; attempt <= attempts; attempt++)); do
      code="$(curl -sS -o "${body}" -w "%{http_code}" --max-time 20 -k \
        --resolve "${host}:${port}:127.0.0.1" \
        "${scheme}://${host}${path}" || true)"
      code="${code:-000}"
      case "${code}" in
        200|301|302|303|307|308)
          echo "==> HTTP ${code} ${scheme}://${host}${path} (attempt ${attempt}/${attempts})"
          ok=1
          break
          ;;
      esac
      echo "==> HTTP ${code} ${scheme}://${host}${path} (attempt ${attempt}/${attempts})"
      if [ "${attempt}" -lt "${attempts}" ]; then
        sleep "${delay}"
      fi
    done

    if [ "${ok}" != "1" ]; then
      echo "ERROR: live HTTP healthcheck failed (${code}) for ${path} after ${attempts} attempts"
      echo "==> Response body (first 800 chars):"
      head -c 800 "${body}" || true
      echo
      if [ -f "${log}" ]; then
        echo "==> Last laravel.log lines:"
        tail -n 40 "${log}" || true
      fi
      rm -f "${body}"
      return 1
    fi
  done
  rm -f "${body}"
}

prune_old_releases() {
  local keep_paths=()
  [ -L "${CURRENT_LINK}" ] && keep_paths+=("$(readlink -f "${CURRENT_LINK}")")
  [ -L "${PREVIOUS_LINK}" ] && keep_paths+=("$(readlink -f "${PREVIOUS_LINK}")")

  local dir resolved keep k
  # shellcheck disable=SC2012
  for dir in $(ls -1dt "${RELEASES_DIR}"/* 2>/dev/null | tail -n +$((KEEP_RELEASES + 1))); do
    resolved="$(readlink -f "${dir}")"
    keep=0
    for k in "${keep_paths[@]+"${keep_paths[@]}"}"; do
      if [ "${resolved}" = "${k}" ]; then
        keep=1
        break
      fi
    done
    if [ "${keep}" = "0" ]; then
      echo "==> Pruning old release ${dir}"
      rm -rf "${dir}"
    fi
  done
}

if [ ! -d "${APP_DIR}/.git" ]; then
  echo "ERROR: ${APP_DIR} is not a git repository."
  exit 1
fi

if [ ! -f "${APP_DIR}/.env" ]; then
  echo "ERROR: ${APP_DIR}/.env is missing. Create the production env before deploying."
  exit 1
fi

for bin in git "${PHP_BIN}" composer; do
  if ! command -v "${bin}" >/dev/null 2>&1; then
    echo "ERROR: ${bin} is not on PATH."
    exit 1
  fi
done

mkdir -p "${RELEASES_DIR}" "${SHARED_DIR}"
require_free_disk

echo "==> Fetch origin/${BRANCH} (live tree unchanged)"
git fetch origin "${BRANCH}"

BUILD_REF=""
if [ -n "${DEPLOY_REF}" ]; then
  if ! git cat-file -e "${DEPLOY_REF}^{commit}" 2>/dev/null; then
    echo "ERROR: pinned commit ${DEPLOY_REF} is not in origin/${BRANCH} after fetch."
    echo "       Was it force-pushed away, or does it live on another branch?"
    exit 1
  fi
  BUILD_REF="${DEPLOY_REF}"
else
  echo "==> WARNING: no DEPLOY_REF; building the current tip of origin/${BRANCH}."
  echo "==>          That may not be the commit the release gate verified."
  BUILD_REF="origin/${BRANCH}"
fi

SHORT_SHA="$(git rev-parse --short "${BUILD_REF}")"
RELEASE_ID="$(date -u +%Y%m%d%H%M%S)_${SHORT_SHA}"
RELEASE_DIR="${RELEASES_DIR}/${RELEASE_ID}"

if [ -L "${CURRENT_LINK}" ]; then
  PREVIOUS_RELEASE="$(readlink -f "${CURRENT_LINK}")"
fi

echo "==> Building release ${RELEASE_ID}"
mkdir -p "${RELEASE_DIR}"
git archive "${BUILD_REF}" | tar -x -C "${RELEASE_DIR}"

# Artisan runs during composer install (package:discover, filament:upgrade).
# Link .env and storage first so that boot uses production config and does
# not write logs into a directory we are about to throw away.
link_shared_into_release "${RELEASE_DIR}"

echo "==> Install PHP dependencies (new release only)"
export COMPOSER_HOME="${SHARED_DIR}/composer"
mkdir -p "${COMPOSER_HOME}"
composer install --working-dir="${RELEASE_DIR}" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

build_front_end "${RELEASE_DIR}"

# Pin compiled views to this release even if .env still has VIEW_COMPILED_PATH.
export VIEW_COMPILED_PATH="${RELEASE_DIR}/storage/framework/views"
mkdir -p "${VIEW_COMPILED_PATH}"

echo "==> Laravel post-deploy (new release)"
run_artisan "${RELEASE_DIR}" artisan storage:link --no-interaction || true

echo "==> Caching config for migrate"
run_artisan "${RELEASE_DIR}" artisan config:cache --no-interaction

backup_database

echo "==> Running pending migrations"
run_artisan "${RELEASE_DIR}" artisan migrate --force --no-interaction

echo "==> Rebuilding Laravel caches (view:clear is release-local)"
run_artisan "${RELEASE_DIR}" artisan optimize --no-interaction

fix_storage_permissions "${RELEASE_DIR}"

echo "==> Health-checking new release (before switch)"
healthcheck_release "${RELEASE_DIR}"

echo "==> Release gate (before switch — live site unchanged)"
run_artisan "${RELEASE_DIR}" artisan mina:release-gate --skip-compile --no-interaction
fix_storage_permissions "${RELEASE_DIR}"

if [ -n "${PREVIOUS_RELEASE}" ]; then
  promote_link "${PREVIOUS_RELEASE}" "${PREVIOUS_LINK}"
fi

echo "==> Switching traffic to ${RELEASE_ID}"
publish_release "${RELEASE_DIR}"
promote_link "${RELEASE_DIR}" "${CURRENT_LINK}"
SWITCHED=1

if [ "$(readlink -f "${APP_DIR}/public")" != "$(readlink -f "${RELEASE_DIR}/public")" ]; then
  echo "ERROR: public/ did not switch to the new release"
  exit 1
fi

install_php_upload_ini "${RELEASE_DIR}/deploy/php/uploads.ini"
reload_php_fpm

run_artisan "${RELEASE_DIR}" artisan queue:restart --no-interaction >/dev/null 2>&1 || true

fix_storage_permissions "${RELEASE_DIR}"

echo "==> Health-checking live release (after switch)"
healthcheck_release "${RELEASE_DIR}"
healthcheck_http

ensure_scheduler_cron

DEPLOY_OK=1
trap - ERR EXIT
prune_old_releases || echo "==> WARNING: pruning old releases failed (the deploy itself succeeded)"

echo "==> Deploy finished: $(date -Is)"
echo "==> Live release: ${RELEASE_DIR}"
run_artisan "${RELEASE_DIR}" artisan --version
git -C "${APP_DIR}" log -1 --oneline "${BUILD_REF}"
