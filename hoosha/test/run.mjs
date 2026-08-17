/**
 * تست‌های هوشا — بدون وابستگی، مثل بقیهٔ این مخزن.
 *
 *   node test/run.mjs
 *
 * قاعده‌ای که از پروژهٔ اصلی می‌آید: سوئیتی که بار اول سبز شود چیزی ثابت نکرده. هر تستی
 * که اینجاست، با خراب‌کردن عمدی کد قرمز شده است.
 */

import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import http from 'node:http';

let passed = 0;
const failures = [];

/**
 * @param {string} name
 * @param {() => any} fn
 */
async function test( name, fn ) {
	try {
		await fn();
		passed++;
		process.stdout.write( `  ✓ ${ name }\n` );
	} catch ( e ) {
		failures.push( { name, error: e?.message || String( e ) } );
		process.stdout.write( `  ✗ ${ name }\n      ${ e?.message || e }\n` );
	}
}

function section( title ) {
	process.stdout.write( `\n${ title }\n` );
}

const tmpRoot = await fs.mkdtemp( path.join( os.tmpdir(), 'hoosha-test-' ) );

// ---------------------------------------------------------------- ابزارها

section( 'ابزارها' );

const { TOOLS } = await import( '../src/tools.js' );
const ctx = { workspace: tmpRoot };

await test( 'write_file فایل می‌سازد و read_file همان را برمی‌گرداند', async () => {
	await TOOLS.write_file.run( { path: 'a/b.txt', content: 'سلام\nدنیا' }, ctx );
	const out = await TOOLS.read_file.run( { path: 'a/b.txt' }, ctx );
	assert.match( out, /سلام/ );
	assert.match( out, /1→/, 'خروجی باید شماره‌گذاری شده باشد' );
} );

await test( 'edit_file وقتی رشته یکتا نیست، امتناع می‌کند', async () => {
	await TOOLS.write_file.run( { path: 'dup.txt', content: 'x\nx\n' }, ctx );
	await assert.rejects( () => TOOLS.edit_file.run( { path: 'dup.txt', old_string: 'x', new_string: 'y' }, ctx ), /تکرار/ );
	const out = await TOOLS.edit_file.run(
		{ path: 'dup.txt', old_string: 'x', new_string: 'y', replace_all: true },
		ctx
	);
	assert.match( out, /2 جایگزینی/ );
} );

await test( 'مسیر بیرون از پوشهٔ کاری رد می‌شود', async () => {
	await assert.rejects( () => TOOLS.read_file.run( { path: '../../etc/passwd' }, ctx ), /بیرون از پوشهٔ کاری/ );
	await assert.rejects( () => TOOLS.write_file.run( { path: '../x.txt', content: 'z' }, ctx ), /بیرون از پوشهٔ کاری/ );
} );

await test( 'glob و grep کار می‌کنند', async () => {
	await TOOLS.write_file.run( { path: 'src/one.js', content: 'export const alpha = 1;' }, ctx );
	await TOOLS.write_file.run( { path: 'src/two.js', content: 'const beta = 2;' }, ctx );
	const globbed = await TOOLS.glob.run( { pattern: 'src/*.js' }, ctx );
	assert.match( globbed, /one\.js/ );
	assert.match( globbed, /two\.js/ );
	const grepped = await TOOLS.grep.run( { pattern: 'alpha' }, ctx );
	assert.match( grepped, /one\.js:1/ );
	assert.doesNotMatch( grepped, /two\.js/ );
} );

await test( 'bash خروجی و کد خروج را برمی‌گرداند', async () => {
	const out = await TOOLS.bash.run( { command: 'echo سلام && exit 0' }, ctx );
	assert.match( out, /exit=0/ );
	assert.match( out, /سلام/ );
} );

// ---------------------------------------------------------------- مجوزها

section( 'مجوزها' );

const { decide } = await import( '../src/permissions.js' );

await test( 'حالت عادی: خواندن آزاد، نوشتن و اجرا با پرسش', () => {
	const rules = { mode: 'default' };
	assert.equal( decide( 'read_file', {}, rules ).decision, 'allow' );
	assert.equal( decide( 'write_file', {}, rules ).decision, 'ask' );
	assert.equal( decide( 'bash', {}, rules ).decision, 'ask' );
} );

