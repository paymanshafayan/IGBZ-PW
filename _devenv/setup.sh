#!/usr/bin/env bash
#
# Build the offline WordPress + WooCommerce test environment.
#
# Reads wordpress-*.zip and woocommerce-*.zip from _devenv/ (committed to the repo) so the
# environment can be rebuilt with no access to wordpress.org. Idempotent: safe to re-run.
#
# Usage:  bash _devenv/setup.sh [--force]
#
set -Eeuo pipefail

DEVENV="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$DEVENV/.." && pwd)"
WORK="$DEVENV/.work"

PLAYGROUND_VERSION="3.1.49"
PHPWASM_VERSION="3.1.49"

die()  { printf '\n\033[31merror:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()   { printf '  \033[32mok\033[0m %s\n' "$*"; }

trap 'die "setup failed on line $LINENO"' ERR

FORCE=0
[ "${1:-}" = "--force" ] && FORCE=1

# ---------------------------------------------------------------------------
# 0. Prerequisites
# ---------------------------------------------------------------------------
command -v node >/dev/null || die "node is required but not installed"
command -v npm  >/dev/null || die "npm is required but not installed"
command -v python3 >/dev/null || die "python3 is required (used to serve the WordPress zip)"

info "node $(node -v), npm $(npm -v)"

# ---------------------------------------------------------------------------
# 1. Locate the zips
# ---------------------------------------------------------------------------
# Pick the newest match so wordpress-7.1.zip wins over wordpress-7.0.4.zip if both exist.
newest() { ls -1t $1 2>/dev/null | head -1; }

WP_ZIP="$(newest "$DEVENV/wordpress-*.zip" || true)"
WC_ZIP="$(newest "$DEVENV/woocommerce-*.zip" || true)"

fetch_if_missing() {
	local kind="$1" dest="$2" url="$3"
	info "$kind zip not found in _devenv/ — trying the network"
	if curl -sfL --max-time 300 -o "$dest.part" "$url" 2>/dev/null; then
		mv "$dest.part" "$dest"
		ok "downloaded $(basename "$dest")"
		printf '%s' "$dest"
	else
		rm -f "$dest.part"
		return 1
	fi
}

if [ -z "$WP_ZIP" ]; then
	WP_ZIP="$(fetch_if_missing WordPress "$DEVENV/wordpress-7.0.4.zip" \
		"https://wordpress.org/wordpress-7.0.4.zip")" || die \
"No WordPress zip found and it could not be downloaded (wordpress.org is blocked here).

Put the official zip at:
    _devenv/wordpress-7.0.4.zip
Get it from:
    https://wordpress.org/latest.zip"
fi

if [ -z "$WC_ZIP" ]; then
	WC_ZIP="$(fetch_if_missing WooCommerce "$DEVENV/woocommerce-11.0.1.zip" \
		"https://downloads.wordpress.org/plugin/woocommerce.11.0.1.zip")" || die \
"No WooCommerce zip found and it could not be downloaded (wordpress.org is blocked here).

Put the official zip at:
    _devenv/woocommerce-11.0.1.zip
Get it from:
    https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip"
fi

ok "WordPress zip:  $(basename "$WP_ZIP") ($(du -h "$WP_ZIP" | cut -f1))"
ok "WooCommerce zip: $(basename "$WC_ZIP") ($(du -h "$WC_ZIP" | cut -f1))"

# ---------------------------------------------------------------------------
# 2. Validate the zips before doing any work, so a bad upload fails clearly
# ---------------------------------------------------------------------------
info "validating zip contents"
python3 - "$WP_ZIP" "$WC_ZIP" <<'PY' || die "zip validation failed (see above)"
import re, sys, zipfile

wp_path, wc_path = sys.argv[1], sys.argv[2]
problems = []

def check(path, label):
    try:
        return zipfile.ZipFile(path)
    except Exception as e:
        problems.append(f"{label}: not a readable zip ({e})")
        return None

wp = check(wp_path, "WordPress")
wc = check(wc_path, "WooCommerce")

if wp:
    names = wp.namelist()
    ver = next((n for n in names if n.endswith("wp-includes/version.php")), None)
    if not ver:
        problems.append("WordPress: no wp-includes/version.php inside — is this really a WordPress zip?")
    else:
        src = wp.read(ver).decode("utf8", "replace")
        m = re.search(r"\$wp_version\s*=\s*'([^']+)'", src)
        nested = ver.split("wp-includes/")[0]
        print(f"  WordPress {m.group(1) if m else '?'}"
              + (f" (nested under '{nested}')" if nested else " (files at zip root)"))

if wc:
    names = wc.namelist()
    main = next((n for n in names if re.fullmatch(r"[^/]+/woocommerce\.php", n)), None)
    if not main:
        problems.append("WooCommerce: no <folder>/woocommerce.php inside — is this really the WooCommerce plugin zip?")
    else:
        src = wc.read(main).decode("utf8", "replace")
        v  = re.search(r"^\s*\*\s*Version:\s*(.+)$", src, re.M)
        wpr= re.search(r"^\s*\*\s*Requires at least:\s*(.+)$", src, re.M)
        print(f"  WooCommerce {v.group(1).strip() if v else '?'}"
              f" (requires WP {wpr.group(1).strip() if wpr else '?'})")
        # The built plugin must contain its vendor autoloader; a git export will not.
        if not any(n.endswith("vendor/autoload.php") for n in names):
            problems.append(
                "WooCommerce: vendor/autoload.php missing. This looks like a source checkout "
                "rather than the released plugin zip; it will not run.")

for p in problems:
    print("  PROBLEM: " + p, file=sys.stderr)
sys.exit(1 if problems else 0)
PY
ok "zips look correct"

# ---------------------------------------------------------------------------
# 3. Node tooling (Playground CLI + php-wasm CLI for the unit tests)
# ---------------------------------------------------------------------------
mkdir -p "$WORK"
cd "$WORK"

if [ ! -f "$WORK/node_modules/@wp-playground/cli/wp-playground.js" ] || [ "$FORCE" = "1" ]; then
	info "installing Playground CLI + php-wasm (npm; this is the only network dependency)"
	[ -f package.json ] || echo '{"name":"igbz-devenv","private":true}' > package.json
	npm install --no-audit --no-fund --loglevel=error \
		"@wp-playground/cli@$PLAYGROUND_VERSION" \
		"@php-wasm/cli@$PHPWASM_VERSION" \
		|| die "npm install failed — is registry.npmjs.org reachable?"
	ok "node tooling installed"
else
	ok "node tooling already present (use --force to reinstall)"
fi

# ---------------------------------------------------------------------------
# 4. Extract WooCommerce
# ---------------------------------------------------------------------------
WC_DIR="$WORK/woocommerce"
WC_STAMP="$WORK/.woocommerce.stamp"
WC_ID="$(basename "$WC_ZIP")-$(stat -c %s "$WC_ZIP")"

if [ ! -f "$WC_STAMP" ] || [ "$(cat "$WC_STAMP")" != "$WC_ID" ] || [ "$FORCE" = "1" ]; then
	info "extracting WooCommerce"
	rm -rf "$WC_DIR" "$WORK/.wc-tmp"
	mkdir -p "$WORK/.wc-tmp"
	python3 -c "import sys,zipfile; zipfile.ZipFile(sys.argv[1]).extractall(sys.argv[2])" \
		"$WC_ZIP" "$WORK/.wc-tmp"
	# The zip contains a single top-level folder; that folder is the plugin.
	inner="$(find "$WORK/.wc-tmp" -mindepth 2 -maxdepth 2 -name woocommerce.php -printf '%h\n' | head -1)"
	[ -n "$inner" ] || die "could not locate woocommerce.php inside the zip"
	mv "$inner" "$WC_DIR"
	rm -rf "$WORK/.wc-tmp"
	echo "$WC_ID" > "$WC_STAMP"
	ok "WooCommerce extracted ($(find "$WC_DIR" -type f | wc -l) files)"
else
	ok "WooCommerce already extracted"
fi

# ---------------------------------------------------------------------------
# 5. Publish the WordPress zip for the local HTTP server
# ---------------------------------------------------------------------------
# The Playground CLI's --wp flag accepts an http(s) URL, and that code path runs *before* its
# blocked api.wordpress.org lookup. run.sh serves this directory and points --wp at it.
info "publishing the WordPress zip for the local server"
mkdir -p "$WORK/serve"
find "$WORK/serve" -name 'wordpress-*.zip' -delete
cp "$WP_ZIP" "$WORK/serve/$(basename "$WP_ZIP")"
echo "$(basename "$WP_ZIP")" > "$WORK/.wp-zip-name"
ok "served as $(basename "$WP_ZIP")"

# ---------------------------------------------------------------------------
# 6. mu-plugins used by the harness
# ---------------------------------------------------------------------------
info "writing harness mu-plugins"
mkdir -p "$WORK/mu"

cat > "$WORK/mu/000-activate.php" <<'PHP'
<?php
/**
 * Harness only. Force-activate WooCommerce and the plugin under test, in that order, so
 * WooCommerce is fully loaded before igbz-suite boots.
 */
add_action( 'plugins_loaded', function () {
	$want = [ 'woocommerce/woocommerce.php', 'igbz-suite/igbz-suite.php' ];
	$have = (array) get_option( 'active_plugins', [] );
	$new  = array_values( array_unique( array_merge( $want, array_diff( $have, $want ) ) ) );
	if ( $new !== $have ) {
		update_option( 'active_plugins', $new );
	}
}, 0 );
PHP

cat > "$WORK/mu/010-enable-modules.php" <<'PHP'
<?php
/**
 * Harness only. Turn on all four modules once, so every admin screen and REST route exists.
 */
add_action( 'igbz_booted', function () {
	if ( get_option( 'igbz_devenv_modules_on' ) ) { return; }
	update_option( 'igbz_enabled_modules', [ 'multitenant', 'instagram', 'hub', 'rest_api' ] );
	update_option( 'igbz_devenv_modules_on', 1 );
} );
PHP

cat > "$WORK/mu/020-healthcheck.php" <<'PHP'
<?php
/**
 * Harness only. GET /?igbz_health=1 -> JSON summary of the environment.
 */
add_action( 'init', function () {
	if ( ! isset( $_GET['igbz_health'] ) ) { return; }
	global $wp_version, $wpdb;

	$out = [
		'wp'          => $wp_version,
		'php'         => PHP_VERSION,
		'wc_active'   => class_exists( 'WooCommerce' ),
		'wc_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
		'igbz_loaded' => function_exists( 'igbz' ),
		'active'      => (array) get_option( 'active_plugins', [] ),
	];

	if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
		$out['hpos'] = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}

	if ( function_exists( 'igbz' ) ) {
		$tables = 0;
		foreach ( \IGBZ\Suite\Support\Schema::tables() as $t ) {
			$full = $wpdb->prefix . 'igbz_' . $t;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ) { $tables++; }
		}
		$out['igbz_tables'] = $tables . '/' . count( \IGBZ\Suite\Support\Schema::tables() );
		$out['modules']     = get_option( 'igbz_enabled_modules' );
	}

	wp_send_json( $out );
}, 99 );
PHP

ok "3 mu-plugins written"

# ---------------------------------------------------------------------------
# Done
# ---------------------------------------------------------------------------
cat <<EOF

$(printf '\033[32mEnvironment ready.\033[0m')

  Start the site :  bash _devenv/run.sh
  Run the tests  :  bash _devenv/test.sh

EOF
