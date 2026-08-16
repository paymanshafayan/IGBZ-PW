/**
 * موتور مجوز — قلب «پلن بده، تأیید بگیر، اجرا کن».
 *
 * تصمیم کارفرما: توانایی ابزار حذف نمی‌شود؛ آنچه کنترل می‌شود دسترسی است. پس همهٔ
 * ابزارها همیشه وجود دارند و این لایه فقط می‌گوید: اجرا کن / بپرس / رد کن.
 *
 * سه حالت (مثل Claude Code):
 *   plan    — فقط خواندن و تحلیل؛ هر ابزار نویسنده/اجراکننده رد می‌شود.
 *   default — خواندنی‌ها آزاد، نویسنده/اجراکننده تأیید می‌خواهند.
 *   auto    — همه‌چیز آزاد جز آنچه صراحتاً در deny آمده.
 */

import { TOOLS } from './tools.js';

export const MODES = /** @type {const} */ ( [ 'plan', 'default', 'auto' ] );

/**
 * @param {string} toolName
 * @param {any} input
 * @param {{mode:string, allow?:string[], ask?:string[], deny?:string[]}} rules
 * @returns {{decision:'allow'|'ask'|'deny', reason?:string}}
 */
export function decide( toolName, input, rules ) {
	const tool = TOOLS[ toolName ];
	if ( ! tool ) {
		return { decision: 'deny', reason: `ابزار ناشناخته: ${ toolName }` };
	}

	const deny = rules.deny || [];
	const allow = rules.allow || [];
	const ask = rules.ask || [];

	if ( matches( deny, toolName, input ) ) {
		return { decision: 'deny', reason: 'در فهرست ممنوع است.' };
	}

	if ( rules.mode === 'plan' && tool.risk !== 'read' ) {
		return {
			decision: 'deny',
			reason: 'حالت «پلن» فعال است: در این حالت فقط بررسی و خواندن مجاز است.',
		};
	}

	if ( matches( allow, toolName, input ) ) {
		return { decision: 'allow' };
	}
	if ( matches( ask, toolName, input ) ) {
		return { decision: 'ask' };
	}

	if ( rules.mode === 'auto' ) {
		return { decision: 'allow' };
	}

	// پیش‌فرض: خواندن آزاد، بقیه با تأیید.
	return tool.risk === 'read' ? { decision: 'allow' } : { decision: 'ask' };
}

/**
 * قاعده‌ها می‌توانند نام ابزار باشند یا `tool:prefix`.
 * مثال: `bash:git ` یعنی هر فرمان bash که با «git » شروع شود.
 *
 * @param {string[]} list
 * @param {string} toolName
 * @param {any} input
 */
function matches( list, toolName, input ) {
	for ( const rule of list ) {
		if ( rule === toolName || rule === '*' ) {
			return true;
		}
		const sep = rule.indexOf( ':' );
		if ( sep > 0 && rule.slice( 0, sep ) === toolName ) {
			const prefix = rule.slice( sep + 1 );
			const subject = String( input?.command ?? input?.path ?? input?.url ?? '' );
			if ( subject.startsWith( prefix ) ) {
				return true;
			}
		}
	}
	return false;
}

/** خلاصهٔ خوانا از یک فراخوانی ابزار، برای نمایش در دروازهٔ تأیید. */
export function describeCall( toolName, input ) {
	switch ( toolName ) {
		case 'bash':
			return input?.command || '';
		case 'write_file':
			return `نوشتن در ${ input?.path } (${ String( input?.content ?? '' ).length } نویسه)`;
		case 'edit_file':
			return `ویرایش ${ input?.path }`;
		case 'read_file':
			return `خواندن ${ input?.path }`;
		case 'list_dir':
			return `فهرست ${ input?.path || '.' }`;
		case 'glob':
			return `جستجوی فایل ${ input?.pattern }`;
		case 'grep':
			return `جستجوی متن «${ input?.pattern }»`;
		case 'web_fetch':
			return input?.url || '';
		default:
			return JSON.stringify( input ?? {} ).slice( 0, 200 );
	}
}
