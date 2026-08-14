# Agent brief — read this first

Hand-off notes for an agent picking this repository up in a fresh session. Everything here was
learned the hard way; following it will save hours.

---

## 1. What this project is

`igbz-suite/` is a **single WordPress plugin containing four toggleable modules**, a faithful port
of the IGBZ product from nopCommerce to WordPress + WooCommerce.

The one intentional functional difference from the nopCommerce original: the **Instagram Graph API
is not used**. It is replaced by two services:

- **Manus** — automated Instagram workflow: niche/trend research, graphic design (incl. Canva),
  reels and short video, caption writing, hashtag selection, and auto-publishing/scheduling of
  posts, stories and reels at peak-engagement hours.
- **ManyChat** — DM funnels of the "comment X and I'll DM you the link" kind. Two integration
  paths, both implemented: a **webhook** (real-time, preferred) and the **ManyChat API** (`GET`
  subscriber profile, recent messages, custom fields).

The Instagram gateway sits behind an adapter interface so Graph API can be added back later, but
**no direct Graph calls should be implemented now**.

### Standing constraints

- **The user writes in Persian. Reply in Persian.**
- **Never modify the `IGBZ-NopCommerce` project.** It was a read-only review, and that review is
  finished (`REVIEW-IGBZ-NopCommerce.md`).
- Tenancy is **single-site with `tenant_id` columns**. Not WordPress Multisite.
- One plugin, four modules — **not** four separate plugins.
- The deliverable is **complete and installable**, never a skeleton.

---

## 2. Getting a working environment (start here)

```bash
bash _devenv/setup.sh     # build (~30s if npm is warm)
bash _devenv/run.sh       # site on http://127.0.0.1:9400, auto-logged-in as admin
bash _devenv/test.sh      # 391 assertions + syntax check on 115 files
```

`_devenv/` contains committed WordPress and WooCommerce zips precisely because **`/tmp` is wiped
between sessions** (this has happened three times) and **wordpress.org is unreachable** from the
sandbox. Do not try to download WordPress or WooCommerce from wordpress.org; it will fail.

Health check while the site runs:

```bash
curl -sL -c /tmp/j.txt -b /tmp/j.txt "http://127.0.0.1:9400/?igbz_health=1"
```

Admin pages need a shared cookie jar and `-L` (the auto-login issues a 302):

```bash
curl -sL -b /tmp/j.txt -c /tmp/j.txt "http://127.0.0.1:9400/wp-admin/admin.php?page=igbz"
```

Boot takes roughly 30–90 s. The debug log lives at
`<vfsroot>/wordpress/wp-content/debug.log`, where `<vfsroot>` is the
`/tmp/node-playground-cli-site-*` directory named in the startup output.

---

## 3. Network reality in this sandbox

| Reachable | Blocked |
| --- | --- |
| `github.com`, `api.github.com`, `codeload.github.com` | **all of `wordpress.org`** (downloads, api, plugins.svn, plugins.trac, playground) |
| `registry.npmjs.org` | `raw.githubusercontent.com`, `release-assets.githubusercontent.com` |
| | jsdelivr, unpkg, esm.sh, statically, ghproxy, raw.githack |
| | gitlab.com, bitbucket.org, packagist.org, deb.debian.org |

Consequences worth internalising:

- **No container runtime, and none installable** (`apt-get` cannot reach Debian; no `/dev/kvm`).
  `@wordpress/env` is a Docker wrapper, so `wp-env` can **never** work here. Do not retry.
- **`php` is not installed as a CLI.** PHP runs through `@php-wasm/cli` under node — that is what
  `_devenv/test.sh` uses.
- `gh release download` fails because the release-asset CDN is blocked, even though `gh api` works.
- If you ever need WooCommerce again from the network, the **only** working route is the
  wordpress.org zip mirror on GitHub, via codeload:
  ```
  gh api "repos/WordPressBugBounty/plugins-woocommerce/commits?path=woocommerce/woocommerce.php&per_page=100" --paginate
  curl -sL -o woo.tar.gz "https://codeload.github.com/WordPressBugBounty/plugins-woocommerce/tar.gz/<sha>"
  ```
  That mirror has no tags; versions live in commit messages. 9.4.2 = `87199f6dbb5e9e477689192fb045a2b9f39fcde6`.
  Prefer the committed zip in `_devenv/`.

---

## 4. Architecture cheat-sheet

Bootstrap `igbz-suite/igbz-suite.php` → `igbz()` → `\IGBZ\Suite\Support\Plugin`. PSR-4 autoloader,
**no Composer**.

**Boot flow.** `boot()` binds core services and hooks `plugins_loaded`@5 → `on_plugins_loaded()`,
which returns early with an admin notice if WooCommerce is absent, then runs
`Activator::maybe_upgrade()`, registers enabled modules, then settings/status pages and cron.