await test( 'حالت پلن هر ابزار تغییردهنده را رد می‌کند', () => {
	const rules = { mode: 'plan' };
	assert.equal( decide( 'read_file', {}, rules ).decision, 'allow' );
	assert.equal( decide( 'bash', {}, rules ).decision, 'deny' );
	assert.equal( decide( 'write_file', {}, rules ).decision, 'deny' );
} );

await test( 'حالت خودکار همه‌چیز را مجاز می‌کند مگر فهرست ممنوع', () => {
	const rules = { mode: 'auto', deny: [ 'bash:rm -rf' ] };
	assert.equal( decide( 'write_file', {}, rules ).decision, 'allow' );
	assert.equal( decide( 'bash', { command: 'ls' }, rules ).decision, 'allow' );
	assert.equal( decide( 'bash', { command: 'rm -rf /tmp/x' }, rules ).decision, 'deny' );
} );

await test( 'قاعدهٔ پیشوندی فقط همان پیشوند را می‌گیرد', () => {
	const rules = { mode: 'default', allow: [ 'bash:git status' ] };
	assert.equal( decide( 'bash', { command: 'git status --short' }, rules ).decision, 'allow' );
	assert.equal( decide( 'bash', { command: 'git push' }, rules ).decision, 'ask' );
} );

await test( 'ابزار MCP از رجیستری پویا شناخته می‌شود', () => {
	const registry = { 'mcp__x__do': { risk: 'exec', spec: {}, run: async () => '' } };
	assert.equal( decide( 'mcp__x__do', {}, { mode: 'default' }, registry ).decision, 'ask' );
	assert.equal( decide( 'mcp__x__do', {}, { mode: 'auto' }, registry ).decision, 'allow' );
	assert.equal( decide( 'mcp__x__do', {}, { mode: 'default' } ).decision, 'deny', 'بدون رجیستری باید ناشناخته باشد' );
} );

// ---------------------------------------------------------------- اسکیل‌ها

section( 'اسکیل‌ها' );

const { parseFrontmatter, loadSkillsFrom, collectSkills, makeSkillTool } = await import( '../src/skills.js' );

await test( 'فرانت‌متر با فهرست و رشته پارس می‌شود', () => {
	const { data, body } = parseFrontmatter(
		'---\nname: seo\ndescription: "بهینه‌سازی"\nallowed-tools:\n  - read_file\n  - grep\n---\nمتن اسکیل'
	);
	assert.equal( data.name, 'seo' );
	assert.equal( data.description, 'بهینه‌سازی' );
	assert.deepEqual( data['allowed-tools'], [ 'read_file', 'grep' ] );
	assert.equal( body.trim(), 'متن اسکیل' );
} );

await test( 'اسکیل از پوشه خوانده می‌شود و ابزار skill بازش می‌کند', async () => {
	const dir = path.join( tmpRoot, 'skills', 'demo' );
	await fs.mkdir( dir, { recursive: true } );
	await fs.writeFile(
		path.join( dir, 'SKILL.md' ),
		'---\nname: demo\ndescription: نمونه\n---\nگام یک. گام دو.',
		'utf8'
	);
	const skills = await loadSkillsFrom( path.join( tmpRoot, 'skills' ), 'user' );
	assert.equal( skills.length, 1 );
	assert.equal( skills[ 0 ].name, 'demo' );

	const tool = makeSkillTool( () => skills );
	const out = await tool.run( { name: 'demo' } );
	assert.match( out, /گام یک/ );
	await assert.rejects( () => tool.run( { name: 'nope' } ), /پیدا نشد/ );
} );

await test( 'اسکیل پروژه بر اسکیل سراسری اولویت دارد', async () => {
	const home = path.join( tmpRoot, 'h' );
	const ws = path.join( tmpRoot, 'w' );
	for ( const [ root, text ] of [
		[ path.join( home, 'skills', 'same' ), 'سراسری' ],
		[ path.join( ws, '.hoosha', 'skills', 'same' ), 'پروژه' ],
	] ) {
		await fs.mkdir( root, { recursive: true } );
		await fs.writeFile( path.join( root, 'SKILL.md' ), `---\nname: same\n---\n${ text }`, 'utf8' );
	}
	const skills = await collectSkills( { home, workspace: ws } );
	assert.equal( skills.length, 1 );
	assert.match( skills[ 0 ].body, /پروژه/ );
} );

