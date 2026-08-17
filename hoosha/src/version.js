/**
 * نسخه و مسیر واقعیِ کدی که اجرا می‌شود.
 *
 * تا امروز نسخه در **سه جا** دستی نوشته شده بود: `cli.js`، `server.js` و README.
 * نتیجه‌اش قابل پیش‌بینی بود — یکی به‌روز می‌شد و بقیه جا می‌ماندند. حالا یک منبع
 * حقیقت هست و آن `package.json` است.
 *
 * و یک کار دوم که مهم‌تر است: تشخیص **کپیِ منجمد**.
 *
 * `npm install -g .` پوشه را کپی می‌کند. از آن لحظه دستور `hoosha` همان کپی را اجرا
 * می‌کند و هیچ `git pull` ای رویش اثر ندارد؛ کاربر ماه‌ها یک نسخهٔ قدیمی می‌بیند و
 * فکر می‌کند تغییراتش اعمال نشده. یک بار در همین پروژه اتفاق افتاد. پیام روی ترمینال
 * کافی نبود، چون کسی که با رابط کار می‌کند ترمینال را نمی‌بیند — پس این تشخیص از
 * `/api/state` هم بیرون می‌رود تا خودِ برنامه بتواند بگوید «من کد مخزن نیستم».
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

/** ریشهٔ پوشهٔ هوشا — همان جایی که `package.json` در آن است. */
export const ROOT = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );

/** @returns {string} */
function readVersion() {
	try {
		const raw = fs.readFileSync( path.join( ROOT, 'package.json' ), 'utf8' );
		return String( JSON.parse( raw ).version || '' ) || 'نامعلوم';
	} catch {
		return 'نامعلوم';
	}
}

export const VERSION = readVersion();

/**
 * این کدی که اجرا می‌شود، از کجا آمده؟
 *
 * @returns {{root:string, version:string, frozen:boolean, git:boolean, hint:string}}
 */
export function installInfo() {
	// اگر مسیر از داخل node_modules رد می‌شود، این یک نصبِ کپی‌شده است نه یک چک‌اوت.
	const frozen = ROOT.split( path.sep ).includes( 'node_modules' );

	// یک چک‌اوت واقعی، بالادستش .git دارد (چون هوشا زیرپوشهٔ مخزن IGBZ-WP است).
	const git = fs.existsSync( path.join( ROOT, '.git' ) ) || fs.existsSync( path.join( ROOT, '..', '.git' ) );

	return {
		root: ROOT,
		version: VERSION,
		frozen,
		git,
		hint: frozen
			? 'این یک کپیِ نصب‌شده است، نه کد مخزن. با «npm rm -g hoosha» و بعد «npm link» در پوشهٔ مخزن درست می‌شود.'
			: '',
	};
}
