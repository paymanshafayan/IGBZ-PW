/**
 * موتور تونل هوشا (۰.۹.۶) — جمع‌آوری کانفیگ رایگان، تست، انبارِ کارکردني‌ها،
 * درگاه محلی پایدار با چرخش خودکار. درخواست کارفرما: «یک موتور داخلی مثل v2ray».
 *
 * مدل: هستهٔ xray دو نقش دارد —
 *   ۱) آزمونگر: برای هر کانفیگ کاندید، یک پروسهٔ کوتاه با درگاه socks موقت.
 *   ۲) سرویس: یک پروسهٔ بلند با socks:7809 و http:7810 که از بهترینِ انبار می‌گذرد.
 */

import fs from 'node:fs';
import path from 'node:path';
import { spawn } from 'node:child_process';
import net from 'node:net';
import { HOME } from '../config.js';
import { coreBin, corePresent } from './core.js';
import { parseAll } from './parse.js';
import { logInfo, logWarn, logError } from '../logs.js';

export const SOCKS_PORT = 7809;
export const HTTP_PORT = 7810;
const POOL_FILE = () => path.join( String( HOME ), 'tunnel', 'pool.json' );
const TEST_URL = 'https://api.ipify.org?format=json';

/**
 * منابع پیش‌فرض — فهرست شش‌گانهٔ کارفرما (۱۴۰۵/۰۵/۲۹) با مسیرهای raw راستی‌آزمایی‌شده
 * از API گیت‌هاب. در دسترس‌بودنشان همیشه تغییر می‌کند؛ فهرست در صفحهٔ پراکسی قابل
 * ویرایش است.
 */
export const DEFAULT_SOURCES = [
	// همهٔ پروتکل‌ها، هر ۱۵ دقیقه (کارفرما: ebrasha)
	'https://raw.githubusercontent.com/ebrasha/free-v2ray-public-list/main/V2Ray-Config-By-EbraSha-All-Type.txt',
	// کلاسیک‌ترین مخزن، کانفیگ‌های تست‌شده (کارفرما: barry-far)
	'https://raw.githubusercontent.com/barry-far/V2ray-Configs/main/All_Configs_Sub.txt',
	// جمع‌آوری از صدها منبع، به‌تفکیک پروتکل (کارفرما: MohammadBahemmat)
	'https://raw.githubusercontent.com/MohammadBahemmat/V2ray-Collector/main/all_servers.txt',
	// پرسرعت و بدون تبلیغ، هر ۱۰ دقیقه (کارفرما: FreeFolksOn)
	'https://raw.githubusercontent.com/FreeFolksOn/abc-configs-free-vpn-proxy-list/main/All_Configs_Sub.txt',
	// تست‌پینگ‌شدهٔ دوساعته (کارفرما: MahanKenway)
	'https://raw.githubusercontent.com/MahanKenway/Freedom-V2Ray/main/configs/mix.txt',
	// گردآوری از وب با هستهٔ Xray تست‌شده (کارفرما: Delta-Kronecker)
	'https://raw.githubusercontent.com/Delta-Kronecker/V2ray-Config/main/config/all_configs.txt',
];

/** @type {{ pool: any[], sources: string[], running: boolean, proc: any, currentIdx: number, startedAt: string, lastCheck: any, exitIp: string, checks: number }} */
const S = { pool: [], sources: [ ...DEFAULT_SOURCES ], running: false, proc: null, currentIdx: -1, startedAt: '', lastCheck: null, exitIp: '', checks: 0 };

export function loadState() {
	try {
		const saved = JSON.parse( fs.readFileSync( POOL_FILE(), 'utf8' ) );
		S.pool = saved.pool || [];
		S.sources = saved.sources?.length ? saved.sources : [ ...DEFAULT_SOURCES ];
	} catch {
		/* نصب تازه */
	}
	return S;
}
function saveState() {
	fs.mkdirSync( path.dirname( POOL_FILE() ), { recursive: true } );
	fs.writeFileSync( POOL_FILE(), JSON.stringify( { pool: S.pool.slice( 0, 60 ), sources: S.sources }, null, 2 ) );
}

export function status() {
	return {
		corePresent: corePresent(),
		running: S.running,
		ports: { socks: SOCKS_PORT, http: HTTP_PORT },
		current: S.currentIdx >= 0 && S.pool[ S.currentIdx ] ? { name: S.pool[ S.currentIdx ].name, proto: S.pool[ S.currentIdx ].proto, ms: S.pool[ S.currentIdx ].ms } : null,
		startedAt: S.startedAt || null,
		lastCheck: S.lastCheck,
		exitIp: S.exitIp,
		poolSize: S.pool.length,
		working: S.pool.filter( ( c ) => c.ok ).length,
		pool: S.pool,
		sources: S.sources,
		defaults: DEFAULT_SOURCES,
	};
}

