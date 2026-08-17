/* رابط کاربری هوشا — بدون فریم‌ورک، تا نصب و اجرا سبک بماند. */

const $ = ( sel ) => document.querySelector( sel );

const chat = $( '#chat' );
const input = $( '#input' );
const sendBtn = $( '#send' );
const stopBtn = $( '#stop' );

let state = null;
/** @type {HTMLElement|null} */
let streamEl = null;
/** @type {Map<string,HTMLElement>} */
const toolEls = new Map();

// ------------------------------------------------------------------ کمکی‌ها

function el( tag, cls, text ) {
	const n = document.createElement( tag );
	if ( cls ) {
		n.className = cls;
	}
	if ( text !== undefined ) {
		n.textContent = text;
	}
	return n;
}

function atBottom() {
	return chat.scrollHeight - chat.scrollTop - chat.clientHeight < 120;
}

function append( node, keepScroll = true ) {
	const stick = keepScroll && atBottom();
	$( '#welcome' )?.remove();
	chat.appendChild( node );
	if ( stick ) {
		chat.scrollTop = chat.scrollHeight;
	}
	return node;
}

async function api( path, options ) {
	const res = await fetch( path, {
		headers: { 'Content-Type': 'application/json' },
		...options,
	} );
	return res.json().catch( () => ( {} ) );
}

// ------------------------------------------------------------------- پیام‌ها

function addMessage( who, text, cls ) {
	const wrap = el( 'div', `msg ${ cls }` );
	wrap.appendChild( el( 'div', 'who', who ) );
	const body = el( 'div', 'body', text );
	wrap.appendChild( body );
	append( wrap );
	return body;
}

function addNotice( text, isError ) {
	append( el( 'div', `notice${ isError ? ' error' : '' }`, text ) );
}

function toolCard( id, name, summary, sub ) {
	const card = el( 'div', 'tool' );
	const head = el( 'div', 'tool-head' );
	if ( sub ) {
		head.appendChild( el( 'span', 'sub-tag', `زیرعامل: ${ sub }` ) );
	}
	head.appendChild( el( 'span', 'tool-name', name ) );
	head.appendChild( el( 'span', 'tool-sum', summary || '' ) );
	const badge = el( 'span', 'state run', 'در حال اجرا' );
	head.appendChild( badge );
	card.appendChild( head );
	card._badge = badge;
	append( card );
	toolEls.set( id, card );
	return card;
}

function finishTool( id, { output, error, denied, reason } ) {
	const card = toolEls.get( id );
	if ( ! card ) {
		return;
	}
	const badge = card._badge;
	if ( denied ) {
		badge.className = 'state deny';
		badge.textContent = 'رد شد';
	} else if ( error ) {
		badge.className = 'state err';
		badge.textContent = 'خطا';
	} else {
		badge.className = 'state ok';
		badge.textContent = 'انجام شد';
	}
	const body = output ?? error ?? reason ?? '';
	if ( body ) {
		card.classList.add( 'open' );
		card.appendChild( el( 'pre', 'tool-out', String( body ) ) );
	}
	if ( atBottom() ) {
		chat.scrollTop = chat.scrollHeight;
	}
}

function askCard( ev ) {
	const card = el( 'div', 'ask' );
	card.appendChild( el( 'h4', null, 'اجازه می‌دهی این کار انجام شود؟' ) );
	card.appendChild( el( 'div', 'tool-name', ev.name ) );
	card.appendChild( el( 'pre', null, ev.summary || JSON.stringify( ev.input, null, 2 ) ) );

	const row = el( 'div', 'row' );
	const allow = el( 'button', 'chip btn-allow', 'اجازه بده' );
	const deny = el( 'button', 'chip btn-deny', 'رد کن' );
	row.appendChild( allow );
	row.appendChild( deny );
	card.appendChild( row );
	append( card );

	const answer = async ( decision ) => {
		allow.disabled = deny.disabled = true;
		row.replaceChildren( el( 'span', 'note', decision === 'allow' ? 'اجازه داده شد.' : 'رد شد.' ) );
		await api( '/api/permission', {
			method: 'POST',
			body: JSON.stringify( { id: ev.id, decision } ),
		} );
	};
	allow.onclick = () => answer( 'allow' );
	deny.onclick = () => answer( 'deny' );
}

