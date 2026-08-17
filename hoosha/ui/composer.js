/**
 * کامپوزر: کادر نوشتن، منوی «/» دستورها، منوی «@» فایل‌ها، چرخش حالت و تاریخچهٔ پیام.
 *
 * نکتهٔ ریز ولی مهم: وقتی عامل مشغول است، پیام تازه دور ریخته نمی‌شود؛ در صف می‌ماند و
 * به‌محض بیکارشدن فرستاده می‌شود. در نسخهٔ قبل، پیام در همین حالت گم می‌شد.
 */

import { $, el, h, toast } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';

const MODES = [ 'plan', 'default', 'auto' ];
const MODE_LABEL = { plan: '◇ پلن', default: '◈ عادی', auto: '◆ خودکار' };
const MODE_HINT = {
	plan: 'فقط بررسی و خواندن — چیزی تغییر نمی‌کند',
	default: 'نوشتن و اجرا با تأیید تو',
	auto: 'بدون تأیید، جز آنچه ممنوع کرده‌ای',
};

let input;
let menu;
let items = [];
let index = 0;
let mode = 'default';
/** @type {string[]} */
let history = [];
let historyIndex = -1;
/** @type {string[]} */
const queue = [];
let busy = false;

/** @type {(text:string)=>Promise<any>} */
let send = async () => {};

/** @param {{onSend:(text:string)=>Promise<any>}} deps */
export function initComposer( deps ) {
	input = $( '#input' );
	menu = $( '#cmd-menu' );
	send = deps.onSend;

	$( '#composer' ).onsubmit = async ( e ) => {
		e.preventDefault();
		await submit();
	};

	$( '#stop' ).onclick = () => post( '/api/stop', {} );

	$( '#pill-mode' ).onclick = () => cycleMode();

	input.addEventListener( 'input', () => {
		autoGrow();
		refreshMenu();
	} );

	input.addEventListener( 'keydown', onKeyDown );

	// چسباندن مسیر فایل با drag & drop
	input.addEventListener( 'drop', ( e ) => {
		const text = e.dataTransfer?.getData( 'text/plain' );
		if ( text ) {
			e.preventDefault();
			insertAtCursor( `@${ text } ` );
		}
	} );

	document.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && ! menu.hidden ) {
			menu.hidden = true;
			e.stopPropagation();
		}
	} );

}

export function setMode( next ) {
	mode = MODES.includes( next ) ? next : 'default';
	const pill = $( '#pill-mode' );
	pill.textContent = MODE_LABEL[ mode ];
	pill.title = MODE_HINT[ mode ];
	pill.dataset.mode = mode;
	$( '#badge-mode' ).textContent = MODE_LABEL[ mode ];
	$( '#badge-mode' ).dataset.mode = mode;
	document.body.dataset.mode = mode;
}

export function currentMode() {
	return mode;
}

export async function cycleMode() {
	const next = MODES[ ( MODES.indexOf( mode ) + 1 ) % MODES.length ];
	setMode( next );
	await post( '/api/mode', { mode: next } );
	toast( `حالت: ${ MODE_LABEL[ next ] } — ${ MODE_HINT[ next ] }` );
}

export function setBusy( value ) {
	busy = value;
	$( '#send' ).hidden = value;
	$( '#stop' ).hidden = ! value;
	document.body.classList.toggle( 'busy', value );

	if ( ! value && queue.length ) {
		const next = queue.shift();
		paintQueue();
		submitText( next );
	}
}

export function focusComposer() {
	input?.focus();
}

function autoGrow() {
	input.style.height = 'auto';
	input.style.height = Math.min( input.scrollHeight, 260 ) + 'px';
}

function insertAtCursor( text ) {
	const start = input.selectionStart ?? input.value.length;
	const end = input.selectionEnd ?? input.value.length;
	input.value = input.value.slice( 0, start ) + text + input.value.slice( end );
	input.selectionStart = input.selectionEnd = start + text.length;
	input.focus();
	autoGrow();
}

async function submit() {
	const text = input.value.trim();
	if ( ! text ) {
		return;
	}
	input.value = '';
	autoGrow();
	menu.hidden = true;
	history.unshift( text );
	historyIndex = -1;

	if ( busy ) {
		queue.push( text );
		paintQueue();
		return;
	}
	await submitText( text );
}

async function submitText( text ) {
	setBusy( true );
	const out = await send( text );
	if ( out?.error || out?.handled ) {
		setBusy( false );
	}
}

