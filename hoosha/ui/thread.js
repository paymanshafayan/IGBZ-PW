/**
 * ناحیهٔ گفتگو: پیام‌ها، کارت ابزارها، دروازهٔ تأیید، کارت نقشه و کارت پرسش.
 *
 * قاعدهٔ طراحی که از Claude Code گرفته‌ایم: **هر ابزار نمایش خودش را دارد**. یک <pre> برای
 * همه‌چیز، همان چیزی است که باعث می‌شود یک ابزار جدی، اسباب‌بازی به‌نظر برسد.
 */

import { $, el, h, esc, copyText, promptDialog } from './lib/dom.js';
import { markdown, wireCodeCopy } from './lib/markdown.js';
import { post } from './lib/api.js';

let chat = null;
let streamEl = null;
const toolEls = new Map();
/** @type {(text:string)=>void} */
let onResend = () => {};
/** @type {(path:string)=>void} */
let onOpenFile = () => {};

/**
 * @param {{root:HTMLElement, onResend:(t:string)=>void, onOpenFile:(p:string)=>void}} opts
 */
export function mountThread( opts ) {
	chat = opts.root;
	onResend = opts.onResend;
	onOpenFile = opts.onOpenFile;
}

export function clearThread() {
	chat.replaceChildren();
	toolEls.clear();
	streamEl = null;
}

function atBottom() {
	return chat.scrollHeight - chat.scrollTop - chat.clientHeight < 160;
}

export function scrollToEnd() {
	chat.scrollTop = chat.scrollHeight;
}

function append( node ) {
	const stick = atBottom();
	$( '#welcome' )?.remove();
	chat.appendChild( node );
	if ( stick ) {
		scrollToEnd();
	}
	return node;
}

// ─────────────────────────────────────────────────────────────────── پیام‌ها

export function addMessage( role, text, asMarkdown = true ) {
	const wrap = el( 'div', `msg ${ role }` );

	const gutter = el( 'div', 'gutter' );
	gutter.appendChild( el( 'span', 'avatar', role === 'user' ? '⌂' : role === 'system' ? 'i' : '✦' ) );
	wrap.appendChild( gutter );

	const col = el( 'div', 'col' );
	const body = el( 'div', 'body' );
	if ( asMarkdown ) {
		body.innerHTML = markdown( text );
		wireCodeCopy( body );
	} else {
		body.textContent = text;
	}
	col.appendChild( body );

	const actions = el( 'div', 'msg-actions' );
	const copy = el( 'button', 'ghost-btn', 'کپی' );
	copy.onclick = () => copyText( body.dataset.raw || body.textContent || '' );
	actions.appendChild( copy );

	if ( role === 'user' ) {
		const again = el( 'button', 'ghost-btn', 'دوباره بفرست' );
		again.onclick = () => onResend( body.textContent || '' );
		actions.appendChild( again );
	}

	col.appendChild( actions );
	wrap.appendChild( col );
	append( wrap );
	return body;
}

export function addNotice( text ) {
	append( h( 'div', { class: 'notice' }, [ h( 'span', { class: 'notice-ico', text: '•' } ), h( 'span', { text } ) ] ) );
}

export function addError( message, hint ) {
	const card = el( 'div', 'err-card' );
	card.appendChild( el( 'b', null, message ) );
	if ( hint ) {
		card.appendChild( el( 'p', null, hint ) );
	}
	append( card );
}

// ────────────────────────────────────────────────────────── کارت‌های ابزار

const TOOL_META = {
	read_file: { ico: '◎', label: 'خواندن' },
	write_file: { ico: '✎', label: 'نوشتن' },
	edit_file: { ico: '✎', label: 'ویرایش' },
	multi_edit: { ico: '✎', label: 'ویرایش چندگانه' },
	list_dir: { ico: '▤', label: 'فهرست پوشه' },
	glob: { ico: '⌕', label: 'یافتن فایل' },
	grep: { ico: '⌕', label: 'جستجوی متن' },
	bash: { ico: '❯', label: 'فرمان' },
	bash_output: { ico: '❯', label: 'خروجی شل' },
	kill_shell: { ico: '■', label: 'توقف شل' },
	web_fetch: { ico: '⇩', label: 'دریافت از وب' },
	web_search: { ico: '⌕', label: 'جستجوی وب' },
	todo_write: { ico: '☑', label: 'فهرست کار' },
	skill: { ico: '◆', label: 'اسکیل' },
	task: { ico: '⌗', label: 'زیرعامل' },
	exit_plan_mode: { ico: '◇', label: 'نقشهٔ کار' },
	ask_user_question: { ico: '?', label: 'پرسش' },
};

