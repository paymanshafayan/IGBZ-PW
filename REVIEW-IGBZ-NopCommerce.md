# گزارش بررسی مخزن IGBZ-NopCommerce

مخزن بررسی‌شده: `https://github.com/paymanshafayan/IGBZ-NopCommerce.git`
کامیت مورد بررسی: `7a67b9a` (شاخهٔ `main`) — تاریخ بررسی: ۱۴۰۵/۰۵/۲۲ (2026-08-13)
نوع کار: **فقط بررسی (Review)** — هیچ تغییری در مخزن اعمال نشده است.

---

## ۱. تصویر کلی

| مورد | مقدار |
|---|---|
| ماهیت | مجموعهٔ ۴ پلاگین nopCommerce **4.90.6** روی **.NET 9** |
| حجم کد | حدود ۱۵٬۶۰۰ خط C# در ۱۴۰ فایل |
| کنترلرها / اکشن‌های HTTP | ۳۶ کنترلر، ۹۹ اکشن دارای `[Http...]` |
| موجودیت‌های دیتابیس | ۲۲ کلاس `BaseEntity` |
| سرویس‌ها | ۳۱ اینترفیس سرویس |
| Consumer / ScheduleTask | ۷ Consumer رویداد + ۲ ScheduleTask |
| تست خودکار | **صفر** فایل تست |
| CI | GitHub Actions، **۲ اجرای آخر موفق** (~۴ دقیقه) |

چهار پلاگین و نقش‌شان:

1. **`Nop.Plugin.Misc.MultiTenantStores`** (~۱۰٬۸۰۰ خط) — هستهٔ سیستم: چندمستأجری، نگاشت دامنه، پرداخت Parbad، پلن/اشتراک، کیف‌پول واحد، Affiliate، LMS، BNPL، سینک مارکت‌پلیس، SEO/ترجمه، پرو مجازی، JWT، OTP.
2. **`Nop.Plugin.Misc.InstagramAssistant`** (~۲٬۹۰۰ خط) — دستیار اینستاگرام، استودیوی چندرسانه‌ای AI، ورود با اینستاگرام، اتوماسیون کامنت، پاداش فالو/منشن.
3. **`Nop.Plugin.Api`** (~۱٬۰۰۰ خط) — Web API اپ فلاتر، Push با FCM v1، دیپ‌لینک، احراز هویت عمومی.
4. **`Nop.Plugin.Misc.MasterSiteHub`** (~۸۷۰ خط) — داشبورد سوپرادمین + لندینگ/ثبت‌نام سایت مادر.

گراف وابستگی سالم است: `MultiTenantStores` پایه (DisplayOrder 1) و سه پلاگین دیگر با `ProjectReference` به آن وصل‌اند.

---

## ۲. نقاط قوت (واقعاً قابل اتکا)

- **CI سبز و معنادار.** ورک‌فلو `build.yml` روی `windows-latest` هستهٔ nopCommerce تگ `release-4.90.6` را کلون، پلاگین‌ها را داخلش کپی و به‌ترتیب وابستگی بیلد می‌کند و آرتیفکت `igbz-plugins-4906` می‌سازد. تنها هشدارهای بیلد از **خود هستهٔ nopCommerce** است (`checkVatPortTypeClient.CloseAsync()` که عضو ارث‌بری‌شده را پنهان می‌کند) و یک اخطار deprecation مربوط به Node 20 — **هیچ هشداری از کد پلاگین‌ها نیست**. این مهم‌ترین دستاورد اخیر پروژه است؛ قبلاً ۸ اجرای پیاپی fail می‌شد.
- **پاک‌سازی بدهی فنی جدی.** سند `گزارش-ممیزی-مشکلات-یافت-شده.md` ۱۹ اشکال (۷ بحرانی، ۹ مهم، ۳ متوسط) را مستند و رفع کرده است؛ از جمله:
  - تأیید پرداخت که همیشه موفق برمی‌گرداند → فراخوانی واقعی HTTP + دفترکل `PaymentTransactionLedger` برای جلوگیری از replay.
  - موجودی کیف‌پول hardcode شده (`150000 + reward`) → دفترکل واقعی.
  - برندهٔ جعلی مسابقه (`@user_winner_XXX`) و PIN تحویل با `Random` بذردار → `RandomNumberGenerator` روی شرکت‌کنندگان واقعی.
  - توکن ویدیو از GUID خام → HMAC-SHA256 امضاشده و مقیّد به IP.
