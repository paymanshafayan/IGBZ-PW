/**
 * نقطهٔ شروع رابط کاربری هوشا.
 *
 * کارش سه چیز است: وصل‌شدن به جریان رویدادها، پخش وضعیت بین بخش‌ها، و میان‌برهای صفحه‌کلید.
 * هر چیز دیگری در ماژول خودش است.
 */

import { $, h, toast, promptDialog, fmtTokens } from './lib/dom.js';
import { post, refreshState, subscribe, getState } from './lib/api.js';
import { mountThread, handleEvent, renderTranscript, clearThread, addError } from './thread.js';
import { initComposer, setBusy, setMode, fillComposer, composerIsEmpty, focusComposer } from './composer.js';
import { initSidebar, refreshSessions, paintSidebarState } from './sidebar.js';
import { initRail, paintRail } from './rail.js';
import { initSettings, openSettings } from './settings.js';
import { openFile, openRewind, openPalette, openShortcuts } from './dialogs.js';

// ─────────────────────────────────────────────────────────────── راه‌اندازی

document.documentElement.dataset.theme = localStorage.getItem( 'hoosha-theme' ) || 'dark';
document.documentElement.dataset.density = localStorage.getItem( 'hoosha-density' ) || 'comfy';
if ( localStorage.getItem( 'hoosha-fontsize' ) ) {
	document.documentElement.style.setProperty( '--fs', `${ localStorage.getItem( 'hoosha-fontsize' ) }px` );
}

mountThread( {
	root: $( '#chat' ),
	onResend: ( text ) => fillComposer( text, true ),
	onOpenFile: ( p ) => openFile( p ),
} );

initComposer( {
	onSend: async ( text, images ) => {
		const out = await post( '/api/message', { text, images } );
		if ( out.error ) {
			addError( out.error );
		}
		if ( out.handled ) {
			await refreshState();
		}
		return out;
	},
} );

initSidebar( { onResume: resumeSession } );
initRail( { onRewind: ( id ) => openRewind( doRewind, id ) } );
initSettings();

// ───────────────────────────────────────────────────────────────── وضعیت

subscribe( ( s ) => {
	// نسخه همیشه روی صفحه باشد. وقتی کسی می‌گوید «همان نسخهٔ قبلی است»، این تنها راهی است
	// که در یک نگاه معلوم شود واقعاً کدام کد در حال اجراست.
	$( '#brand-version' ).textContent = `v${ s.version }`;
	document.title = `هوشا ${ s.version }`;

	paintSidebarState( s );
	paintRail( s );
	setMode( s.config.permissions?.mode || 'default' );

	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#pill-model' ).textContent = p.model || 'مدل تنظیم نشده';
	$( '#pill-model' ).title = `${ p.provider || '' } · ${ p.model || '' }`;

	$( '#session-title' ).textContent = s.sessionTitle || 'گفتگوی تازه';

	const used = s.context?.used || 0;
	const win = s.context?.window || 200_000;
	const pct = Math.min( 100, Math.round( ( used / win ) * 100 ) );
	$( '#meter-fill' ).style.width = `${ pct }%`;
	$( '#meter-text' ).textContent = `${ pct }٪`;
	$( '#context-meter' ).title = `کانتکست: ${ fmtTokens( used ) } از ${ fmtTokens( win ) }`;
	$( '#context-meter' ).classList.toggle( 'high', pct > 75 );

	const cost = s.usage?.cost;
	$( '#cost-chip' ).textContent = cost ? `$${ Number( cost ).toFixed( 3 ) }` : `${ fmtTokens( ( s.usage?.inputTokens || 0 ) + ( s.usage?.outputTokens || 0 ) ) } توکن`;

	setBusy( Boolean( s.busy ) );

	if ( ! s.ready?.ok ) {
		showSetupBanner( s );
	} else {
		$( '#setup-banner' )?.remove();
	}
} );

