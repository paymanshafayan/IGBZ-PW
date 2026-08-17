/** ذخیره و بازخوانی نشست‌ها — یک فایل JSON برای هر نشست در ~/.hoosha/sessions. */

import fs from 'node:fs/promises';
import path from 'node:path';
import { SESSIONS_DIR, ensureHome } from './config.js';

/** @param {string} id */
function fileFor( id ) {
	return path.join( SESSIONS_DIR, `${ String( id ).replace( /[^\w.-]/g, '' ) }.json` );
}

/**
 * @param {string} id
 * @param {{messages:any[],transcript:any[],title?:string}} data
 */
export async function saveSession( id, data ) {
	await ensureHome();
	const previous = await loadSession( id );
	const title =
		data.title ||
		previous?.title ||
		data.messages?.find( ( m ) => m.role === 'user' )?.content?.slice( 0, 60 ) ||
		'بدون عنوان';
	await fs.writeFile(
		fileFor( id ),
		JSON.stringify(
			{
				id,
				title,
				createdAt: previous?.createdAt || Date.now(),
				updatedAt: Date.now(),
				messages: data.messages,
				transcript: data.transcript,
			},
			null,
			2
		),
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
			out.push( {
				id: raw.id,
				title: raw.title,
				updatedAt: raw.updatedAt,
				messages: Array.isArray( raw.messages ) ? raw.messages.length : 0,
			} );
		} catch {
			// یک فایل خراب نباید کل فهرست را بخواباند.
		}
	}
	return out.sort( ( a, b ) => ( b.updatedAt || 0 ) - ( a.updatedAt || 0 ) ).slice( 0, 200 );
}

/** @param {string} id */
export async function loadSession( id ) {
	try {
		return JSON.parse( await fs.readFile( fileFor( id ), 'utf8' ) );
	} catch {
		return null;
	}
}

/** @param {string} id */
export async function deleteSession( id ) {
	const file = fileFor( id );
	const exists = await fs
		.stat( file )
		.then( () => true )
		.catch( () => false );
	if ( ! exists ) {
		throw new Error( 'نشست پیدا نشد.' );
	}
	await fs.rm( file, { force: true } );
	return { ok: true };
}

/**
 * @param {string} id
 * @param {string} title
 */
export async function renameSession( id, title ) {
	const saved = await loadSession( id );
	if ( ! saved ) {
		throw new Error( 'نشست پیدا نشد.' );
	}
	saved.title = String( title || '' ).slice( 0, 120 ) || saved.title;
	saved.updatedAt = Date.now();
	await fs.writeFile( fileFor( id ), JSON.stringify( saved, null, 2 ), 'utf8' );
	return { ok: true, title: saved.title };
}
