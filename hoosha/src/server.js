/**
 * سرور محلی هوشا.
 *
 * نسخهٔ دسکتاپ روی همین می‌چرخد: یک سرور کوچک روی لوکال‌هاست که رابط کاربری را سرو
 * می‌کند و رویدادهای عامل را با SSE می‌فرستد. پوستهٔ Electron (پوشهٔ desktop/) هم فقط
 * همین را در یک پنجره باز می‌کند — یعنی یک هسته، دو ظاهر.
 */

import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadConfig, saveConfig, publicConfig, activeProfile } from './config.js';
import { PROVIDERS, createProvider, validateProfile, providerInfo } from './providers/index.js';
import { Agent } from './agent.js';
import { MODES } from './permissions.js';
import { saveSession, listSessions, loadSession } from './session.js';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const UI_DIR = path.join( __dirname, '..', 'ui' );

const MIME = {
	'.html': 'text/html; charset=utf-8',
	'.js': 'text/javascript; charset=utf-8',
	'.css': 'text/css; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.ico': 'image/x-icon',
};

export async function startServer( { port = 7788, host = '0.0.0.0', workspace } = {} ) {
	const cfg = await loadConfig();
	if ( workspace ) {
		cfg.workspace = path.resolve( workspace );
		await saveConfig( cfg );
	}

	/** @type {Set<import('node:http').ServerResponse>} */
	const clients = new Set();
	/** @type {any[]} */
	let transcript = [];
	/** @type {Agent|null} */
	let agent = null;
	let sessionId = `s_${ Date.now().toString( 36 ) }`;

	const broadcast = ( ev ) => {
		if ( ev.type !== 'text' ) {
			transcript.push( { ...ev, at: Date.now() } );
		}
		const payload = `data: ${ JSON.stringify( ev ) }\n\n`;
		for ( const res of clients ) {
			res.write( payload );
		}
	};

	async function buildAgent() {
		const fresh = await loadConfig();
		const profile = activeProfile( fresh );
		if ( ! profile ) {
			throw new Error( 'هیچ پروفایلی تنظیم نشده است.' );
		}
		const check = validateProfile( profile );
		if ( ! check.ok ) {
			throw new Error( `تنظیمات ناقص است: ${ check.missing.join( '، ' ) }` );
		}
		const info = providerInfo( profile.provider );
		const provider = createProvider( profile );
		const next = new Agent( {
			provider,
			model: profile.model || info?.defaultModel || '',
			workspace: fresh.workspace,
			rules: fresh.permissions,
			emit: broadcast,
		} );
		if ( agent ) {
			next.messages = agent.messages;
			next.usage = agent.usage;
		}
		agent = next;
		return agent;
	}

	const server = http.createServer( async ( req, res ) => {
		const url = new URL( req.url || '/', `http://${ req.headers.host || 'localhost' }` );
		const send = ( code, body, type = 'application/json; charset=utf-8' ) => {
			res.writeHead( code, { 'Content-Type': type, 'Cache-Control': 'no-store' } );
			res.end( typeof body === 'string' ? body : JSON.stringify( body ) );
		};

		try {
			// ---------------------------------------------------------- رویدادها
			if ( url.pathname === '/api/events' ) {
				res.writeHead( 200, {
					'Content-Type': 'text/event-stream; charset=utf-8',
					'Cache-Control': 'no-cache',
					Connection: 'keep-alive',
					'X-Accel-Buffering': 'no',
				} );
				res.write( `data: ${ JSON.stringify( { type: 'hello', sessionId } ) }\n\n` );
				clients.add( res );
				const ping = setInterval( () => res.write( ': ping\n\n' ), 25_000 );
				req.on( 'close', () => {
					clearInterval( ping );
					clients.delete( res );
				} );
				return;
			}

			// ------------------------------------------------------------ وضعیت
			if ( url.pathname === '/api/state' && req.method === 'GET' ) {
				const fresh = await loadConfig();
				const profile = activeProfile( fresh );
				return send( 200, {
					config: publicConfig( fresh ),
					providers: PROVIDERS,
					modes: MODES,
					ready: profile ? validateProfile( profile ) : { ok: false, missing: [ 'پروفایل' ] },
					busy: Boolean( agent?.busy ),
					transcript,
					sessionId,
					usage: agent?.usage || { inputTokens: 0, outputTokens: 0 },
				} );
			}

			// ----------------------------------------------------------- تنظیمات
			if ( url.pathname === '/api/profile' && req.method === 'POST' ) {
				const body = await readJson( req );
				const fresh = await loadConfig();
				const id = body.id || 'default';
				const prev = fresh.profiles[ id ] || {};
				const info = providerInfo( body.provider );
				fresh.profiles[ id ] = {
					label: body.label || prev.label || id,
					provider: body.provider,
					baseUrl: body.baseUrl ?? ( info?.editableBaseUrl ? prev.baseUrl : '' ) ?? '',
					// کلید خالی یعنی «دست نزن» تا ماسک باعث پاک‌شدن کلید نشود.
					apiKey: body.apiKey ? body.apiKey : prev.apiKey || '',
					model: body.model || info?.defaultModel || '',
				};
				fresh.activeProfile = id;
				await saveConfig( fresh );
				await buildAgent().catch( () => {} );
				return send( 200, { ok: true, config: publicConfig( fresh ) } );
			}

			if ( url.pathname === '/api/mode' && req.method === 'POST' ) {
				const body = await readJson( req );
				const fresh = await loadConfig();
				if ( ! MODES.includes( body.mode ) ) {
					return send( 400, { error: 'حالت نامعتبر' } );
				}
				fresh.permissions.mode = body.mode;
				await saveConfig( fresh );
				if ( agent ) {
					agent.rules = fresh.permissions;
				}
				broadcast( { type: 'mode', mode: body.mode } );
				return send( 200, { ok: true } );
			}

			if ( url.pathname === '/api/workspace' && req.method === 'POST' ) {
				const body = await readJson( req );
				const dir = path.resolve( String( body.path || '' ) );
				const stat = await fs.stat( dir ).catch( () => null );
				if ( ! stat?.isDirectory() ) {
					return send( 400, { error: 'این مسیر یک پوشه نیست.' } );
				}
				const fresh = await loadConfig();
				fresh.workspace = dir;
				await saveConfig( fresh );
				if ( agent ) {
					agent.workspace = dir;
				}
				broadcast( { type: 'workspace', path: dir } );
				return send( 200, { ok: true, path: dir } );
			}

			if ( url.pathname === '/api/models' && req.method === 'GET' ) {
				const fresh = await loadConfig();
				const profile = activeProfile( fresh );
				try {
					const provider = createProvider( profile );
					const models = await provider.listModels();
					return send( 200, { models } );
				} catch ( e ) {
					return send( 200, { models: [], error: e?.message || String( e ) } );
				}
			}

			// -------------------------------------------------------------- چت
			if ( url.pathname === '/api/message' && req.method === 'POST' ) {
				const body = await readJson( req );
				const text = String( body.text || '' ).trim();
				if ( ! text ) {
					return send( 400, { error: 'متن خالی است.' } );
				}
				const a = await buildAgent();
				if ( a.busy ) {
					return send( 409, { error: 'یک درخواست در حال اجراست.' } );
				}
				a.run( text ).then( () => saveSession( sessionId, { messages: a.messages, transcript } ) );
				return send( 202, { ok: true } );
			}

			if ( url.pathname === '/api/permission' && req.method === 'POST' ) {
				const body = await readJson( req );
				const ok = agent?.resolvePermission( body.id, body.decision );
				return send( 200, { ok: Boolean( ok ) } );
			}

			if ( url.pathname === '/api/stop' && req.method === 'POST' ) {
				agent?.stop();
				return send( 200, { ok: true } );
			}

			if ( url.pathname === '/api/new' && req.method === 'POST' ) {
				if ( agent ) {
					await saveSession( sessionId, { messages: agent.messages, transcript } );
				}
				agent = null;
				transcript = [];
				sessionId = `s_${ Date.now().toString( 36 ) }`;
				broadcast( { type: 'reset', sessionId } );
				return send( 200, { ok: true, sessionId } );
			}

			if ( url.pathname === '/api/sessions' && req.method === 'GET' ) {
				return send( 200, { sessions: await listSessions() } );
			}

			if ( url.pathname.startsWith( '/api/sessions/' ) && req.method === 'GET' ) {
				const id = url.pathname.split( '/' ).pop();
				return send( 200, ( await loadSession( id ) ) || { error: 'پیدا نشد' } );
			}

			// ------------------------------------------------------ فایل‌های UI
			const rel = url.pathname === '/' ? 'index.html' : url.pathname.replace( /^\/+/, '' );
			const file = path.join( UI_DIR, rel );
			if ( ! file.startsWith( UI_DIR ) ) {
				return send( 403, { error: 'ممنوع' } );
			}
			const data = await fs.readFile( file ).catch( () => null );
			if ( ! data ) {
				return send( 404, { error: 'پیدا نشد' } );
			}
			return send( 200, data, MIME[ path.extname( file ) ] || 'application/octet-stream' );
		} catch ( e ) {
			return send( 500, { error: e?.message || String( e ) } );
		}
	} );

	await new Promise( ( resolve ) => server.listen( port, host, resolve ) );
	return { server, port, host, config: cfg };
}

/** @param {import('node:http').IncomingMessage} req */
function readJson( req ) {
	return new Promise( ( resolve, reject ) => {
		let raw = '';
		req.on( 'data', ( c ) => {
			raw += c;
			if ( raw.length > 5_000_000 ) {
				reject( new Error( 'بدنهٔ درخواست خیلی بزرگ است.' ) );
				req.destroy();
			}
		} );
		req.on( 'end', () => {
			try {
				resolve( raw ? JSON.parse( raw ) : {} );
			} catch {
				reject( new Error( 'JSON نامعتبر' ) );
			}
		} );
		req.on( 'error', reject );
	} );
}