function showSetupBanner( s ) {
	if ( $( '#setup-banner' ) ) {
		return;
	}
	const banner = h( 'div', { class: 'banner', id: 'setup-banner' }, [
		h( 'b', { text: 'هنوز آمادهٔ کار نیست' } ),
		h( 'span', { text: `کم است: ${ ( s.ready?.missing || [] ).join( '، ' ) }` } ),
		h( 'button', { class: 'pill primary', text: 'تنظیم پرووایدر', onClick: () => openSettings( 'provider' ) } ),
	] );
	$( '.main' ).insertBefore( banner, $( '#chat' ) );
}

// ───────────────────────────────────────────────────────── جریان رویدادها

function connectEvents() {
	const es = new EventSource( '/api/events' );

	es.onmessage = ( e ) => {
		const ev = JSON.parse( e.data );

		switch ( ev.type ) {
			case 'idle':
				setBusy( false );
				refreshState();
				refreshSessions();
				return;

			case 'reset':
				clearThread();
				showWelcome();
				refreshState();
				refreshSessions();
				return;

			case 'resumed':
				renderTranscript( ev.transcript );
				refreshState();
				return;

			case 'rewound':
				renderTranscript( ev.transcript );
				handleEvent( ev );
				refreshState();
				return;

			case 'mode':
				setMode( ev.mode );
				return;

			case 'profile':
			case 'workspace':
			case 'checkpoint':
			case 'shell_start':
			case 'shell_exit':
				refreshState();
				return;

			case 'usage':
				refreshState();
				return;

			case 'open_panel':
				openSettings( ev.tab );
				return;

			case 'open_rewind':
				openRewind( doRewind );
				return;

			case 'open_sessions':
				openPalette( paletteDeps() );
				return;

			case 'export':
				doExport( ev.format );
				return;

			case 'tool_log':
				if ( ev.todos ) {
					refreshState();
				}
				return;

			case 'hello':
				return;

			default:
				handleEvent( ev );
		}
	};

	es.onerror = () => {
		// EventSource خودش دوباره وصل می‌شود؛ فقط به کاربر بگو.
		document.body.classList.add( 'offline' );
		setTimeout( () => document.body.classList.remove( 'offline' ), 4000 );
	};
}

// ───────────────────────────────────────────────────────────────── کنش‌ها