export function toolMeta( name ) {
	if ( TOOL_META[ name ] ) {
		return TOOL_META[ name ];
	}
	if ( String( name ).startsWith( 'mcp__' ) ) {
		const [ , server, tool ] = String( name ).split( '__' );
		return { ico: '⇄', label: `${ server } · ${ tool }` };
	}
	return { ico: '⚒', label: name };
}

function toolCard( id, name, summary, sub ) {
	const meta = toolMeta( name );
	const card = el( 'div', 'tool' );
	const head = el( 'div', 'tool-head' );

	head.appendChild( el( 'span', 'tool-ico', meta.ico ) );
	if ( sub ) {
		head.appendChild( el( 'span', 'sub-tag', sub ) );
	}
	head.appendChild( el( 'span', 'tool-name', meta.label ) );
	head.appendChild( el( 'span', 'tool-sum mono', summary || '' ) );

	const badge = el( 'span', 'tool-state run', 'در حال اجرا' );
	head.appendChild( badge );
	head.appendChild( el( 'span', 'tool-chevron', '⌄' ) );

	head.onclick = () => {
		card.classList.toggle( 'open' );
		const body = card.querySelector( '.tool-body' );
		if ( body ) {
			body.hidden = ! card.classList.contains( 'open' );
		}
	};

	card.appendChild( head );
	card._badge = badge;
	card._name = name;
	append( card );
	toolEls.set( id, card );
	return card;
}

/** خروجی هر ابزار، به شکل خودش. */
function renderOutput( name, output ) {
	const text = String( output ?? '' );

	if ( name === 'write_file' || name === 'edit_file' || name === 'multi_edit' ) {
		return diffView( text );
	}
	if ( name === 'todo_write' ) {
		return todoView( text );
	}
	if ( name === 'bash' || name === 'bash_output' ) {
		return h( 'pre', { class: 'tool-body mono terminal', text } );
	}
	if ( name === 'grep' || name === 'glob' ) {
		return hitList( text );
	}
	if ( name === 'list_dir' ) {
		return dirView( text );
	}
	if ( name === 'read_file' ) {
		return h( 'pre', { class: 'tool-body mono code-lines', text } );
	}
	if ( name === 'web_search' ) {
		return linkList( text );
	}
	return h( 'pre', { class: 'tool-body mono', text } );
}

function diffView( output ) {
	const box = el( 'div', 'tool-body diff mono' );
	for ( const line of output.split( '\n' ) ) {
		const cls = line.startsWith( '+' )
			? 'add'
			: line.startsWith( '-' )
			? 'del'
			: line.startsWith( '@@' )
			? 'meta'
			: line.startsWith( '---' ) || line.startsWith( '+++' )
			? 'meta'
			: '';
		box.appendChild( el( 'div', cls, line ) );
	}
	return box;
}

function todoView( output ) {
	const box = el( 'div', 'tool-body todos' );
	for ( const line of output.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}
		const done = line.startsWith( '☑' );
		const doing = line.startsWith( '▸' );
		const row = el( 'div', `todo ${ done ? 'done' : doing ? 'doing' : '' }` );
		row.appendChild( el( 'span', 'box', done ? '☑' : doing ? '▸' : '☐' ) );
		row.appendChild( el( 'span', null, line.replace( /^[☑▸☐]\s*/, '' ) ) );
		box.appendChild( row );
	}
	return box;
}

function hitList( output ) {
	const box = el( 'div', 'tool-body hits mono' );
	for ( const line of output.split( '\n' ) ) {
		if ( ! line.trim() ) {
			continue;
		}
		const m = /^([^:]+):(\d+):\s?(.*)$/.exec( line );
		const row = el( 'div', 'hit' );
		if ( m ) {
			const link = el( 'button', 'file-link', `${ m[ 1 ] }:${ m[ 2 ] }` );
			link.onclick = () => onOpenFile( m[ 1 ] );
			row.appendChild( link );
			row.appendChild( el( 'span', 'hit-text', m[ 3 ] ) );
		} else {
			const link = el( 'button', 'file-link', line );
			link.onclick = () => onOpenFile( line.trim() );
			row.appendChild( link );
		}
		box.appendChild( row );
	}
	return box;
}

function dirView( output ) {
	const box = el( 'div', 'tool-body dir' );
	for ( const name of output.split( '\n' ) ) {
		if ( ! name.trim() ) {
			continue;
		}
		const isDir = name.endsWith( '/' );
		box.appendChild(
			h( 'div', { class: `dir-item ${ isDir ? 'is-dir' : '' }` }, [
				h( 'span', { class: 'dir-ico', text: isDir ? '▸' : '·' } ),
				h( 'span', { class: 'mono', text: name } ),
			] )
		);
	}
	return box;
}

