/**
 * آزمون زنده — همان کاری که آدم با ماوس می‌کند، ولی خودکار.
 *
 *   node test/live.mjs
 *
 * چرا جدا از سوئیت واحد: اینجا یک سرور واقعی بالا می‌آید، SSE وصل می‌شود، و همان مسیرهایی
 * صدا زده می‌شوند که رابط کاربری صدا می‌زند. تجربهٔ این پروژه می‌گوید بیشتر باگ‌های واقعی
 * دقیقاً همین‌جا پیدا می‌شوند، نه در تست تابع‌به‌تابع.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';

const HOME = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-home-' ) );
const WORK = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-work-' ) );
process.env.HOOSHA_HOME = HOME;

const PORT = 7900 + Math.floor( Math.random() * 80 );
const BASE = `http://127.0.0.1:${ PORT }`;

let passed = 0;
const failures = [];

async function step( name, fn ) {
	try {
		await fn();
		passed++;
		process.stdout.write( `  ✓ ${ name }\n` );
	} catch ( e ) {
		failures.push( { name, error: e?.message || String( e ) } );
		process.stdout.write( `  ✗ ${ name }\n      ${ e?.message || e }\n` );
	}
}

const { startServer } = await import( '../src/server.js' );
const { server } = await startServer( { port: PORT, host: '127.0.0.1', workspace: WORK } );

// ------------------------------------------------------------ ابزار کمکی

const events = [];
const waiters = [];

function onEvent( ev ) {
	events.push( ev );
	for ( const w of [ ...waiters ] ) {
		if ( w.match( ev ) ) {
			waiters.splice( waiters.indexOf( w ), 1 );
			w.resolve( ev );
		}
	}
}

/** @param {(ev:any)=>boolean} match */
function waitFor( match, ms = 12_000, label = 'رویداد' ) {
	const existing = events.find( match );
	if ( existing ) {
		return Promise.resolve( existing );
	}
	return new Promise( ( resolve, reject ) => {
		const w = { match, resolve };
		waiters.push( w );
		setTimeout( () => {
			if ( waiters.includes( w ) ) {
				waiters.splice( waiters.indexOf( w ), 1 );
				reject( new Error( `${ label } نیامد (${ ms }ms)` ) );
			}
		}, ms ).unref?.();
	} );
}

// جریان رویدادها
const sse = await fetch( `${ BASE }/api/events` );
const reader = sse.body.getReader();
( async () => {
	const decoder = new TextDecoder();
	let buffer = '';
	while ( true ) {
		const { value, done } = await reader.read().catch( () => ( { done: true } ) );
		if ( done ) {
			break;
		}
		buffer += decoder.decode( value, { stream: true } );
		const parts = buffer.split( '\n\n' );
		buffer = parts.pop() || '';
		for ( const part of parts ) {
			const line = part.split( '\n' ).find( ( l ) => l.startsWith( 'data: ' ) );
			if ( line ) {
				try {
					onEvent( JSON.parse( line.slice( 6 ) ) );
				} catch {
					// ping
				}
			}
		}
	}
} )();

const get = ( p ) => fetch( BASE + p ).then( ( r ) => r.json() );
const post = ( p, body ) =>
	fetch( BASE + p, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify( body || {} ) } ).then( ( r ) =>
		r.json()
	);

async function say( text ) {
	// اگر نوبت قبلی هنوز تمام نشده، پیام تازه با ۴۰۹ رد می‌شود و باعث می‌شود آزمونِ بعدی
	// به‌خاطر گناهِ آزمونِ قبلی قرمز شود. پس اول صبر می‌کنیم بیکار شود.
	for ( let i = 0; i < 100; i++ ) {
		const s = await get( '/api/state' );
		if ( ! s.busy ) {
			break;
		}
		await new Promise( ( r ) => setTimeout( r, 100 ) );
	}
	events.length = 0;
	const out = await post( '/api/message', { text } );
	assert.ok( ! out.error, `ارسال پیام شکست خورد: ${ out.error }` );
	return out;
}

