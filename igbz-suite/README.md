# IGBZ Suite — WordPress + WooCommerce

A faithful port of the IGBZ product from nopCommerce to WordPress and WooCommerce.

The nopCommerce original shipped as four separate plugins. This port ships as **one plugin with
four toggleable modules**, so an operator installs once and turns on only what the site needs.

| Module | Id | Replaces (nop plugin) | What it does |
| --- | --- | --- | --- |
| Multi-Tenant Stores | `multitenant` | `IGBZ.MultiTenant` | Tenants, wallet, subscription plans, BNPL, affiliate, LMS, OTP login, marketplace feeds, Iranian payment gateways |
| Instagram Automation | `instagram` | `IGBZ.Instagram` | Content generation and auto-publishing via **Manus**, comment-to-DM funnels via **ManyChat** |
| Master Site Hub | `hub` | `IGBZ.Hub` | Public store directory, tenant signup, domain verification, VIP links, content blocks |
| Mobile REST API | `rest_api` | `IGBZ.MobileApi` | JWT auth, catalog, account, store-admin endpoints, FCM push, device registry |

### The one functional change from the nopCommerce version

The Instagram **Graph API** integration has been removed and replaced by two services:

* **Manus** — niche research and trend discovery, graphic design (including Canva), reels and short
  video, caption writing, hashtag selection, and auto-publishing/scheduling of posts, stories and
  reels at the page's peak-engagement hours. No manual download/upload step.
* **ManyChat** — DM funnels ("comment the word X and I'll DM you the link"), supported over both a
  real-time **webhook** and the **ManyChat API**.

Everything Instagram-facing sits behind `Contracts\PublisherInterface` and
`Contracts\ContentGeneratorInterface`, so a Graph API adapter can be dropped back in later without
touching the rest of the plugin. No direct Graph calls exist in this codebase.

---

## Requirements

| | |
| --- | --- |
| WordPress | 6.3 or newer |
| WooCommerce | 8.0 or newer (HPOS and cart/checkout blocks are both declared compatible) |
| PHP | 8.1 or newer |
| MySQL / MariaDB | 5.7+ / 10.3+ (SQLite also works — see below) |
| PHP extensions | `openssl` (required — settings are encrypted at rest), `mbstring`, `json`, `hash` |
| Cron | WordPress cron must run. A real system cron is strongly recommended (see below). |

No Composer install is needed. The plugin ships its own PSR-4 autoloader.

### SQLite / WordPress Playground

The plugin detects SQLite (WordPress Playground, or the `sqlite-database-integration` plugin) and
adapts the two pieces of SQL that are not portable:

* `Db::upsert()` emits `INSERT … ON DUPLICATE KEY UPDATE` on MySQL and
  `INSERT … ON CONFLICT … DO UPDATE` on SQLite, mapping `GREATEST` onto SQLite's multi-argument
  `MAX`. Used by the wallet balance cache, Instagram insights and LMS lesson progress.
* `Db::lock()` uses `GET_LOCK` on MySQL. SQLite is single-writer, so locking is a no-op there.

SQLite is fine for demos and review. **Use MySQL or MariaDB in production** — the concurrency
guarantees the wallet relies on are only meaningful there.

You can boot a disposable demo with no server at all:

