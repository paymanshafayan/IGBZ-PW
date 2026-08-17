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

import { loadConfig, saveConfig, publicConfig, activeProfile, HOME } from './config.js';
import { PROVIDERS, createProvider, validateProfile, providerInfo } from './providers/index.js';
import { MODES } from './permissions.js';
import { saveSession, listSessions, loadSession } from './session.js';
import { Runtime } from './runtime.js';
import { parseInput, BUILTIN_COMMANDS } from './commands.js';
import { listPlugins, installPlugin, removePlugin, setPluginEnabled, fetchMarketplace } from './plugins.js';
import { explain } from './errors.js';

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

export async function startServer( { port = 7788, host = '127.0.0.1', workspace } = {} ) {
	const boot = await loadConfig();
	if ( workspace ) {
		boot.workspace = path.resolve( workspace );
		await saveConfig( boot );
	}

	/** @type {Set<import('node:http').ServerResponse>} */
	const clients = new Set();
	/** @type {any[]} */
	let transcript = [];
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

	const runtime = new Runtime( broadcast );
	await runtime.reload();
	await runtime.loadProjectMemory();
	await runtime.hooks.run( 'SessionStart', { sessionId } );

	const server = http.createServer( async ( req, res ) => {
		const url = new URL( req.url || '/', `http://${ req.headers.host || 'localhost' }` );
		const send = ( code, body, type = 'application/json; charset=utf-8' ) => {
			res.writeHead( code, { 'Content-Type': type, 'Cache-Control': 'no-store' } );
			res.end( typeof body === 'string' || Buffer.isBuffer( body ) ? body : JSON.stringify( body ) );
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
				const cfg = runtime.config;
				const active = activeProfile( cfg ) || {};
				return send( 200, {
					config: publicConfig( cfg ),
					providers: PROVIDERS,
					modes: MODES,
					ready: runtime.ready,
					hasKey: Boolean( active.apiKey ),
					home: HOME,
					busy: Boolean( runtime.agent?.busy ),
					transcript,
					sessionId,
					usage: runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 },
					tools: Object.values( runtime.tools() ).map( ( t ) => ( {
						name: t.spec.name,
						description: t.spec.description,
						risk: t.risk,
					} ) ),
					skills: runtime.skills.map( ( s ) => ( { name: s.name, description: s.description, source: s.source } ) ),
					commands: [
						...BUILTIN_COMMANDS.map( ( c ) => ( { ...c, source: 'builtin' } ) ),
						...runtime.commands.map( ( c ) => ( { name: c.name, description: c.description, source: c.source } ) ),
					],
					mcp: runtime.mcp.status,
					plugins: await listPlugins( HOME ),
					memory: Boolean( runtime.projectMemory ),
				} );
			}

			// ----------------------------------------------------------- تنظیمات
			if ( url.pathname === '/api/profile' && req.method === 'POST' ) {
				const body = await readJson( req );
				const cfg = await loadConfig();
				const id = body.id || 'default';
				const prev = cfg.profiles[ id ] || {};
				const info = providerInfo( body.provider );
				cfg.profiles[ id ] = {
					label: body.label || prev.label || id,
					provider: body.provider,
					baseUrl: body.baseUrl ?? ( info?.editableBaseUrl ? prev.baseUrl : '' ) ?? '',
					apiKey: body.apiKey ? body.apiKey : prev.apiKey || '',
					model: body.model || info?.defaultModel || '',
				};
				cfg.activeProfile = id;
				await saveConfig( cfg );
				await runtime.reload();
				return send( 200, { ok: true, config: publicConfig( runtime.config ), ready: runtime.ready } );
			}

			if ( url.pathname === '/api/mode' && req.method === 'POST' ) {
				const body = await readJson( req );
				if ( ! MODES.includes( body.mode ) ) {
					return send( 400, { error: 'حالت نامعتبر' } );
				}
				const cfg = await loadConfig();
				cfg.permissions.mode = body.mode;
				await saveConfig( cfg );
				runtime.config = cfg;
				if ( runtime.agent ) {
					runtime.agent.rules = cfg.permissions;
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
				const cfg = await loadConfig();
				cfg.workspace = dir;
				await saveConfig( cfg );
				await runtime.reload();
				await runtime.loadProjectMemory();
				broadcast( { type: 'workspace', path: dir } );
				return send( 200, { ok: true, path: dir } );
			}

			// آزمودن واقعی پرووایدر: یک درخواست کوچک، و خطای قابل‌فهم.
			if ( url.pathname === '/api/test-connection' && req.method === 'POST' ) {
				const cfg = await loadConfig();
				const profile = activeProfile( cfg );
				const check = validateProfile( profile );
				if ( ! check.ok ) {
					return send( 200, { ok: false, message: `تنظیمات ناقص است: ${ check.missing.join( '، ' ) }` } );
				}

				const info = providerInfo( profile.provider );
				const base = profile.baseUrl || info?.baseUrl;
				try {
					const provider = createProvider( profile );
					let text = '';
					for await ( const ev of provider.stream( {
						model: profile.model || info?.defaultModel || '',
						messages: [ { role: 'user', content: 'بگو: سلام' } ],
						maxTokens: 16,
					} ) ) {
						if ( ev.type === 'text' ) {
							text += ev.text;
						}
						if ( ev.type === 'error' ) {
							throw new Error( ev.error );
						}
					}
					return send( 200, {
						ok: true,
						message: `اتصال برقرار است. پاسخ مدل: «${ text.trim().slice( 0, 60 ) || '(خالی)' }»`,
					} );
				} catch ( e ) {
					const info2 = explain( e, { baseUrl: base, model: profile.model, provider: profile.provider } );
					return send( 200, { ok: false, message: info2.message, hint: info2.hint, kind: info2.kind } );
				}
			}

			if ( url.pathname === '/api/models' && req.method === 'GET' ) {
				const cfg = await loadConfig();
				const p = activeProfile( cfg );
				try {
					const provider = createProvider( p );
					return send( 200, { models: await provider.listModels() } );
				} catch ( e ) {
					const info = explain( e, { baseUrl: p?.baseUrl, provider: p?.provider } );
					return send( 200, { models: [], error: info.message, hint: info.hint } );
				}
			}

			// ------------------------------------------------------------ پلاگین
			if ( url.pathname === '/api/plugins' && req.method === 'POST' ) {
				const body = await readJson( req );
				try {
					if ( body.action === 'install' ) {
						const out = await installPlugin( HOME, String( body.source || '' ), body.name );
						await runtime.reload();
						return send( 200, { ok: true, plugin: out } );
					}
					if ( body.action === 'remove' ) {
						await removePlugin( HOME, String( body.name || '' ) );
						await runtime.reload();
						return send( 200, { ok: true } );
					}
					if ( body.action === 'toggle' ) {
						await setPluginEnabled( HOME, String( body.name || '' ), Boolean( body.enabled ) );
						await runtime.reload();
						return send( 200, { ok: true } );
					}
					if ( body.action === 'marketplace' ) {
						return send( 200, { ok: true, marketplace: await fetchMarketplace( String( body.source || '' ) ) } );
					}
					return send( 400, { error: 'کنش ناشناخته' } );
				} catch ( e ) {
					return send( 400, { error: e?.message || String( e ) } );
				}
			}

			if ( url.pathname === '/api/reload' && req.method === 'POST' ) {
				await runtime.reload();
				await runtime.loadProjectMemory();
				broadcast( { type: 'notice', text: 'اسکیل‌ها، پلاگین‌ها و سرورهای MCP دوباره بارگذاری شدند.' } );
				return send( 200, { ok: true, mcp: runtime.mcp.status } );
			}

			// -------------------------------------------------------------- چت
			if ( url.pathname === '/api/message' && req.method === 'POST' ) {
				const body = await readJson( req );
				const text = String( body.text || '' ).trim();
				if ( ! text ) {
					return send( 400, { error: 'متن خالی است.' } );
				}

				const intent = parseInput( text, runtime.commands );
				if ( intent.kind === 'builtin' ) {
					const out = await handleBuiltin( intent.name, intent.args );
					return send( 200, { ok: true, handled: true, ...out } );
				}

				if ( ! runtime.ready.ok ) {
					return send( 400, { error: `تنظیمات ناقص است: ${ runtime.ready.missing.join( '، ' ) }` } );
				}
				const agent = runtime.agent;
				if ( agent.busy ) {
					return send( 409, { error: 'یک درخواست در حال اجراست.' } );
				}
				agent.run( intent.text ).then( () => saveSession( sessionId, { messages: agent.messages, transcript } ) );
				return send( 202, { ok: true } );
			}

			if ( url.pathname === '/api/permission' && req.method === 'POST' ) {
				const body = await readJson( req );

				// «همیشه اجازه بده» یعنی یک قاعدهٔ ماندگار، نه فقط پاسخ به همین یک بار.
				if ( body.remember && body.rule ) {
					const cfg = await loadConfig();
					cfg.permissions.allow = [ ...new Set( [ ...( cfg.permissions.allow || [] ), String( body.rule ) ] ) ];
					await saveConfig( cfg );
					runtime.config = cfg;
					if ( runtime.agent ) {
						runtime.agent.rules = cfg.permissions;
					}
					broadcast( { type: 'notice', text: `از این پس «${ body.rule }» بدون پرسش اجرا می‌شود.` } );
				}

				return send( 200, { ok: Boolean( runtime.agent?.resolvePermission( body.id, body.decision ) ) } );
			}

			if ( url.pathname === '/api/stop' && req.method === 'POST' ) {
				runtime.agent?.stop();
				return send( 200, { ok: true } );
			}

			if ( url.pathname === '/api/new' && req.method === 'POST' ) {
				if ( runtime.agent ) {
					await saveSession( sessionId, { messages: runtime.agent.messages, transcript } );
				}
				transcript = [];
				sessionId = `s_${ Date.now().toString( 36 ) }`;
				await runtime.reload( { keepHistory: false } );
				broadcast( { type: 'reset', sessionId } );
				return send( 200, { ok: true, sessionId } );
			}

			if ( url.pathname === '/api/sessions' && req.method === 'GET' ) {
				return send( 200, { sessions: await listSessions() } );
			}

			if ( url.pathname === '/api/resume' && req.method === 'POST' ) {
				const body = await readJson( req );
				const saved = await loadSession( String( body.id || '' ) );
				if ( ! saved ) {
					return send( 404, { error: 'نشست پیدا نشد.' } );
				}
				await runtime.reload( { keepHistory: false } );
				runtime.agent.messages = saved.messages || [];
				transcript = saved.transcript || [];
				sessionId = saved.id;
				broadcast( { type: 'resumed', sessionId, transcript } );
				return send( 200, { ok: true, sessionId, transcript } );
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

	/**
	 * دستورهای داخلی — این‌ها اصلاً به مدل نمی‌رسند.
	 * @param {string} name
	 * @param {string} args
	 */
	async function handleBuiltin( name, args ) {
		const say = ( text ) => {
			broadcast( { type: 'system', text } );
			return { text };
		};

		switch ( name ) {
			case 'help': {
				const lines = [
					'**دستورهای داخلی**',
					...BUILTIN_COMMANDS.map( ( c ) => `/${ c.name } — ${ c.description }` ),
				];
				if ( runtime.commands.length ) {
					lines.push( '', '**دستورهای خودت**' );
					lines.push( ...runtime.commands.map( ( c ) => `/${ c.name } — ${ c.description || '' } (${ c.source })` ) );
				}
				return say( lines.join( '\n' ) );
			}

			case 'clear': {
				transcript = [];
				sessionId = `s_${ Date.now().toString( 36 ) }`;
				await runtime.reload( { keepHistory: false } );
				broadcast( { type: 'reset', sessionId } );
				return { ok: true };
			}

			case 'compact': {
				if ( ! runtime.agent?.messages.length ) {
					return say( 'چیزی برای فشرده‌کردن نیست.' );
				}
				const r = await runtime.agent.compactNow();
				return say( `گفتگو فشرده شد: ${ r.before } پیام → ${ r.after } پیام.` );
			}

			case 'mode': {
				if ( ! args ) {
					return say( `حالت فعلی: ${ runtime.config.permissions.mode }` );
				}
				if ( ! MODES.includes( args ) ) {
					return say( `حالت نامعتبر. یکی از این‌ها: ${ MODES.join( ' | ' ) }` );
				}
				const cfg = await loadConfig();
				cfg.permissions.mode = args;
				await saveConfig( cfg );
				runtime.config = cfg;
				if ( runtime.agent ) {
					runtime.agent.rules = cfg.permissions;
				}
				broadcast( { type: 'mode', mode: args } );
				return say( `حالت شد: ${ args }` );
			}

			case 'model': {
				const cfg = await loadConfig();
				const profile = activeProfile( cfg );
				if ( ! args ) {
					return say( `پرووایدر: ${ profile.provider }\nمدل: ${ profile.model }` );
				}
				profile.model = args;
				await saveConfig( cfg );
				await runtime.reload();
				broadcast( { type: 'profile' } );
				return say( `مدل شد: ${ args }` );
			}

			case 'tools': {
				const tools = Object.values( runtime.tools() );
				return say(
					[ `${ tools.length } ابزار در دسترس:`, ...tools.map( ( t ) => `• ${ t.spec.name } (${ t.risk })` ) ].join( '\n' )
				);
			}

			case 'skills': {
				if ( ! runtime.skills.length ) {
					return say( 'هیچ اسکیلی نصب نیست.\nپوشهٔ اسکیل‌ها: ~/.hoosha/skills/<name>/SKILL.md' );
				}
				return say(
					[
						`${ runtime.skills.length } اسکیل:`,
						...runtime.skills.map( ( s ) => `• ${ s.name } — ${ s.description } [${ s.source }]` ),
					].join( '\n' )
				);
			}

			case 'mcp': {
				if ( ! runtime.mcp.status.length ) {
					return say( 'هیچ سرور MCP تنظیم نشده.\nدر config.json کلید mcpServers را پر کن یا .hoosha/mcp.json بساز.' );
				}
				return say(
					runtime.mcp.status
						.map( ( s ) =>
							s.status === 'connected'
								? `✓ ${ s.name } — ${ s.tools.length } ابزار: ${ s.tools.join( ', ' ) }`
								: `✗ ${ s.name } — ${ s.status }${ s.error ? `: ${ s.error }` : '' }`
						)
						.join( '\n' )
				);
			}

			case 'plugin': {
				const [ sub, ...rest ] = args.split( /\s+/ ).filter( Boolean );
				const value = rest.join( ' ' );
				try {
					if ( ! sub || sub === 'list' ) {
						const list = await listPlugins( HOME );
						return say(
							list.length
								? list
										.map(
											( p ) =>
												`${ p.enabled ? '✓' : '✗' } ${ p.name } — اسکیل: ${ p.has.skills }، دستور: ${ p.has.commands }${
													p.has.mcp ? '، MCP' : ''
												}${ p.has.hooks ? '، هوک' : '' }`
										)
										.join( '\n' )
								: 'هیچ پلاگینی نصب نیست.'
						);
					}
					if ( sub === 'install' ) {
						const out = await installPlugin( HOME, value );
						await runtime.reload();
						return say( `پلاگین «${ out.name }» نصب شد.` );
					}
					if ( sub === 'remove' ) {
						await removePlugin( HOME, value );
						await runtime.reload();
						return say( `پلاگین «${ value }» حذف شد.` );
					}
					return say( 'کاربرد: /plugin list | install <منبع> | remove <نام>' );
				} catch ( e ) {
					return say( `خطا: ${ e?.message || e }` );
				}
			}

			case 'permissions': {
				const p = runtime.config.permissions;
				return say(
					[
						`حالت: ${ p.mode }`,
						`مجاز: ${ ( p.allow || [] ).join( '، ' ) || '—' }`,
						`با پرسش: ${ ( p.ask || [] ).join( '، ' ) || '—' }`,
						`ممنوع: ${ ( p.deny || [] ).join( '، ' ) || '—' }`,
					].join( '\n' )
				);
			}

			case 'cost': {
				const u = runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 };
				return say( `توکن ورودی: ${ u.inputTokens }\nتوکن خروجی: ${ u.outputTokens }` );
			}

			case 'workspace': {
				if ( ! args ) {
					return say( `پوشهٔ کاری: ${ runtime.config.workspace }` );
				}
				const dir = path.resolve( args );
				const stat = await fs.stat( dir ).catch( () => null );
				if ( ! stat?.isDirectory() ) {
					return say( 'این مسیر یک پوشه نیست.' );
				}
				const cfg = await loadConfig();
				cfg.workspace = dir;
				await saveConfig( cfg );
				await runtime.reload();
				await runtime.loadProjectMemory();
				broadcast( { type: 'workspace', path: dir } );
				return say( `پوشهٔ کاری شد: ${ dir }` );
			}

			case 'sessions': {
				const list = await listSessions();
				return say(
					list.length
						? list.map( ( s ) => `• ${ s.id } — ${ s.title }` ).join( '\n' )
						: 'نشست ذخیره‌شده‌ای نیست.'
				);
			}

			default:
				return say( `دستور ناشناخته: /${ name }\nبرای فهرست دستورها /help را بزن.` );
		}
	}

	await new Promise( ( resolve ) => server.listen( port, host, resolve ) );

	const shutdown = async () => {
		await runtime.hooks.run( 'SessionEnd', { sessionId } ).catch( () => {} );
		await runtime.close();
	};
	process.on( 'SIGINT', async () => {
		await shutdown();
		process.exit( 0 );
	} );
	process.on( 'SIGTERM', async () => {
		await shutdown();
		process.exit( 0 );
	} );

	return { server, port, host, config: runtime.config, runtime };
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