// ------------------------------------------------------------------ رویدادها

function handle( ev ) {
	switch ( ev.type ) {
		case 'user':
			addMessage( 'تو', ev.text, 'user' );
			break;

		case 'assistant_start':
			streamEl = addMessage( 'هوشا', '', 'assistant' );
			break;

		case 'text':
			if ( ! streamEl ) {
				streamEl = addMessage( 'هوشا', '', 'assistant' );
			}
			streamEl.textContent += ev.text;
			if ( atBottom() ) {
				chat.scrollTop = chat.scrollHeight;
			}
			break;

		case 'assistant_end':
			if ( streamEl && ! streamEl.textContent.trim() ) {
				streamEl.closest( '.msg' )?.remove();
			}
			streamEl = null;
			break;

		case 'permission_request':
			askCard( ev );
			break;

		case 'tool_start':
			toolCard( ev.id, ev.name, ev.summary, ev.sub );
			break;

		case 'tool_result':
			finishTool( ev.id, { output: ev.output } );
			break;

		case 'tool_error':
			finishTool( ev.id, { error: ev.error } );
			break;

		case 'tool_denied':
			if ( ! toolEls.has( ev.id ) ) {
				toolCard( ev.id, ev.name, ev.summary, ev.sub );
			}
			finishTool( ev.id, { denied: true, reason: ev.reason } );
			break;

		case 'system':
			addMessage( 'هوشا', ev.text, 'system' );
			break;

		case 'subagent_start':
			append( el( 'div', 'notice', `زیرعامل شروع شد: ${ ev.label }` ) );
			break;

		case 'subagent_end':
			append( el( 'div', 'notice', `زیرعامل تمام شد: ${ ev.label }` ) );
			break;

		case 'compacted':
			addNotice( `گفتگو فشرده شد (${ ev.before } → ${ ev.after } پیام).` );
			break;

		case 'hook':
			addNotice( `هوک ${ ev.event } اجرا شد.` );
			break;

		case 'notice':
			addNotice( ev.text );
			break;

		case 'error':
			addNotice( ev.error, true );
			break;

		case 'idle':
			setBusy( false );
			if ( ev.usage ) {
				$( '#usage' ).textContent = ev.usage.inputTokens + ev.usage.outputTokens
					? `توکن: ${ ev.usage.inputTokens } ورودی / ${ ev.usage.outputTokens } خروجی`
					: '';
			}
			break;

		case 'reset':
			chat.replaceChildren();
			toolEls.clear();
			break;

		case 'workspace':
			$( '#btn-workspace' ).textContent = shortPath( ev.path );
			break;
	}
}

function setBusy( busy ) {
	sendBtn.disabled = busy;
	stopBtn.hidden = ! busy;
	sendBtn.hidden = busy;
}

function shortPath( p ) {
	if ( ! p ) {
		return '…';
	}
	const parts = String( p ).split( /[\\/]/ ).filter( Boolean );
	return parts.length <= 2 ? p : '…/' + parts.slice( -2 ).join( '/' );
}

// -------------------------------------------------------------------- شروع

async function boot() {
	state = await api( '/api/state' );

	$( '#mode' ).value = state.config.permissions.mode;
	$( '#btn-workspace' ).textContent = shortPath( state.config.workspace );
	renderProviderChip();
	buildProviderSelect();

	if ( ! state.ready.ok ) {
		addNotice( `برای شروع، تنظیمات پرووایدر را کامل کن: ${ state.ready.missing.join( '، ' ) }` );
	}

	const es = new EventSource( '/api/events' );
	es.onmessage = ( m ) => {
		try {
			handle( JSON.parse( m.data ) );
		} catch {
			/* خط ping */
		}
	};
	es.onerror = () => {
		/* EventSource خودش دوباره وصل می‌شود */
	};
}