// ---------------------------------------------------------------- دستورها

section( 'دستورهای اسلش' );

const { parseInput, expand, loadCommandsFrom } = await import( '../src/commands.js' );

await test( 'متن عادی دستور نیست', () => {
	assert.deepEqual( parseInput( 'سلام', [] ), { kind: 'prompt', text: 'سلام' } );
} );

await test( 'دستور داخلی با پارامتر شناخته می‌شود', () => {
	const out = parseInput( '/mode auto', [] );
	assert.equal( out.kind, 'builtin' );
	assert.equal( out.name, 'mode' );
	assert.equal( out.args, 'auto' );
} );

await test( 'دستور کاربر به پرامپت باز می‌شود و پارامترها جای می‌گیرند', async () => {
	const dir = path.join( tmpRoot, 'commands' );
	await fs.mkdir( dir, { recursive: true } );
	await fs.writeFile( path.join( dir, 'review.md' ), '---\ndescription: بازبینی\n---\nفایل $1 را بازبینی کن: $ARGUMENTS', 'utf8' );
	const cmds = await loadCommandsFrom( dir, 'user' );
	const out = parseInput( '/review src/app.js با دقت', cmds );
	assert.equal( out.kind, 'prompt' );
	assert.match( out.text, /فایل src\/app\.js را بازبینی کن/ );
	assert.match( out.text, /src\/app\.js با دقت/ );
} );

await test( 'expand بدون پارامتر، جای‌خالی را خالی می‌گذارد', () => {
	assert.equal( expand( 'x $1 y', '' ), 'x  y' );
} );

// ---------------------------------------------------------------- هوک‌ها

section( 'هوک‌ها' );

const { HookRunner } = await import( '../src/hooks.js' );

await test( 'هوک با کد ۲ جلوی ابزار را می‌گیرد', async () => {
	const runner = new HookRunner( {
		workspace: tmpRoot,
		hooks: { PreToolUse: [ { matcher: 'bash', command: 'echo "نه" >&2; exit 2' } ] },
	} );
	const res = await runner.run( 'PreToolUse', { tool: 'bash' } );
	assert.equal( res.blocked, true );
	assert.match( res.reason, /نه/ );
} );

await test( 'matcher غیرمرتبط، هوک را اجرا نمی‌کند', async () => {
	const runner = new HookRunner( {
		workspace: tmpRoot,
		hooks: { PreToolUse: [ { matcher: 'bash', command: 'exit 2' } ] },
	} );
	const res = await runner.run( 'PreToolUse', { tool: 'read_file' } );
	assert.equal( res.blocked, false );
} );

await test( 'خروجی JSON هوک به‌عنوان کانتکست اضافه خوانده می‌شود', async () => {
	const runner = new HookRunner( {
		workspace: tmpRoot,
		hooks: { UserPromptSubmit: [ { command: `echo '{"additionalContext":"شاخهٔ فعلی: main"}'` } ] },
	} );
	const res = await runner.run( 'UserPromptSubmit', { prompt: 'x' } );
	assert.equal( res.blocked, false );
	assert.deepEqual( res.context, [ 'شاخهٔ فعلی: main' ] );
} );

// ------------------------------------------------------------ فشرده‌سازی

section( 'فشرده‌سازی کانتکست' );

const { shouldCompact, compact } = await import( '../src/subagent.js' );

await test( 'گفتگوی کوتاه فشرده نمی‌شود', () => {
	assert.equal( shouldCompact( [ { role: 'user', content: 'سلام' } ] ), false );
} );