[Launch in WordPress Playground](https://playground.wordpress.net/?blueprint-url=https://raw.githubusercontent.com/paymanshafayan/IGBZ-PW/refs/heads/arena/019ffbb1-igbz-pw/_playground/blueprint.json)

Outbound HTTP is proxied there, so payment gateways and the Manus/ManyChat calls will not reach
real endpoints.

---

## Installation

1. Copy the `igbz-suite` directory into `wp-content/plugins/`, or zip it and upload it through
   **Plugins → Add New → Upload Plugin**.
2. Activate **IGBZ Suite**. On activation the plugin creates its 32 database tables, registers its
   roles and capabilities, schedules its cron events and seeds default settings.
3. Go to **IGBZ → Settings → Modules** and enable the modules you need. Only *Multi-Tenant Stores*
   is on by default.
4. Work through the settings tabs for the modules you enabled (see *Configuration* below).
5. Check **IGBZ → Status**. Every module reports its own health rows there; a red row tells you
   exactly which setting is missing.

### Encryption key (do this before entering any credentials)

API keys and secrets are encrypted with AES-256-GCM before they are written to the options table.
The encryption key is derived from `IGBZ_ENCRYPTION_KEY`, `AUTH_KEY` and `SECURE_AUTH_SALT`.

Add a dedicated key to `wp-config.php`:

```php
define( 'IGBZ_ENCRYPTION_KEY', 'a-long-random-string-you-generate-once' );
```

If you skip this, the derivation still works but falls back to the WordPress salts alone — which
means **rotating your WordPress salts will make every stored credential unreadable** and you will
have to re-enter them. Set `IGBZ_ENCRYPTION_KEY` first, and back it up with your database.

### Real cron

WordPress' pseudo-cron only fires when someone visits the site, which is not good enough for
payment reconciliation, BNPL reminders or scheduled Instagram publishing. Disable it and use a
system cron:

```php
// wp-config.php
define( 'DISABLE_WP_CRON', true );
```

```cron
* * * * * cd /path/to/wordpress && wp cron event run --due-now > /dev/null 2>&1
```

The plugin registers three recurrences: `igbz_cron_five_minutes`, `igbz_cron_hourly` and
`igbz_cron_daily`.

---

## Configuration

Settings live in a single `igbz_settings` option, keyed with dotted names. The admin screen is
**IGBZ → Settings**, split into tabs. General, Modules and Advanced are always visible; the rest
appear only when their module is enabled.

Secret fields are shown masked (`••••••••••••`). Re-submitting the form without touching a masked
field preserves the stored value, so you never have to re-type a key to change something else on
the same tab.

### General

| Key | Default | Notes |
| --- | --- | --- |
| `general.default_currency` | `IRT` | `IRT` (Toman) or `IRR` (Rial). Drives the gateway conversion — see *Money*. |
| `general.tenant_resolution` | `domain` | `domain`, `path` or `single`. |
| `general.tenant_path_base` | `store` | Used when resolution is `path` → `example.com/store/acme`. |
| `general.default_tenant_id` | `0` | Tenant used when nothing else resolves. |
| `general.allow_self_signup` | `true` | Lets visitors request a store from the hub. |
| `general.auto_approve_tenants` | `false` | Leave off unless signup is paid and verified. |

### Payments

Four Iranian PSPs are implemented as adapters: **Zarinpal**, **IDPay**, **NextPay** and **Pay.ir**.
Each is registered with WooCommerce as its own gateway but only appears at checkout when it is both
enabled and configured.

| Key | Notes |
| --- | --- |
| `payments.default_gateway` | Used by wallet top-ups and subscription renewals. Falls back to the first enabled and configured gateway. |
| `payments.currency_multiplier` | Default `10`. See below. |
| `payments.zarinpal.enabled` / `.merchant_id` / `.sandbox` | Merchant id is the 36-character UUID. |
| `payments.idpay.enabled` / `.api_key` / `.sandbox` | Sandbox sends `X-SANDBOX: 1`. |
| `payments.nextpay.enabled` / `.api_key` | The api key is a UUID. |
| `payments.payir.enabled` / `.api_key` / `.sandbox` | Sandbox sends the literal key `test`; no real key needed. Pay.ir enforces a 10,000 Rial minimum. |

**Money.** Every Iranian PSP settles in **Rial**, while nearly every Iranian shop prices in
**Toman**. `Payments\Money` centralises the conversion so a wrong factor cannot overcharge a
customer tenfold:

* Store currency `IRT`/`TOMAN`/`TMN` → amounts are multiplied by `payments.currency_multiplier`
  (default 10) on the way to the gateway.
* Store currency `IRR`/`RIAL` → factor forced to 1; never converted.
* Any non-Iranian currency → factor forced to 1.
* A multiplier of zero or less falls back to 10 rather than zeroing the charge.

**Callback URL.** Each gateway is handed
`https://your-site/?igbz_payment_callback=<gateway-id>&payment_id=<id>`. Register that pattern in
the PSP dashboard if it asks for a fixed return URL. Every adapter re-checks the verified amount
against the stored amount and rejects a mismatch, and a repeated callback resolves to
`already_verified` instead of crediting twice.

### Wallet, Plans, BNPL, Affiliate, LMS, OTP, Marketplace

These tabs mirror the nopCommerce settings one for one; the defaults are seeded on activation and
every field is documented inline on the settings screen. Highlights:

* **Wallet** — ledger with a unique idempotency key per `(tenant, user, reason, reference)`, so a
  retried job cannot double-credit. Order cashback percentage, top-up bounds, and a
  pay-with-wallet checkout gateway.
* **BNPL** — internal provider plus adapter slots for SnappPay and Tara. `bnpl.fee_percent` is
  applied **only to the financed remainder** (`amount − down payment`), and the instalment schedule
  is rounded so it sums exactly to the total.
* **LMS** — courses, lessons, enrolments, progress, quizzes, time-limited signed video links
  (`lms.video_link_ttl`, HMAC-signed with `lms.video_hmac_secret`).
* **OTP** — phone login with Kavenegar and SMS.ir providers, plus a `log` provider that writes the
  code to the IGBZ log for development.
* **Marketplace** — Torob, Emalls and Google Merchant product feeds.

### Manus (Instagram content)

| Key | Notes |
| --- | --- |
| `manus.api_key` | Sent as the `x-manus-api-key` header. Required. |
| `manus.project_id` | Optional; groups tasks in one Manus project. |
| `manus.agent_profile` | `manus-1.6` (default), `manus-1.6-lite` or `manus-1.6-max`. |
| `manus.locale` / `manus.content_language` | `fa-IR` and `Persian (Farsi)` by default. |
| `manus.use_canva` | Requests the Canva connector for graphic tasks. |
| `manus.auto_generate` / `manus.auto_schedule` / `manus.collect_insights` | Cron-driven automation switches. |
| `manus.default_peak_hours` | `12:00,18:30,21:00`. Used until real insights exist. |
| `manus.min_gap_minutes` | `90`. Minimum spacing between two scheduled posts. |
| `manus.poll_interval` | `300` seconds. Only used when webhooks are not configured. |
| `manus.webhook_token` | Shared secret for the Manus callback (see below). |
| `canva.api_key` | Optional, only if you want Canva driven directly rather than through Manus. |

Manus tasks are **asynchronous**. The plugin will poll `task.detail` on the five-minute cron, but a
webhook is much better:

```
POST https://your-site/wp-json/igbz/v1/manus/task?token=<manus.webhook_token>
```

The endpoint accepts the token as `?token=`, an `X-IGBZ-Token` header, or an HMAC of the raw body in
`X-Manus-Signature` (`hash_hmac('sha256', body, token)`) — configure whichever Manus offers you.
It handles `task_created`, `task_progress` and `task_stopped`, and pulls `attachments[]` into the
content record.

**Scheduling.** `ContentScheduler::next_peak_slot()` picks a publish time in this order: the
account's explicit `peak_hours`, then hours learned from the `ig_insights`
`engagement_by_hour` data, then `manus.default_peak_hours` — always respecting
`manus.min_gap_minutes`. Set each account's timezone on **IGBZ → Instagram → Accounts**; slots are
computed in the account's own timezone, not the server's.

### ManyChat (DM funnels)

Both developer integration paths from the ManyChat docs are supported.

**1. Webhook (preferred, real-time).** ManyChat has no generic inbound-webhook subscription API, so
the real-time path is a flow's **External Request** action (a ManyChat Pro feature). Point it at one
of:

| Endpoint | Purpose |
| --- | --- |
| `POST /wp-json/igbz/v1/manychat/comment` | New Comment / keyword events. The main funnel entry point. |
| `POST /wp-json/igbz/v1/manychat/event` | Any other Instagram interaction (story reply, DM keyword, mention). |
| `POST /wp-json/igbz/v1/manychat/subscriber` | Store/refresh a subscriber profile; returns the linked WordPress user, wallet balance and order count. |
| `GET  /wp-json/igbz/v1/manychat/ping` | Connectivity check. |

Authentication is a shared secret from `manychat.webhook_token`, accepted as `?token=`, an
`X-IGBZ-Token` header, or `Authorization: Bearer …`.

The comment endpoint accepts:

```json
{
  "subscriber_id": "1234567890",
  "comment_text": "LINK",
  "comment_id": "179…",
  "post_id": "178…",
  "timestamp": 1723459200,
  "ig_username": "customer",
  "ig_user_id": "178…",
  "first_name": "Sara",
  "last_name": "M",
  "account_id": 1,
  "tenant_id": 0
}
```

and replies with a ManyChat **Dynamic Content** envelope (`{"version":"v2","content":{…}}`)
carrying the message and a URL button, plus flat `igbz_link`, `igbz_coupon`, `igbz_message`,
`igbz_funnel` and `igbz_hit_id` fields so the flow can map them straight into custom fields.

> **ManyChat waits about 10 seconds for a response.** Anything slower is treated as a failure. With
> `manychat.async_reply` on (the default) the endpoint answers immediately and any slow work —
> issuing a unique coupon, generating a link — is finished on the next cron tick and pushed back to
> the subscriber with `setCustomField` + `sendFlow`. Leave this on unless you know your funnel does
> no slow work.

**2. ManyChat API (`GET` a subscriber's profile once a comment pulled them into a Flow).** Set
`manychat.api_key` (a Pro plan is required) and the plugin will call
`https://api.manychat.com/fb/` with `Authorization: Bearer …`. `Gateways\ManyChatClient` wraps
`subscriber/getInfo`, `findByName`, `findByCustomField`, `findBySystemField`, `getInfoByUserRef`,
`updateSubscriber`, `addTag`, `addTagByName`, `removeTag`, `setCustomField(ByName)`,
`sending/sendContent`, `sending/sendFlow`, and the `page/*` metadata endpoints (tags, custom fields,
bot fields, flows). Flow lists are cached because ManyChat rate-limits `getFlows` to 10 requests
per second against 100 for other page calls.

Other keys: `manychat.default_flow_ns` (flow to trigger when a funnel does not name one),
`manychat.link_field_name` / `manychat.coupon_field_name` (custom fields written by the async path),
`manychat.button_label`, `manychat.duplicate_message`.

**Funnels** are managed at **IGBZ → Instagram → Funnels**: keyword plus match mode
(`exact`, `contains`, `starts`, `regex`), an optional post id to scope it to one post, a target
(`url`, `product`, `coupon` or `flow`), per-user and total limits, an optional wallet credit, and a
date window.

### Master Site Hub

`hub.subdomain_base` and `hub.cname_target` drive the domain verification instructions shown to
tenants; `hub.vip_link_secret` signs time-limited VIP links (`hub.vip_link_ttl`, default 900s);
`hub.mother_origin` is the allowed CORS origin for the hub REST controller. Shortcodes:
`[igbz_store_directory]`, `[igbz_hub_grid]`, `[igbz_hub_stats]`, `[igbz_hub_blocks]`.

### Mobile REST API

`api.jwt_secret` signs HS256 tokens (`api.jwt_ttl`, default 1 hour) with rotating refresh tokens
(`api.refresh_ttl`, default 30 days) — a fix for the nop version's 30-day non-refreshable token.
Tokens carry a `jti` and are revoked on password reset and profile update, and per-device sessions
can be listed and revoked. Push uses FCM v1: set `api.fcm_project_id` and paste the service-account
JSON into `api.fcm_service_account`.

---

## Admin screens

Everything lives under one top-level **IGBZ** menu, and every screen is capability gated. (The
nopCommerce original never implemented `IAdminMenuPlugin`, so roughly 26 of its admin controllers
were reachable only by typing a URL; that is fixed here.)

| Screen | Slug | Module |
| --- | --- | --- |
| Status | `igbz` | always |
| Settings | `igbz-settings` | always |
| Tenants | `igbz-tenants` | multitenant |
| Wallet | `igbz-wallet` | multitenant |
| Plans | `igbz-plans` | multitenant |
| BNPL | `igbz-bnpl` | multitenant |
| Affiliate | `igbz-affiliate` | multitenant |
| Courses | `igbz-courses` | multitenant |
| Payments | `igbz-payments` | multitenant |
| IG Accounts | `igbz-ig-accounts` | instagram |
| IG Content | `igbz-ig-content` | instagram |
| IG Funnels | `igbz-ig-funnels` | instagram |
| IG Subscribers | `igbz-ig-subscribers` | instagram |
| IG Insights | `igbz-ig-insights` | instagram |
| Hub | `igbz-hub` | hub |
| Mobile API | `igbz-mobile-api` | rest_api |

---

## Storefront shortcodes

| Shortcode | Attributes |
| --- | --- |
| `[igbz_courses]` | `limit` (12), `level`, `columns` (3) |
| `[igbz_course]` | `slug` — falls back to `?igbz_course=<slug>` |
| `[igbz_plans]` | — |
| `[igbz_bnpl_calculator]` | — |
| `[igbz_wallet_balance]` | — |
| `[igbz_otp_login]` | — |

---

## REST API

Namespace `igbz/v1` (the hub controller uses `igbz-hub/v1`).

```
GET  /igbz/v1/auth/login-options
POST /igbz/v1/auth/otp/request        { phone }
POST /igbz/v1/auth/otp/verify         { phone, code, device_id? }
POST /igbz/v1/auth/password           { username, password, device_id? }
POST /igbz/v1/auth/refresh            { refresh_token, device_id? }
POST /igbz/v1/auth/logout
GET  /igbz/v1/auth/sessions
POST /igbz/v1/auth/sessions/revoke    { jti? | all }
GET  /igbz/v1/auth/me

GET  /igbz/v1/catalog/products
GET  /igbz/v1/catalog/products/<id>
GET  /igbz/v1/catalog/categories
GET  /igbz/v1/catalog/search-suggest

GET  /igbz/v1/account/profile
GET  /igbz/v1/account/orders
GET  /igbz/v1/account/orders/<id>
GET  /igbz/v1/account/wallet
POST /igbz/v1/account/wallet/topup
GET  /igbz/v1/account/instalments
POST /igbz/v1/account/instalments/<id>/pay
GET  /igbz/v1/account/courses
POST /igbz/v1/account/courses/progress
GET  /igbz/v1/account/affiliate
GET  /igbz/v1/account/payments

POST /igbz/v1/devices/register
POST /igbz/v1/devices/unregister
GET  /igbz/v1/devices
POST /igbz/v1/devices/test
POST /igbz/v1/notifications/send

GET  /igbz/v1/app/config
GET  /igbz/v1/app/resolve-store

GET  /igbz/v1/admin/summary
GET  /igbz/v1/admin/orders
GET  /igbz/v1/admin/customers
GET  /igbz/v1/admin/categories
GET  /igbz/v1/admin/categories/tree
GET  /igbz/v1/admin/domains
POST /igbz/v1/admin/domains/<id>/verify
POST /igbz/v1/admin/tenants/<id>/status
POST /igbz/v1/admin/vip-link

POST /igbz/v1/manychat/comment
POST /igbz/v1/manychat/event
POST /igbz/v1/manychat/subscriber
GET  /igbz/v1/manychat/ping
POST /igbz/v1/manus/task

GET  /igbz-hub/v1/stores
GET  /igbz-hub/v1/stores/<slug>
GET  /igbz-hub/v1/plans
GET  /igbz-hub/v1/landing
GET  /igbz-hub/v1/blocks
GET  /igbz-hub/v1/blocks/<page_key>
GET  /igbz-hub/v1/check-slug
POST /igbz-hub/v1/signup
POST /igbz-hub/v1/signup/verify-payment
```

Unlike the nop version, CORS is restricted to `hub.mother_origin` rather than `AllowAnyOrigin()`.

---

## Architecture

```
igbz-suite.php            bootstrap, constants, igbz() accessor
uninstall.php             drops data only when purge_on_uninstall is set
src/Support/              autoloader, container, settings, crypto, db, http,
                          logger, schema, capabilities, cron, activator, admin shell
src/Modules/MultiTenant/  tenants, wallet, plans, bnpl, affiliate, lms, otp,
                          marketplace, payments, admin pages, storefront
src/Modules/Instagram/    manus + manychat services, funnels, subscribers,
                          insights, scheduler, webhooks, admin pages
src/Modules/Hub/          directory, signup, domains, vip links, blocks, REST
src/Modules/RestApi/      jwt auth, controllers, fcm push, device registry
assets/                   css + js
languages/                igbz-suite.pot
tests/                    dependency-free test runner
```

`Support\Plugin` is a small singleton container. Core services have accessors —
`igbz()->settings()`, `igbz()->logger()`, `igbz()->db()`, `igbz()->http()`, `igbz()->tenancy()` —
and everything a module binds is reached with `igbz()->get( $id )`. Resolved services are
singletons; an unknown id throws.

| Module | Service ids |
| --- | --- |
| core | `settings`, `logger`, `db`, `http`, `tenancy` |
| multitenant | `tenants`, `wallet`, `plans`, `bnpl.providers`, `bnpl`, `affiliate`, `lms`, `payments`, `otp`, `marketplace` |
| instagram | `ig.prompts`, `ig.manus_client`, `ig.manus`, `ig.scheduler`, `ig.insights`, `ig.manychat`, `ig.subscribers`, `ig.funnels` |
| hub | `hub.stats`, `hub.directory`, `hub.vip`, `hub.domains`, `hub.blocks`, `hub.signup` |
| rest_api | `api.tokens`, `api.auth`, `api.devices`, `api.google_auth`, `api.push`, `api.notifications` |

A module's services only exist while that module is enabled, so guard cross-module calls with
`igbz()->has( 'wallet' )`.

**Tenancy** is single-site with a `tenant_id` column, not WordPress Multisite. All 32 tables carry
`tenant_id` except `tenants`, `tenant_domains`, `tenant_members`, `plans`, `jobs`, `logs`, and
`lesson_progress` (which inherits scope through `enrollment_id`). Products and orders are scoped
with the `_igbz_tenant_id` meta key, where `0` or absent means platform-shared.

### Extension points

Filters:

```php
igbz_register_payment_gateways   // add a PSP adapter
igbz_register_bnpl_providers     // add a BNPL provider
igbz_manus_prompt_*              // rewrite any Manus prompt
```

Actions:

```php
igbz_booted
igbz_tenant_created  igbz_tenant_updated  igbz_tenant_deleted
igbz_wallet_entry_created
igbz_payment_verified  igbz_payment_failed
igbz_subscription_started  igbz_subscription_renewed
igbz_subscription_cancelled  igbz_subscription_expired
igbz_bnpl_contract_created  igbz_bnpl_contract_declined
igbz_bnpl_contract_activated  igbz_bnpl_contract_cancelled
igbz_bnpl_contract_settled  igbz_bnpl_contract_defaulted
igbz_bnpl_installment_paid  igbz_bnpl_reminder_due
igbz_affiliate_enrolled  igbz_referral_converted
igbz_affiliate_commission_recorded
igbz_lms_enrolled  igbz_lms_course_completed  igbz_lms_quiz_submitted
igbz_otp_verified  igbz_otp_user_registered
igbz_manychat_event
```

### Roles and capabilities

Roles `igbz_tenant_owner`, `igbz_tenant_staff` and `igbz_instructor` are created on activation.
Capabilities: `igbz_manage_suite`, `igbz_manage_tenants`, `igbz_manage_own_tenant`,
`igbz_manage_wallet`, `igbz_manage_plans`, `igbz_manage_bnpl`, `igbz_manage_lms`,
`igbz_manage_affiliate`, `igbz_manage_instagram`, `igbz_manage_api`.

---

## Tests

The suite is dependency-free — no Composer, no PHPUnit — so it runs anywhere PHP does:

```bash
php igbz-suite/tests/run.php
```

`tests/bootstrap.php` provides doubles for the WordPress functions the tested classes touch, plus a
fake `$wpdb`. Coverage is deliberately aimed at the code where a bug costs money or leaks data:
`Crypto`, `Settings` (encryption at rest and mask handling), `Schema` (tenant scoping and dbDelta
formatting), `Jwt`, the BNPL instalment schedule, `Money`, the PSP adapter contracts, the module
registry and the Manus prompt builder.

---

## Uninstall

Deactivating leaves everything in place. Deleting the plugin drops all IGBZ tables, options, user
meta, cron events and roles **only if** you first tick *Remove all data on uninstall* on the
Advanced settings tab. Otherwise the data survives a delete and reinstall.

---

## Licence

GPL-2.0-or-later.