- **معماری چندمستأجری منسجم.** `MultiTenantStoreContext` جایگزین `IStoreContext` می‌شود؛ `CrossStoreCustomerGuardFilter` و `ReferralCookieCaptureFilter` سراسری ثبت شده‌اند و `TenantAdminScopeFilter` عمداً فقط روی کنترلرهای ادمین (۱۰ کنترلر، با `[ServiceFilter]` صریح) اعمال می‌شود — و در `TenantPlansController` عمداً اعمال **نشده** چون فقط سوپرادمین است. این تصمیم‌ها در کامنت‌ها توضیح داده شده‌اند.
- **کیف‌پول یکپارچه.** `WalletLedger` جایگزین سه دفترکل موازی قبلی (`AiUsageCreditLedger`، `CustomerWalletLedger`، …) شده؛ `TryDebitAsync` صراحتاً idempotent است (کلید: CustomerId+StoreId+Reason+ReferenceCode) که برابر کلیک دوباره/درخواست تکراری مقاوم است.
- **مدیریت اسرار درست.** اسکن رگولار روی کل کد **هیچ رمز/کلید hardcode‌شده‌ای** پیدا نکرد. کلیدهای حساس (`JwtSigningSecret`، `VodHmacSigningSecret`، `VipLinkHmacSigningSecret`، `FcmProjectId`، …) اگر تنظیم نشوند در زمان resolve عمداً `InvalidOperationException` با پیام فارسی روشن پرتاب می‌کنند. کلیدهای API مستأجرها هم با `IEncryptionService` رمز و برای نمایش mask می‌شوند.
- **بهداشت کد.** صفر مورد `TODO` / `FIXME` / `HACK` / `NotImplementedException` در کل مخزن. کامنت‌های فارسیِ توضیح‌دهندهٔ «چرا»، نه «چه»، در سرتاسر کد وجود دارد که برای انتقال دانش عالی است.
- **بررسی سازگاری نسخه.** `Nop490CompatibilityChecker` امضای متدهای کلیدی هسته را در Startup چک و در لاگ هشدار می‌دهد — کار غیرمعمول و هوشمندانه‌ای برای پلاگینی که به نسخهٔ خاصی از nopCommerce قفل است.

---

## ۳. اشکالات و شکاف‌های واقعی (اولویت‌بندی‌شده)

### 🔴 بحرانی — مانع بهره‌برداری واقعی

**۳٫۱ نبود `IPaymentMethod` استاندارد nopCommerce.**
پرداخت فقط از مسیر `OrderPaymentController` (مخصوص اپ فلاتر) کار می‌کند. یعنی **فروشگاه وب استاندارد nopCommerce اصلاً قابل پرداخت نیست** — در صفحهٔ checkout هیچ روش پرداختی ظاهر نمی‌شود. این در `HANDOFF.md` §۶ هم اعتراف شده. تا وقتی یک `BasePaymentPlugin : IPaymentMethod` نوشته نشود، محصول برای مشتری وب فروش ندارد.

**۳٫۲ سیزده Endpoint نمادین `*.local` هنوز باقی است.**
هیچ‌کدام از این آدرس‌ها وجود خارجی ندارند و در Production خطای DNS می‌دهند:
- `api.ai-image-studio-provider.local` , `api.ai-video-studio-provider.local` , `api.ai-tts-provider.local` (در `InstagramAssistant/Services/AiMultimediaStudioService.cs`)
- سرویس‌های Vision و حذف پس‌زمینه (`AiVisionAndBackgroundRemovalServices.cs`)
- ترجمه (`MultiTenantStores/Services/CryptoAndTranslationService.cs`)
- **درخواست و تأیید درگاه Parbad** (`ParbadPaymentService.cs`) ← این یکی خطرناک‌ترین است، چون مسیر پول است.

