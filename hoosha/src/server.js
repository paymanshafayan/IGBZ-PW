/**
 * سرور محلی هوشا.
 *
 * یک سرور کوچک روی لوکال‌هاست که رابط کاربری را سرو می‌کند و رویدادهای عامل را با SSE
 * می‌فرستد. هرچه در رابط کاربری قابل‌کلیک است، اینجا یک مسیر دارد — قاعده این است که
 * **هیچ قابلیتی فقط با ویرایش دستی JSON در دسترس نباشد**.
 *
 * مسیرها با یک جدول تعریف شده‌اند (نه زنجیرهٔ if) تا اضافه‌کردن قابلیت بعدی، یک سطر باشد.
 */

import http from 'node:http';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadConfig, saveConfig, publicConfig, activeProfile, HOME } from './config.js';
import { PROVIDERS, createProvider, validateProfile, providerInfo } from './providers/index.js';
import { MODES } from './permissions.js';
import { saveSession, listSessions, loadSession, deleteSession, renameSession } from './session.js';
import { Runtime } from './runtime.js';
import { parseInput, BUILTIN_COMMANDS, saveCommand, removeCommand } from './commands.js';
import { listPlugins, installPlugin, removePlugin, setPluginEnabled, fetchMarketplace } from './plugins.js';
import { installSkill, removeSkill, setSkillEnabled } from './skillstore.js';
import { listConnectors, saveConnector, removeConnector, setConnectorEnabled, testConnector } from './connectors.js';
import { saveAgent, removeAgent } from './agents.js';
import { CheckpointStore } from './checkpoints.js';
import { shells } from './background.js';
import { listFiles, fuzzyFilter, readWorkspaceFile, gitStatus, gitDiff } from './workspace.js';
import { estimateCost, estimateContextTokens, recordUsage, readUsage } from './usage.js';
import { toMarkdown, toJson } from './export.js';
import { diagnose } from './doctor.js';
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
	'.woff2': 'font/woff2',
	'.json': 'application/json; charset=utf-8',
};

/** پنجرهٔ کانتکست تقریبی مدل‌های رایج — فقط برای نوار «چقدر پر شده». */
const CONTEXT_WINDOW = 200_000;

/**
 * رویدادهایی که در نوار گفتگو ذخیره می‌شوند.
 *
 * فهرست سفید است نه سیاه، و دلیلش یک باگ واقعی است: رویداد `rewound` خودِ نوار گفتگو را
 * با خودش حمل می‌کند؛ اگر آن را داخل همان نوار بگذاریم، ساختار حلقه می‌زند و
 * JSON.stringify کل درخواست را می‌ترکاند.
 */
