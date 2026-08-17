/*
 * رابط کاربری هوشا.
 *
 * بدون فریم‌ورک و بدون مرحلهٔ ساخت — تا نصب سبک بماند و هر تغییری بدون build دیده شود.
 * چیدمان از نسخهٔ وب Claude Code گرفته شده: نوار کناری نشست‌ها، ناحیهٔ گفتگو، کامپوزر پایین.
 */

const $ = ( s ) => document.querySelector( s );
const $$ = ( s ) => [ ...document.querySelectorAll( s ) ];

const chat = $( '#chat' );
const input = $( '#input' );
const sendBtn = $( '#send' );
const stopBtn = $( '#stop' );
const cmdMenu = $( '#cmd-menu' );

let state = null;
let streamEl = null;
let cmdIndex = 0;
const toolEls = new Map();

// ───────────────────────────────────────────────────────────── ابزارهای پایه

function el( tag, cls, text ) {
	const n = document.createElement( tag );
	if ( cls ) n.className = cls;
	if ( text !== undefined ) n.textContent = text;
	return n;
}

function atBottom() {
	return chat.scrollHeight - chat.scrollTop - chat.clientHeight < 140;
}

function append( node ) {
	const stick = atBottom();
	$( '#welcome' )?.remove();
	chat.appendChild( node );
	if ( stick ) chat.scrollTop = chat.scrollHeight;
	return node;
}

async function api( path, options ) {
	const res = await fetch( path, { headers: { 'Content-Type': 'application/json' }, ...options } );
	return res.json().catch( () => ( {} ) );
}

const esc = ( s ) =>
	String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

/**
 * مارک‌داون کوچک — فقط چیزی که یک مدل واقعاً می‌نویسد.
 * کتابخانهٔ کامل مارک‌داون برای این حجم، وابستگی اضافه است.
 */
