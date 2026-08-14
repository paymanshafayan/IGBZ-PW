# `_devenv/` — offline test environment

This directory exists for one reason: **the sandbox the agent runs in cannot reach
wordpress.org**, and its `/tmp` is wiped between sessions. Everything needed to boot a real
WordPress + WooCommerce site is therefore kept here, in the repository, where it survives.

Nothing in this directory ships with the plugin. It is developer tooling only.

## What goes here

Two zip files, committed to git:

| File | What it is | Where to get it |
| --- | --- | --- |
| `wordpress-6.5.5.zip` | WordPress core, official zip | <https://wordpress.org/wordpress-6.5.5.zip> |
| `woocommerce-9.4.2.zip` | WooCommerce plugin, official zip | <https://downloads.wordpress.org/plugin/woocommerce.9.4.2.zip> |

The exact filenames do not matter. `setup.sh` globs for `wordpress-*.zip` and
`woocommerce-*.zip` and picks the newest match, so `wordpress-6.5.zip` or
`woocommerce-9.4.2-fa_IR.zip` work just as well.

### Zip layout

Both must be the **official wordpress.org zips**, unmodified:

- WordPress: files at the root of the zip (`wp-includes/`, `wp-admin/`, `index.php`, …) —
  *not* nested inside a `wordpress/` folder. Both layouts are handled, but the official
  wordpress.org core zip nests everything under `wordpress/`, and `setup.sh` unwraps it.
- WooCommerce: everything under a single top-level `woocommerce/` folder.

`setup.sh` validates both and fails loudly with a clear message if the layout is wrong, so a
bad upload is caught immediately instead of producing a confusing boot failure.

## Why these versions

WooCommerce 9.4.2 declares `Requires at least: 6.5`, which matches WordPress 6.5.5 exactly, and
the plugin header already declares `WC tested up to: 9.4`.

Newer WooCommerce (11.x) requires WordPress 6.9. That combination is fine to use — just drop in
the matching pair of zips and `setup.sh` will use them.

## Usage

```bash
bash _devenv/setup.sh          # build the environment (~1-2 min the first time)
bash _devenv/run.sh            # start the site on http://127.0.0.1:9400
bash _devenv/test.sh           # unit tests + lint
```

`setup.sh` is idempotent and safe to re-run. It never writes inside the repository except to
this directory, and the build output it creates (`_devenv/.work/`) is git-ignored.

If the zips are missing, `setup.sh` says exactly which file it wanted and where to put it, then
falls back to downloading from the network if that happens to be reachable.

## How it works

The Playground CLI normally downloads WordPress from wordpress.org, which is blocked here.
However, its `--wp` flag accepts an **http(s) URL**, and that branch is evaluated *before* the
blocked `api.wordpress.org` version lookup. So `setup.sh` starts a tiny local HTTP server on
port 8799 that serves `wordpress-*.zip`, and points `--wp` at it.

This replaces the previous approach, which monkey-patched `fetch` inside the CLI. No patching
is needed any more.

WooCommerce needs no server: it is simply extracted and bind-mounted into the site as
`wp-content/plugins/woocommerce`.
