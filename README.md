# IGBZ-PW

The IGBZ product ported from **nopCommerce** to **WordPress + WooCommerce**.

## Contents

| Path | What it is |
| --- | --- |
| [`igbz-suite/`](igbz-suite/) | The plugin. One plugin, four toggleable modules. See its [README](igbz-suite/README.md) for installation, configuration and the API reference. |
| [`REVIEW-IGBZ-NopCommerce.md`](REVIEW-IGBZ-NopCommerce.md) | Read-only review of the original nopCommerce repository (in Persian), which this port is based on. |

## The port at a glance

The nopCommerce original was four separate plugins. This is **one plugin with four modules** you can
switch on and off independently:

* **Multi-Tenant Stores** — tenants, wallet, subscription plans, BNPL, affiliate, LMS, OTP login,
  marketplace feeds, and four Iranian payment gateways (Zarinpal, IDPay, NextPay, Pay.ir).
* **Instagram Automation** — content generation and auto-publishing via **Manus**, comment-to-DM
  funnels via **ManyChat**.
* **Master Site Hub** — public store directory, tenant signup, domain verification, VIP links.
* **Mobile REST API** — JWT auth with rotating refresh tokens, catalog/account/admin endpoints,
  FCM push.

**The single functional change from the nopCommerce version** is that the Instagram Graph API
integration is replaced by Manus (research, design, reels, captions, scheduling and auto-publish at
peak hours) and ManyChat (DM funnels, over both a real-time webhook and the ManyChat API). The
publisher and generator sit behind interfaces so a Graph adapter can be added back later.

Tenancy is single-site with `tenant_id` columns — not WordPress Multisite.

## Install

Copy `igbz-suite/` into `wp-content/plugins/` and activate. Requires WordPress 6.3+,
WooCommerce 8.0+, PHP 8.1+ with `openssl`. Full instructions, including the required
`IGBZ_ENCRYPTION_KEY` constant and the real-cron setup, are in
[`igbz-suite/README.md`](igbz-suite/README.md).

## Tests

```bash
php igbz-suite/tests/run.php
```

No Composer and no PHPUnit — the runner is dependency-free.