await test( 'گفتگوی بلند فشرده می‌شود و نتیجهٔ ابزارِ بی‌صاحب نمی‌ماند', async () => {
	const messages = [];
	for ( let i = 0; i < 20; i++ ) {
		messages.push( { role: 'user', content: 'x'.repeat( 100 ) } );
		messages.push( { role: 'assistant', content: 'y'.repeat( 100 ), toolCalls: [ { id: 't' + i, name: 'read_file', input: {} } ] } );
		messages.push( { role: 'tool', toolCallId: 't' + i, content: 'z'.repeat( 100 ) } );
	}
	const fakeProvider = {
		async *stream() {
			yield { type: 'text', text: 'خلاصهٔ ساختگی' };
		},
	};
	const out = await compact( { provider: fakeProvider, model: 'm', messages, keep: 4 } );
	assert.ok( out.length < messages.length );
	assert.match( out[ 0 ].content, /خلاصهٔ ساختگی/ );
	assert.notEqual( out[ 1 ]?.role, 'tool', 'نباید با نتیجهٔ ابزارِ بی‌صاحب شروع شود' );
} );

await test( 'اگر خلاصه‌سازی شکست بخورد، گفتگو دست‌نخورده می‌ماند', async () => {
	const messages = Array.from( { length: 12 }, ( _, i ) => ( { role: 'user', content: 'x' + i } ) );
	const broken = {
		async *stream() {
			yield { type: 'error', error: 'قطع شد' };
		},
	};
	const out = await compact( { provider: broken, model: 'm', messages, keep: 3 } );
	assert.equal( out.length, messages.length );
} );

// ---------------------------------------------------------- لایهٔ پرووایدر

section( 'پرووایدرها' );

const { createOpenAiProvider } = await import( '../src/providers/openai.js' );
const { createAnthropicProvider } = await import( '../src/providers/anthropic.js' );
const { validateProfile } = await import( '../src/providers/index.js' );

/** سرور کوچکی که یک پاسخ SSE از پیش‌آماده می‌دهد و درخواست را ثبت می‌کند. */
async function fakeServer( handler ) {
	const server = http.createServer( handler );
	await new Promise( ( r ) => server.listen( 0, '127.0.0.1', r ) );
	const { port } = server.address();
	return { server, url: `http://127.0.0.1:${ port }`, close: () => new Promise( ( r ) => server.close( r ) ) };
}

await test( 'آداپتور OpenAI: JSON قطعه‌قطعهٔ ابزار درست سرهم می‌شود', async () => {
	let seen = null;
	const fake = await fakeServer( async ( req, res ) => {
		let body = '';
		for await ( const c of req ) {
			body += c;
		}
		seen = JSON.parse( body );
		res.writeHead( 200, { 'Content-Type': 'text/event-stream' } );
		const send = ( o ) => res.write( `data: ${ JSON.stringify( o ) }\n\n` );
		send( { choices: [ { delta: { content: 'سلام ' } } ] } );
		send( { choices: [ { delta: { tool_calls: [ { index: 0, id: 'c1', function: { name: 'read_file', arguments: '{"pa' } } ] } } ] } );
		send( { choices: [ { delta: { tool_calls: [ { index: 0, function: { arguments: 'th":"x.txt"}' } } ] } } ] } );
		send( { usage: { prompt_tokens: 10, completion_tokens: 5 } } );
		res.write( 'data: [DONE]\n\n' );
		res.end();
	} );

	const provider = createOpenAiProvider( { providerId: 'x', kind: 'openai', baseUrl: fake.url, apiKey: 'k', model: 'm' } );
	const events = [];
	for await ( const ev of provider.stream( {
		model: 'm',
		system: 'sys',
		messages: [ { role: 'user', content: 'hi' } ],
		tools: [ { name: 'read_file', description: 'd', parameters: { type: 'object' } } ],
	} ) ) {
		events.push( ev );
	}
	await fake.close();

	const call = events.find( ( e ) => e.type === 'tool_call' );
	assert.deepEqual( call.input, { path: 'x.txt' } );
	assert.equal( events.find( ( e ) => e.type === 'usage' ).inputTokens, 10 );
	assert.equal( seen.messages[ 0 ].role, 'system', 'system باید پیام اول باشد' );
	assert.equal( seen.tools[ 0 ].type, 'function' );
} );