نکته‌ی مثبت: ساختار فراخوانی (HttpClientFactory، هدر Authorization، خواندن پاسخ JSON، مدیریت خطا) **درست** نوشته شده؛ فقط URL و شکل payload باید با مستندات واقعی provider ایرانی جایگزین شود. حتی کامنت کد هم اذعان می‌کند نام فیلد `background_music_track_id` حدسی است.
(دامنه‌های `@instagram.igbz.local` و `@customer.igbz.local` عمدی و برای ایمیل مصنوعی‌اند — مشکلی ندارند.)

**۳٫۳ باگ ثبت‌نام مستأجر جدید.**
طبق `PLACEMENT-GUIDE.md` §۶، فرایند ثبت‌نام فقط مشتری *موجود* را به‌روزرسانی می‌کند و اگر ایمیل ادمین ناشناس باشد، **هیچ مشتری‌ای ساخته نمی‌شود**. یعنی مسیر اصلی جذب مشتری (Signup از سایت مادر) برای کاربر جدید کار نمی‌کند.

### 🟠 مهم — ریسک کیفیت و قابلیت استفاده

**۳٫۴ هیچ پلاگینی `IAdminMenuPlugin` را پیاده نکرده.**
جست‌وجو برای `IAdminMenuPlugin` / `ManageSiteMapAsync` **صفر نتیجه** دارد. یعنی ~۲۶ کنترلر ادمین (پلن‌ها، BNPL، دوره‌ها، درخواست‌های برداشت Affiliate، اعتبارنامه‌های یکپارچه‌سازی، …) فقط با **تایپ دستی URL** قابل دسترسی‌اند. از دید کاربر نهایی، بخش بزرگی از محصول عملاً نامرئی است. این کم‌هزینه‌ترین اصلاح با بیشترین اثر محسوس است.

**۳٫۵ صفر تست.**
نه Unit، نه Integration، نه یک تست دود ساده. CI هم مرحلهٔ `dotnet test` ندارد. با توجه به اینکه ممیزی قبلی ۱۹ اشکال منطقی (نه کامپایلی) پیدا کرد، «بیلد سبز» تضمینی برای درستی رفتار نیست. حداقلِ لازم: تست‌های `WalletService` (idempotency، موجودی ناکافی)، امضای توکن HMAC ویدیو، و anti-replay دفترکل پرداخت.

**۳٫۶ کد مرده و دوگانگی منطقی.**
- `ISnappPayBnplGateway` و `SnappPayBnplGateway` **هیچ ارجاعی در کل مخزن ندارند** — با `Services/BnplService.cs` جایگزین شده‌اند اما حذف نشده‌اند. باید پاک شود.
- دو مسیر رقیب برای کمیسیون Affiliate: `GamificationAndAffiliateService` و `AffiliateMarketingService` (+ `AffiliateCommissionOrderConsumer`). کامنت‌ها می‌گویند دومی جایگزین اولی است، ولی هر دو هنوز در DI ثبت‌اند. تصمیم نهایی و حذف مسیر بازنده هنوز گرفته نشده.
- `MasterSiteHub` **هیچ `SchemaMigration` ندارد** (سه پلاگین دیگر دارند) و روی موجودیت `TenantStoreSubscription` متعلق به پلاگین دیگر repository می‌زند. اگر ترتیب نصب رعایت نشود، خطای runtime می‌دهد.

**۳٫۷ فقدان `.gitignore`.**
مخزن هیچ `.gitignore` ندارد. شاهد عینی مشکل: شاخهٔ PR باز حاوی کامیت‌هایی مثل `Add files via upload` و `Delete logs_84841616024.zip` است — یعنی فایل‌های ZIP لاگ داخل مخزن آپلود و بعد حذف شده‌اند. یک `.gitignore` استاندارد dotnet ضروری است.

