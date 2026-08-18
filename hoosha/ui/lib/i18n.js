/**
 * دو زبانه — فارسی و انگلیسی.
 *
 * قاعده‌ای که این ماژول را شکل داد: **زبان پیش‌فرض فارسی است و هیچ رشته‌ای بی‌ترجمه
 * نمی‌ماند.** اگر کلیدی در فرهنگ انگلیسی نباشد، همان فارسی برمی‌گردد — نه یک کلید خام
 * مثل `sidebar.chats` که در رابط زشت است و کاربر را گیج می‌کند.
 *
 * سه چیز با هم عوض می‌شوند و نه یکی‌شان جدا:
 *   زبان  ·  جهت صفحه (rtl/ltr)  ·  خانوادهٔ فونت
 *
 * فارسی با وزیرمتن نوشته می‌شود (فایلش کنار برنامه است)، انگلیسی با فونت سیستم. اگر
 * فقط زبان عوض شود و جهت نه، رابط به‌هم می‌ریزد — این را جدا نگه‌نداشتن، خودش یک باگ
 * است که با یک تابع بسته می‌شود.
 */

const STORE_KEY = 'hoosha-lang';

/** @type {Record<string, Record<string,string>>} */
const EN = {
	// نوار کناری
	'گفتگوی تازه': 'New chat',
	'گفتگوها': 'Chats',
	'پروژه‌ها': 'Projects',
	'ابزارها': 'Tools',
	'تغییرات': 'Changes',
	'سفارشی‌سازی': 'Customize',
	'امکانات': 'Features',
	'فضای کار': 'Workspace',
	'اخیر': 'Recents',
	'همهٔ گفتگوها': 'All chats',
	'جستجو (Ctrl+K)': 'Search (Ctrl+K)',
	'بستن نوار کناری': 'Collapse sidebar',
	'حساب و تنظیمات': 'Account and settings',
	'پروفایل': 'Profile',
	'هاب پرووایدر': 'Provider hub',
	'مسیریابی خودکار': 'Automatic routing',

	// منوی حساب
	'تنظیمات': 'Settings',
	'ظاهر': 'Appearance',
	'راهنما و میان‌برها': 'Help and shortcuts',
	'مصرف و هزینه': 'Usage and cost',
	'وضعیت و تشخیص': 'Status and diagnostics',
	'بارگذاری دوباره': 'Reload',

	// گفتگو
	'امروز چه کمکی از من برمی‌آید؟': 'How can I help you today?',
	'صبح‌بخیر، چه خبر؟': 'Morning, how are things?',
	'ظهر بخیر، چه خبر؟': 'Afternoon, how are things?',
	'عصر بخیر، چه خبر؟': 'Evening, how are things?',
	'شب‌بخیر، چه خبر؟': 'Late night, how are things?',
	'هوشا هم اشتباه می‌کند. کارهای مهم را خودت بازبینی کن.': 'Hoosha can make mistakes. Double-check anything that matters.',
	'ارسال': 'Send',
	'توقف (Esc)': 'Stop (Esc)',
	'گفتن به‌جای نوشتن (Ctrl+M)': 'Speak instead of typing (Ctrl+M)',
	'بلندخوانی پاسخ': 'Read the reply aloud',
	'افزودن و ابزارها': 'Attach and tools',
	'بازگشت به گفتگو': 'Back to chat',
	'بیشتر': 'More',
	'اشتراک': 'Share',
	'بدون پروژه': 'No project',
	'گفتگوی تازه است': 'New chat',
	'مصرف کانتکست': 'Context used',

	// صفحه‌ها
	'جستجو در گفتگوها…': 'Search chats…',
	'هنوز گفتگویی نیست': 'No chats yet',
	'از «گفتگوی تازه» شروع کن؛ هر گفتگو خودش ذخیره می‌شود.': 'Start with “New chat” — every conversation saves itself.',
	'پروژهٔ تازه': 'New project',
	'پروژهٔ فعلی': 'Current project',
	'باز کن': 'Open',
	'پیام': 'messages',
	'باز': 'open',
	'امروز': 'Today',
	'هفت روز گذشته': 'Previous 7 days',
	'سی روز گذشته': 'Previous 30 days',
	'قدیمی‌تر': 'Older',
	'بدون عنوان': 'Untitled',

	// تغییرات
	'مخزن': 'Repository',
	'شاخه': 'Branch',
	'تغییر': 'Changes',
	'جلوتر از ریموت': 'Ahead of remote',
	'ثبت تغییرات': 'Commit',
	'فرستادن': 'Push',
	'درخواست ادغام': 'Pull request',
	'چیزی تغییر نکرده.': 'Nothing has changed.',
	'کامیت‌های اخیر': 'Recent commits',
	'این پوشه مخزن گیت نیست.': 'This folder is not a git repository.',
	'اتصال مخزن': 'Connect a repository',

	// تنظیمات
	'پرووایدر و مدل': 'Provider and model',
	'پرووایدرهای استاندارد': 'Standard providers',
	'پرووایدرهای سازگار': 'Compatible providers',
	'مدل‌ها': 'Models',
	'هاب و مسیریابی': 'Hub and routing',
	'سلامت و عیب‌یاب': 'Health and diagnoser',
	'پروفایل تک‌نفره': 'Single profile',
	'مجوزها': 'Permissions',
	'سندباکس': 'Sandbox',
	'اسکیل‌ها': 'Skills',
	'کانکتورها': 'Connectors',
	'پلاگین‌ها': 'Plugins',
	'زیرعامل‌ها': 'Subagents',
	'دستورها': 'Commands',
	'هوک‌ها': 'Hooks',
	'حافظهٔ پروژه': 'Project memory',
	'جستجو…': 'Search…',
	'بستن': 'Close',
	'مرور': 'Browse',
	'افزودن': 'Add',
	'ذخیره': 'Save',
	'انصراف': 'Cancel',
	'ویرایش': 'Edit',
	'حذف': 'Delete',
	'زبان': 'Language',
	'فارسی': 'Persian',
	'انگلیسی': 'English',
};