function linkList( output ) {
	const box = el( 'div', 'tool-body links' );
	box.innerHTML = output
		.split( '\n' )
		.map( ( l ) => esc( l ).replace( /(https?:\/\/\S+)/g, '<a href="$1" target="_blank" rel="noreferrer">$1</a>' ) )
		.join( '<br />' );
	return box;
}

function finishTool( id, { output, error, denied, reason } ) {
	const card = toolEls.get( id );
	if ( ! card ) {
		return;
	}

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
	if ( ! body ) {
		return;
	}

	const node = error || denied ? h( 'pre', { class: 'tool-body mono', text: String( body ) } ) : renderOutput( card._name, body );

	const short = String( body ).split( '\n' ).length <= 14;
	node.hidden = ! short;
	if ( short ) {
		card.classList.add( 'open' );
	}

	// خط اول خروجی، خلاصهٔ خوبی برای هدر است (مثلاً «+12 −3»).
	const first = String( body ).split( '\n' )[ 0 ];
	if ( /[+−-]\d+/.test( first ) && first.length < 90 ) {
		card.querySelector( '.tool-sum' ).textContent = first;
	}

	card.appendChild( node );
	if ( atBottom() ) {
		scrollToEnd();
	}
}

// ──────────────────────────────────────────────────────── دروازهٔ تأیید

function askCard( ev ) {
	const meta = toolMeta( ev.name );
	const card = el( 'div', 'ask' );

	card.appendChild(
		h( 'div', { class: 'ask-head' }, [
			h( 'span', { class: 'ask-ico', text: meta.ico } ),
			h( 'b', { text: 'اجازه می‌دهی این کار انجام شود؟' } ),
			h( 'span', { class: 'ask-tool mono', text: ev.name } ),
		] )
	);

	card.appendChild( h( 'pre', { class: 'mono ask-body', text: ev.summary || JSON.stringify( ev.input, null, 2 ) } ) );

	// برای ویرایش فایل، نشان بده دقیقاً چه چیزی عوض می‌شود.
	if ( ev.name === 'edit_file' && ev.input?.old_string ) {
		const box = el( 'div', 'diff mono preview' );
		for ( const line of String( ev.input.old_string ).split( '\n' ) ) {
			box.appendChild( el( 'div', 'del', `-  ${ line }` ) );
		}
		for ( const line of String( ev.input.new_string ?? '' ).split( '\n' ) ) {
			box.appendChild( el( 'div', 'add', `+  ${ line }` ) );
		}
		card.appendChild( box );
	}

	const actions = el( 'div', 'ask-actions' );
	const allow = el( 'button', 'pill primary', 'اجازه بده' );
	const always = el( 'button', 'pill', alwaysLabel( ev ) );
	const deny = el( 'button', 'pill ghost', 'رد کن' );
	const never = el( 'button', 'pill ghost danger', 'هرگز' );

	const answer = async ( decision, remember ) => {
		for ( const b of [ allow, always, deny, never ] ) {
			b.disabled = true;
		}
		actions.replaceChildren(
			el(
				'span',
				`note ${ decision === 'allow' ? 'ok' : 'error' }`,
				decision === 'allow'
					? remember
						? 'اجازه داده شد و به یاد سپرده شد.'
						: 'اجازه داده شد.'
					: remember
					? 'رد شد و از این پس همیشه رد می‌شود.'
					: 'رد شد.'
			)
		);
		await post( '/api/permission', {
			id: ev.id,
			decision,
			remember,
			rule: remember ? ruleFor( ev ) : undefined,
		} );
	};

	allow.onclick = () => answer( 'allow', false );
	always.onclick = () => answer( 'allow', true );
	deny.onclick = () => answer( 'deny', false );
	never.onclick = () => answer( 'deny', true );

	actions.append( allow, always, deny, never );
	card.appendChild( actions );
	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
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

// ─────────────────────────────────────────── کارت نقشه و کارت پرسش

function planCard( ev ) {
	const card = el( 'div', 'plan-card' );
	card.appendChild( h( 'div', { class: 'plan-head' }, [ h( 'span', { text: '◇' } ), h( 'b', { text: 'نقشهٔ کار آماده است' } ) ] ) );

	const body = el( 'div', 'plan-body' );
	body.innerHTML = markdown( ev.plan || '' );
	wireCodeCopy( body );
	card.appendChild( body );

	const actions = el( 'div', 'ask-actions' );
	const run = el( 'button', 'pill primary', 'تأیید و اجرا (با تأیید هر مرحله)' );
	const auto = el( 'button', 'pill', 'تأیید و اجرای خودکار' );
	const keep = el( 'button', 'pill ghost', 'نه، اصلاحش کن' );

	const answer = async ( value, mode ) => {
		for ( const b of [ run, auto, keep ] ) {
			b.disabled = true;
		}
		actions.replaceChildren( el( 'span', `note ${ value.approved ? 'ok' : '' }`, value.approved ? 'تأیید شد.' : 'برگشت به پلن.' ) );
		await post( '/api/answer', { id: ev.id, value, mode } );
	};

	run.onclick = () => answer( { approved: true, mode: 'default' }, 'default' );
	auto.onclick = () => answer( { approved: true, mode: 'auto' }, 'auto' );
	keep.onclick = async () => {
		const feedback = ( await promptDialog( 'چه چیزی را عوض کند؟', '' ) ) || '';
		answer( { approved: false, feedback }, 'plan' );
	};

	actions.append( run, auto, keep );
	card.appendChild( actions );
	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
}

function questionCard( ev ) {
	const card = el( 'div', 'q-card' );
	card.appendChild( h( 'div', { class: 'q-head' }, [ h( 'span', { text: '?' } ), h( 'b', { text: ev.question || 'یک انتخاب لازم است' } ) ] ) );

	const list = el( 'div', 'q-options' );
	const send = async ( value ) => {
		list.querySelectorAll( 'button' ).forEach( ( b ) => ( b.disabled = true ) );
		card.appendChild( el( 'div', 'note ok', `پاسخ: ${ value }` ) );
		await post( '/api/answer', { id: ev.id, value } );
	};

	for ( const opt of ev.options || [] ) {
		const btn = h( 'button', { class: 'q-option', onClick: () => send( opt.label ) }, [
			h( 'b', { text: opt.label } ),
			opt.description ? h( 'span', { text: opt.description } ) : null,
		] );
		list.appendChild( btn );
	}
	card.appendChild( list );

	if ( ev.allowOther !== false ) {
		const input = h( 'input', { class: 'field', placeholder: 'یا خودت بنویس…' } );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' && input.value.trim() ) {
				send( input.value.trim() );
			}
		} );
		card.appendChild( input );
	}

	append( card );
	card.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
}