**۳٫۸ CORS با fallback به `AllowAnyOrigin`.**
در `MultiTenantStoresPlugin.cs` اگر `MultiTenantStores:MotherSiteOrigin` تنظیم نشود، به `AllowAnyOrigin().AllowAnyMethod().AllowAnyHeader()` برمی‌گردد. کامنت می‌گوید «فقط برای توسعهٔ محلی»، اما پیکربندی‌نشدن یک کلید نباید سکوت کند و به حالت باز برگردد — باید مثل بقیهٔ کلیدها استثنا پرتاب کند.

**۳٫۹ اعتبار JWT ۳۰ روزه و بدون Refresh Token.**
`JwtTokenService` توکن ۳۰ روزه صادر می‌کند بدون مکانیزم ابطال یا refresh. اگر توکنی لو برود، یک ماه معتبر است. برای اپ موبایل باید جفت access (کوتاه) + refresh (قابل ابطال) باشد.

### 🟡 متوسط — تمیزکاری و پایداری

**۳٫۱۰ شکنندگی ورک‌فلو CI.**
- مراحل `dotnet sln add` با `|| true` محافظت شده‌اند → اگر پروژه‌ای به solution اضافه نشود، بی‌صدا رد می‌شود و بیلد همچنان «سبز» می‌ماند.
- `cp -r Views` با `2>/dev/null || true` → پلاگینی که View‌هایش کپی نشود، در زمان اجرا خطای View not found می‌دهد ولی CI موفق گزارش می‌کند.
- `BUILD-GUIDE.md` از کش NuGet حرف می‌زند ولی چنین مرحله‌ای در ورک‌فلو وجود ندارد (مستندات و واقعیت واگرا شده‌اند).
- تنها assertion موجود، وجود DLL پلاگین MultiTenantStores است؛ برای سه پلاگین دیگر چنین بررسی‌ای نیست.

**۳٫۱۱ ثابت‌های سخت‌کدشدهٔ کسب‌وکاری.**
مثلاً در `AiCreditOrderBonusConsumer` نرخ پاداش `const decimal OrderAiBonusPercent = 2m` است و کامنت خودش می‌گوید باید در `TenantPlan` یا Setting قابل ویرایش باشد. همین الگو برای درصد کمیسیون Affiliate هم هست.

**۳٫۱۲ اسناد ناهمگام.**
`بررسی-تطابق-موارد-igbz.md` رسماً کهنه است (`HANDOFF.md` §۶ هم تذکر داده). نمونه: آن سند می‌گوید فیدهای ترب/دیجی‌کالا کنترلر عمومی ندارند، درحالی‌که `Controllers/Public/MarketplaceFeedsController.cs` موجود و به هر دو سرویس وصل است. داشتن ۶ سند markdown با ادعاهای متناقض، برای عضو جدید تیم گمراه‌کننده است — باید ادغام یا تاریخ‌گذاری/آرشیو شوند.

**۳٫۱۳ PR باز شمارهٔ ۱ بلاتکلیف.**
PR #1 با عنوان «Add files via upload» از شاخهٔ `arena/019fe017-igbz-nopcommerce` باز است، **توضیحات خالی**، و diff آن نسبت به `main` **کاملاً تهی است** (تمام تغییرات آن قبلاً در main هست؛ تاریخچه‌اش فقط آپلود و حذف فایل‌های ZIP لاگ است). این PR باید بدون merge بسته و شاخه‌اش حذف شود.

**۳٫۱۴ نسخهٔ پکیج‌های «تقریبی».**
در `MultiTenantStores.csproj` کامنت‌ها نسخه‌های `JwtBearer 9.0.4`، `System.IdentityModel.Tokens.Jwt 8.2.1`، `Caching.Memory 9.0.4` را «تقریبی» توصیف می‌کنند. حالا که CI سبز است، این کامنت‌ها باید حذف و نسخه‌ها قطعی اعلام شوند (ترجیحاً با `Directory.Packages.props` و Central Package Management).