function renderProviderChip() {
	const p = state.config.profiles[ state.config.activeProfile ];
	const info = state.providers.find( ( x ) => x.id === p?.provider );
	$( '#chip-provider' ).textContent = p ? `${ info?.label || p.provider } · ${ p.model || 'بدون مدل' }` : 'تنظیم پرووایدر';
}

// ------------------------------------------------------------------ تنظیمات

function buildProviderSelect() {
	const sel = $( '#f-provider' );
	sel.replaceChildren();
	for ( const p of state.providers ) {
		const o = el( 'option', null, p.label );
		o.value = p.id;
		sel.appendChild( o );
	}
	sel.onchange = () => fillProviderDefaults( sel.value );
}

function fillProviderDefaults( id, profile ) {
	const info = state.providers.find( ( p ) => p.id === id );
	$( '#p-note' ).textContent = info?.note || '';
	$( '#f-baseurl' ).value = profile?.baseUrl || info?.baseUrl || '';
	$( '#f-baseurl' ).disabled = ! info?.editableBaseUrl;
	$( '#f-model' ).value = profile?.model || info?.defaultModel || '';
	$( '#f-apikey' ).placeholder = info?.needsKey
		? 'خالی بگذار تا کلید فعلی حفظ شود'
		: 'این پرووایدر کلید نمی‌خواهد';
	$( '#btn-fetch-models' ).disabled = ! info?.canListModels;
	$( '#models-note' ).textContent = '';
}

$( '#btn-settings' ).onclick = () => {
	const p = state.config.profiles[ state.config.activeProfile ];
	$( '#f-provider' ).value = p?.provider || 'mock';
	fillProviderDefaults( p?.provider || 'mock', p );
	$( '#f-apikey' ).value = '';
	$( '#settings' ).showModal();
};

$( '#settings-cancel' ).onclick = () => $( '#settings' ).close();

$( '#settings-save' ).onclick = async () => {
	const body = {
		id: state.config.activeProfile || 'default',
		provider: $( '#f-provider' ).value,
		baseUrl: $( '#f-baseurl' ).value.trim(),
		apiKey: $( '#f-apikey' ).value.trim(),
		model: $( '#f-model' ).value.trim(),
	};
	const out = await api( '/api/profile', { method: 'POST', body: JSON.stringify( body ) } );
	if ( out.config ) {
		state.config = out.config;
		renderProviderChip();
		$( '#settings' ).close();
		addNotice( 'تنظیمات ذخیره شد.' );
	}
};