> **Ordering trap:** anything needed *during activation* must be registered at file-load time, not
> inside `on_plugins_loaded()`. This is why `Cron::register_schedules()` is called at load time.

**Modules** (`Modules::all()`): `multitenant`, `instagram`, `hub`, `rest_api`. Default enabled:
`multitenant`. Option: `igbz_enabled_modules`.

**Container ids**
- core: `settings, logger, db, http, tenancy`
- multitenant: `tenants, wallet, plans, bnpl.providers, bnpl, affiliate, lms, payments, otp, marketplace`
- instagram: `ig.prompts, ig.manus_client, ig.manus, ig.scheduler, ig.insights, ig.manychat, ig.subscribers, ig.funnels`
- hub: `hub.stats, hub.directory, hub.vip, hub.domains, hub.blocks, hub.signup`
- rest_api: `api.tokens, api.auth, api.devices, api.google_auth, api.push, api.notifications`

**Admin screens** (top-level `igbz`): `igbz`, `igbz-settings`, `igbz-tenants`, `igbz-wallet`,
`igbz-plans`, `igbz-bnpl`, `igbz-affiliate`, `igbz-courses`, `igbz-payments`, `igbz-ig-accounts`,
`igbz-ig-content`, `igbz-ig-funnels`, `igbz-ig-subscribers`, `igbz-ig-insights`, `igbz-hub`,
`igbz-mobile-api`.

**REST**: `igbz/v1` (48 routes) and `igbz-hub/v1` (15 routes).

**Schema**: 32 tables in `src/Support/Schema.php`. All carry `tenant_id` except `tenants`,
`tenant_domains`, `tenant_members`, `plans`, `logs`, `jobs`, `lesson_progress`. Product/order
tenant scoping uses the meta key `_igbz_tenant_id`.

**Payments**: gateways `igbz_wallet`, `igbz_bnpl`, `igbz_zarinpal`, `igbz_idpay`, `igbz_nextpay`,
`igbz_payir`. `Money::to_rial/from_rial` handles the Toman/Rial factor
(`payments.currency_multiplier`, default 10).

---

## 5. Rules that must not be regressed

1. **Always write MySQL SQL.** `$wpdb` speaks MySQL on both engines and the SQLite drop-in
   translates. Only `SELECT … FOR UPDATE` and `GET_LOCK`/`RELEASE_LOCK` need `Db::is_sqlite()`
   branching.
2. **Always pass an explicit `$format` to `$wpdb->insert/update/delete`.** Otherwise core guesses
   from the *column name* via `wpdb::$field_types` and silently forces `post_id`, `user_id`, `ID`,
   `count`, `parent`, `active`, `public`, `deleted`, `spam` to `%d` **on any table**. `Db::formats()`
   derives formats from PHP types; `SchemaTest::assert_no_unsafe_core_column_names()` guards new
   columns. This is a real bug that was already fixed once — `ig_funnels.post_id` holds Instagram
   media ids, which are strings.
3. **dbDelta needs two spaces** in `PRIMARY KEY  (`.
4. Secrets are encrypted at rest. `Settings::set_many()` skips values equal to `Crypto::MASK` or
   `''`, so a masked field round-trips without wiping the stored secret.

---

## 6. Traps that cost time before

- **A 403 "Sorry, you are not allowed to access this page" from `admin.php?page=…` usually means
  the page was never registered** — check module gating before suspecting capabilities.
- The local `.git` has silently lost commits twice when `/tmp` was wiped. **`origin` is the source
  of truth.** Recover with `git fetch origin <branch>` then `git reset --soft <sha>`, and if the
  index looks wrong afterwards, plain `git reset` rebuilds it from HEAD without touching files.
  Verify remote state with `git ls-remote origin <branch>` — `git fetch` does not create a
  remote-tracking ref here.
- Anonymous requests need their **own** cookie jar; the auto-login 302s the first cookieless request.
- `Db::wpdb()` is typed `: \wpdb`, so a test double must literally be `class wpdb`.
- `TestCase::assert_contains` is `(needle, haystack, message)`.
- The lint harness must use `token_get_all($src, TOKEN_PARSE)` — never `eval`.
- `ReflectionMethod::setAccessible()` is deprecated in PHP 8.5; just call `invoke()`.
- Services read settings through `igbz()->settings()`, so tests must call
  `igbz_test_reset_settings()` and double any new WP functions in `tests/bootstrap.php`.
- Heredocs containing non-ASCII can corrupt generated files; write such files with the file tools.
- One error in the debug log during order payment is **WooCommerce core's own** HPOS refund lookup
  (`OrdersTableQuery`, `LIMIT 0, 18446744073709551615`) which the SQLite translator cannot parse.
  It is not caused by this plugin and does not occur on MySQL.

---

## 7. Verified behaviour (regression baseline)

Confirmed live on **WP 6.5.5 / WC 9.4.2 / PHP 8.2.32** *and re-confirmed on* **WP 7.0.4 / WC 11.0.1
/ PHP 8.3.32** (SQLite in both cases). Moving between the two is purely a matter of swapping the
zips in `_devenv/` and re-running `setup.sh --force`; no plugin code differs between them.

