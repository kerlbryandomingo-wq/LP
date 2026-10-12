#!/bin/bash
# End-to-end QA of a release ZIP on a disposable Linux test machine.
#
#   sudo tests/qa/run-all.sh NEW_RELEASE.zip OUTPUT_DIR [PREVIOUS_RELEASE.zip]
#
# Needs: Apache 2.4 with mod_php, mod_rewrite, mod_headers; MariaDB/MySQL
# reachable as root via unix socket; PHP CLI with curl + pdo_mysql; unzip.
#
# The new ZIP is extracted three times into /var/www/lpqa and served on
#   8911  package extracted into the web root          (like public_html/)
#   8912  package extracted into a subfolder           (like public_html/lpsystem/)
#   8913  web root pointed at the package's public/    (custom document root)
#   8915  fresh copy installed over the root run's (already populated)
#         database and over another app's database — see reinstall.php
#   8916  (only when QA_PLAYWRIGHT_DIR and CHROME are set) subfolder copy
#         installed and exercised in headless Chromium — see browser.mjs
#   8914  (only with PREVIOUS_RELEASE.zip) previous release installed in the
#         web root, then upgraded to the new ZIP — see upgrade.php
# each with its own new, empty database, a random database password and a
# random admin password generated for this run only (never printed or saved).
#
# Tear everything down with: tests/qa/run-all.sh --cleanup
set -euo pipefail

WWW=/var/www/lpqa
SITE=/etc/apache2/sites-available/lpqa.conf
HERE="$(cd "$(dirname "$0")" && pwd)"
DBS="lpqa_root lpqa_sub lpqa_pub lpqa_upg lpqa_foreign lpqa_browser"

cleanup() {
  a2dissite lpqa.conf >/dev/null 2>&1 || true
  rm -f "$SITE"
  service apache2 reload >/dev/null 2>&1 || true
  rm -rf "$WWW"
  for db in $DBS; do mysql -u root -e "DROP DATABASE IF EXISTS $db"; done
  mysql -u root -e "DROP USER IF EXISTS 'lpqa'@'localhost'; DROP USER IF EXISTS 'lpqa'@'127.0.0.1';"
}

if [[ "${1:-}" == "--cleanup" ]]; then cleanup; echo "cleaned up"; exit 0; fi

ZIP="$(realpath "$1")"
OUT="$(realpath -m "$2")"
PREV=""
if [[ -n "${3:-}" ]]; then PREV="$(realpath "$3")"; fi
mkdir -p "$OUT"

cleanup
mkdir -p "$WWW/root" "$WWW/sub/lpsystem" "$WWW/pub" "$WWW/upg" "$WWW/re" "$WWW/br/lpsystem"
for d in "$WWW/root" "$WWW/sub/lpsystem" "$WWW/pub" "$WWW/re" "$WWW/br/lpsystem"; do unzip -q "$ZIP" -d "$d"; done
if [[ -n "$PREV" ]]; then unzip -q "$PREV" -d "$WWW/upg"; fi
chown -R www-data:www-data "$WWW"

DBPASS="$(openssl rand -hex 16)"
mysql -u root -e "CREATE USER 'lpqa'@'localhost' IDENTIFIED BY '$DBPASS'; CREATE USER 'lpqa'@'127.0.0.1' IDENTIFIED BY '$DBPASS';"
for db in $DBS; do
  mysql -u root -e "CREATE DATABASE $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON $db.* TO 'lpqa'@'localhost'; GRANT ALL PRIVILEGES ON $db.* TO 'lpqa'@'127.0.0.1';"
done

{
  for p in 8911 8912 8913 8914 8915 8916; do echo "Listen $p"; done
  for spec in "8911 $WWW/root $WWW/root" "8912 $WWW/sub $WWW/sub" "8913 $WWW/pub/public $WWW/pub" "8914 $WWW/upg $WWW/upg" "8915 $WWW/re $WWW/re" "8916 $WWW/br $WWW/br"; do
    set -- $spec
    cat <<VHOST
<VirtualHost *:$1>
    DocumentRoot $2
    <Directory $3>
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog \${APACHE_LOG_DIR}/lpqa-error.log
</VirtualHost>
VHOST
  done
} > "$SITE"
a2enmod rewrite headers >/dev/null
a2ensite lpqa.conf >/dev/null
apache2ctl configtest 2>&1 | grep -v AH00558
service apache2 restart >/dev/null 2>&1 || service apache2 start >/dev/null 2>&1
sleep 1