/** جمع‌آوری از همهٔ منابع — نتیجه: کاندیدهای تازه (بدون تست). */
export async function harvest() {
	const found = [];
	const seen = new Set( S.pool.map( ( c ) => `${ c.proto }|${ c.host }:${ c.port }` ) );
	for ( const url of S.sources ) {
		try {
			const text = await ( await fetch( url, { headers: { 'user-agent': 'hoosha-tunnel' }, signal: AbortSignal.timeout( 25_000 ) } ) ).text();
			const parsed = parseAll( text );
			for ( const p of parsed.slice( 0, 400 ) ) {
				const key = `${ p.proto }|${ p.host }:${ p.port }`;
				if ( seen.has( key ) ) { continue; }
				seen.add( key );
				found.push( { id: `t${ Date.now().toString( 36 ) }${ found.length }`, proto: p.proto, name: p.name, host: p.host, port: p.port, outbound: p.outbound, ok: false, ms: 0, lastCheck: null, enabled: true, pinned: false, source: url.slice( 0, 60 ) } );
			}
			logInfo( 'tunnel', 'منبع خوانده شد.', { url: url.slice( 0, 60 ), found: parsed.length } );
		} catch ( e ) {
			logWarn( 'tunnel', 'خواندن منبع نشد.', { url: url.slice( 0, 60 ), error: String( e?.message || e ).slice( 0, 120 ) } );
		}
	}
	S.pool = [ ...S.pool, ...found ].slice( 0, 400 );
	saveState();
	logInfo( 'tunnel', 'جمع‌آوری تمام شد.', { newFound: found.length, total: S.pool.length } );
	return { ok: true, added: found.length, total: S.pool.length };
}

/** پورت آزاد تصادفی برای آزمونگر. */
function freePort() {
	return new Promise( ( res, rej ) => {
		const srv = net.createServer();
		srv.unref();
		srv.on( 'error', rej );
		srv.listen( 0, '127.0.0.1', () => { const p = srv.address().port; srv.close( () => res( p ) ); } );
	} );
}

function runOnce( outbound, port, ttlMs ) {
	return new Promise( ( resolve ) => {
		const cfg = {
			inbounds: [ { listen: '127.0.0.1', port, protocol: 'socks', settings: { udp: false } } ],
			outbounds: [ outbound, { protocol: 'freedom', tag: 'direct' } ],
		};
		const tmp = path.join( String( HOME ), 'tunnel', `probe-${ port }.json` );
		fs.mkdirSync( path.dirname( tmp ), { recursive: true } );
		fs.writeFileSync( tmp, JSON.stringify( cfg ) );
		const proc = spawn( coreBin(), [ 'run', '-c', tmp ], { stdio: 'ignore' } );
		const done = ( result ) => {
			try { proc.kill(); } catch {}
			fs.rmSync( tmp, { force: true } );
			resolve( result );
		};
		const timer = setTimeout( () => done( { ok: false, ms: ttlMs, error: 'timeout' } ), ttlMs );
		proc.on( 'error', ( e ) => { clearTimeout( timer ); done( { ok: false, ms: 0, error: String( e.message ).slice( 0, 80 ) } ); } );
		( async () => {
			await new Promise( ( r ) => setTimeout( r, 350 ) ); // فرصت بالا آمدن
			try {
				const t0 = Date.now();
				const { socksDispatcher } = await import( 'fetch-socks' );
				const res = await fetch( TEST_URL, {
					dispatcher: socksDispatcher( { url: new URL( `socks5://127.0.0.1:${ port }` ) } ),
					signal: AbortSignal.timeout( ttlMs - 500 ),
				} );
				const body = await res.json().catch( () => ( {} ) );
				clearTimeout( timer );
				done( { ok: res.ok, ms: Date.now() - t0, ip: body.ip || '' } );
			} catch ( e ) {
				clearTimeout( timer );
				done( { ok: false, ms: 0, error: String( e?.message || e ).slice( 0, 80 ) } );
			}
		} )();
	} );
}

/** تست همهٔ کاندیدهای فعال — با هم‌زمانی محدود. نتیجه در انبار ذخیره می‌شود. */
export async function testAll( onProgress = () => {} ) {
	if ( ! corePresent() ) {
		return { ok: false, error: 'هستهٔ xray نصب نیست — اول «دانلود هسته».' };
	}
	const cands = S.pool.filter( ( c ) => c.enabled );
	let done = 0;
	const CONC = 4;
	const queue = [ ...cands ];
	const worker = async () => {
		while ( queue.length ) {
			const c = queue.shift();
			const port = await freePort();
			const r = await runOnce( c.outbound, port, 8000 );
			c.ok = r.ok; c.ms = r.ok ? r.ms : 0; c.error = r.error || ''; c.lastCheck = new Date().toISOString();
			done += 1;
			onProgress( { done, total: cands.length, name: c.name, ok: c.ok, ms: c.ms } );
		}
	};
	await Promise.all( Array.from( { length: CONC }, worker ) );
	S.pool.sort( ( a, b ) => ( Number( b.pinned ) - Number( a.pinned ) ) || ( Number( b.ok ) - Number( a.ok ) ) || ( ( a.ms || 1e9 ) - ( b.ms || 1e9 ) ) );
	S.pool = S.pool.slice( 0, 60 ); // انبار: فقط کارکردنی‌ها و برترین‌ها می‌مانند
	saveState();
	logInfo( 'tunnel', 'آزمون همه تمام شد.', { working: S.pool.filter( ( c ) => c.ok ).length, total: S.pool.length } );
	return { ok: true, working: S.pool.filter( ( c ) => c.ok ).length, total: S.pool.length };
}