await test( 'آداپتور OpenAI: نتیجهٔ ابزار به شکل پیام tool فرستاده می‌شود', async () => {
	let seen = null;
	const fake = await fakeServer( async ( req, res ) => {
		let body = '';
		for await ( const c of req ) {
			body += c;
		}
		seen = JSON.parse( body );
		res.writeHead( 200, { 'Content-Type': 'text/event-stream' } );
		res.write( 'data: [DONE]\n\n' );
		res.end();
	} );
	const provider = createOpenAiProvider( { providerId: 'x', kind: 'openai', baseUrl: fake.url, apiKey: 'k', model: 'm' } );
	for await ( const _ of provider.stream( {
		model: 'm',
		messages: [
			{ role: 'user', content: 'hi' },
			{ role: 'assistant', content: '', toolCalls: [ { id: 'c1', name: 'read_file', input: { path: 'a' } } ] },
			{ role: 'tool', toolCallId: 'c1', content: 'محتوا' },
		],
	} ) ) {
		// فقط برای مصرف استریم
	}
	await fake.close();

	assert.equal( seen.messages[ 1 ].tool_calls[ 0 ].function.name, 'read_file' );
	assert.equal( seen.messages[ 2 ].role, 'tool' );
	assert.equal( seen.messages[ 2 ].tool_call_id, 'c1' );
} );

await test( 'آداپتور Anthropic: system جداست و tool_result داخل پیام user می‌رود', async () => {
	let seen = null;
	const fake = await fakeServer( async ( req, res ) => {
		let body = '';
		for await ( const c of req ) {
			body += c;
		}
		seen = JSON.parse( body );
		res.writeHead( 200, { 'Content-Type': 'text/event-stream' } );
		const send = ( t, o ) => res.write( `data: ${ JSON.stringify( { type: t, ...o } ) }\n\n` );
		send( 'content_block_start', { index: 0, content_block: { type: 'tool_use', id: 'u1', name: 'grep' } } );
		send( 'content_block_delta', { index: 0, delta: { type: 'input_json_delta', partial_json: '{"pattern"' } } );
		send( 'content_block_delta', { index: 0, delta: { type: 'input_json_delta', partial_json: ':"x"}' } } );
		res.end();
	} );

	const provider = createAnthropicProvider( { providerId: 'a', kind: 'anthropic', baseUrl: fake.url, apiKey: 'k', model: 'm' } );
	const events = [];
	for await ( const ev of provider.stream( {
		model: 'm',
		system: 'sys',
		messages: [
			{ role: 'user', content: 'hi' },
			{ role: 'assistant', content: '', toolCalls: [ { id: 'u0', name: 'grep', input: {} } ] },
			{ role: 'tool', toolCallId: 'u0', content: 'نتیجه' },
		],
	} ) ) {
		events.push( ev );
	}
	await fake.close();

	assert.equal( seen.system, 'sys' );
	assert.ok( seen.max_tokens > 0, 'max_tokens اجباری است' );
	assert.equal( seen.messages[ 2 ].role, 'user' );
	assert.equal( seen.messages[ 2 ].content[ 0 ].type, 'tool_result' );
	assert.deepEqual( events.find( ( e ) => e.type === 'tool_call' ).input, { pattern: 'x' } );
} );

await test( 'پروفایل ناقص با پیام روشن رد می‌شود', () => {
	assert.equal( validateProfile( { provider: 'mock' } ).ok, true );
	const bad = validateProfile( { provider: 'openai-compatible' } );
	assert.equal( bad.ok, false );
	assert.ok( bad.missing.length >= 2 );
} );

// ------------------------------------------------------------------ زیرعامل

section( 'زیرعامل' );

const { makeTaskTool } = await import( '../src/subagent.js' );

await test( 'ابزار task فقط نتیجهٔ نهایی زیرعامل را برمی‌گرداند', async () => {
	const seen = [];
	const tool = makeTaskTool( {
		emit: ( ev ) => seen.push( ev ),
		makeAgent: () => ( {
			messages: [],
			async run( prompt ) {
				this.messages.push( { role: 'user', content: prompt } );
				this.messages.push( { role: 'assistant', content: 'کارِ داخلی، پرحرف و طولانی' } );
				this.messages.push( { role: 'assistant', content: 'نتیجه: سه فایل.' } );
			},
		} ),
	} );

	const out = await tool.run( { description: 'شمارش', prompt: 'چند فایل؟' } );
	assert.equal( out, 'نتیجه: سه فایل.' );
	assert.ok( seen.some( ( e ) => e.type === 'subagent_start' ) );
	assert.ok( seen.some( ( e ) => e.type === 'subagent_end' ) );
} );