// ──────────────────────────────────────────────────────────── رویدادها

/** @param {any} ev */
export function handleEvent( ev ) {
	switch ( ev.type ) {
		case 'user':
			addMessage( 'user', ev.text, false );
			break;

		case 'assistant_start':
			streamEl = addMessage( 'assistant', '' );
			streamEl._raw = '';
			break;

		case 'text': {
			if ( ! streamEl ) {
				streamEl = addMessage( 'assistant', '' );
				streamEl._raw = '';
			}
			streamEl._raw += ev.text;
			streamEl.dataset.raw = streamEl._raw;
			streamEl.innerHTML = markdown( streamEl._raw );
			wireCodeCopy( streamEl );
			if ( atBottom() ) {
				scrollToEnd();
			}
			break;
		}

		case 'assistant_end':
			if ( streamEl && ! streamEl.textContent.trim() ) {
				streamEl.closest( '.msg' )?.remove();
			}
			streamEl = null;
			break;

		case 'system':
			addMessage( 'system', ev.text );
			break;

		case 'notice':
			addNotice( ev.text );
			break;

		case 'error':
			addError( ev.error, ev.hint );
			break;

		case 'permission_request':
			askCard( ev );
			break;

		case 'ask_user':
			if ( ev.kind === 'plan' ) {
				planCard( ev );
			} else {
				questionCard( ev );
			}
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

		case 'subagent_start':
			append( h( 'div', { class: 'subagent open' }, [ h( 'span', { text: '⌗' } ), h( 'b', { text: ev.label } ), h( 'span', { class: 'note', text: 'زیرعامل شروع شد' } ) ] ) );
			break;

		case 'subagent_end':
			append( h( 'div', { class: 'subagent done' }, [ h( 'span', { text: '⌗' } ), h( 'b', { text: ev.label } ), h( 'span', { class: 'note', text: 'زیرعامل تمام شد' } ) ] ) );
			break;

		case 'compacted':
			addNotice( `گفتگو فشرده شد: ${ ev.before } → ${ ev.after } پیام.` );
			break;

		case 'rewound':
			addNotice(
				`بازگشت انجام شد. ${ ev.restored?.length || 0 } فایل برگشت${ ev.deleted?.length ? ` و ${ ev.deleted.length } فایل حذف شد` : '' }.`
			);
			break;

		default:
			break;
	}
}

/** بازسازی کل صفحه از روی نوار رویدادهای ذخیره‌شده (بازخوانی نشست). */
export function renderTranscript( list ) {
	clearThread();
	for ( const ev of list || [] ) {
		if ( ev.type === 'assistant_end' ) {
			if ( String( ev.text || '' ).trim() ) {
				addMessage( 'assistant', ev.text );
			}
			continue;
		}
		if ( ev.type === 'assistant_start' || ev.type === 'text' ) {
			continue;
		}
		handleEvent( ev );
	}
	scrollToEnd();
}