$( '#btn-fetch-models' ).onclick = async () => {
	const note = $( '#models-note' );
	note.className = 'note';
	note.textContent = 'در حال گرفتن فهرست…';

	// اول تنظیمات فعلی ذخیره می‌شود، وگرنه فهرست را از پرووایدر قبلی می‌گیریم.
	await api( '/api/profile', {
		method: 'POST',
		body: JSON.stringify( {
			id: state.config.activeProfile || 'default',
			provider: $( '#f-provider' ).value,
			baseUrl: $( '#f-baseurl' ).value.trim(),
			apiKey: $( '#f-apikey' ).value.trim(),
			model: $( '#f-model' ).value.trim(),
		} ),
	} );

	const out = await api( '/api/models' );
	if ( out.error ) {
		note.className = 'note error';
		note.textContent = out.error;
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

// --------------------------------------------------------------- کنترل‌ها

$( '#mode' ).onchange = async ( e ) => {
	await api( '/api/mode', { method: 'POST', body: JSON.stringify( { mode: e.target.value } ) } );
	addNotice( `حالت شد: ${ e.target.selectedOptions[ 0 ].textContent }` );
};

$( '#btn-workspace' ).onclick = async () => {
	const next = prompt( 'مسیر پوشهٔ کاری:', state.config.workspace );
	if ( ! next ) {
		return;
	}
	const out = await api( '/api/workspace', { method: 'POST', body: JSON.stringify( { path: next } ) } );
	if ( out.error ) {
		addNotice( out.error, true );
	} else {
		state.config.workspace = out.path;
	}
};

$( '#btn-new' ).onclick = async () => {
	await api( '/api/new', { method: 'POST' } );
};

$( '#stop' ).onclick = async () => {
	await api( '/api/stop', { method: 'POST' } );
};

$( '#composer' ).onsubmit = async ( e ) => {
	e.preventDefault();
	const text = input.value.trim();
	if ( ! text ) {
		return;
	}
	input.value = '';
	input.style.height = 'auto';
	setBusy( true );
	const out = await api( '/api/message', { method: 'POST', body: JSON.stringify( { text } ) } );
	if ( out.error ) {
		addNotice( out.error, true );
		setBusy( false );
	} else if ( out.handled ) {
		setBusy( false );
		refreshState();
	}
};

input.addEventListener( 'input', () => {
	input.style.height = 'auto';
	input.style.height = Math.min( input.scrollHeight, 200 ) + 'px';
} );

input.addEventListener( 'keydown', ( e ) => {
	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		$( '#composer' ).requestSubmit();
	}
} );

boot();

// ------------------------------------------------- پنل افزونه‌ها و دستورها

async function refreshState() {
	state = await api( '/api/state' );
	renderProviderChip();
	$( '#mode' ).value = state.config.permissions.mode;
	$( '#btn-workspace' ).textContent = shortPath( state.config.workspace );
	return state;
}

function itemRow( name, desc, tags = [] ) {
	const row = el( 'div', 'item' );
	row.appendChild( el( 'b', null, name ) );
	row.appendChild( el( 'p', null, desc || '' ) );
	for ( const t of tags ) {
		row.appendChild( el( 'span', `tag ${ t.kind || '' }`, t.text ) );
	}
	return row;
}

function renderPanel( tab ) {
	for ( const t of document.querySelectorAll( '.tab' ) ) {
		t.classList.toggle( 'active', t.dataset.tab === tab );
	}
	for ( const id of [ 'tools', 'skills', 'mcp', 'plugins', 'commands' ] ) {
		$( `#tab-${ id }` ).hidden = id !== tab;
	}

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
		if ( ! state.skills.length ) {
			box.appendChild(
				el(
					'div',
					'empty',
					'هیچ اسکیلی نصب نیست.\n\nیک اسکیل آماده را در این مسیر بگذار:\n~/.hoosha/skills/<نام>/SKILL.md\n\nیا با نصب یک پلاگین، اسکیل‌هایش را بیاور.'
				)
			);
		}
		for ( const s of state.skills ) {
			box.appendChild( itemRow( s.name, s.description, [ { text: s.source } ] ) );
		}
	}

	if ( tab === 'mcp' ) {
		const box = $( '#tab-mcp' );
		box.replaceChildren();
		if ( ! state.mcp.length ) {
			box.appendChild(
				el(
					'div',
					'empty',
					'سرور MCP تنظیم نشده.\n\nدر ~/.hoosha/config.json کلید mcpServers را پر کن، یا در پوشهٔ پروژه فایل .hoosha/mcp.json بساز:\n\n{ "mcpServers": { "files": { "command": "npx", "args": ["-y","@modelcontextprotocol/server-filesystem","."] } } }'
				)
			);
		}
		for ( const s of state.mcp ) {
			box.appendChild(
				itemRow( s.name, s.status === 'connected' ? `${ s.tools.length } ابزار: ${ s.tools.join( '، ' ) }` : s.error || '', [
					{ text: s.status, kind: s.status === 'connected' ? 'ok' : 'err' },
				] )
			);
		}
	}

	if ( tab === 'plugins' ) {
		const box = $( '#plugin-list' );
		box.replaceChildren();
		if ( ! state.plugins.length ) {
			box.appendChild( el( 'div', 'empty', 'پلاگینی نصب نیست. بالا یک منبع بده و نصب کن.' ) );
		}
		for ( const p of state.plugins ) {
			const row = itemRow(
				p.name,
				`اسکیل: ${ p.has.skills } · دستور: ${ p.has.commands }${ p.has.mcp ? ' · MCP' : '' }${ p.has.hooks ? ' · هوک' : '' }`,
				[ { text: p.enabled ? 'فعال' : 'خاموش', kind: p.enabled ? 'ok' : '' } ]
			);
			const toggle = el( 'button', 'chip', p.enabled ? 'خاموش کن' : 'روشن کن' );
			toggle.onclick = async () => {
				await api( '/api/plugins', {
					method: 'POST',
					body: JSON.stringify( { action: 'toggle', name: p.name, enabled: ! p.enabled } ),
				} );
				await refreshState();
				renderPanel( 'plugins' );
			};
			const del = el( 'button', 'chip', 'حذف' );
			del.onclick = async () => {
				if ( ! confirm( `پلاگین «${ p.name }» حذف شود؟` ) ) {
					return;
				}
				await api( '/api/plugins', { method: 'POST', body: JSON.stringify( { action: 'remove', name: p.name } ) } );
				await refreshState();
				renderPanel( 'plugins' );
			};
			row.appendChild( toggle );
			row.appendChild( del );
			box.appendChild( row );
		}
	}

	if ( tab === 'commands' ) {
		const box = $( '#tab-commands' );
		box.replaceChildren();
		for ( const c of state.commands ) {
			box.appendChild( itemRow( `/${ c.name }`, c.description, [ { text: c.source } ] ) );
		}
	}
}

