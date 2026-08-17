/**
 * دستورهای اسلش.
 *
 * دو دسته‌اند:
 *   ۱) دستورهای داخلی که کاری با خود برنامه می‌کنند (/mode، /mcp، /skills، …) و اصلاً
 *      به مدل نمی‌رسند.
 *   ۲) دستورهای کاربر: هر فایل مارک‌داون در `~/.hoosha/commands/` یا
 *      `<workspace>/.hoosha/commands/` یا `commands/` یک پلاگین، به یک دستور تبدیل می‌شود.
 *      محتوای فایل، همان پرامپتی است که فرستاده می‌شود؛ `$ARGUMENTS` با ورودی کاربر و
 *      `$1`,`$2`… با پارامترهای جدا جایگزین می‌شوند.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { parseFrontmatter } from './skills.js';

/**
 * @typedef {Object} UserCommand
 * @property {string} name
 * @property {string} description
 * @property {string} body
 * @property {string} source
 */

/**
 * @param {string} dir
 * @param {string} source
 * @returns {Promise<UserCommand[]>}
 */
export async function loadCommandsFrom( dir, source ) {
	/** @type {UserCommand[]} */
	const out = [];
	let files;
	try {
		files = await fs.readdir( dir );
	} catch {
		return out;
	}
	for ( const f of files ) {
		if ( ! f.endsWith( '.md' ) ) {
			continue;
		}
		const text = await fs.readFile( path.join( dir, f ), 'utf8' ).catch( () => '' );
		if ( ! text ) {
			continue;
		}
		const { data, body } = parseFrontmatter( text );
		out.push( {
			name: String( data.name || path.basename( f, '.md' ) ),
			description: String( data.description || body.trim().split( '\n' )[ 0 ] || '' ).slice( 0, 200 ),
			body,
			source,
		} );
	}
	return out;
}

/**
 * @param {{home:string, workspace:string, pluginDirs?:{name:string,dir:string}[]}} opts
 */
export async function collectCommands( { home, workspace, pluginDirs = [] } ) {
	/** @type {UserCommand[]} */
	const all = [];
	all.push( ...( await loadCommandsFrom( path.join( home, 'commands' ), 'user' ) ) );
	for ( const p of pluginDirs ) {
		all.push( ...( await loadCommandsFrom( path.join( p.dir, 'commands' ), p.name ) ) );
	}
	all.push( ...( await loadCommandsFrom( path.join( workspace, '.hoosha', 'commands' ), 'project' ) ) );

	/** @type {Map<string,UserCommand>} */
	const byName = new Map();
	for ( const c of all ) {
		byName.set( c.name, c );
	}
	return [ ...byName.values() ];
}

/** فهرست دستورهای داخلی — برای راهنما و تکمیل خودکار در رابط کاربری. */
export const BUILTIN_COMMANDS = [
	{ name: 'help', description: 'فهرست دستورها' },
	{ name: 'clear', description: 'پاک‌کردن گفتگو و شروع تازه' },
	{ name: 'compact', description: 'فشرده‌کردن گفتگوی طولانی در یک خلاصه' },
	{ name: 'mode', description: 'تغییر حالت: plan | default | auto' },
	{ name: 'model', description: 'نمایش یا تغییر مدل' },
	{ name: 'tools', description: 'فهرست ابزارهای در دسترس' },
	{ name: 'skills', description: 'فهرست اسکیل‌های نصب‌شده' },
	{ name: 'mcp', description: 'وضعیت سرورهای MCP' },
	{ name: 'plugin', description: 'مدیریت پلاگین‌ها: list | install <src> | remove <name>' },
	{ name: 'permissions', description: 'نمایش قواعد مجوز' },
	{ name: 'cost', description: 'مصرف توکن این نشست' },
	{ name: 'workspace', description: 'نمایش یا تغییر پوشهٔ کاری' },
	{ name: 'sessions', description: 'فهرست نشست‌های ذخیره‌شده' },
];

/**
 * تبدیل ورودی کاربر به یک «قصد».
 *
 * @param {string} text
 * @param {UserCommand[]} userCommands
 * @returns {{kind:'prompt', text:string} | {kind:'builtin', name:string, args:string}}
 */
export function parseInput( text, userCommands ) {
	const trimmed = text.trim();
	if ( ! trimmed.startsWith( '/' ) ) {
		return { kind: 'prompt', text };
	}

	const space = trimmed.search( /\s/ );
	const name = ( space === -1 ? trimmed.slice( 1 ) : trimmed.slice( 1, space ) ).toLowerCase();
	const args = space === -1 ? '' : trimmed.slice( space + 1 ).trim();

	const custom = userCommands.find( ( c ) => c.name.toLowerCase() === name );
	if ( custom ) {
		return { kind: 'prompt', text: expand( custom.body, args ) };
	}

	return { kind: 'builtin', name, args };
}

/**
 * @param {string} body
 * @param {string} args
 */
export function expand( body, args ) {
	const parts = args.split( /\s+/ ).filter( Boolean );
	let out = body.replace( /\$ARGUMENTS/g, args );
	out = out.replace( /\$(\d+)/g, ( _, n ) => parts[ Number( n ) - 1 ] ?? '' );
	return out.trim();
}