**۳٫۱۵ عدم قطعیت وب‌هوک متا.**
شکل payload وب‌هوک mentions هرگز با داشبورد واقعی Meta راستی‌آزمایی نشده (`HANDOFF.md` §۶). تا آن زمان، قابلیت پاداش منشن غیرقابل اتکاست.

---

## ۴. پیشنهاد نقشهٔ راه (به ترتیب اولویت)

**گام ۱ — بدون آن قابل عرضه نیست**
1. پیاده‌سازی `IPaymentMethod` استاندارد تا checkout وب کار کند.
2. جایگزینی endpointهای واقعی Parbad (درخواست + تأیید) به‌جای `*.local`.
3. رفع باگ ساخت‌نشدن مشتری در ثبت‌نام مستأجر جدید.

**گام ۲ — قابل‌استفاده شدن محصول (کم‌هزینه، اثر زیاد)**
4. پیاده‌سازی `IAdminMenuPlugin` در `MultiTenantStores` و افزودن منوی «IGBZ» با زیرمنوی همهٔ کنترلرهای ادمین.
5. افزودن `.gitignore` استاندارد dotnet؛ بستن PR #1 و حذف شاخه‌اش.
6. حذف `ISnappPayBnplGateway`/`SnappPayBnplGateway` و انتخاب قطعی یکی از دو مسیر Affiliate.

**گام ۳ — تثبیت کیفیت**
7. پروژهٔ تست (xUnit) + مرحلهٔ `dotnet test` در CI؛ شروع با `WalletService`، امضای HMAC ویدیو، anti-replay پرداخت.
8. حذف `|| true` از مراحل `dotnet sln add` و اضافه‌کردن assertion وجود DLL برای هر ۴ پلاگین؛ افزودن کش NuGet مطابق مستندات.
9. تبدیل fallback CORS به خطای صریح؛ افزودن Refresh Token و کوتاه‌کردن عمر access token.
10. افزودن `SchemaMigration` به `MasterSiteHub` (حتی خالی) و اعلام صریح وابستگی نصبش به `MultiTenantStores`.

**گام ۴ — بلوغ**
11. انتقال ثابت‌های کسب‌وکاری (درصد پاداش/کمیسیون) به `TenantPlan`/Setting.
12. ادغام ۶ سند markdown در یک `README.md` + یک `CHANGELOG.md`؛ آرشیو کردن اسناد کهنه.
13. اتصال providerهای واقعی AI ایرانی و راستی‌آزمایی payload وب‌هوک متا.
14. Central Package Management و حذف کامنت‌های «نسخهٔ تقریبی».

---

## ۵. جمع‌بندی

پروژه از نظر **معماری و انضباط مهندسی در سطح خوبی** است: چندمستأجری منسجم، کیف‌پول idempotent، مدیریت درست اسرار، صفر TODO، کامنت‌های توضیحی باکیفیت، و CI سبز پس از یک دور ممیزی جدی که ۱۹ اشکال واقعی (اکثراً «داده‌های جعلی به‌جای منطق واقعی») را رفع کرد.

اما پروژه **آمادهٔ Production نیست**، و دلیلش کیفیت کد نیست بلکه **اتصال‌نداشتن به دنیای واقعی** است: درگاه پرداخت و سرویس‌های AI هنوز به آدرس‌های نمادین `.local` اشاره می‌کنند، روش پرداخت استاندارد nopCommerce وجود ندارد، و کاربر ادمین اصلاً منویی برای رسیدن به قابلیت‌ها ندارد. به‌علاوه با صفر تست، هر اصلاح بعدی ریسک بازگشت باگ‌های همان ۱۹ مورد را دارد.

ارزیابی کلی: **اسکلت محکم، سیم‌کشیِ ناتمام.** سه مورد گام ۱ فاصلهٔ اصلی تا محصول قابل فروش‌اند.
