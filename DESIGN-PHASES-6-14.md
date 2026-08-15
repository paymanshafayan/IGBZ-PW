# پیاده‌سازی فازهای ۶ تا ۱۴ (نقشهٔ راه nop) روی IGBZ-WP — طراحی و وضعیت

**آخرین به‌روزرسانی:** ۱۴۰۵/۰۵/۲۴ · **مبنای طراحی:** `ARCHITECTURE_AND_ROADMAP.md` (مخزن nop)
و تطبیق آن با معماری فعلی وردپرس/ووکامرس و قواعد پروژه.

> **قانون بنیادین:** بدون Graph API اینستاگرام/متا. هر قابلیت «رشد فالوور» فقط از طریق
> زیرساخت خودمان (ManyChat webhook، قیف کامنت→دایرکت، داده‌های `ig_funnel_hits`) پیاده می‌شود.

> **الگوی مشترک همهٔ فازها:** سرویس پشت یک اینترفیس آداپتور، با Endpoint های تنظیم‌پذیر
> (مثل `HttpRampAdapter` و `HttpSpeechToText`) تا هر سرویس‌دهندهٔ ایرانی با کلید ریالی وصل شود؛
> و همهٔ خروجی‌ها از دادهٔ واقعی، نه رشتهٔ ثابت.

---

## فاز ۶ — درگاه‌های پرداخت چندگانه و BNPL خارجی
- **وضعیت پایه:** ۴ درگاه (زرین‌پال/آیدی‌پی/نکست‌پی/پی.آی.آر) + BNPL داخلی.
- **کار:** `HttpPspGateway` (درگاه عمومی تنظیم‌پذیر برای بانک‌های بیشتر) + `SnappPayBnplProvider`
  و `TaraBnplProvider` (آداپتورهای HTTP تنظیم‌پذیر) ثبت‌شده با `igbz_register_bnpl_providers`.
- **فایل‌ها:** `Payments/HttpPspGateway.php`, `Bnpl/HttpBnplProvider.php`.

## فاز ۷ — لجستیک و ارسال
- جدول `ig_shipments`؛ سرویس `LogisticsService` (دسته‌بندی مسیر با قواعد تنظیم‌پذیر، تولید PIN
  تحویل با `random_int` رمزنگارانه)؛ `ShippingAdapterInterface` + `HttpShippingAdapter`
  (تاپین/پستکس)؛ صفحهٔ ادمین `igbz-logistics`؛ REST ارسال.

## فاز ۸ — استودیوی هوش مصنوعی محتوا
- `AiProviderInterface` + `HttpAiStudioProvider` (تصویر/حذف پس‌زمینه/ویدیو/TTS/عکس مدل)؛
  `AiStudioService` (ذخیرهٔ خروجی واقعی در مدیالایبری)؛ صفحهٔ ادمین `igbz-ai-studio`.

## فاز ۹ — مارکت‌پلیس‌ها (دیجی‌کالا/دیوار)
- جدول‌های `ig_marketplace_sync` (صف بادوام) و `ig_category_mapping`؛
  `MarketplaceAdapterInterface` + `HttpMarketplaceAdapter`؛ `MarketplaceSyncService` (هوک
  تغییر محصول + کرون worker)؛ صفحهٔ ادمین `igbz-marketplaces`. (فید ترب از قبل موجود است.)

## فاز ۱۰ — سئو و شبکه‌های تبلیغاتی
- `SeoService` (متای خودکار + هشتگ با قالب قطعی یا AI)؛ `ProductFeedService` (XML/JSON
  یکتانت/تپسل از کاتالوگ واقعی)؛ `AdNetworkService` (تریبون HTTP)؛ صفحهٔ ادمین `igbz-seo`.

## فاز ۱۱ — گیمیفیکیشن و تخفیف رفتارمحور
- `GamificationService` (چرخ‌وفلک با سردی ۲۴h و RNG امن؛ ساخت کوپن واقعی ووکامرس)؛
  `AbandonedCartService` (جدول `ig_abandoned_carts` + کرون یادآوری با کد تخفیف)؛ صفحهٔ ادمین.

## فاز ۱۲ — درگاه رمزارزی (NOWPayments) + ترجمهٔ خودکار
- `NowPaymentsGateway` (اینترفیس `GatewayInterface`؛ ساخت Invoice؛ وبوک IPN با idempotency)؛
  `TranslationService` + `HttpTranslationAdapter`؛ صفحهٔ ادمین `igbz-translator`.
- **تفکیک ثبت‌شده:** این «درآمد ارزی فروشگاه از مشتری خارجی» است — جدا از ماژول FX
  («هزینهٔ ابزارها»).

## فاز ۱۳ — LMS + امنیت ویدیو (VOD)
- LMS کامل است؛ `LmsVodService` (لینک امضاشدهٔ HMAC با انقضا/آی‌پی — الگوی آروان‌کلاد،
  تنظیم‌پذیر) + فیلدهای تنظیمات `lms.vod_*`. واترمارک/FLAG_SECURE سمت اپ مستند می‌شود.

## فاز ۱۴ — کیف پول هوشمند، استودیوی AI مشتری، رشد فالوور
- کشبک کیف پول موجود است. `AiCreditsService` (جدول `ig_ai_credit_ledger`؛ شارژ با خرید از
  درصد تنظیم‌پذیر + خرید نقدی با `purpose=ai_credit_topup`)؛ REST استودیوی مشتری
  (`/igbz/v1/ai/studio/*`)؛ `GiveawayService` (جدول `ig_giveaways`؛ قرعه‌کشی از کامنت‌های واقعی
  `ig_funnel_hits` با `random_int`)؛ صفحهٔ ادمین `igbz-giveaways`.

---

## وضعیت اجرا (پس از هر فاز به‌روز می‌شود)
| فاز | وضعیت |
|---|---|
| ۶ | ✅ |
| ۷ | ✅ |
| ۸ | ✅ |
| ۹ | ✅ |
| ۱۰ | ✅ |
| ۱۱ | ✅ |
| ۱۲ | ✅ |
| ۱۳ | ✅ |
| ۱۴ | ✅ |