async function resumeSession( id ) {
	const out = await post( '/api/resume', { id } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	renderTranscript( out.transcript );
	await refreshState();
	await refreshSessions();
}

async function doRewind( id, opts ) {
	const out = await post( '/api/rewind', { id, ...opts } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	await refreshState();
}

async function doExport( format = 'md' ) {
	const res = await fetch( `/api/export?format=${ format === 'json' ? 'json' : 'md' }` );
	const text = await res.text();
	const blob = new Blob( [ text ], { type: format === 'json' ? 'application/json' : 'text/markdown' } );
	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( blob );
	a.download = `hoosha-${ getState()?.sessionId || 'session' }.${ format === 'json' ? 'json' : 'md' }`;
	a.click();
	URL.revokeObjectURL( a.href );
	toast( 'خروجی ذخیره شد.' );
}

function paletteDeps() {
	return {
		onSession: resumeSession,
		onCommand: ( name ) => fillComposer( `/${ name } `, false ),
		onFile: ( p ) => openFile( p ),
		onSettings: ( tab ) => openSettings( tab ),
	};
}

function showWelcome() {
	const chat = $( '#chat' );
	if ( $( '#welcome' ) ) {
		return;
	}
	const w = h( 'div', { class: 'welcome', id: 'welcome' }, [
		h( 'div', { class: 'welcome-mark' } ),
		h( 'h1', { text: 'هوشا' } ),
		h( 'p', { text: 'یک دستیار عامل که روی دستگاه خودت اجرا می‌شود، ابزار واقعی دارد و با هر پرووایدری کار می‌کند.' } ),
		h( 'div', { class: 'starter', id: 'starter' } ),
	] );
	chat.appendChild( w );
	renderStarters();
}

const STARTERS = [
	[ 'این پروژه را برایم توضیح بده', 'ساختار پوشه‌ها و کارِ هر بخش را بگو.' ],
	[ 'یک HOOSHA.md بساز', 'قواعد این پروژه را در حافظهٔ دائمی بنویس.' ],
	[ 'تست‌ها را اجرا کن', 'و اگر قرمز شد، دلیلش را پیدا کن.' ],
	[ 'دنبال کد تکراری بگرد', 'و پیشنهاد پاک‌سازی بده.' ],
];

function renderStarters() {
	const box = $( '#starter' );
	if ( ! box ) {
		return;
	}
	box.replaceChildren();
	for ( const [ title, sub ] of STARTERS ) {
		box.appendChild(
			h( 'button', { class: 'starter-card', onClick: () => fillComposer( title, false ) }, [
				h( 'b', { text: title } ),
				h( 'span', { text: sub } ),
			] )
		);
	}
}

// ───────────────────────────────────────────────────────── دکمه‌های بالا

$( '#btn-new' ).onclick = async () => {
	await post( '/api/new', {} );
	clearThread();
	showWelcome();
	await refreshState();
	await refreshSessions();
	focusComposer();
};

$( '#btn-settings' ).onclick = () => openSettings();
$( '#btn-help' ).onclick = () => openShortcuts();
$( '#btn-export' ).onclick = () => doExport( 'md' );
$( '#btn-menu' ).onclick = () => document.body.classList.toggle( 'sidebar-open' );

$( '#btn-workspace' ).onclick = async () => {
	const next = await promptDialog( 'مسیر پوشهٔ کاری:', getState()?.config?.workspace || '' );
	if ( ! next ) {
		return;
	}
	const out = await post( '/api/workspace', { path: next } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	await refreshState();
	toast( 'پوشهٔ کاری عوض شد.' );
};

document.addEventListener( 'hoosha:settings', ( e ) => openSettings( e.detail ) );
$( '#session-title' ).onclick = async () => {
	const s = getState();
	const title = await promptDialog( 'نام گفتگو:', s?.sessionTitle || '' );
	if ( title === null ) {
		return;
	}
	await post( '/api/sessions', { action: 'rename', id: s.sessionId, title } );
	await refreshState();
	await refreshSessions();
};

// ────────────────────────────────────────────────────── میان‌برهای صفحه

let lastEscape = 0;

document.addEventListener( 'keydown', ( e ) => {
	const typing = [ 'INPUT', 'TEXTAREA' ].includes( document.activeElement?.tagName ) || document.activeElement?.isContentEditable;

	if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'k' ) {
		e.preventDefault();
		openPalette( paletteDeps() );
		return;
	}
	if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'n' ) {
		e.preventDefault();
		$( '#btn-new' ).click();
		return;
	}
	if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'b' ) {
		e.preventDefault();
		$( '#btn-rail' ).click();
		return;
	}
	if ( ( e.ctrlKey || e.metaKey ) && e.key === ',' ) {
		e.preventDefault();
		openSettings();
		return;
	}

	if ( e.key === 'Escape' ) {
		const now = Date.now();
		if ( now - lastEscape < 600 ) {
			openRewind( doRewind );
			lastEscape = 0;
			return;
		}
		lastEscape = now;
		if ( getState()?.busy ) {
			post( '/api/stop', {} );
			toast( 'متوقف شد.' );
		}
		return;
	}

	if ( ! typing && e.key === '?' ) {
		e.preventDefault();
		openShortcuts();
		return;
	}

	if ( typing && e.key === '?' && composerIsEmpty() ) {
		e.preventDefault();
		openShortcuts();
	}
} );

// ──────────────────────────────────────────────────────────────── شروع

async function boot() {
	const s = await refreshState();
	await refreshSessions();
	renderStarters();

	if ( s.transcript?.length ) {
		renderTranscript( s.transcript );
	}
	for ( const ask of s.pendingAsk || [] ) {
		handleEvent( ask );
	}

	connectEvents();
	focusComposer();
}

boot();