/** فرهنگ‌ها بر اساس زبان. فارسی کلیدِ خودش است، پس فرهنگ لازم ندارد. */
const DICT = { fa: null, en: EN };

let current = 'fa';

/** @returns {'fa'|'en'} */
export function lang() {
	return current;
}

/**
 * ترجمهٔ یک رشته.
 *
 * کلیدها **خودِ متن فارسی** هستند، نه شناسه‌های مصنوعی. دلیلش این است که کد بدون
 * فرهنگ هم خوانا بماند و اگر ترجمه‌ای جا افتاد، کاربر متن فارسی ببیند نه `nav.chats`.
 *
 * @param {string} fa
 */
export function t( fa ) {
	const dict = DICT[ current ];
	if ( ! dict ) {
		return fa;
	}
	return dict[ fa ] ?? fa;
}

/** آیا این زبان راست‌به‌چپ است؟ */
export function isRtl( code = current ) {
	return code === 'fa';
}

/**
 * زبان را عوض می‌کند و **هر سه چیز وابسته** را با هم به‌روز می‌کند.
 *
 * @param {'fa'|'en'} code
 */
export function setLang( code ) {
	current = DICT[ code ] !== undefined ? code : 'fa';
	localStorage.setItem( STORE_KEY, current );

	const root = document.documentElement;
	root.lang = current;
	root.dir = isRtl() ? 'rtl' : 'ltr';
	root.dataset.lang = current;
	return current;
}

/** خواندن زبان ذخیره‌شده در شروع برنامه. */
export function initLang() {
	const saved = localStorage.getItem( STORE_KEY );
	return setLang( saved === 'en' ? 'en' : 'fa' );
}

/**
 * ترجمهٔ متن‌های ثابتِ داخل HTML.
 *
 * هر المانی که `data-t` داشته باشد، متنش ترجمه می‌شود؛ `data-t-title` و
 * `data-t-ph` هم برای تیتر و placeholder. این‌طور لازم نیست کل `index.html` را در
 * جاوااسکریپت بازتولید کنیم.
 */
export function translateDom( root = document ) {
	for ( const el of root.querySelectorAll( '[data-t]' ) ) {
		el.textContent = t( el.dataset.t );
	}
	for ( const el of root.querySelectorAll( '[data-t-title]' ) ) {
		el.title = t( el.dataset.tTitle );
	}
	for ( const el of root.querySelectorAll( '[data-t-ph]' ) ) {
		el.placeholder = t( el.dataset.tPh );
	}
}

/** فهرست زبان‌ها برای منوی انتخاب. */
export const LANGS = [
	{ code: 'fa', label: 'فارسی', english: 'Persian' },
	{ code: 'en', label: 'English', english: 'English' },
];