status=0
common() { echo QA_DB_USER=lpqa QA_DB_PASS="$DBPASS" QA_DB_HOST=127.0.0.1 QA_ADMIN_PASSWORD="Adm$(openssl rand -hex 10)9"; }
run() { # layout base approot db
  env $(common) QA_LAYOUT="$1" QA_BASE="$2" QA_APP_ROOT="$3" QA_DB_NAME="$4" QA_OUT="$OUT/results-$1.json" \
    php "$HERE/run.php" > "$OUT/log-$1.txt" 2>&1 || status=1
  tail -1 "$OUT/log-$1.txt"
}
run root           http://127.0.0.1:8911          "$WWW/root"         lpqa_root
run subfolder      http://127.0.0.1:8912/lpsystem "$WWW/sub/lpsystem" lpqa_sub
run docroot-public http://127.0.0.1:8913          "$WWW/pub"          lpqa_pub
# Another application's tables (WordPress-like "users") in its own database.
mysql -u root lpqa_foreign -e "CREATE TABLE users (ID BIGINT PRIMARY KEY, user_login VARCHAR(60), user_pass VARCHAR(255)); CREATE TABLE posts (ID BIGINT PRIMARY KEY);"
env $(common) QA_BASE=http://127.0.0.1:8915 QA_APP_ROOT="$WWW/re" QA_DB_NAME=lpqa_root QA_FOREIGN_DB=lpqa_foreign QA_OUT="$OUT/results-reinstall.json" \
  php "$HERE/reinstall.php" > "$OUT/log-reinstall.txt" 2>&1 || status=1
tail -1 "$OUT/log-reinstall.txt"
if [[ -n "$PREV" ]]; then
  env $(common) QA_BASE=http://127.0.0.1:8914 QA_APP_ROOT="$WWW/upg" QA_DB_NAME=lpqa_upg QA_NEW_ZIP="$ZIP" QA_OUT="$OUT/results-upgrade.json" \
    php "$HERE/upgrade.php" > "$OUT/log-upgrade.txt" 2>&1 || status=1
  tail -1 "$OUT/log-upgrade.txt"
fi
# Failure modes on the installed root copy: config.php missing, then the
# database server stopped (it is started again right after).
for phase in config dbdown; do
  if [[ "$phase" == dbdown ]]; then service mariadb stop >/dev/null 2>&1 || service mysql stop >/dev/null 2>&1; fi
  env QA_BASE=http://127.0.0.1:8911 QA_APP_ROOT="$WWW/root" QA_DB_PASS="$DBPASS" QA_PHASE=$phase QA_OUT="$OUT/results-failure-$phase.json" \
    php "$HERE/failure-modes.php" > "$OUT/log-failure-$phase.txt" 2>&1 || status=1
  if [[ "$phase" == dbdown ]]; then service mariadb start >/dev/null 2>&1 || service mysql start >/dev/null 2>&1; fi
  tail -1 "$OUT/log-failure-$phase.txt"
done
if [[ -n "${QA_PLAYWRIGHT_DIR:-}" && -n "${CHROME:-}" ]]; then
  cp "$HERE/browser.mjs" "$QA_PLAYWRIGHT_DIR/browser.mjs"
  (cd "$QA_PLAYWRIGHT_DIR" && env $(common) QA_BASE=http://127.0.0.1:8916/lpsystem QA_DB_NAME=lpqa_browser QA_SHOTS="$OUT/screenshots" \
    QA_OUT="$OUT/results-browser.json" CHROME="$CHROME" node browser.mjs > "$OUT/log-browser.txt" 2>&1) || status=1
  tail -1 "$OUT/log-browser.txt"
fi
exit $status
