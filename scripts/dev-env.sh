#!/usr/bin/env bash
# Disposable local WordPress + BuddyBoss/BuddyPress stack for developing and testing the plugin.
#
#   scripts/dev-env.sh up [dir]      create (or reuse) the stack in dir (default: .dev-env)
#   scripts/dev-env.sh wp <args>     run WP-CLI on the BuddyBoss site   (WP_SITE=bp for BuddyPress)
#   scripts/dev-env.sh db [args]      MariaDB client on the dev database (e.g. db -e 'SELECT 1')
#   scripts/dev-env.sh serve [port]  serve the BuddyBoss site on http://127.0.0.1:<port> (default 8765)
#   scripts/dev-env.sh demo          seed a synthetic 12-month community + public page (for screenshots;
#                                    the integration test then fails: it needs empty months, use a fresh env)
#   scripts/dev-env.sh test          unit + integration tests on both sites
#   scripts/dev-env.sh down          stop MariaDB and delete the stack
#
# Requires php (8.1+), mariadb (server + client) and curl. Everything lives in one directory,
# MariaDB listens only on a private Unix socket (no TCP), and the plugin is symlinked, so edits
# are live. Admin login: admin / fixture-only. Never point this at real data.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="${CP_DEV_DIR:-$ROOT/.dev-env}"
[[ "${1:-}" == "up" && -n "${2:-}" ]] && DIR="$2"
# macOS limits Unix socket paths to ~104 chars, so the socket lives in a short temp path.
TMP="${TMPDIR:-/tmp}"
SOCK="${TMP%/}/cp-dev-$(printf '%s' "$DIR" | cksum | cut -d' ' -f1).sock"
WPCLI="$DIR/wp-cli.phar"
BUDDYBOSS_ZIP="https://github.com/buddyboss/buddyboss-platform/releases/download/3.8.0/buddyboss-platform-3.8.0.zip"

wp_site() { # wp_site <bb|bp> args...
  local site="$1"; shift
  php -d memory_limit=512M "$WPCLI" --path="$DIR/$site" "$@"
}

db_up() {
  if [[ -S "$SOCK" ]] && mariadb -S "$SOCK" -e 'SELECT 1' >/dev/null 2>&1; then return; fi
  if [[ ! -d "$DIR/db/mysql" ]]; then
    mkdir -p "$DIR/db"
    mariadb-install-db --datadir="$DIR/db" >/dev/null
  fi
  nohup mariadbd --datadir="$DIR/db" --socket="$SOCK" --skip-networking --pid-file="$DIR/db.pid" >"$DIR/db.log" 2>&1 &
  for _ in $(seq 40); do [[ -S "$SOCK" ]] && break; sleep 0.25; done
  [[ -S "$SOCK" ]] || { echo "MariaDB did not start; see $DIR/db.log" >&2; exit 1; }
  mariadb -S "$SOCK" -e "CREATE DATABASE IF NOT EXISTS cp; CREATE USER IF NOT EXISTS 'cp'@'localhost' IDENTIFIED BY 'cp'; GRANT ALL ON cp.* TO 'cp'@'localhost';"
}

site_up() { # site_up <bb|bp> <table prefix>
  local site="$1" prefix="$2"
  [[ -f "$DIR/$site/wp-config.php" ]] && return
  mkdir -p "$DIR/$site"
  wp_site "$site" core download --quiet
  wp_site "$site" config create --dbname=cp --dbuser=cp --dbpass=cp --dbhost="localhost:$SOCK" --dbprefix="$prefix" --quiet
  wp_site "$site" core install --url=http://127.0.0.1:8765 --title="Community Pulse dev ($site)" \
    --admin_user=admin --admin_password=fixture-only --admin_email=admin@example.test --skip-email --quiet
  wp_site "$site" rewrite structure '/%postname%/' --quiet
  if [[ "$site" == bb ]]; then
    wp_site bb plugin install "$BUDDYBOSS_ZIP" --activate --quiet
    # Run BuddyBoss's installer now; otherwise the first wp-admin visit runs it and resets
    # the component list to its defaults (without Groups).
    wp_site bb eval 'bp_version_updater();'
    bb_components
    wp_site bb eval 'require_once WP_PLUGIN_DIR . "/buddyboss-platform/bp-core/admin/bp-core-admin-schema.php"; bp_core_install(); bp_core_install_invitations(); bp_core_add_page_mappings(bp_get_option("bp-active-components"), "keep");'
  else
    wp_site bp plugin install buddypress --activate --quiet
    wp_site bp bp component activate activity --quiet
    wp_site bp bp component activate groups --quiet
  fi
  ln -s "$ROOT" "$DIR/$site/wp-content/plugins/buddyboss-community-pulse"
  wp_site "$site" plugin activate buddyboss-community-pulse --quiet
}

# Enable the components the plugin needs. BuddyBoss 3.x keeps the source of truth in
# bb-active-features and re-syncs bp-active-components from it on wp-admin requests, so both
# options must include Groups (setting only bp-active-components is undone on the first admin visit).
bb_components() {
  wp_site bb eval '
    foreach (["bb-active-features", "bp-active-components"] as $o) {
      bp_update_option($o, array_merge((array) bp_get_option($o, []), ["activity" => 1, "groups" => 1, "members" => 1, "settings" => 1, "notifications" => 1]));
    }'
}

case "${1:-}" in
  up)
    mkdir -p "$DIR"
    [[ -f "$WPCLI" ]] || curl -fsSL -o "$WPCLI" https://github.com/wp-cli/wp-cli/releases/download/v2.12.0/wp-cli-2.12.0.phar
    db_up; site_up bb wp_; site_up bp bpx_; bb_components
    echo "Ready in $DIR  (socket $SOCK)"
    ;;
  wp) shift; db_up; wp_site "$([[ "${WP_SITE:-bb}" == bp ]] && echo bp || echo bb)" "$@" ;;
  db) shift; db_up; exec mariadb -S "$SOCK" -ucp -pcp cp "$@" ;;
  serve)
    db_up; bb_components; port="${2:-8765}"
    wp_site bb option update home "http://127.0.0.1:$port" --quiet
    wp_site bb option update siteurl "http://127.0.0.1:$port" --quiet
    echo "http://127.0.0.1:$port/wp-admin/tools.php?page=community-pulse  (admin / fixture-only)"
    exec php -S "127.0.0.1:$port" -t "$DIR/bb"
    ;;
  demo) db_up; bb_components; wp_site bb eval-file "$ROOT/scripts/seed-demo.php" ;;
  test)
    db_up; bb_components
    php "$ROOT/tests/unit.php"
    (cd "$ROOT" && wp_site bb eval-file tests/integration.php && wp_site bp eval-file tests/integration.php)
    ;;
  down)
    [[ -f "$DIR/db.pid" ]] && kill "$(cat "$DIR/db.pid")" 2>/dev/null || true
    sleep 1; rm -rf "$DIR"; rm -f "$SOCK"; echo "Removed $DIR"
    ;;
  *) sed -n '2,15p' "$0"; exit 1 ;;
esac