await test( 'رویداد idle زیرعامل بیرون درز نمی‌کند', async () => {
	const seen = [];
	const tool = makeTaskTool( {
		emit: ( ev ) => seen.push( ev ),
		makeAgent: ( o ) => ( {
			messages: [],
			async run() {
				// دقیقاً همان چیزی که یک عامل واقعی می‌فرستد:
				o.emit( { type: 'user', text: 'x' } );
				o.emit( { type: 'assistant_start' } );
				o.emit( { type: 'tool_start', id: 't', name: 'read_file', summary: 'x' } );
				o.emit( { type: 'idle', usage: {} } );
				this.messages.push( { role: 'assistant', content: 'تمام.' } );
			},
		} ),
	} );

	await tool.run( { prompt: 'کاری بکن' } );

	assert.ok( ! seen.some( ( e ) => e.type === 'idle' ), 'idle زیرعامل نباید پخش شود' );
	assert.ok( ! seen.some( ( e ) => e.type === 'user' ), 'پیام user زیرعامل نباید پخش شود' );
	const toolEv = seen.find( ( e ) => e.type === 'tool_start' );
	assert.equal( toolEv.sub, undefined === toolEv.sub ? undefined : toolEv.sub );
	assert.ok( toolEv.sub, 'رویداد ابزارِ زیرعامل باید برچسب sub داشته باشد' );
} );

await test( 'زیرعامل ابزار task ندارد — جلوی بازگشت بی‌پایان گرفته می‌شود', async () => {
	const { Runtime } = await import( '../src/runtime.js' );
	const rt = new Runtime( () => {} );
	rt.config = { profiles: { d: { provider: 'mock' } }, activeProfile: 'd', workspace: tmpRoot, permissions: { mode: 'auto' } };
	rt.skills = [];

	assert.ok( rt.tools( 0 ).task, 'عامل اصلی باید task داشته باشد' );
	assert.equal( rt.tools( 1 ).task, undefined, 'زیرعامل نباید task داشته باشد' );
	assert.ok( rt.tools( 1 ).read_file, 'ولی بقیهٔ ابزارها را دارد' );
} );

// ------------------------------------------------------------------ MCP

section( 'MCP' );

const { McpManager } = await import( '../src/mcp.js' );

await test( 'سرور MCP خراب، بالا آمدن را نمی‌خواباند', async () => {
	const mcp = new McpManager();
	const status = await mcp.connectAll( {
		home: tmpRoot,
		workspace: tmpRoot,
		servers: { broken: { command: 'this-command-does-not-exist-xyz' } },
	} );
	assert.equal( status.length, 1 );
	assert.equal( status[ 0 ].status, 'failed' );
	assert.equal( Object.keys( mcp.toolEntries() ).length, 0 );
	await mcp.close();
} );

await test( 'سرور غیرفعال اصلاً وصل نمی‌شود', async () => {
	const mcp = new McpManager();
	const status = await mcp.connectAll( {
		home: tmpRoot,
		workspace: tmpRoot,
		servers: { off: { command: 'node', disabled: true } },
	} );
	assert.equal( status[ 0 ].status, 'disabled' );
	await mcp.close();
} );

await test( 'اتصال واقعی به یک سرور MCP، فراخوانی ابزار و خطای ابزار', async () => {
	const serverFile = path.join( path.dirname( new URL( import.meta.url ).pathname ), 'fixtures', 'mcp-server.mjs' );

	const mcp = new McpManager();
	const status = await mcp.connectAll( {
		home: tmpRoot,
		workspace: tmpRoot,
		servers: { demo: { command: process.execPath, args: [ serverFile ] } },
	} );

	assert.equal( status[ 0 ].status, 'connected', status[ 0 ].error || '' );
	assert.deepEqual( status[ 0 ].tools.sort(), [ 'add', 'boom' ] );

	const entries = mcp.toolEntries();
	assert.ok( entries.mcp__demo__add, 'ابزار باید با نام فضادار ثبت شود' );
	assert.equal( entries.mcp__demo__add.risk, 'exec', 'ابزار بیرونی محتاطانه رده‌بندی می‌شود' );
	assert.match( entries.mcp__demo__add.spec.description, /MCP:demo/ );

	assert.equal( ( await entries.mcp__demo__add.run( { a: 2, b: 3 } ) ).trim(), '5' );
	await assert.rejects( () => entries.mcp__demo__boom.run( {} ), /خرابی عمدی/ );

	await mcp.close();
} );