async function waitIdle() {
	await waitFor( ( e ) => e.type === 'idle', 15_000, 'پایان نوبت' );
}

// ---------------------------------------------------------------- آزمون‌ها

process.stdout.write( '\nآزمون زنده\n' );

await step( 'وضعیت اولیه: ابزارها، دستورها و پرووایدر آزمایشی حاضرند', async () => {
	const s = await get( '/api/state' );
	assert.ok( s.tools.length >= 15, `تعداد ابزار کم است: ${ s.tools.length }` );
	assert.ok( s.commands.length >= 20 );
	assert.equal( s.config.workspace, WORK );
	assert.ok( s.ready.ok, 'پرووایدر آزمایشی باید آماده باشد' );
} );

await step( 'فهرست کار زنده می‌شود و در وضعیت می‌نشیند', async () => {
	await say( 'کارها خواندن کد، زدن تغییر، اجرای تست' );
	await waitIdle();
	const s = await get( '/api/state' );
	assert.equal( s.todos.length, 3 );
	assert.equal( s.todos[ 0 ].status, 'in_progress' );
} );

await step( 'نوشتن فایل: تأیید می‌خواهد، بعد از اجازه فایل ساخته می‌شود و دیف برمی‌گردد', async () => {
	await say( 'بنویس note.txt سلام' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	assert.match( ask.summary, /note\.txt/ );

	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /ساخته شد/ );
	await waitIdle();

	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'سلام' );
} );

await step( 'ویرایش چندگانه، دیف واقعی با شمار +/− می‌دهد', async () => {
	await say( 'جایگزین note.txt سلام درود' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /\+1 −1/ );
	assert.match( result.output, /^\+\s+1\s+درود$/m );
	await waitIdle();
	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'درود' );
} );

await step( 'چک‌پوینت ساخته می‌شود و بازگشت، فایل را به حالت قبل برمی‌گرداند', async () => {
	const before = await get( '/api/checkpoints' );
	assert.ok( before.checkpoints.length >= 3, 'برای هر پیام باید یک چک‌پوینت باشد' );

	// چک‌پوینتِ نوبتِ «جایگزین» — یعنی آخری.
	const target = before.checkpoints[ before.checkpoints.length - 1 ];
	assert.ok( target.files.includes( 'note.txt' ), 'فایل تغییریافته باید در چک‌پوینت ثبت شده باشد' );

	const out = await post( '/api/rewind', { id: target.id } );
	assert.ok( out.ok );
	assert.deepEqual( out.restored, [ 'note.txt' ] );
	assert.equal( ( await fs.readFile( path.join( WORK, 'note.txt' ), 'utf8' ) ).trim(), 'سلام' );
} );

await step( 'بازگشت به چک‌پوینتِ قبل از ساخت فایل، فایل را حذف می‌کند', async () => {
	const list = ( await get( '/api/checkpoints' ) ).checkpoints;
	const target = list.find( ( c ) => c.files.includes( 'note.txt' ) ) || list[ list.length - 1 ];
	await post( '/api/rewind', { id: target.id } );
	const exists = await fs
		.stat( path.join( WORK, 'note.txt' ) )
		.then( () => true )
		.catch( () => false );
	assert.equal( exists, false, 'فایلی که آن لحظه نبود، باید حذف شود' );
} );