function serviceConfig( outbound ) {
	return {
		inbounds: [
			{ listen: '127.0.0.1', port: SOCKS_PORT, protocol: 'socks', settings: { udp: false } },
			{ listen: '127.0.0.1', port: HTTP_PORT, protocol: 'http' },
		],
		outbounds: [ outbound, { protocol: 'freedom', tag: 'direct' } ],
	};
}

function pickBest( from = 0 ) {
	for ( let i = from; i < S.pool.length; i += 1 ) {
		if ( S.pool[ i ].ok && S.pool[ i ].enabled !== false ) { return i; }
	}
	return -1;
}

async function spawnService() {
	const idx = pickBest( 0 );
	if ( idx === -1 ) {
		return { ok: false, error: 'هیچ کانفیگ سالمی در انبار نیست — اول «به‌روزرسانی منابع» و «تست همه».' };
	}
	S.currentIdx = idx;
	const cfgPath = path.join( String( HOME ), 'tunnel', 'service.json' );
	fs.mkdirSync( path.dirname( cfgPath ), { recursive: true } );
	fs.writeFileSync( cfgPath, JSON.stringify( serviceConfig( S.pool[ idx ].outbound ) ) );
	S.proc = spawn( coreBin(), [ 'run', '-c', cfgPath ], { stdio: 'ignore' } );
	S.proc.on( 'exit', ( code ) => {
		logWarn( 'tunnel', 'پروسهٔ سرویس بسته شد.', { code } );
		S.running = false;
	} );
	S.running = true;
	S.startedAt = new Date().toISOString();
	await new Promise( ( r ) => setTimeout( r, 600 ) );
	logInfo( 'tunnel', 'تونل روشن شد.', { config: S.pool[ idx ].name, socks: SOCKS_PORT, http: HTTP_PORT } );
	return { ok: true, current: S.pool[ idx ].name };
}

export async function start() {
	if ( ! corePresent() ) {
		return { ok: false, error: 'هستهٔ xray نصب نیست — اول «دانلود هسته».' };
	}
	if ( S.running ) {
		return { ok: true, already: true };
	}
	loadState();
	return spawnService();
}

export async function stop() {
	try { S.proc?.kill(); } catch {}
	S.proc = null; S.running = false; S.currentIdx = -1; S.exitIp = '';
	logInfo( 'tunnel', 'تونل خاموش شد.' );
	return { ok: true };
}

/** چرخش به کانفیگ بعدی — دستی یا خودکار بعد از شکست سلامت. */
export async function rotate() {
	if ( ! S.running ) {
		return { ok: false, error: 'تونل روشن نیست.' };
	}
	const next = pickBest( S.currentIdx + 1 ) !== -1 ? pickBest( S.currentIdx + 1 ) : pickBest( 0 );
	if ( next === -1 || next === S.currentIdx ) {
		return { ok: false, error: 'کانفیگ جایگزین سالمی نیست — دوباره تست همه.' };
	}
	await stop();
	S.running = false;
	const out = await spawnService();
	logInfo( 'tunnel', 'چرخش انجام شد.', { to: S.pool[ next ]?.name } );
	return out;
}

/** بررسی سلامت — از server در هر دقیقه صدا زده می‌شود. */
export async function healthCheck() {
	if ( ! S.running ) { return; }
	S.checks += 1;
	try {
		const { socksDispatcher } = await import( 'fetch-socks' );
		const t0 = Date.now();
		const res = await fetch( TEST_URL, {
			dispatcher: socksDispatcher( { url: new URL( `socks5://127.0.0.1:${ SOCKS_PORT }` ) } ),
			signal: AbortSignal.timeout( 9000 ),
		} );
		const body = await res.json().catch( () => ( {} ) );
		S.lastCheck = { at: new Date().toISOString(), ok: res.ok, ms: Date.now() - t0 };
		S.exitIp = body.ip || S.exitIp;
	} catch ( e ) {
		S.lastCheck = { at: new Date().toISOString(), ok: false, error: String( e?.message || e ).slice( 0, 80 ) };
		logWarn( 'tunnel', 'بررسی سلامت شکست خورد — چرخش.', S.lastCheck );
		await rotate();
	}
}

export function setSources( urls ) {
	S.sources = ( urls || [] ).map( String ).filter( ( u ) => /^https?:\/\//.test( u ) ).slice( 0, 20 );
	saveState();
	return { ok: true, count: S.sources.length };
}

export function toggleConfig( id, patch ) {
	const c = S.pool.find( ( x ) => x.id === id );
	if ( ! c ) { return { ok: false, error: 'کانفیگ پیدا نشد.' }; }
	if ( 'enabled' in patch ) { c.enabled = Boolean( patch.enabled ); }
	if ( 'pinned' in patch ) { c.pinned = Boolean( patch.pinned ); }
	saveState();
	return { ok: true };
}