$( '#btn-panel' ).onclick = async () => {
	await refreshState();
	renderPanel( 'tools' );
	$( '#panel' ).showModal();
};
$( '#panel-close' ).onclick = () => $( '#panel' ).close();
for ( const t of document.querySelectorAll( '.tab' ) ) {
	t.onclick = () => renderPanel( t.dataset.tab );
}

$( '#btn-reload' ).onclick = async () => {
	const out = await api( '/api/reload', { method: 'POST' } );
	await refreshState();
	renderPanel( document.querySelector( '.tab.active' )?.dataset.tab || 'tools' );
	if ( out.mcp?.some( ( s ) => s.status === 'failed' ) ) {
		addNotice( 'بعضی سرورهای MCP وصل نشدند — تب MCP را ببین.', true );
	}
};

$( '#btn-install-plugin' ).onclick = async () => {
	const source = $( '#plugin-source' ).value.trim();
	const note = $( '#plugin-note' );
	if ( ! source ) {
		return;
	}
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

// ------------------------------------------------ تکمیل خودکار دستورها

const cmdMenu = $( '#cmd-menu' );
let cmdIndex = 0;

function commandMatches() {
	const text = input.value;
	if ( ! text.startsWith( '/' ) || text.includes( '\n' ) ) {
		return null;
	}
	const q = text.slice( 1 ).split( /\s/ )[ 0 ].toLowerCase();
	if ( text.slice( 1 ).includes( ' ' ) ) {
		return null;
	}
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

input.addEventListener( 'input', renderCmdMenu );

input.addEventListener( 'keydown', ( e ) => {
	if ( cmdMenu.hidden ) {
		return;
	}
	const list = commandMatches() || [];
	if ( e.key === 'ArrowDown' ) {
		e.preventDefault();
		cmdIndex = ( cmdIndex + 1 ) % list.length;
		renderCmdMenu();
	} else if ( e.key === 'ArrowUp' ) {
		e.preventDefault();
		cmdIndex = ( cmdIndex - 1 + list.length ) % list.length;
		renderCmdMenu();
	} else if ( e.key === 'Tab' && list.length ) {
		e.preventDefault();
		input.value = `/${ list[ cmdIndex ].name } `;
		cmdMenu.hidden = true;
	} else if ( e.key === 'Escape' ) {
		cmdMenu.hidden = true;
	}
}, true );

$( '#composer' ).addEventListener( 'submit', () => {
	cmdMenu.hidden = true;
} );