await step( 'کارت نقشه: تأیید کاربر، حالت را عوض می‌کند و به مدل هم گفته می‌شود', async () => {
	await post( '/api/mode', { mode: 'plan' } );
	await say( 'نقشه بازطراحی صفحهٔ سبد' );
	const ask = await waitFor( ( e ) => e.type === 'ask_user' && e.kind === 'plan', 10_000, 'کارت نقشه' );
	assert.match( ask.plan, /نقشهٔ کار/ );

	await post( '/api/answer', { id: ask.id, value: { approved: true, mode: 'default' }, mode: 'default' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /تأیید کرد/ );
	await waitIdle();

	const s = await get( '/api/state' );
	assert.equal( s.config.permissions.mode, 'default' );
} );

await step( 'کارت پرسش: جواب کاربر به مدل برمی‌گردد', async () => {
	await say( 'بپرس کدام راه؟' );
	const ask = await waitFor( ( e ) => e.type === 'ask_user' && e.kind === 'question', 10_000, 'کارت پرسش' );
	assert.equal( ask.options.length, 2 );

	await post( '/api/answer', { id: ask.id, value: 'راه تمیز' } );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( result.output, /راه تمیز/ );
	await waitIdle();
} );

await step( 'شل پس‌زمینه اجرا می‌شود، در فهرست می‌آید و کشته می‌شود', async () => {
	await say( 'پس‌زمینه sleep 30' );
	const ask = await waitFor( ( e ) => e.type === 'permission_request', 10_000, 'درخواست مجوز' );
	await post( '/api/permission', { id: ask.id, decision: 'allow' } );
	await waitIdle();

	const list = ( await get( '/api/shells' ) ).shells;
	assert.equal( list.length, 1 );
	assert.equal( list[ 0 ].status, 'running' );

	await post( '/api/shells', { action: 'kill', id: list[ 0 ].id } );
	const after = ( await get( '/api/shells' ) ).shells;
	assert.equal( after[ 0 ].status, 'killed' );
} );

await step( 'کانکتور MCP از راه API اضافه، آزموده و حذف می‌شود', async () => {
	const fixture = path.resolve( 'test/fixtures/mcp-server.mjs' );

	const test = await post( '/api/connectors', { action: 'test', name: 'probe', kind: 'stdio', command: 'node', args: fixture } );
	assert.ok( test.ok, `تست کانکتور شکست خورد: ${ test.message }` );
	assert.deepEqual( test.tools, [ 'add', 'boom' ] );

	await post( '/api/connectors', { action: 'save', name: 'probe', kind: 'stdio', command: 'node', args: fixture } );
	let s = await get( '/api/state' );
	assert.ok( s.tools.some( ( t ) => t.name === 'mcp__probe__add' ), 'ابزار MCP باید در رجیستری بیاید' );
	assert.equal( s.connectors.length, 1 );

	await post( '/api/connectors', { action: 'toggle', name: 'probe', enabled: false } );
	s = await get( '/api/state' );
	assert.equal( s.mcp[ 0 ].status, 'disabled' );

	await post( '/api/connectors', { action: 'remove', name: 'probe' } );
	s = await get( '/api/state' );
	assert.equal( s.connectors.length, 0 );
	assert.equal( s.tools.some( ( t ) => t.name.startsWith( 'mcp__' ) ), false );
} );

await step( 'زیرعامل ساخته و حذف می‌شود و در ابزار task دیده می‌شود', async () => {
	const out = await post( '/api/agents', {
		action: 'save',
		name: 'reviewer',
		description: 'مرور کد',
		prompt: 'تو یک مرورگر کد سخت‌گیری.',
		tools: [ 'read_file', 'grep' ],
	} );
	assert.ok( out.ok, out.error );

	let s = await get( '/api/state' );
	assert.equal( s.agents.length, 1 );
	assert.deepEqual( s.agents[ 0 ].tools, [ 'read_file', 'grep' ] );
	const task = s.tools.find( ( t ) => t.name === 'task' );
	assert.match( task.description, /reviewer/ );

	await post( '/api/agents', { action: 'remove', name: 'reviewer' } );
	s = await get( '/api/state' );
	assert.equal( s.agents.length, 0 );
} );

await step( 'دستور کاربر ساخته می‌شود و واقعاً به پرامپت باز می‌شود', async () => {
	await post( '/api/commands', { action: 'save', name: 'salam', description: 'سلام کن', body: 'بگو سلام به $1' } );
	let s = await get( '/api/state' );
	assert.ok( s.commands.some( ( c ) => c.name === 'salam' ) );

	await say( '/salam دنیا' );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.text, 'بگو سلام به دنیا' );
	await waitIdle();

	await post( '/api/commands', { action: 'remove', name: 'salam' } );
	s = await get( '/api/state' );
	assert.equal( s.commands.some( ( c ) => c.name === 'salam' ), false );
} );