function markdown( src ) {
	const blocks = String( src ).split( /```/ );
	let out = '';

	blocks.forEach( ( block, i ) => {
		if ( i % 2 === 1 ) {
			const nl = block.indexOf( '\n' );
			const body = nl === -1 ? block : block.slice( nl + 1 );
			out += `<pre><code>${ esc( body.replace( /\n$/, '' ) ) }</code></pre>`;
			return;
		}

		for ( const chunk of block.split( /\n{2,}/ ) ) {
			const text = chunk.trim();
			if ( ! text ) continue;

			const lines = text.split( '\n' );
			const isList = lines.every( ( l ) => /^\s*([-*•]|\d+[.)])\s+/.test( l ) );

			if ( isList ) {
				const ordered = /^\s*\d+[.)]/.test( lines[ 0 ] );
				const items = lines.map( ( l ) => `<li>${ inline( l.replace( /^\s*([-*•]|\d+[.)])\s+/, '' ) ) }</li>` ).join( '' );
				out += ordered ? `<ol>${ items }</ol>` : `<ul>${ items }</ul>`;
				continue;
			}

			const heading = /^(#{1,3})\s+(.*)$/.exec( text );
			if ( heading ) {
				out += `<h${ heading[ 1 ].length }>${ inline( heading[ 2 ] ) }</h${ heading[ 1 ].length }>`;
				continue;
			}

			if ( text.startsWith( '> ' ) ) {
				out += `<blockquote>${ inline( text.replace( /^> ?/gm, '' ) ) }</blockquote>`;
				continue;
			}

			out += `<p>${ inline( text ) }</p>`;
		}
	} );

	return out;
}

function inline( s ) {
	return esc( s )
		.replace( /`([^`]+)`/g, '<code>$1</code>' )
		.replace( /\*\*([^*]+)\*\*/g, '<strong>$1</strong>' )
		.replace( /(^|\s)\*([^*\n]+)\*/g, '$1<em>$2</em>' )
		.replace( /\n/g, '<br />' );
}

// ─────────────────────────────────────────────────────────────── پیام‌ها

const AVATAR = { user: 'تو', assistant: 'ه', system: 'ه' };

function addMessage( role, text, asMarkdown = true ) {
	const wrap = el( 'div', `msg ${ role }` );
	wrap.appendChild( el( 'div', 'avatar', AVATAR[ role ] || '' ) );
	const body = el( 'div', 'body' );
	if ( asMarkdown ) body.innerHTML = markdown( text );
	else body.textContent = text;
	wrap.appendChild( body );
	append( wrap );
	return body;
}

function addNotice( text ) {
	append( el( 'div', 'notice', text ) );
}

function addError( message, hint ) {
	const card = el( 'div', 'err-card' );
	card.appendChild( el( 'b', null, message ) );
	if ( hint ) card.appendChild( el( 'p', null, hint ) );
	append( card );
}

// ───────────────────────────────────────────────────────── کارت‌های ابزار

const TOOL_META = {
	read_file: { ico: '◎', label: 'خواندن فایل' },
	write_file: { ico: '✎', label: 'نوشتن فایل' },
	edit_file: { ico: '✎', label: 'ویرایش فایل' },
	list_dir: { ico: '▤', label: 'فهرست پوشه' },
	glob: { ico: '⌕', label: 'جستجوی فایل' },
	grep: { ico: '⌕', label: 'جستجوی متن' },
	bash: { ico: '❯', label: 'اجرای فرمان' },
	web_fetch: { ico: '⇩', label: 'دریافت از وب' },
	todo_write: { ico: '☑', label: 'فهرست کار' },
	skill: { ico: '◆', label: 'اسکیل' },
	task: { ico: '⌗', label: 'زیرعامل' },
};

function toolMeta( name ) {
	if ( TOOL_META[ name ] ) return TOOL_META[ name ];
	if ( name.startsWith( 'mcp__' ) ) {
		const [ , server, tool ] = name.split( '__' );
		return { ico: '⇄', label: `${ server } · ${ tool }` };
	}
	return { ico: '⚒', label: name };
}

function toolCard( id, name, summary, sub ) {
	const meta = toolMeta( name );
	const card = el( 'div', 'tool' );
	const head = el( 'div', 'tool-head' );

	head.appendChild( el( 'span', 'tool-ico', meta.ico ) );
	if ( sub ) head.appendChild( el( 'span', 'sub-tag', sub ) );
	head.appendChild( el( 'span', 'tool-name', meta.label ) );
	head.appendChild( el( 'span', 'tool-sum mono', summary || '' ) );

	const badge = el( 'span', 'tool-state run', 'در حال اجرا' );
	head.appendChild( badge );
	head.appendChild( el( 'span', 'tool-chevron', '›' ) );

	head.onclick = () => {
		card.classList.toggle( 'open' );
		const b = card.querySelector( '.tool-out, .diff, .todos' );
		if ( b ) b.hidden = ! card.classList.contains( 'open' );
	};

	card.appendChild( head );
	card._badge = badge;
	card._name = name;
	append( card );
	toolEls.set( id, card );
	return card;
}

function renderOutput( card, name, output ) {
	// هر ابزار، شکل درست خودش را دارد — یک <pre> برای همه، همان چیزی بود که «ساده» به‌نظر می‌رسید.
	if ( name === 'write_file' || name === 'edit_file' ) {
		return diffView( output );
	}
	if ( name === 'todo_write' ) {
		return todoView( output );
	}
	const pre = el( 'pre', name === 'bash' ? 'tool-out mono terminal' : 'tool-out mono', String( output ) );
	return pre;
}

function diffView( output ) {
	const box = el( 'div', 'diff mono' );
	for ( const line of String( output ).split( '\n' ) ) {
		const cls = line.startsWith( '+' ) ? 'add' : line.startsWith( '-' ) ? 'del' : line.startsWith( '@@' ) ? 'meta' : '';
		box.appendChild( el( 'div', cls, line ) );
	}
	return box;
}

function todoView( output ) {
	const box = el( 'div', 'todos' );
	for ( const line of String( output ).split( '\n' ) ) {
		if ( ! line.trim() ) continue;
		const done = line.startsWith( '☑' );
		const doing = line.startsWith( '▸' );
		const row = el( 'div', `todo ${ done ? 'done' : doing ? 'doing' : '' }` );
		row.appendChild( el( 'span', 'box', done ? '☑' : doing ? '▸' : '☐' ) );
		row.appendChild( el( 'span', null, line.replace( /^[☑▸☐]\s*/, '' ) ) );
		box.appendChild( row );
	}
	return box;
}

function finishTool( id, { output, error, denied, reason } ) {
	const card = toolEls.get( id );
	if ( ! card ) return;

	const badge = card._badge;
	if ( denied ) {
		badge.className = 'tool-state deny';
		badge.textContent = 'رد شد';
	} else if ( error ) {
		badge.className = 'tool-state err';
		badge.textContent = 'خطا';
	} else {
		badge.className = 'tool-state ok';
		badge.textContent = 'انجام شد';
	}

	const body = output ?? error ?? reason ?? '';
	if ( ! body ) return;

	const node = error || denied ? el( 'pre', 'tool-out mono', String( body ) ) : renderOutput( card, card._name, body );

	// خروجی کوتاه باز، خروجی بلند جمع — مثل کلاد کد.
	const short = String( body ).split( '\n' ).length <= 12;
	node.hidden = ! short;
	if ( short ) card.classList.add( 'open' );

	card.appendChild( node );
	if ( atBottom() ) chat.scrollTop = chat.scrollHeight;
}

// ───────────────────────────────────────────────────────── دروازهٔ تأیید

function askCard( ev ) {
	const meta = toolMeta( ev.name );
	const card = el( 'div', 'ask' );
	card.appendChild( el( 'div', 'ask-head', `${ meta.ico }  اجازه می‌دهی این کار انجام شود؟` ) );
	card.appendChild( el( 'pre', 'mono', ev.summary || JSON.stringify( ev.input, null, 2 ) ) );

	const actions = el( 'div', 'ask-actions' );
	const allow = el( 'button', 'pill primary', 'اجازه بده' );
	const always = el( 'button', 'pill', alwaysLabel( ev ) );
	const deny = el( 'button', 'pill ghost', 'رد کن' );

	const answer = async ( decision, remember ) => {
		for ( const b of [ allow, always, deny ] ) b.disabled = true;
		actions.replaceChildren(
			el( 'span', 'note', decision === 'allow' ? ( remember ? 'اجازه داده شد و به یاد سپرده شد.' : 'اجازه داده شد.' ) : 'رد شد.' )
		);
		await api( '/api/permission', {
			method: 'POST',
			body: JSON.stringify( { id: ev.id, decision, remember, rule: remember ? ruleFor( ev ) : undefined } ),
		} );
	};

	allow.onclick = () => answer( 'allow', false );
	always.onclick = () => answer( 'allow', true );
	deny.onclick = () => answer( 'deny', false );

	actions.append( allow, always, deny );
	card.appendChild( actions );
	append( card );
}

function ruleFor( ev ) {
	if ( ev.name === 'bash' ) {
		const first = String( ev.input?.command || '' ).trim().split( /\s+/ )[ 0 ];
		return first ? `bash:${ first }` : 'bash';
	}
	return ev.name;
}

function alwaysLabel( ev ) {
	return ev.name === 'bash'
		? `همیشه برای «${ String( ev.input?.command || '' ).trim().split( /\s+/ )[ 0 ] }»`
		: 'همیشه اجازه بده';
}

// ─────────────────────────────────────────────────────────────── رویدادها

function handle( ev ) {
	switch ( ev.type ) {
		case 'user':
			addMessage( 'user', ev.text, false );
			break;

		case 'assistant_start':
			streamEl = addMessage( 'assistant', '' );
			streamEl._raw = '';
			break;

		case 'text':
			if ( ! streamEl ) {
				streamEl = addMessage( 'assistant', '' );
				streamEl._raw = '';
			}
			streamEl._raw += ev.text;
			streamEl.innerHTML = markdown( streamEl._raw );
			if ( atBottom() ) chat.scrollTop = chat.scrollHeight;
			break;

		case 'assistant_end':
			if ( streamEl && ! streamEl.textContent.trim() ) streamEl.closest( '.msg' )?.remove();
			streamEl = null;
			break;

		case 'system':
			addMessage( 'system', ev.text );
			break;

		case 'permission_request': askCard( ev ); break;
		case 'tool_start': toolCard( ev.id, ev.name, ev.summary, ev.sub ); break;
		case 'tool_result': finishTool( ev.id, { output: ev.output } ); break;
		case 'tool_error': finishTool( ev.id, { error: ev.error } ); break;

		case 'tool_denied':
			if ( ! toolEls.has( ev.id ) ) toolCard( ev.id, ev.name, ev.summary, ev.sub );
			finishTool( ev.id, { denied: true, reason: ev.reason } );
			break;

		case 'subagent_start': addNotice( `زیرعامل: ${ ev.label }` ); break;
		case 'compacted': addNotice( `گفتگو فشرده شد (${ ev.before } → ${ ev.after } پیام).` ); break;
		case 'notice': addNotice( ev.text ); break;
		case 'error': addError( ev.error, ev.hint ); break;

		case 'idle':
			setBusy( false );
			updateMeter( ev.usage );
			refreshSessions();
			break;

		case 'reset':
		case 'resumed':
			chat.replaceChildren();
			toolEls.clear();
			if ( ev.transcript ) replay( ev.transcript );
			break;

		case 'workspace':
			$( '#btn-workspace' ).textContent = shortPath( ev.path );
			break;

		case 'mode':
			$( '#mode' ).value = ev.mode;
			$( '#badge-mode' ).textContent = MODE_LABEL[ ev.mode ] || ev.mode;
			break;
	}
}

const MODE_LABEL = { plan: 'پلن', default: 'عادی', auto: 'خودکار' };

function replay( transcript ) {
	for ( const ev of transcript ) {
		if ( [ 'hello', 'idle', 'assistant_start', 'text' ].includes( ev.type ) ) continue;
		if ( ev.type === 'assistant_end' && ev.text ) {
			addMessage( 'assistant', ev.text );
			continue;
		}
		handle( ev );
	}
}

function setBusy( busy ) {
	sendBtn.hidden = busy;
	stopBtn.hidden = ! busy;
}

function updateMeter( usage ) {
	if ( ! usage ) return;
	const total = ( usage.inputTokens || 0 ) + ( usage.outputTokens || 0 );
	$( '#context-meter' ).textContent = total ? `${ total.toLocaleString( 'fa-IR' ) } توکن` : '';
}

function shortPath( p ) {
	if ( ! p ) return '…';
	const parts = String( p ).split( /[\\/]/ ).filter( Boolean );
	return parts.length <= 2 ? p : '…/' + parts.slice( -2 ).join( '/' );
}

// ─────────────────────────────────────────────────────────────── راه‌اندازی

const STARTERS = [
	[ 'این پروژه چیست؟', 'ساختار پوشه‌ها را بررسی کن و در چند خط بگو این پروژه چه کار می‌کند.' ],
	[ 'یک باگ پیدا کن', 'کد را بخوان و یک مشکل واقعی پیدا کن؛ فقط چیزی که مطمئنی.' ],
	[ 'تست‌ها را اجرا کن', 'تست‌های پروژه را پیدا کن و اجرا کن و نتیجه را خلاصه بگو.' ],
];

function renderStarters() {
	const box = $( '#starter' );
	if ( ! box ) return;
	box.replaceChildren();
	for ( const [ title, prompt ] of STARTERS ) {
		const b = el( 'button' );
		b.appendChild( el( 'b', null, title ) );
		b.appendChild( el( 'span', null, prompt ) );
		b.onclick = () => {
			input.value = prompt;
			input.focus();
			autoGrow();
		};
		box.appendChild( b );
	}
}

async function refreshState() {
	state = await api( '/api/state' );

	$( '#mode' ).value = state.config.permissions.mode;
	$( '#badge-mode' ).textContent = MODE_LABEL[ state.config.permissions.mode ];
	$( '#btn-workspace' ).textContent = shortPath( state.config.workspace );
	$( '#c-tools' ).textContent = state.tools.length;
	$( '#c-skills' ).textContent = state.skills.length;
	$( '#c-mcp' ).textContent = state.mcp.length;
	$( '#c-plugins' ).textContent = state.plugins.length;
	$( '#c-commands' ).textContent = state.commands.length;
	updateMeter( state.usage );

	const p = state.config.profiles[ state.config.activeProfile ];
	const info = state.providers.find( ( x ) => x.id === p?.provider );
	$( '#chip-provider' ).textContent = info ? `${ info.label }` : 'تنظیمات';
	$( '#pill-model' ).textContent = p?.model || 'انتخاب مدل';

	return state;
}

async function refreshSessions() {
	const { sessions = [] } = await api( '/api/sessions' );
	const box = $( '#session-list' );
	box.replaceChildren();
	for ( const s of sessions.slice( 0, 20 ) ) {
		const b = el( 'button', `session-item${ s.id === state?.sessionId ? ' active' : '' }` );
		b.appendChild( el( 'span', null, s.title || 'بدون عنوان' ) );
		b.onclick = async () => {
			const out = await api( '/api/resume', { method: 'POST', body: JSON.stringify( { id: s.id } ) } );
			if ( out.error ) addNotice( out.error );
			else {
				$( '#session-title' ).textContent = s.title || 'نشست';
				await refreshState();
				refreshSessions();
			}
		};
		box.appendChild( b );
	}
}

async function boot() {
	document.documentElement.dataset.theme = localStorage.getItem( 'hoosha-theme' ) || 'light';

	await refreshState();
	renderStarters();
	refreshSessions();

	if ( ! state.ready.ok ) {
		addNotice( `برای شروع، تنظیمات پرووایدر را کامل کن: ${ state.ready.missing.join( '، ' ) }` );
	}

	const es = new EventSource( '/api/events' );
	es.onmessage = ( m ) => {
		try {
			handle( JSON.parse( m.data ) );
		} catch {
			/* ping */
		}
	};
}

// ─────────────────────────────────────────────────────────────── تنظیمات

function buildProviderSelect() {
	const sel = $( '#f-provider' );
	sel.replaceChildren();
	for ( const p of state.providers ) {
		const o = el( 'option', null, p.label );
		o.value = p.id;
		sel.appendChild( o );
	}
	sel.onchange = () => fillProvider( sel.value );
}

function fillProvider( id, profile ) {
	const info = state.providers.find( ( p ) => p.id === id );
	$( '#p-note' ).textContent = info?.note || '';
	$( '#f-baseurl' ).value = profile?.baseUrl || info?.baseUrl || '';
	$( '#f-baseurl' ).disabled = ! info?.editableBaseUrl;
	$( '#f-model' ).value = profile?.model || info?.defaultModel || '';
	$( '#btn-fetch-models' ).disabled = ! info?.canListModels;
	$( '#f-apikey' ).placeholder = info?.needsKey ? 'برای تغییر، کلید تازه را بنویس' : 'این سرویس کلید نمی‌خواهد';
	$( '#key-state' ).textContent = state.hasKey && profile?.provider === id ? '✓ ذخیره شده' : '';
	$( '#models-note' ).textContent = '';
	$( '#test-note' ).textContent = '';
}

$( '#btn-settings' ).onclick = async () => {
	await refreshState();
	buildProviderSelect();
	const p = state.config.profiles[ state.config.activeProfile ];
	$( '#f-provider' ).value = p?.provider || 'mock';
	fillProvider( p?.provider || 'mock', p );
	$( '#f-apikey' ).value = '';
	$( '#home-note' ).textContent = `تنظیمات اینجا ذخیره می‌شود: ${ state.home }`;
	$( '#settings' ).showModal();
};

$( '#settings-cancel' ).onclick = () => $( '#settings' ).close();
$( '#pill-model' ).onclick = () => $( '#btn-settings' ).click();

async function saveProfile() {
	return api( '/api/profile', {
		method: 'POST',
		body: JSON.stringify( {
			id: state.config.activeProfile || 'default',
			provider: $( '#f-provider' ).value,
			baseUrl: $( '#f-baseurl' ).value.trim(),
			apiKey: $( '#f-apikey' ).value.trim(),
			model: $( '#f-model' ).value.trim(),
		} ),
	} );
}

$( '#settings-save' ).onclick = async () => {
	const out = await saveProfile();
	if ( out.config ) {
		await refreshState();
		$( '#settings' ).close();
		addNotice( 'تنظیمات ذخیره شد.' );
	}
};

$( '#btn-test' ).onclick = async () => {
	const note = $( '#test-note' );
	note.className = 'note';
	note.textContent = 'در حال آزمودن…';
	await saveProfile();
	const out = await api( '/api/test-connection', { method: 'POST' } );
	note.className = `note ${ out.ok ? 'ok' : 'error' }`;
	note.textContent = out.ok ? out.message : `${ out.message }${ out.hint ? '\n' + out.hint : '' }`;
	await refreshState();
};

$( '#btn-fetch-models' ).onclick = async () => {
	const note = $( '#models-note' );
	note.className = 'note';
	note.textContent = 'در حال گرفتن فهرست…';
	await saveProfile();
	const out = await api( '/api/models' );
	if ( out.error ) {
		note.className = 'note error';
		note.textContent = `${ out.error }${ out.hint ? '\n' + out.hint : '' }`;
		return;
	}
	const list = $( '#model-list' );
	list.replaceChildren();
	for ( const m of out.models || [] ) {
		const o = document.createElement( 'option' );
		o.value = m;
		list.appendChild( o );
	}
	note.textContent = `${ ( out.models || [] ).length } مدل پیدا شد — روی کادر مدل کلیک کن.`;
};

// ───────────────────────────────────────────────────────── پنل افزونه‌ها

const PANEL_TITLE = { tools: 'ابزارها', skills: 'اسکیل‌ها', mcp: 'سرورهای MCP', plugins: 'پلاگین‌ها', commands: 'دستورها' };

function itemRow( name, desc, tags = [] ) {
	const row = el( 'div', 'item' );
	row.appendChild( el( 'b', 'mono', name ) );
	row.appendChild( el( 'p', null, desc || '' ) );
	for ( const t of tags ) row.appendChild( el( 'span', `tag ${ t.kind || '' }`, t.text ) );
	return row;
}

function renderPanel( tab ) {
	$( '#panel-title' ).textContent = PANEL_TITLE[ tab ];
	for ( const t of $$( '.tab' ) ) t.classList.toggle( 'active', t.dataset.tab === tab );
	for ( const id of Object.keys( PANEL_TITLE ) ) $( `#tab-${ id }` ).hidden = id !== tab;

	if ( tab === 'tools' ) {
		const box = $( '#tab-tools' );
		box.replaceChildren();
		for ( const t of state.tools ) {
			box.appendChild(
				itemRow( t.name, t.description, [
					{ text: t.risk },
					...( t.name.startsWith( 'mcp__' ) ? [ { text: 'MCP', kind: 'mcp' } ] : [] ),
				] )
			);
		}
	}

	if ( tab === 'skills' ) {
		const box = $( '#tab-skills' );
		box.replaceChildren();
		if ( ! state.skills.length )
			box.appendChild( el( 'div', 'empty', 'هیچ اسکیلی نصب نیست.\n\nیک اسکیل آماده را اینجا بگذار:\n~/.hoosha/skills/<نام>/SKILL.md' ) );
		for ( const s of state.skills ) box.appendChild( itemRow( s.name, s.description, [ { text: s.source } ] ) );
	}

	if ( tab === 'mcp' ) {
		const box = $( '#tab-mcp' );
		box.replaceChildren();
		if ( ! state.mcp.length )
			box.appendChild(
				el( 'div', 'empty', 'سرور MCP تنظیم نشده.\n\nدر ~/.hoosha/config.json کلید mcpServers را پر کن،\nیا در پوشهٔ پروژه فایل .hoosha/mcp.json بساز.' )
			);
		for ( const s of state.mcp )
			box.appendChild(
				itemRow( s.name, s.status === 'connected' ? `${ s.tools.length } ابزار: ${ s.tools.join( '، ' ) }` : s.error || '', [
					{ text: s.status, kind: s.status === 'connected' ? 'ok' : 'err' },
				] )
			);
	}

	if ( tab === 'plugins' ) {
		const box = $( '#plugin-list' );
		box.replaceChildren();
		if ( ! state.plugins.length ) box.appendChild( el( 'div', 'empty', 'پلاگینی نصب نیست.' ) );
		for ( const p of state.plugins ) {
			const row = itemRow(
				p.name,
				`اسکیل: ${ p.has.skills } · دستور: ${ p.has.commands }${ p.has.mcp ? ' · MCP' : '' }${ p.has.hooks ? ' · هوک' : '' }`,
				[ { text: p.enabled ? 'فعال' : 'خاموش', kind: p.enabled ? 'ok' : '' } ]
			);
			const toggle = el( 'button', 'pill', p.enabled ? 'خاموش' : 'روشن' );
			toggle.onclick = async () => {
				await api( '/api/plugins', { method: 'POST', body: JSON.stringify( { action: 'toggle', name: p.name, enabled: ! p.enabled } ) } );
				await refreshState();
				renderPanel( 'plugins' );
			};
			const del = el( 'button', 'pill ghost', 'حذف' );
			del.onclick = async () => {
				if ( ! confirm( `پلاگین «${ p.name }» حذف شود؟` ) ) return;
				await api( '/api/plugins', { method: 'POST', body: JSON.stringify( { action: 'remove', name: p.name } ) } );
				await refreshState();
				renderPanel( 'plugins' );
			};
			row.append( toggle, del );
			box.appendChild( row );
		}
	}

	if ( tab === 'commands' ) {
		const box = $( '#tab-commands' );
		box.replaceChildren();
		for ( const c of state.commands ) box.appendChild( itemRow( `/${ c.name }`, c.description, [ { text: c.source } ] ) );
	}
}

for ( const b of $$( '[data-panel]' ) ) {
	b.onclick = async () => {
		await refreshState();
		renderPanel( b.dataset.panel );
		$( '#panel' ).showModal();
	};
}
for ( const t of $$( '.tab' ) ) t.onclick = () => renderPanel( t.dataset.tab );
$( '#panel-close' ).onclick = () => $( '#panel' ).close();

$( '#btn-reload' ).onclick = async () => {
	await api( '/api/reload', { method: 'POST' } );
	await refreshState();
	renderPanel( $( '.tab.active' )?.dataset.tab || 'tools' );
};

$( '#btn-install-plugin' ).onclick = async () => {
	const source = $( '#plugin-source' ).value.trim();
	const note = $( '#plugin-note' );
	if ( ! source ) return;
	note.className = 'note';
	note.textContent = 'در حال نصب…';
	const out = await api( '/api/plugins', { method: 'POST', body: JSON.stringify( { action: 'install', source } ) } );
	if ( out.error ) {
		note.className = 'note error';
		note.textContent = out.error;
		return;
	}
	note.textContent = `«${ out.plugin.name }» نصب شد.`;
	$( '#plugin-source' ).value = '';
	await refreshState();
	renderPanel( 'plugins' );
};

// ─────────────────────────────────────────────────────────────── کنترل‌ها

$( '#btn-theme' ).onclick = () => {
	const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
	document.documentElement.dataset.theme = next;
	localStorage.setItem( 'hoosha-theme', next );
};

$( '#btn-menu' ).onclick = () => $( '#sidebar' ).classList.toggle( 'open' );

$( '#mode' ).onchange = async ( e ) => {
	await api( '/api/mode', { method: 'POST', body: JSON.stringify( { mode: e.target.value } ) } );
};

$( '#btn-workspace' ).onclick = async () => {
	const next = prompt( 'مسیر پوشهٔ کاری:', state.config.workspace );
	if ( ! next ) return;
	const out = await api( '/api/workspace', { method: 'POST', body: JSON.stringify( { path: next } ) } );
	if ( out.error ) addNotice( out.error );
	else await refreshState();
};

$( '#btn-new' ).onclick = async () => {
	await api( '/api/new', { method: 'POST' } );
	$( '#session-title' ).textContent = 'گفتگوی تازه';
	chat.replaceChildren();
	renderStarters();
	const w = el( 'div', 'welcome' );
	w.id = 'welcome';
	chat.appendChild( w );
	await refreshState();
	refreshSessions();
};

$( '#stop' ).onclick = () => api( '/api/stop', { method: 'POST' } );

$( '#composer' ).onsubmit = async ( e ) => {
	e.preventDefault();
	const text = input.value.trim();
	if ( ! text ) return;

	input.value = '';
	autoGrow();
	cmdMenu.hidden = true;
	setBusy( true );

	const out = await api( '/api/message', { method: 'POST', body: JSON.stringify( { text } ) } );
	if ( out.error ) {
		addError( out.error );
		setBusy( false );
	} else if ( out.handled ) {
		setBusy( false );
		refreshState();
	}
};

function autoGrow() {
	input.style.height = 'auto';
	input.style.height = Math.min( input.scrollHeight, 220 ) + 'px';
}

// ───────────────────────────────────────────────── تکمیل خودکار دستورها

function commandMatches() {
	const text = input.value;
	if ( ! text.startsWith( '/' ) || text.includes( '\n' ) || text.slice( 1 ).includes( ' ' ) ) return null;
	const q = text.slice( 1 ).toLowerCase();
	return ( state?.commands || [] ).filter( ( c ) => c.name.toLowerCase().startsWith( q ) ).slice( 0, 8 );
}

function renderCmdMenu() {
	const list = commandMatches();
	if ( ! list || ! list.length ) {
		cmdMenu.hidden = true;
		return;
	}
	cmdIndex = Math.min( cmdIndex, list.length - 1 );
	cmdMenu.replaceChildren();
	list.forEach( ( c, i ) => {
		const row = el( 'div', `cmd-item${ i === cmdIndex ? ' active' : '' }` );
		row.appendChild( el( 'b', null, `/${ c.name }` ) );
		row.appendChild( el( 'span', null, c.description || '' ) );
		row.appendChild( el( 'em', null, c.source ) );
		row.onclick = () => {
			input.value = `/${ c.name } `;
			cmdMenu.hidden = true;
			input.focus();
		};
		cmdMenu.appendChild( row );
	} );
	cmdMenu.hidden = false;
}

input.addEventListener( 'input', () => {
	autoGrow();
	renderCmdMenu();
} );

input.addEventListener( 'keydown', ( e ) => {
	if ( ! cmdMenu.hidden ) {
		const list = commandMatches() || [];
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			cmdIndex = ( cmdIndex + 1 ) % list.length;
			return renderCmdMenu();
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			cmdIndex = ( cmdIndex - 1 + list.length ) % list.length;
			return renderCmdMenu();
		}
		if ( ( e.key === 'Tab' || e.key === 'Enter' ) && list.length ) {
			e.preventDefault();
			input.value = `/${ list[ cmdIndex ].name } `;
			cmdMenu.hidden = true;
			return;
		}
		if ( e.key === 'Escape' ) {
			cmdMenu.hidden = true;
			return;
		}
	}

	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		$( '#composer' ).requestSubmit();
	}
} );

boot();
