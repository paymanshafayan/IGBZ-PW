#!/usr/bin/env node
/**
 * ورودی خط فرمان هوشا.
 *
 *   hoosha                     اجرای نسخهٔ دسکتاپ (سرور محلی + باز کردن پنجره)
 *   hoosha --port 7788         پورت دلخواه
 *   hoosha --dir /path/to/dir  پوشهٔ کاری
 *   hoosha --no-open           پنجره باز نشود (برای سرور)
 *   hoosha --host 0.0.0.0      شنیدن روی همهٔ رابط‌ها
 */

import { spawn } from 'node:child_process';
import { startServer } from './server.js';

const args = process.argv.slice( 2 );

function flag( name, fallback = undefined ) {
	const i = args.indexOf( `--${ name }` );
	if ( i === -1 ) {
		return fallback;
	}
	const next = args[ i + 1 ];
	return next && ! next.startsWith( '--' ) ? next : true;
}

if ( args.includes( '--help' ) || args.includes( '-h' ) ) {
	console.log( `
هوشا — دستیار عامل بومی

  hoosha                  اجرا با تنظیمات ذخیره‌شده
  hoosha --dir <path>     تعیین پوشهٔ کاری
  hoosha --port <n>       پورت (پیش‌فرض 7788)
  hoosha --host <h>       میزبان (پیش‌فرض 127.0.0.1)
  hoosha --no-open        پنجره/مرورگر باز نشود
  hoosha --version        نسخه
` );
	process.exit( 0 );
}

if ( args.includes( '--version' ) || args.includes( '-v' ) ) {
	console.log( '0.1.0' );
	process.exit( 0 );
}

const port = Number( flag( 'port', 7788 ) );
const host = String( flag( 'host', '127.0.0.1' ) );
const dir = flag( 'dir' );
const noOpen = args.includes( '--no-open' );

const { config } = await startServer( {
	port,
	host,
	workspace: typeof dir === 'string' ? dir : undefined,
} );

const shown = host === '0.0.0.0' ? '127.0.0.1' : host;
const url = `http://${ shown }:${ port }`;

console.log( '' );
console.log( '  هوشا آمادهٔ کار است' );
console.log( `  آدرس:       ${ url }` );
console.log( `  پوشهٔ کاری:  ${ config.workspace }` );
console.log( '' );

if ( ! noOpen ) {
	openBrowser( url );
}

/** @param {string} target */
function openBrowser( target ) {
	const cmd =
		process.platform === 'darwin' ? 'open' : process.platform === 'win32' ? 'start' : 'xdg-open';
	try {
		const child = spawn( cmd, [ target ], {
			shell: process.platform === 'win32',
			stdio: 'ignore',
			detached: true,
		} );
		child.on( 'error', () => {} );
		child.unref();
	} catch {
		// روی سرور بدون محیط گرافیکی طبیعی است؛ آدرس بالا چاپ شده.
	}
}