await step( 'قواعد مجوز از API ذخیره می‌شود و دروازه را باز می‌کند', async () => {
	await post( '/api/permissions', { mode: 'default', allow: [ 'bash:echo' ], ask: [], deny: [] } );
	await say( '!echo سلام' );
	const result = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'اجرای بدون پرسش' );
	assert.match( result.output, /سلام/ );
	await waitIdle();

	const denied = events.find( ( e ) => e.type === 'permission_request' );
	assert.equal( denied, undefined, 'با قاعدهٔ allow نباید پرسشی می‌آمد' );

	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );
} );

await step( 'حافظهٔ پروژه نوشته و خوانده می‌شود', async () => {
	await post( '/api/memory', { text: '# قواعد\n- تست' } );
	const out = await get( '/api/memory' );
	assert.match( out.text, /قواعد/ );
	const s = await get( '/api/state' );
	assert.equal( s.memory, true );
} );

await step( 'جستجوی فایل برای منوی «@» کار می‌کند', async () => {
	await fs.writeFile( path.join( WORK, 'readme-hoosha.md' ), '# سلام' );
	const out = await get( '/api/files?q=hoosha' );
	assert.ok( out.files.includes( 'readme-hoosha.md' ), JSON.stringify( out.files ) );
} );

await step( 'خروجی مارک‌داون گفتگو ساخته می‌شود', async () => {
	const res = await fetch( `${ BASE }/api/export?format=md` );
	const text = await res.text();
	assert.match( text, /# گفتگوی هوشا/ );
	assert.match( text, /🧑 کاربر/ );
} );

await step( 'تشخیص وضعیت، همهٔ بررسی‌ها را برمی‌گرداند', async () => {
	const out = await get( '/api/doctor' );
	assert.ok( out.checks.length >= 6 );
	assert.ok( out.checks.some( ( c ) => c.name === 'پرووایدر' ) );
} );

await step( 'نشست تغییر نام و حذف می‌شود', async () => {
	const s = await get( '/api/state' );
	await post( '/api/new', {} );
	let list = ( await get( '/api/sessions' ) ).sessions;
	assert.ok( list.length >= 1 );

	await post( '/api/sessions', { action: 'rename', id: s.sessionId, title: 'نام تازه' } );
	list = ( await get( '/api/sessions' ) ).sessions;
	assert.ok( list.some( ( x ) => x.title === 'نام تازه' ) );

	await post( '/api/sessions', { action: 'delete', id: s.sessionId } );
	list = ( await get( '/api/sessions' ) ).sessions;
	assert.equal( list.some( ( x ) => x.id === s.sessionId ), false );
} );

await step( 'مسیر ناشناخته، ۴۰۴ می‌دهد نه صفحهٔ HTML', async () => {
	const res = await fetch( `${ BASE }/api/nope` );
	assert.equal( res.status, 404 );
	const body = await res.json();
	assert.match( body.error, /ناشناخته/ );
} );


await step( 'پیام با تصویر: پیوست به مدل می‌رسد و در رویداد کاربر دیده می‌شود', async () => {
	// یک PNG یک‌پیکسلی واقعی.
	const png =
		'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	events.length = 0;
	const out = await post( '/api/message', {
		text: 'این را ببین',
		images: [ { name: 'dot.png', mediaType: 'image/png', data: png } ],
	} );
	assert.ok( ! out.error, out.error );

	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.images.length, 1 );
	assert.equal( user.images[ 0 ].name, 'dot.png' );
	await waitIdle();

	// در حافظهٔ مدل، محتوا باید تکه‌تکه شده باشد نه یک رشته.
	const session = await get( `/api/sessions/${ ( await get( '/api/state' ) ).sessionId }` );
	const stored = ( session.messages || [] ).find( ( m ) => Array.isArray( m.content ) );
	assert.ok( stored, 'پیام چندتکه باید در نشست ذخیره شده باشد' );
	assert.equal( stored.content[ 1 ].type, 'image' );
} );