const STORED_EVENTS = new Set( [
	'user',
	'assistant_end',
	'system',
	'notice',
	'error',
	'tool_start',
	'tool_result',
	'tool_error',
	'tool_denied',
	'permission_request',
	'ask_user',
	'subagent_start',
	'subagent_end',
	'compacted',
] );

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
	let sessionTitle = '';
	/** @type {{content:string,status:string}[]} */
	let todos = [];
	/** @type {any[]} */
	let pendingAsk = [];
	/** @type {{inputTokens:number,outputTokens:number,cost:number}} */
	let sessionCost = { inputTokens: 0, outputTokens: 0, cost: 0 };

	const broadcast = ( ev ) => {

		// فهرست کارها را زنده نگه می‌داریم تا رابط کاربری بتواند همیشه نشانش بدهد.
		if ( ev.type === 'tool_log' && typeof ev.text === 'string' && ev.text.includes( '"todos"' ) ) {
			try {
				const parsed = JSON.parse( ev.text );
				if ( Array.isArray( parsed.todos ) ) {
					todos = parsed.todos;
					ev = { ...ev, todos };
				}
			} catch {
				// لاگ عادی بود، نه فهرست کار.
			}
		}

		if ( ev.type === 'ask_user' ) {
			pendingAsk.push( ev );
		}
		if ( ev.type === 'ask_answered' ) {
			pendingAsk = pendingAsk.filter( ( a ) => a.id !== ev.id );
		}

		if ( STORED_EVENTS.has( ev.type ) ) {
			transcript.push( { ...ev, at: Date.now() } );
		}

		const payload = `data: ${ JSON.stringify( ev ) }\n\n`;
		for ( const res of clients ) {
			res.write( payload );
		}
	};

	const runtime = new Runtime( broadcast );
	runtime.onTurnEnd = async ( { usage, model } ) => {
		const cfg = runtime.config;
		const cost = estimateCost( model, usage, cfg?.pricing );
		sessionCost = { ...usage, cost: cost ?? 0 };
		await recordUsage( HOME, {
			model,
			inputTokens: usage.inputTokens,
			outputTokens: usage.outputTokens,
			cost: cost ?? 0,
		} ).catch( () => {} );
		broadcast( { type: 'usage', usage, cost } );
	};

	await runtime.reload();
	await runtime.loadProjectMemory();
	runtime.checkpoints = new CheckpointStore( { home: HOME, workspace: runtime.config.workspace, sessionId } );
	if ( runtime.agent ) {
		runtime.agent.checkpoints = runtime.checkpoints;
	}
	await runtime.hooks.run( 'SessionStart', { sessionId } );

	/** پس از تغییر پوشهٔ کاری یا نشست، چک‌پوینت‌ها باید دنبالش بروند. */
	function rebindCheckpoints() {
		runtime.checkpoints = new CheckpointStore( { home: HOME, workspace: runtime.config.workspace, sessionId } );
		if ( runtime.agent ) {
			runtime.agent.checkpoints = runtime.checkpoints;
		}
	}

	// ------------------------------------------------------------------ وضعیت

	async function buildState() {
		const cfg = runtime.config;
		const active = activeProfile( cfg ) || {};
		const info = providerInfo( active.provider );
		const contextTokens = estimateContextTokens( runtime.agent?.messages || [] );

		return {
			config: publicConfig( cfg ),
			providers: PROVIDERS,
			modes: MODES,
			ready: runtime.ready,
			hasKey: Boolean( active.apiKey ),
			home: HOME,
			busy: Boolean( runtime.agent?.busy ),
			transcript,
			sessionId,
			sessionTitle,
			todos,
			pendingAsk,
			usage: {
				...( runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 } ),
				cost: sessionCost.cost,
			},
			context: { used: contextTokens, window: CONTEXT_WINDOW },
			tools: Object.values( runtime.tools() ).map( ( t ) => ( {
				name: t.spec.name,
				description: t.spec.description,
				risk: t.risk,
			} ) ),
			skills: runtime.skills.map( ( s ) => ( { name: s.name, description: s.description, source: s.source } ) ),
			agents: runtime.agents.map( ( a ) => ( {
				name: a.name,
				description: a.description,
				tools: a.tools || [],
				model: a.model || '',
				source: a.source,
				prompt: a.prompt,
			} ) ),
			commands: [
				...BUILTIN_COMMANDS.map( ( c ) => ( { ...c, source: 'builtin' } ) ),
				...runtime.commands.map( ( c ) => ( { name: c.name, description: c.description, source: c.source, body: c.body } ) ),
			],
			mcp: runtime.mcp.status,
			connectors: await listConnectors( { workspace: cfg.workspace } ),
			plugins: await listPlugins( HOME ),
			shells: shells.list(),
			checkpoints: await runtime.checkpoints.list(),
			memory: Boolean( runtime.projectMemory ),
			providerInfo: info || null,
			version: '0.3.0',
		};
	}

	// ------------------------------------------------------------------ مسیرها

	/** @type {Record<string, (c:any)=>any>} */
	const routes = {
		'GET /api/state': async () => ( { status: 200, body: await buildState() } ),

		// ---------------------------------------------------------- پرووایدر
		'POST /api/profile': async ( { body } ) => saveProfileRoute( body ),
		'POST /api/profiles': async ( { body } ) => {
			const cfg = await loadConfig();
			if ( body.action === 'activate' ) {
				if ( ! cfg.profiles?.[ body.id ] ) {
					return { status: 404, body: { error: 'پروفایل پیدا نشد.' } };
				}
				cfg.activeProfile = body.id;
				await saveConfig( cfg );
				await runtime.reload();
				broadcast( { type: 'profile' } );
				return { status: 200, body: { ok: true, config: publicConfig( runtime.config ), ready: runtime.ready } };
			}
			if ( body.action === 'remove' ) {
				if ( Object.keys( cfg.profiles || {} ).length <= 1 ) {
					return { status: 400, body: { error: 'آخرین پروفایل را نمی‌شود حذف کرد.' } };
				}
				delete cfg.profiles[ body.id ];
				if ( cfg.activeProfile === body.id ) {
					cfg.activeProfile = Object.keys( cfg.profiles )[ 0 ];
				}
				await saveConfig( cfg );
				await runtime.reload();
				return { status: 200, body: { ok: true, config: publicConfig( runtime.config ) } };
			}
			return saveProfileRoute( body );
		},

		'POST /api/test-connection': async ( { body } ) => {
			const cfg = await loadConfig();
			const profile = body?.id ? cfg.profiles?.[ body.id ] : activeProfile( cfg );
			const check = validateProfile( profile );
			if ( ! check.ok ) {
				return { status: 200, body: { ok: false, message: `تنظیمات ناقص است: ${ check.missing.join( '، ' ) }` } };
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
				return { status: 200, body: { ok: true, message: `اتصال برقرار است. پاسخ مدل: «${ text.trim().slice( 0, 60 ) || '(خالی)' }»` } };
			} catch ( e ) {
				const info2 = explain( e, { baseUrl: base, model: profile.model, provider: profile.provider } );
				return { status: 200, body: { ok: false, message: info2.message, hint: info2.hint, kind: info2.kind } };
			}
		},

		'GET /api/models': async () => {
			const cfg = await loadConfig();
			const p = activeProfile( cfg );
			try {
				const provider = createProvider( p );
				return { status: 200, body: { models: await provider.listModels() } };
			} catch ( e ) {
				const info = explain( e, { baseUrl: p?.baseUrl, provider: p?.provider } );
				return { status: 200, body: { models: [], error: info.message, hint: info.hint } };
			}
		},

		// ------------------------------------------------------------- حالت
		'POST /api/mode': async ( { body } ) => {
			if ( ! MODES.includes( body.mode ) ) {
				return { status: 400, body: { error: 'حالت نامعتبر' } };
			}
			await setMode( body.mode );
			return { status: 200, body: { ok: true } };
		},

		'POST /api/permissions': async ( { body } ) => {
			const cfg = await loadConfig();
			cfg.permissions = {
				mode: MODES.includes( body.mode ) ? body.mode : cfg.permissions.mode,
				allow: cleanList( body.allow ?? cfg.permissions.allow ),
				ask: cleanList( body.ask ?? cfg.permissions.ask ),
				deny: cleanList( body.deny ?? cfg.permissions.deny ),
			};
			await saveConfig( cfg );
			runtime.config = cfg;
			if ( runtime.agent ) {
				runtime.agent.rules = cfg.permissions;
			}
			broadcast( { type: 'mode', mode: cfg.permissions.mode } );
			return { status: 200, body: { ok: true, permissions: cfg.permissions } };
		},

		'POST /api/workspace': async ( { body } ) => {
			const dir = path.resolve( String( body.path || '' ) );
			const stat = await fs.stat( dir ).catch( () => null );
			if ( ! stat?.isDirectory() ) {
				return { status: 400, body: { error: 'این مسیر یک پوشه نیست.' } };
			}
			const cfg = await loadConfig();
			cfg.workspace = dir;
			await saveConfig( cfg );
			await runtime.reload();
			await runtime.loadProjectMemory();
			rebindCheckpoints();
			broadcast( { type: 'workspace', path: dir } );
			return { status: 200, body: { ok: true, path: dir } };
		},

		// -------------------------------------------------------- کانکتورها
		'POST /api/connectors': async ( { body } ) => {
			const scope = body.scope === 'project' ? 'project' : 'user';
			const opts = { workspace: runtime.config.workspace, scope };
			try {
				if ( body.action === 'test' ) {
					return { status: 200, body: await testConnector( body, HOME ) };
				}
				if ( body.action === 'save' ) {
					const out = await saveConnector( opts, body );
					await runtime.reload();
					broadcast( { type: 'notice', text: `کانکتور «${ out.name }» ذخیره شد.` } );
					return { status: 200, body: { ok: true, connector: out, mcp: runtime.mcp.status } };
				}
				if ( body.action === 'remove' ) {
					await removeConnector( opts, String( body.name ) );
					await runtime.reload();
					return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
				}
				if ( body.action === 'toggle' ) {
					await setConnectorEnabled( opts, String( body.name ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ----------------------------------------------------------- اسکیل
		'POST /api/skills': async ( { body } ) => {
			try {
				if ( body.action === 'install' ) {
					const out = await installSkill( HOME, String( body.source || '' ), body.name );
					await runtime.reload();
					return { status: 200, body: { ok: true, ...out } };
				}
				if ( body.action === 'remove' ) {
					await removeSkill( HOME, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'toggle' ) {
					await setSkillEnabled( HOME, String( body.name || '' ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- پلاگین
		'POST /api/plugins': async ( { body } ) => {
			try {
				if ( body.action === 'install' ) {
					const out = await installPlugin( HOME, String( body.source || '' ), body.name );
					await runtime.reload();
					return { status: 200, body: { ok: true, plugin: out } };
				}
				if ( body.action === 'remove' ) {
					await removePlugin( HOME, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'toggle' ) {
					await setPluginEnabled( HOME, String( body.name || '' ), Boolean( body.enabled ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				if ( body.action === 'marketplace' ) {
					return { status: 200, body: { ok: true, marketplace: await fetchMarketplace( String( body.source || '' ) ) } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ---------------------------------------------------------- عامل‌ها
		'POST /api/agents': async ( { body } ) => {
			const roots = { home: HOME, workspace: runtime.config.workspace };
			try {
				if ( body.action === 'remove' ) {
					await removeAgent( roots, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				const out = await saveAgent( roots, body );
				await runtime.reload();
				return { status: 200, body: { ok: true, agent: out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- دستورها
		'POST /api/commands': async ( { body } ) => {
			const roots = { home: HOME, workspace: runtime.config.workspace };
			try {
				if ( body.action === 'remove' ) {
					await removeCommand( roots, String( body.name || '' ) );
					await runtime.reload();
					return { status: 200, body: { ok: true } };
				}
				const out = await saveCommand( roots, body );
				await runtime.reload();
				return { status: 200, body: { ok: true, command: out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ------------------------------------------------------------ هوک
		'POST /api/hooks': async ( { body } ) => {
			const cfg = await loadConfig();
			cfg.hooks = body.hooks && typeof body.hooks === 'object' ? body.hooks : {};
			await saveConfig( cfg );
			await runtime.reload();
			return { status: 200, body: { ok: true, hooks: cfg.hooks } };
		},

		'GET /api/hooks': async () => ( { status: 200, body: { hooks: runtime.config.hooks || {} } } ),

		// ------------------------------------------------------- حافظهٔ پروژه
		'GET /api/memory': async () => {
			const file = path.join( runtime.config.workspace, 'HOOSHA.md' );
			const text = await fs.readFile( file, 'utf8' ).catch( () => '' );
			return { status: 200, body: { path: file, text } };
		},

		'POST /api/memory': async ( { body } ) => {
			const file = path.join( runtime.config.workspace, 'HOOSHA.md' );
			await fs.writeFile( file, String( body.text ?? '' ), 'utf8' );
			await runtime.loadProjectMemory();
			return { status: 200, body: { ok: true, path: file } };
		},

		// ------------------------------------------------------------ فایل‌ها
		'GET /api/files': async ( { url } ) => {
			const q = url.searchParams.get( 'q' ) || '';
			const files = await listFiles( runtime.config.workspace );
			return { status: 200, body: { files: fuzzyFilter( files, q, 25 ), total: files.length } };
		},

		'GET /api/file': async ( { url } ) => {
			try {
				return { status: 200, body: await readWorkspaceFile( runtime.config.workspace, url.searchParams.get( 'path' ) || '' ) };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		'GET /api/git': async ( { url } ) => {
			const status = await gitStatus( runtime.config.workspace );
			const file = url.searchParams.get( 'diff' );
			const diff = file !== null ? await gitDiff( runtime.config.workspace, file || undefined ) : undefined;
			return { status: 200, body: { git: status, diff } };
		},

		// -------------------------------------------------------- چک‌پوینت
		'GET /api/checkpoints': async () => ( { status: 200, body: { checkpoints: await runtime.checkpoints.list() } } ),

		'POST /api/rewind': async ( { body } ) => {
			try {
				const out = await runtime.checkpoints.restore( String( body.id ), {
					files: body.files !== false,
					conversation: body.conversation !== false,
				} );
				if ( body.conversation !== false && runtime.agent ) {
					const kept = runtime.agent.messages.slice( 0, out.messageCount );
					runtime.agent.messages = kept;
					transcript = trimTranscript( transcript, kept.filter( ( m ) => m.role === 'user' ).length );
				}
				broadcast( {
					type: 'rewound',
					restored: out.restored,
					deleted: out.deleted,
					transcript,
				} );
				return { status: 200, body: { ok: true, ...out } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// ---------------------------------------------------------- شل‌ها
		'GET /api/shells': async () => ( { status: 200, body: { shells: shells.list() } } ),

		'POST /api/shells': async ( { body } ) => {
			try {
				if ( body.action === 'kill' ) {
					shells.kill( String( body.id ) );
					return { status: 200, body: { ok: true, shells: shells.list() } };
				}
				if ( body.action === 'read' ) {
					return { status: 200, body: shells.read( String( body.id ), { peek: true } ) };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		// --------------------------------------------------------- مصرف
		'GET /api/usage': async () => {
			const history = await readUsage( HOME );
			return {
				status: 200,
				body: {
					session: { ...( runtime.agent?.usage || { inputTokens: 0, outputTokens: 0 } ), cost: sessionCost.cost },
					history,
					model: runtime.agent?.model || '',
				},
			};
		},

		'GET /api/doctor': async () => {
			const active = activeProfile( runtime.config ) || {};
			return {
				status: 200,
				body: await diagnose( {
					home: HOME,
					workspace: runtime.config.workspace,
					config: runtime.config,
					runtime,
					providerInfo: providerInfo( active.provider ),
				} ),
			};
		},

		'GET /api/export': async ( { url } ) => {
			const data = {
				sessionId,
				transcript,
				messages: runtime.agent?.messages || [],
				model: runtime.agent?.model,
				workspace: runtime.config.workspace,
			};
			if ( url.searchParams.get( 'format' ) === 'json' ) {
				return { status: 200, raw: toJson( data ), type: 'application/json; charset=utf-8' };
			}
			return { status: 200, raw: toMarkdown( data ), type: 'text/markdown; charset=utf-8' };
		},

		// ------------------------------------------------------------- چت
		'POST /api/message': async ( { body } ) => {
			const text = String( body.text || '' ).trim();
			if ( ! text ) {
				return { status: 400, body: { error: 'متن خالی است.' } };
			}

			const intent = parseInput( text, runtime.commands );
			if ( intent.kind === 'builtin' ) {
				const out = await handleBuiltin( intent.name, intent.args );
				return { status: 200, body: { ok: true, handled: true, ...out } };
			}

			if ( ! runtime.ready.ok ) {
				return { status: 400, body: { error: `تنظیمات ناقص است: ${ runtime.ready.missing.join( '، ' ) }` } };
			}
			const agent = runtime.agent;
			if ( agent.busy ) {
				return { status: 409, body: { error: 'یک درخواست در حال اجراست.' } };
			}

			if ( ! sessionTitle ) {
				sessionTitle = text.slice( 0, 60 );
			}
			await runtime.checkpoints.begin( { label: text.slice( 0, 80 ), messageCount: agent.messages.length } );
			broadcast( { type: 'checkpoint', checkpoints: await runtime.checkpoints.list() } );

			agent
				.run( intent.text )
				.then( () => saveSession( sessionId, { messages: agent.messages, transcript, title: sessionTitle } ) );
			return { status: 202, body: { ok: true } };
		},

		'POST /api/permission': async ( { body } ) => {
			if ( body.remember && body.rule ) {
				const cfg = await loadConfig();
				const bucket = body.decision === 'deny' ? 'deny' : 'allow';
				cfg.permissions[ bucket ] = [ ...new Set( [ ...( cfg.permissions[ bucket ] || [] ), String( body.rule ) ] ) ];
				await saveConfig( cfg );
				runtime.config = cfg;
				if ( runtime.agent ) {
					runtime.agent.rules = cfg.permissions;
				}
				broadcast( {
					type: 'notice',
					text:
						bucket === 'allow'
							? `از این پس «${ body.rule }» بدون پرسش اجرا می‌شود.`
							: `از این پس «${ body.rule }» همیشه رد می‌شود.`,
				} );
			}
			return { status: 200, body: { ok: Boolean( runtime.agent?.resolvePermission( body.id, body.decision ) ) } };
		},

		'POST /api/answer': async ( { body } ) => {
			// جواب دادن به ask_user_question یا تأیید نقشه (exit_plan_mode).
			if ( body.mode && MODES.includes( body.mode ) ) {
				await setMode( body.mode );
			}
			const ok = Boolean( runtime.agent?.resolveQuestion( body.id, body.value ) );
			broadcast( { type: 'ask_answered', id: body.id, value: body.value } );
			return { status: 200, body: { ok } };
		},

		'POST /api/stop': async () => {
			runtime.agent?.stop();
			return { status: 200, body: { ok: true } };
		},

		'POST /api/new': async () => {
			if ( runtime.agent && runtime.agent.messages.length ) {
				await saveSession( sessionId, { messages: runtime.agent.messages, transcript, title: sessionTitle } );
			}
			transcript = [];
			todos = [];
			pendingAsk = [];
			sessionTitle = '';
			sessionCost = { inputTokens: 0, outputTokens: 0, cost: 0 };
			sessionId = `s_${ Date.now().toString( 36 ) }`;
			await runtime.reload( { keepHistory: false } );
			rebindCheckpoints();
			broadcast( { type: 'reset', sessionId } );
			return { status: 200, body: { ok: true, sessionId } };
		},

		'GET /api/sessions': async () => ( { status: 200, body: { sessions: await listSessions() } } ),

		'POST /api/sessions': async ( { body } ) => {
			try {
				if ( body.action === 'delete' ) {
					await deleteSession( String( body.id ) );
					return { status: 200, body: { ok: true, sessions: await listSessions() } };
				}
				if ( body.action === 'rename' ) {
					await renameSession( String( body.id ), String( body.title || '' ) );
					if ( body.id === sessionId ) {
						sessionTitle = String( body.title || '' );
					}
					return { status: 200, body: { ok: true, sessions: await listSessions() } };
				}
				return { status: 400, body: { error: 'کنش ناشناخته' } };
			} catch ( e ) {
				return { status: 400, body: { error: e?.message || String( e ) } };
			}
		},

		'POST /api/resume': async ( { body } ) => {
			const saved = await loadSession( String( body.id || '' ) );
			if ( ! saved ) {
				return { status: 404, body: { error: 'نشست پیدا نشد.' } };
			}
			await runtime.reload( { keepHistory: false } );
			runtime.agent.messages = saved.messages || [];
			transcript = saved.transcript || [];
			sessionId = saved.id;
			sessionTitle = saved.title || '';
			rebindCheckpoints();
			broadcast( { type: 'resumed', sessionId, transcript, title: sessionTitle } );
			return { status: 200, body: { ok: true, sessionId, transcript } };
		},

		'POST /api/reload': async () => {
			await runtime.reload();
			await runtime.loadProjectMemory();
			broadcast( { type: 'notice', text: 'اسکیل‌ها، پلاگین‌ها، عامل‌ها و کانکتورها دوباره بارگذاری شدند.' } );
			return { status: 200, body: { ok: true, mcp: runtime.mcp.status } };
		},
	};

	// ------------------------------------------------------------- کمکی‌ها

	/** @param {any} body */
	async function saveProfileRoute( body ) {
		const cfg = await loadConfig();
		const id = String( body.id || 'default' ).replace( /[^\w-]/g, '' ) || 'default';
		const prev = cfg.profiles[ id ] || {};
		const info = providerInfo( body.provider );
		cfg.profiles[ id ] = {
			label: body.label || prev.label || id,
			provider: body.provider || prev.provider,
			baseUrl: body.baseUrl ?? ( info?.editableBaseUrl ? prev.baseUrl : '' ) ?? '',
			apiKey: body.apiKey ? body.apiKey : prev.apiKey || '',
			model: body.model || info?.defaultModel || '',
		};
		if ( body.activate !== false ) {
			cfg.activeProfile = id;
		}
		await saveConfig( cfg );
		await runtime.reload();
		broadcast( { type: 'profile' } );
		return { status: 200, body: { ok: true, config: publicConfig( runtime.config ), ready: runtime.ready } };
	}

	/** @param {string} mode */
	async function setMode( mode ) {
		const cfg = await loadConfig();
		cfg.permissions.mode = mode;
		await saveConfig( cfg );
		runtime.config = cfg;
		if ( runtime.agent ) {
			runtime.agent.rules = cfg.permissions;
		}
		broadcast( { type: 'mode', mode } );
	}

	const server = http.createServer( async ( req, res ) => {
		const url = new URL( req.url || '/', `http://${ req.headers.host || 'localhost' }` );
		const send = ( code, body, type = 'application/json; charset=utf-8' ) => {
			res.writeHead( code, { 'Content-Type': type, 'Cache-Control': 'no-store' } );
			res.end( typeof body === 'string' || Buffer.isBuffer( body ) ? body : JSON.stringify( body ) );
		};

		try {
			// رویدادها (SSE)
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

			const key = `${ req.method } ${ url.pathname }`;
			const handler = routes[ key ];
			if ( handler ) {
				const body = req.method === 'POST' ? await readJson( req ) : {};
				const out = await handler( { body, url, req } );
				if ( out.raw !== undefined ) {
					return send( out.status || 200, out.raw, out.type );
				}
				return send( out.status || 200, out.body );
			}

			// یک نشست مشخص
			if ( url.pathname.startsWith( '/api/sessions/' ) && req.method === 'GET' ) {
				const id = url.pathname.split( '/' ).pop();
				return send( 200, ( await loadSession( id ) ) || { error: 'پیدا نشد' } );
			}

			if ( url.pathname.startsWith( '/api/' ) ) {
				return send( 404, { error: `مسیر ناشناخته: ${ url.pathname }` } );
			}

			// فایل‌های رابط کاربری
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
		const open = ( panel, tab ) => {
			broadcast( { type: 'open_panel', panel, tab } );
			return { panel, tab };
		};

		switch ( name ) {
			case 'help': {
				const lines = [ '**دستورهای داخلی**', ...BUILTIN_COMMANDS.map( ( c ) => `/${ c.name } — ${ c.description }` ) ];
				if ( runtime.commands.length ) {
					lines.push( '', '**دستورهای خودت**' );
					lines.push( ...runtime.commands.map( ( c ) => `/${ c.name } — ${ c.description || '' } (${ c.source })` ) );
				}
				lines.push( '', 'میان‌برها: Shift+Tab حالت · Esc توقف · Esc Esc بازگشت · @ فایل · / دستور · Ctrl+K جستجو · ? میان‌برها' );
				return say( lines.join( '\n' ) );
			}

			case 'clear': {
				transcript = [];
				todos = [];
				pendingAsk = [];
				sessionTitle = '';
				sessionId = `s_${ Date.now().toString( 36 ) }`;
				await runtime.reload( { keepHistory: false } );
				rebindCheckpoints();
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
				await setMode( args );
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

			case 'config':
			case 'settings':
				return open( 'settings', 'provider' );

			case 'login':
			case 'provider':
				return open( 'settings', 'provider' );

			case 'connectors':
			case 'mcp': {
				if ( ! args ) {
					return open( 'settings', 'connectors' );
				}
				if ( ! runtime.mcp.status.length ) {
					return say( 'هیچ کانکتوری تنظیم نشده. از تنظیمات → کانکتورها یکی اضافه کن.' );
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

			case 'tools':
				return open( 'settings', 'tools' );

			case 'skills':
				return open( 'settings', 'skills' );

			case 'agents':
				return open( 'settings', 'agents' );

			case 'hooks':
				return open( 'settings', 'hooks' );

			case 'memory':
			case 'init':
				return open( 'settings', 'memory' );

			case 'permissions':
				return open( 'settings', 'permissions' );

			case 'usage':
			case 'cost':
				return open( 'settings', 'usage' );

			case 'doctor':
			case 'status':
				return open( 'settings', 'status' );

			case 'plugin': {
				const [ sub, ...rest ] = args.split( /\s+/ ).filter( Boolean );
				const value = rest.join( ' ' );
				if ( ! sub ) {
					return open( 'settings', 'plugins' );
				}
				try {
					if ( sub === 'list' ) {
						const list = await listPlugins( HOME );
						return say(
							list.length
								? list.map( ( p ) => `${ p.enabled ? '✓' : '✗' } ${ p.name }` ).join( '\n' )
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

			case 'rewind': {
				const list = await runtime.checkpoints.list();
				if ( ! list.length ) {
					return say( 'هنوز چک‌پوینتی ساخته نشده.' );
				}
				broadcast( { type: 'open_rewind', checkpoints: list } );
				return { ok: true };
			}

			case 'bashes': {
				const list = shells.list();
				if ( ! list.length ) {
					return say( 'شل پس‌زمینه‌ای در کار نیست.' );
				}
				return say( list.map( ( s ) => `• ${ s.id } [${ s.status }] ${ s.command }` ).join( '\n' ) );
			}

			case 'todos': {
				if ( ! todos.length ) {
					return say( 'فهرست کار خالی است.' );
				}
				const icon = { pending: '☐', in_progress: '▸', completed: '☑' };
				return say( todos.map( ( t ) => `${ icon[ t.status ] || '☐' } ${ t.content }` ).join( '\n' ) );
			}

			case 'export': {
				broadcast( { type: 'export', format: args || 'md' } );
				return { ok: true };
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
				rebindCheckpoints();
				broadcast( { type: 'workspace', path: dir } );
				return say( `پوشهٔ کاری شد: ${ dir }` );
			}

			case 'sessions':
			case 'resume': {
				broadcast( { type: 'open_sessions' } );
				return { ok: true };
			}

			default:
				return say( `دستور ناشناخته: /${ name }\nبرای فهرست دستورها /help را بزن.` );
		}
	}

	await new Promise( ( resolve ) => server.listen( port, host, resolve ) );

	const shutdown = async () => {
		await runtime.hooks.run( 'SessionEnd', { sessionId } ).catch( () => {} );
		shells.killAll();
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

/** @param {any} value */
function cleanList( value ) {
	if ( ! Array.isArray( value ) ) {
		return [];
	}
	return [ ...new Set( value.map( ( v ) => String( v ).trim() ).filter( Boolean ) ) ];
}

/**
 * بریدن نوار رویدادها هم‌زمان با بریدن پیام‌ها.
 *
 * معیار، شمارش رویدادهای `user` است: اگر بعد از بازگشت فقط N پیام کاربر مانده، هرچه بعد از
 * شروع پیام کاربرِ N+1 آمده باید از صفحه هم برود. وگرنه کاربر متنی را می‌بیند که دیگر در
 * حافظهٔ مدل نیست — و این بدترین نوع سردرگمی است.
 *
 * @param {any[]} list
 * @param {number} userCount
 */
export function trimTranscript( list, userCount ) {
	if ( userCount <= 0 ) {
		return [];
	}
	let seen = 0;
	const out = [];
	for ( const ev of list ) {
		if ( ev.type === 'user' ) {
			seen++;
			if ( seen > userCount ) {
				break;
			}
		}
		out.push( ev );
	}
	return out;
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