- 400 assertions in 11 test cases; 116 files lint clean.
- 16/16 admin screens return 200 with no notices; 32/32 tables; 3 cron hooks scheduled.
- All six payment gateways register with WooCommerce and their settings screens render.
- Paying a real order with the wallet gateway debits exactly the order total, moves the order to
  `processing`, sets the transaction id, and credits 2% cashback
  (`wallet.order_cashback_percent`), with a correct running balance in `wallet_ledger`.
- WooCommerce's own admin screens (Home, Settings, Status, Products, Orders) stay clean.
- ManyChat funnel, end to end: wrong/missing token → 401; valid token → 200 with the v2 envelope;
  idempotent per `comment_id`; `per_user_limit` enforced (a capped user receives only the
  "already received" message).

**ManyChat webhook contract**: `POST /?rest_route=/igbz/v1/manychat/comment`, auth via
`Authorization: Bearer <token>`, `?token=`, or `X-IGBZ-Token`. Body keys:
`text|comment_text|last_input_text`, `subscriber_id|id`, `comment_id`, `post_id|media_id`,
`username|ig_username`.

**The token is the identity (DB v6).** It is per account, stored in
`ig_accounts.manychat_webhook_token` / `manus_webhook_token`, and the tenant is read from the
matched row — a `tenant_id` in the request body is ignored. Before this, one global token plus a
body-supplied `tenant_id` let any authenticated caller fire another tenant's funnels and spend
their coupons and wallet credit.

**Credentials are per account, not per install.** `ig_accounts` carries `manus_api_key` /
`manychat_api_key` (encrypted with `Crypto`) and a `credential_mode`:

- `own` — the account's own keys, unlimited. Never falls back to the shared key.
- `trial` — borrows the operator's `manus.api_key` / `manychat.api_key`, metered by
  `trial.task_quota` (**default 1**, `0` = unlimited) and `trial.days` (default 14). A closed trial
  returns an empty key rather than falling through.

This is forced by the products themselves: a ManyChat API key is scoped by ManyChat to a *single
page*, so one shared key can only ever drive one Instagram account. `AccountCredentials` is the
only place that resolves a key or counts trial usage, so the quota cannot be bypassed.

**The trial is one request.** The quota defaults to a single task: the account sends one thing,
sees the result, and then must bring its own keys. Three consequences that are easy to break:

1. **Claim before calling, never count after.** With a quota of one, the gap between "is the trial
   open?" and "spend it" is exactly wide enough for two cron ticks to both pass. Quota is claimed
   by `AccountCredentials::claim_trial_task()`, whose `WHERE … AND trial_tasks_used < %d` lets the
   database pick the winner; the loser sees zero affected rows and gets `false`. There is no
   `consume_trial_task()` any more — do not reintroduce a check-then-increment pair.
2. **Spending the last task closes the trial immediately**, by stamping `trial_expires_at` with the
   current time, so every read path agrees without re-deriving "used up". Because of that,
   `trial_blocked_reason()` checks *exhausted before expired* — otherwise a used-up trial would
   claim it ran out of time.
3. **A refused provider call is refunded** via `release_trial_task()`, which decrements and reopens
   the window; a network error must not cost a tenant their only free request.

Fair-share scheduling lives in `ContentScheduler::fair_share()` — still needed, because `tick()`
runs once site-wide per cron with a shared `BATCH` ordered by id, so one tenant queueing hundreds
of drafts would otherwise own every tick regardless of whose API key pays for it.

`ContentScheduler::per_account_cap( $account )` is **per account, not global**. An `own` account
buys its own Manus capacity, so the operator has no business throttling it: its cap is
`manus.account_concurrency` only when that is set above 0, otherwise half the batch. A `trial`
account is capped by what is left of its quota. `manus.account_concurrency` now defaults to `0`
and the `igbz_ig_account_concurrency` filter receives `$account` as a second argument.

**Never call `__()` on a code path that can run before `init`.** WordPress 6.7+ answers with a
`_load_textdomain_just_in_time` doing-it-wrong notice. Two such paths existed and were fixed by
guarding on `did_action( 'init' )`: `Cron::add_schedules()` (the `cron_schedules` filter fires
during `plugins_loaded` whenever another plugin — Jetpack's `Nonce_Handler`, for one — schedules an
event) and `Activator::add_roles()` / `seed_defaults()` (reached from `maybe_upgrade()` on
`plugins_loaded`). Both persist their strings, so storing the English original is correct anyway.
`CronScheduleTest` guards the regression.

---

## 8. Git

Work happens on the session branch; push only to that branch. Never rewrite `main`.
Keep generated artifacts out of git — `_devenv/.work/` is ignored, and the two zips in `_devenv/`
are the deliberate exception to the `*.zip` ignore rule.