await step( 'فایل غیرتصویری به‌عنوان پیوست پذیرفته نمی‌شود', async () => {
	events.length = 0;
	await post( '/api/message', {
		text: 'این فایل',
		images: [ { name: 'x.pdf', mediaType: 'application/pdf', data: 'AAAA' } ],
	} );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.equal( user.images.length, 0 );
	await waitIdle();
} );

await step( 'پرامپت MCP مثل دستور اسلش کار می‌کند و منبعش خوانده می‌شود', async () => {
	const fixture = path.resolve( 'test/fixtures/mcp-server.mjs' );
	await post( '/api/connectors', { action: 'save', name: 'demo', kind: 'stdio', command: process.execPath, args: fixture } );

	const s = await get( '/api/state' );
	assert.ok( s.commands.some( ( c ) => c.name === 'mcp__demo__greet' && c.source === 'MCP' ), 'پرامپت باید در فهرست دستورها بیاید' );
	assert.ok( s.tools.some( ( t ) => t.name === 'read_mcp_resource' ), 'ابزار خواندن منبع باید ساخته شود' );
	assert.deepEqual( s.resources.map( ( r ) => r.uri ), [ 'demo://note' ] );

	await say( '/mcp__demo__greet پیمان' );
	const user = await waitFor( ( e ) => e.type === 'user', 8000, 'پیام کاربر' );
	assert.match( user.text, /به پیمان سلام رسمی بگو/ );
	await waitIdle();

	await post( '/api/connectors', { action: 'remove', name: 'demo' } );
} );

await step( 'حالت بدون‌رابط: اجرا می‌شود، JSON می‌دهد، و بدون اجازه چیزی را اجرا نمی‌کند', async () => {
	const { execFile } = await import( 'node:child_process' );
	const run = ( args ) =>
		new Promise( ( resolve ) => {
			execFile(
				process.execPath,
				[ path.resolve( 'src/cli.js' ), ...args ],
				{ env: { ...process.env, HOOSHA_HOME: HOME }, timeout: 30_000 },
				( err, stdout ) => resolve( { code: err?.code ?? 0, stdout } )
			);
		} );

	const denied = await run( [ '-p', '!echo سلام', '--dir', WORK, '--output-format', 'json' ] );
	const a = JSON.parse( denied.stdout );
	assert.equal( a.tools.length, 0, 'در حالت پیش‌فرض نباید ابزاری اجرا شود' );
	assert.equal( a.denied.length, 1 );

	const allowed = await run( [ '-p', '!echo سلام‌بی‌رابط', '--dir', WORK, '--mode', 'auto', '--output-format', 'json' ] );
	const b = JSON.parse( allowed.stdout );
	assert.deepEqual( b.tools, [ 'bash' ] );
	assert.match( b.text, /سلام‌بی‌رابط/ );
	assert.equal( b.ok, true );
} );