// ------------------------------------------------------------------ پلاگین

section( 'پلاگین‌ها' );

const { installPlugin, listPlugins, setPluginEnabled, removePlugin } = await import( '../src/plugins.js' );

await test( 'نصب پلاگین محلی، اسکیل‌ها و دستورهایش را می‌آورد', async () => {
	const home = path.join( tmpRoot, 'home-plugins' );
	const src = path.join( tmpRoot, 'my-plugin' );
	await fs.mkdir( path.join( src, 'skills', 'x' ), { recursive: true } );
	await fs.mkdir( path.join( src, 'commands' ), { recursive: true } );
	await fs.writeFile( path.join( src, 'plugin.json' ), JSON.stringify( { name: 'my-plugin' } ), 'utf8' );
	await fs.writeFile( path.join( src, 'skills', 'x', 'SKILL.md' ), '---\nname: x\n---\nبدنه', 'utf8' );
	await fs.writeFile( path.join( src, 'commands', 'hi.md' ), 'سلام کن', 'utf8' );

	const installed = await installPlugin( home, src );
	assert.equal( installed.name, 'my-plugin' );

	const list = await listPlugins( home );
	assert.equal( list.length, 1 );
	assert.equal( list[ 0 ].has.skills, 1 );
	assert.equal( list[ 0 ].has.commands, 1 );
	assert.equal( list[ 0 ].enabled, true );

	const skills = await collectSkills( { home, workspace: tmpRoot, pluginDirs: [ { name: 'my-plugin', dir: list[ 0 ].dir } ] } );
	assert.ok( skills.some( ( s ) => s.name === 'x' && s.source === 'my-plugin' ) );

	await setPluginEnabled( home, 'my-plugin', false );
	assert.equal( ( await listPlugins( home ) )[ 0 ].enabled, false );

	await removePlugin( home, 'my-plugin' );
	assert.equal( ( await listPlugins( home ) ).length, 0 );
} );

await test( 'نصب دوبارهٔ همان پلاگین رد می‌شود', async () => {
	const home = path.join( tmpRoot, 'home-dup' );
	const src = path.join( tmpRoot, 'dup-plugin' );
	await fs.mkdir( path.join( src, 'skills' ), { recursive: true } );
	await fs.writeFile( path.join( src, 'plugin.json' ), JSON.stringify( { name: 'dup' } ), 'utf8' );
	await installPlugin( home, src );
	await assert.rejects( () => installPlugin( home, src ), /از قبل نصب است/ );
} );

// ------------------------------------------------------------------ عامل

section( 'حلقهٔ عامل' );

const { Agent } = await import( '../src/agent.js' );

/** پرووایدری که یک اسکریپت از پیش‌نوشته را بازی می‌کند. */
function scriptedProvider( turns ) {
	let i = 0;
	return {
		async *stream() {
			const turn = turns[ Math.min( i++, turns.length - 1 ) ];
			for ( const ev of turn ) {
				yield ev;
			}
		},
	};
}

await test( 'ابزار اجرا می‌شود و نتیجه‌اش به نوبت بعدی می‌رسد', async () => {
	const events = [];
	const agent = new Agent( {
		provider: scriptedProvider( [
			[ { type: 'text', text: 'می‌بینم.' }, { type: 'tool_call', id: 'c1', name: 'list_dir', input: { path: '.' } } ],
			[ { type: 'text', text: 'تمام شد.' } ],
		] ),
		model: 'm',
		workspace: tmpRoot,
		rules: { mode: 'auto' },
		getTools: () => TOOLS,
		emit: ( ev ) => events.push( ev ),
	} );

	await agent.run( 'چه چیزی اینجاست؟' );

	const toolMsg = agent.messages.find( ( m ) => m.role === 'tool' );
	assert.ok( toolMsg, 'نتیجهٔ ابزار باید در تاریخچه باشد' );
	assert.equal( toolMsg.toolCallId, 'c1' );
	assert.ok( events.some( ( e ) => e.type === 'tool_result' ) );
	assert.equal( agent.messages.at( -1 ).content, 'تمام شد.' );
} );