function paintQueue() {
	const box = $( '#queue' );
	box.replaceChildren();
	box.hidden = ! queue.length;
	queue.forEach( ( q, i ) => {
		box.appendChild(
			h( 'div', { class: 'queued' }, [
				h( 'span', { text: '⏱' } ),
				h( 'span', { class: 'q-text', text: q } ),
				h( 'button', {
					class: 'icon-btn tiny',
					text: '×',
					onClick: () => {
						queue.splice( i, 1 );
						paintQueue();
					},
				} ),
			] )
		);
	} );
}

// ──────────────────────────────────────────────── منوهای «/» و «@»

function context() {
	const value = input.value;
	const pos = input.selectionStart ?? value.length;
	const before = value.slice( 0, pos );

	if ( /^\/[\w-]*$/.test( before ) && ! value.includes( '\n' ) ) {
		return { kind: 'command', query: before.slice( 1 ).toLowerCase(), start: 0 };
	}

	const at = before.lastIndexOf( '@' );
	if ( at > -1 && ! /\s/.test( before.slice( at + 1 ) ) && ( at === 0 || /\s/.test( before[ at - 1 ] ) ) ) {
		return { kind: 'file', query: before.slice( at + 1 ), start: at };
	}
	return null;
}

async function refreshMenu() {
	const ctx = context();
	if ( ! ctx ) {
		menu.hidden = true;
		items = [];
		return;
	}

	if ( ctx.kind === 'command' ) {
		const s = getState();
		items = ( s?.commands || [] )
			.filter( ( c ) => c.name.toLowerCase().startsWith( ctx.query ) )
			.slice( 0, 10 )
			.map( ( c ) => ( { label: `/${ c.name }`, hint: c.description || '', source: c.source, insert: `/${ c.name } `, start: 0 } ) );
	} else {
		const out = await api( `/api/files?q=${ encodeURIComponent( ctx.query ) }` );
		items = ( out.files || [] ).slice( 0, 10 ).map( ( f ) => ( { label: f, hint: '', source: 'فایل', insert: `@${ f } `, start: ctx.start } ) );
	}

	index = 0;
	paintMenu();
}

function paintMenu() {
	if ( ! items.length ) {
		menu.hidden = true;
		return;
	}
	menu.replaceChildren();
	items.forEach( ( it, i ) => {
		menu.appendChild(
			h( 'div', { class: `cmd-item ${ i === index ? 'active' : '' }`, onClick: () => pick( i ) }, [
				h( 'b', { text: it.label } ),
				h( 'span', { text: it.hint } ),
				h( 'em', { text: it.source } ),
			] )
		);
	} );
	menu.hidden = false;
}

function pick( i ) {
	const it = items[ i ];
	if ( ! it ) {
		return;
	}
	const pos = input.selectionStart ?? input.value.length;
	input.value = input.value.slice( 0, it.start ) + it.insert + input.value.slice( pos );
	input.selectionStart = input.selectionEnd = it.start + it.insert.length;
	menu.hidden = true;
	items = [];
	input.focus();
	autoGrow();
}

function onKeyDown( e ) {
	// منوی باز، اول کلیدها را می‌گیرد.
	if ( ! menu.hidden && items.length ) {
		if ( e.key === 'ArrowDown' ) {
			e.preventDefault();
			index = ( index + 1 ) % items.length;
			return paintMenu();
		}
		if ( e.key === 'ArrowUp' ) {
			e.preventDefault();
			index = ( index - 1 + items.length ) % items.length;
			return paintMenu();
		}
		if ( e.key === 'Tab' || ( e.key === 'Enter' && ! e.shiftKey ) ) {
			e.preventDefault();
			return pick( index );
		}
		if ( e.key === 'Escape' ) {
			menu.hidden = true;
			return;
		}
	}

	if ( e.key === 'Tab' && e.shiftKey ) {
		e.preventDefault();
		cycleMode();
		return;
	}

	if ( e.key === 'Enter' && ! e.shiftKey ) {
		e.preventDefault();
		$( '#composer' ).requestSubmit();
		return;
	}

	// تاریخچه: وقتی کادر خالی است، بالا یعنی پیام قبلی.
	if ( e.key === 'ArrowUp' && ! input.value.trim() && history.length ) {
		e.preventDefault();
		historyIndex = Math.min( historyIndex + 1, history.length - 1 );
		input.value = history[ historyIndex ];
		autoGrow();
		return;
	}
	if ( e.key === 'ArrowDown' && historyIndex > -1 ) {
		e.preventDefault();
		historyIndex--;
		input.value = historyIndex === -1 ? '' : history[ historyIndex ];
		autoGrow();
	}
}

/** برای دکمه‌های بیرونی (مثل «دوباره بفرست»). */
export function fillComposer( text, submitNow = false ) {
	input.value = text;
	autoGrow();
	input.focus();
	if ( submitNow ) {
		$( '#composer' ).requestSubmit();
	}
}

export function composerIsEmpty() {
	return ! input.value.trim();
}