await step( 'سندباکس: روشن‌کردن از API، شکستِ بسته، و اجرای واقعی با موتور جعلی', async () => {
	const bin = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-live-bin-' ) );
	const fake = path.join( bin, 'docker' );
	await fs.writeFile(
		fake,
		[ '#!/bin/sh', 'for a in "$@"; do last="$a"; done', 'eval "$last"', '' ].join( '\n' ),
		{ mode: 0o755 }
	);

	// ۱) روشن، بدون موتور کانتینر → فرمان باید رد شود، نه اینکه ساکت روی میزبان برود.
	const realPath = process.env.PATH;
	process.env.PATH = '/nonexistent-hoosha';

	let out = await post( '/api/sandbox', { action: 'save', sandbox: { enabled: true, image: 'demo:1' } } );
	assert.ok( out.ok, out.error );
	assert.equal( out.sandbox.enabled, true );
	assert.equal( out.sandbox.available, false );

	await post( '/api/permissions', { mode: 'auto', allow: [], ask: [], deny: [] } );
	await say( '!echo نباید_اجرا_شود' );
	const failed = await waitFor( ( e ) => e.type === 'tool_result' || e.type === 'tool_error', 10_000, 'نتیجهٔ ابزار' );
	assert.match( String( failed.output || failed.error ), /موتور کانتینر پیدا نشد/ );
	await waitIdle();

	// ۲) همان تنظیمات، این بار با موتور جعلی در PATH → باید واقعاً از راه docker برود.
	process.env.PATH = `${ bin }:${ realPath }`;
	const status = await get( '/api/sandbox' );
	assert.equal( status.available, true );
	assert.equal( status.runtimeName, 'docker' );

	await say( '!echo از_داخل_کانتینر' );
	const ok = await waitFor( ( e ) => e.type === 'tool_result', 10_000, 'نتیجهٔ ابزار' );
	assert.match( ok.output, /از_داخل_کانتینر/ );
	assert.match( ok.output, /سندباکس: docker/ );
	await waitIdle();

	// ۳) خاموش‌کردن، همه‌چیز را به حالت قبل برمی‌گرداند.
	out = await post( '/api/sandbox', { action: 'save', sandbox: { enabled: false } } );
	assert.equal( out.sandbox.enabled, false );
	await post( '/api/permissions', { mode: 'default', allow: [], ask: [], deny: [] } );

	process.env.PATH = realPath;
	await fs.rm( bin, { recursive: true, force: true } );
} );

await step( 'ابزار notebook_edit در فهرست ابزارها هست و روی فایل واقعی کار می‌کند', async () => {
	const s = await get( '/api/state' );
	assert.ok( s.tools.some( ( t ) => t.name === 'notebook_edit' ), 'ابزار نوت‌بوک باید ثبت شده باشد' );

	const nb = {
		nbformat: 4,
		nbformat_minor: 5,
		metadata: {},
		cells: [ { cell_type: 'code', id: 'a1', metadata: {}, execution_count: 3, source: [ 'print(1)' ], outputs: [ { output_type: 'stream', text: [ '1\n' ] } ] } ],
	};
	await fs.writeFile( path.join( WORK, 'live.ipynb' ), JSON.stringify( nb ) );

	const { TOOLS } = await import( '../src/tools.js' );
	const out = await TOOLS.notebook_edit.run(
		{ path: 'live.ipynb', mode: 'insert', cell_type: 'markdown', source: 'یادداشت' },
		{ workspace: WORK }
	);
	assert.match( out, /افزوده شد/ );

	const after = JSON.parse( await fs.readFile( path.join( WORK, 'live.ipynb' ), 'utf8' ) );
	assert.equal( after.cells.length, 2 );
	assert.equal( after.cells[ 1 ].cell_type, 'markdown' );
} );


// ------------------------------------------------------------------- پایان

reader.cancel().catch( () => {} );
server.close();

process.stdout.write( `\n${ '-'.repeat( 56 ) }\n` );
if ( failures.length ) {
	process.stdout.write( `${ passed } موفق، ${ failures.length } ناموفق\n` );
	for ( const f of failures ) {
		process.stdout.write( `  ✗ ${ f.name }: ${ f.error }\n` );
	}
	process.exitCode = 1;
} else {
	process.stdout.write( `${ passed } آزمون زنده، همه موفق\n` );
}

await fs.rm( HOME, { recursive: true, force: true } ).catch( () => {} );
await fs.rm( WORK, { recursive: true, force: true } ).catch( () => {} );
setTimeout( () => process.exit( process.exitCode || 0 ), 300 ).unref();