await test( 'رد کاربر، ابزار را اجرا نمی‌کند ولی مدل دلیلش را می‌فهمد', async () => {
	const events = [];
	const target = path.join( tmpRoot, 'must-not-exist.txt' );
	const agent = new Agent( {
		provider: scriptedProvider( [
			[ { type: 'tool_call', id: 'c2', name: 'write_file', input: { path: 'must-not-exist.txt', content: 'x' } } ],
			[ { type: 'text', text: 'باشد.' } ],
		] ),
		model: 'm',
		workspace: tmpRoot,
		rules: { mode: 'default' },
		getTools: () => TOOLS,
		emit: ( ev ) => {
			events.push( ev );
			if ( ev.type === 'permission_request' ) {
				setImmediate( () => agent.resolvePermission( ev.id, 'deny' ) );
			}
		},
	} );

	await agent.run( 'یک فایل بساز' );

	assert.equal( await fs.access( target ).then( () => true ).catch( () => false ), false, 'فایل نباید ساخته شود' );
	const toolMsg = agent.messages.find( ( m ) => m.role === 'tool' );
	assert.match( toolMsg.content, /اجازه/ );
} );

await test( 'هوک PreToolUse حتی ابزار مجاز را هم می‌تواند متوقف کند', async () => {
	const events = [];
	const agent = new Agent( {
		provider: scriptedProvider( [
			[ { type: 'tool_call', id: 'c3', name: 'bash', input: { command: 'echo hi' } } ],
			[ { type: 'text', text: 'باشد.' } ],
		] ),
		model: 'm',
		workspace: tmpRoot,
		rules: { mode: 'auto' },
		getTools: () => TOOLS,
		hooks: new HookRunner( { workspace: tmpRoot, hooks: { PreToolUse: [ { matcher: 'bash', command: 'exit 2' } ] } } ),
		emit: ( ev ) => events.push( ev ),
	} );

	await agent.run( 'یک فرمان بزن' );

	assert.ok( events.some( ( e ) => e.type === 'tool_denied' ) );
	assert.ok( ! events.some( ( e ) => e.type === 'tool_result' ) );
} );

await test( 'ابزار ناشناخته باعث خرابی نمی‌شود و فهرست موجود را برمی‌گرداند', async () => {
	const agent = new Agent( {
		provider: scriptedProvider( [
			[ { type: 'tool_call', id: 'c4', name: 'not_a_tool', input: {} } ],
			[ { type: 'text', text: 'باشد.' } ],
		] ),
		model: 'm',
		workspace: tmpRoot,
		rules: { mode: 'auto' },
		getTools: () => TOOLS,
		emit: () => {},
	} );

	await agent.run( 'کاری بکن' );
	const toolMsg = agent.messages.find( ( m ) => m.role === 'tool' );
	assert.match( toolMsg.content, /وجود ندارد/ );
} );

await test( 'سقف گام رعایت می‌شود', async () => {
	let calls = 0;
	const provider = {
		async *stream() {
			calls++;
			yield { type: 'tool_call', id: 'x' + calls, name: 'list_dir', input: { path: '.' } };
		},
	};
	const agent = new Agent( {
		provider,
		model: 'm',
		workspace: tmpRoot,
		rules: { mode: 'auto' },
		getTools: () => TOOLS,
		maxSteps: 3,
		emit: () => {},
	} );
	await agent.run( 'حلقه' );
	assert.equal( calls, 3 );
} );

// ------------------------------------------------------------------ پایان

await fs.rm( tmpRoot, { recursive: true, force: true } );

process.stdout.write( `\n${ '-'.repeat( 56 ) }\n` );
if ( failures.length ) {
	process.stdout.write( `${ passed } تست موفق، ${ failures.length } ناموفق\n` );
	for ( const f of failures ) {
		process.stdout.write( `  ✗ ${ f.name }: ${ f.error }\n` );
	}
	process.exit( 1 );
}
process.stdout.write( `${ passed } تست، همه موفق\n` );
