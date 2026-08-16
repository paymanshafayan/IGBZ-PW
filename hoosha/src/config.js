/**
 * تنظیمات هوشا — یک فایل JSON در پوشهٔ خانگی کاربر.
 *
 * کلید API در همین فایل ذخیره می‌شود (مثل بقیهٔ ابزارهای این خانواده) و دسترسی فایل
 * روی 600 بسته می‌شود. هشدارش هم در README آمده.
 */

import fs from 'node:fs/promises';
import fssync from 'node:fs';
import os from 'node:os';
import path from 'node:path';

export const HOME = path.join( os.homedir(), '.hoosha' );
export const CONFIG_PATH = path.join( HOME, 'config.json' );
export const SESSIONS_DIR = path.join( HOME, 'sessions' );

/** @returns {any} */
export function defaultConfig() {
	return {
		activeProfile: 'default',
		profiles: {
			default: {
				label: 'پیش‌فرض',
				provider: 'mock',
				baseUrl: '',
				apiKey: '',
				model: 'hoosha-mock-1',
			},
		},
		permissions: {
			mode: 'default',
			allow: [],
			ask: [],
			deny: [],
		},
		workspace: process.cwd(),
		ui: { theme: 'dark' },
	};
}

export async function ensureHome() {
	await fs.mkdir( HOME, { recursive: true } );
	await fs.mkdir( SESSIONS_DIR, { recursive: true } );
}

export async function loadConfig() {
	await ensureHome();
	try {
		const raw = await fs.readFile( CONFIG_PATH, 'utf8' );
		const parsed = JSON.parse( raw );
		return { ...defaultConfig(), ...parsed };
	} catch {
		const cfg = defaultConfig();
		await saveConfig( cfg );
		return cfg;
	}
}

/** @param {any} cfg */
export async function saveConfig( cfg ) {
	await ensureHome();
	await fs.writeFile( CONFIG_PATH, JSON.stringify( cfg, null, 2 ), 'utf8' );
	try {
		fssync.chmodSync( CONFIG_PATH, 0o600 );
	} catch {
		// روی ویندوز اهمیتی ندارد.
	}
	return cfg;
}

/** نسخهٔ امن برای فرستادن به رابط کاربری: کلیدها ماسک می‌شوند. */
export function publicConfig( cfg ) {
	const profiles = {};
	for ( const [ id, p ] of Object.entries( cfg.profiles || {} ) ) {
		profiles[ id ] = { ...p, apiKey: p.apiKey ? '••••••••' + String( p.apiKey ).slice( -4 ) : '' };
	}
	return { ...cfg, profiles };
}

/** @param {any} cfg */
export function activeProfile( cfg ) {
	return cfg.profiles?.[ cfg.activeProfile ] || Object.values( cfg.profiles || {} )[ 0 ] || null;
}
