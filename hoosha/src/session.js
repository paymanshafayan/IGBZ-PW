/** ذخیره و بازخوانی نشست‌ها — یک فایل JSON برای هر نشست در ~/.hoosha/sessions. */

import fs from 'node:fs/promises';
import path from 'node:path';
import { SESSIONS_DIR, ensureHome } from './config.js';

/**
 * @param {string} id
 * @param {{messages:any[],transcript:any[]}} data
 */
export async function saveSession( id, data ) {
	await ensureHome();
	const file = path.join( SESSIONS_DIR, `${ id }.json` );
	const title =
		data.messages?.find( ( m ) => m.role === 'user' )?.content?.slice( 0, 60 ) || 'بدون عنوان';
	await fs.writeFile(
		file,
		JSON.stringify( { id, title, updatedAt: Date.now(), ...data }, null, 2 ),
		'utf8'
	);
}

export async function listSessions() {
	await ensureHome();
	const names = await fs.readdir( SESSIONS_DIR ).catch( () => [] );
	/** @type {any[]} */
	const out = [];
	for ( const n of names.filter( ( n ) => n.endsWith( '.json' ) ) ) {
		try {
			const raw = JSON.parse( await fs.readFile( path.join( SESSIONS_DIR, n ), 'utf8' ) );
			out.push( { id: raw.id, title: raw.title, updatedAt: raw.updatedAt } );
		} catch {
			// یک فایل خراب نباید کل فهرست را بخواباند.
		}
	}
	return out.sort( ( a, b ) => ( b.updatedAt || 0 ) - ( a.updatedAt || 0 ) ).slice( 0, 50 );
}

/** @param {string} id */
export async function loadSession( id ) {
	const file = path.join( SESSIONS_DIR, `${ String( id ).replace( /[^\w.-]/g, '' ) }.json` );
	try {
		return JSON.parse( await fs.readFile( file, 'utf8' ) );
	} catch {
		return null;
	}
}
