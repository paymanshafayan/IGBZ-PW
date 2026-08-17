/**
 * نقطهٔ شروع رابط کاربری هوشا.
 *
 * دو نما دارد: «گفتگو» و «پنل». نوار کناری بینشان جابه‌جا می‌کند — همان‌طور که در Claude
 * با Chats / Projects / Artifacts / Code جابه‌جا می‌شوی. هیچ‌کدام از این‌ها پشت یک دیالوگ
 * تنظیمات پنهان نیست.
 */

import { $, h, toast, promptDialog, fmtTokens } from './lib/dom.js';
import { post, refreshState, subscribe, getState } from './lib/api.js';
import {
	mountThread,
	handleEvent,
	renderTranscript,
	clearThread,
	addError,
	showWorking,
	hideWorking,
} from './thread.js';
import { initComposer, setBusy, setMode, fillComposer, composerIsEmpty, focusComposer } from './composer.js';
import { initSidebar, refreshSessions, paintSidebarState, markActiveView } from './sidebar.js';
import { initRail, paintRail } from './rail.js';
import { mountSettings, renderSection, SETTINGS_TABS } from './settings.js';
import { openFile, openRewind, openPalette, openShortcuts } from './dialogs.js';
import { logoSvg } from './lib/logo.js';

// تم: تا وقتی کاربر خودش انتخاب نکرده، از تنظیم سیستم پیروی می‌کنیم — مثل Claude.
const savedTheme = localStorage.getItem( 'hoosha-theme' );
const systemDark = window.matchMedia?.( '(prefers-color-scheme: dark)' );
document.documentElement.dataset.theme = savedTheme || ( systemDark?.matches ? 'dark' : 'light' );
systemDark?.addEventListener?.( 'change', ( e ) => {
	if ( ! localStorage.getItem( 'hoosha-theme' ) ) {
		document.documentElement.dataset.theme = e.matches ? 'dark' : 'light';
	}
} );

// نشان، همان یک جا تعریف می‌شود و همه‌جا از همان می‌آید.
$( '#brand-mark' ).innerHTML = logoSvg( 20 );

// ───────────────────────────────────────────────────────── نماها

const PANELS = {
	tools: { title: 'ابزارها', sub: 'هرچه مدل می‌تواند صدا بزند. آنچه کنترل می‌شود دسترسی است، نه توانایی.', section: 'tools' },
	connectors: { title: 'کانکتورها', sub: 'سرورهای MCP: ابزارهای سرویس‌های بیرونی را داخل هوشا می‌آورند.', section: 'connectors' },
	skills: { title: 'اسکیل‌ها', sub: 'دانش رویه‌ای آماده، با فرمت استاندارد SKILL.md.', section: 'skills' },
	agents: { title: 'زیرعامل‌ها', sub: 'هر زیرعامل یک متخصص است با پرامپت، مدل و ابزارهای خودش.', section: 'agents' },
	workspace: { title: 'فضای کار', sub: 'پوشهٔ کاری، حافظهٔ پروژه، مجوزها و سندباکس — همه در همین صفحه.', section: 'workspace' },
	settings: { title: 'تنظیمات', sub: 'هر چیزی که در فایل تنظیمات هست، اینجا فرم دارد.', section: 'settings' },
};

let view = 'chat';
let settingsTab = 'provider';

/** میان‌بر: باز کردن صفحهٔ تنظیمات روی یک تب مشخص. */
function openSettings( tab ) {
	settingsTab = tab || settingsTab;
	showView( 'settings' );
}

async function showView( next ) {
	view = next;
	markActiveView( next );

	const chat = $( '#view-chat' );
	const panel = $( '#view-panel' );

	if ( next === 'chat' ) {
		chat.hidden = false;
		panel.hidden = true;
		focusComposer();
		return;
	}

	chat.hidden = true;
	panel.hidden = false;

	const meta = PANELS[ next ];
	const box = $( '#panel-body' );
	box.replaceChildren(
		h( 'h1', { class: 'panel-title', text: meta.title } ),
		h( 'p', { class: 'panel-sub', text: meta.sub } )
	);

	const host = h( 'div', {} );
	box.appendChild( host );

	if ( next === 'settings' ) {
		box.replaceChildren(); // صفحهٔ تنظیمات سربرگ خودش را دارد
		await mountSettings( box, settingsTab );
		return;
	}
	if ( next === 'workspace' ) {
		await renderWorkspacePanel( host );
		return;
	}
	await renderSection( meta.section, host );
}

const WORKSPACE_TABS = [
	{ id: 'memory', label: 'حافظهٔ پروژه' },
	{ id: 'permissions', label: 'مجوزها' },
	{ id: 'sandbox', label: 'سندباکس' },
	{ id: 'usage', label: 'مصرف و هزینه' },
	{ id: 'status', label: 'وضعیت' },
];
let workspaceTab = 'memory';

async function renderWorkspacePanel( host ) {
	const s = getState();

	host.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'h4', { text: 'پوشهٔ کاری' } ),
			h( 'p', { class: 'mono note', text: s.config.workspace } ),
			h( 'div', { class: 'row' }, [
				h( 'button', {
					class: 'pill primary',
					text: 'تغییر پوشه',
					onClick: async () => {
						const next = await promptDialog( 'مسیر پوشهٔ کاری:', s.config.workspace );
						if ( ! next ) {
							return;
						}
						const out = await post( '/api/workspace', { path: next } );
						if ( out.error ) {
							toast( out.error, 'error' );
							return;
						}
						await refreshState();
						showView( 'workspace' );
					},
				} ),
			] ),
		] )
	);

	// زیربخش‌ها همین‌جا باز می‌شوند — هیچ‌کدام دیالوگ باز نمی‌کنند.
	const tabs = h( 'nav', { class: 'tab-row' } );
	const body = h( 'div', {} );
	host.append( tabs, body );

	const paint = async ( id ) => {
		workspaceTab = id;
		for ( const b of tabs.children ) {
			b.classList.toggle( 'active', b.dataset.tab === id );
		}
		await renderSection( id, body );
	};

	for ( const t of WORKSPACE_TABS ) {
		tabs.appendChild(
			h( 'button', { class: 'tab-btn', dataset: { tab: t.id }, text: t.label, onClick: () => paint( t.id ) } )
		);
	}
	await paint( workspaceTab );
}

// ───────────────────────────────────────────────────────── راه‌اندازی

mountThread( {
	root: $( '#chat' ),
	onResend: ( text ) => fillComposer( text, true ),
	onOpenFile: ( p ) => openFile( p ),
} );

initComposer( {
	onSend: async ( text, images ) => {
		if ( view !== 'chat' ) {
			showView( 'chat' );
		}
		const out = await post( '/api/message', { text, images } );
		if ( out.error ) {
			addError( out.error );
		}
		if ( out.handled ) {
			await refreshState();
		}
		return out;
	},
	onView: ( v ) => showView( v ),
} );

initSidebar( { onResume: resumeSession, onView: showView } );
initRail( { onRewind: ( id ) => openRewind( doRewind, id ) } );

// ───────────────────────────────────────────────────────── وضعیت

subscribe( ( s ) => {
	$( '#brand-version' ).textContent = `v${ s.version }`;
	document.title = `هوشا ${ s.version }`;

	paintSidebarState( s );
	paintRail( s );
	setMode( s.config.permissions?.mode || 'default' );

	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#model-name' ).textContent = p.model || 'مدل تنظیم نشده';
	$( '#pill-model' ).title = `${ p.provider || '' } · ${ p.model || '' }`;

	$( '#session-title-text' ).textContent = s.sessionTitle || 'گفتگوی تازه';

	const used = s.context?.used || 0;
	const win = s.context?.window || 200_000;
	const pct = Math.min( 100, Math.round( ( used / win ) * 100 ) );
	$( '#meter-text' ).textContent = `${ pct }٪`;
	$( '#context-meter' ).title = `کانتکست: ${ fmtTokens( used ) } از ${ fmtTokens( win ) }${
		s.usage?.cost ? ` · $${ Number( s.usage.cost ).toFixed( 3 ) }` : ''
	}`;
	$( '#context-meter' ).classList.toggle( 'high', pct > 75 );

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
	const banner = h( 'div', { class: 'banner danger', id: 'setup-banner' }, [
		h( 'b', { text: 'هنوز آمادهٔ کار نیست' } ),
		h( 'span', { text: `کم است: ${ ( s.ready?.missing || [] ).join( '، ' ) }` } ),
		h( 'button', { class: 'pill primary', text: 'تنظیم پرووایدر', onClick: () => openSettings( 'provider' ) } ),
	] );
	$( '.main' ).insertBefore( banner, $( '#view-chat' ) );
}

// ─────────────────────────────────────────────────── جریان رویدادها

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
				if ( view !== 'chat' ) {
					showView( 'chat' );
				}
				handleEvent( ev );
		}
	};

	es.onerror = () => {
		document.body.classList.add( 'offline' );
		setTimeout( () => document.body.classList.remove( 'offline' ), 4000 );
	};
}

// ───────────────────────────────────────────────────────── کنش‌ها

async function resumeSession( id ) {
	showView( 'chat' );
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
	chat.appendChild(
		h( 'div', { class: 'welcome', id: 'welcome' }, [
			h( 'div', { class: 'greet' }, [
				h( 'span', { class: 'greet-mark', html: logoSvg( 34 ) } ),
				h( 'span', { text: greeting() } ),
			] ),
		] )
	);
}

function greeting() {
	const hour = new Date().getHours();
	if ( hour < 5 ) {
		return 'شب‌بخیر — چه کاری انجام بدهم؟';
	}
	if ( hour < 12 ) {
		return 'صبح‌بخیر — چه کاری انجام بدهم؟';
	}
	if ( hour < 17 ) {
		return 'ظهر بخیر — چه کاری انجام بدهم؟';
	}
	return 'عصر بخیر — چه کاری انجام بدهم؟';
}

// ─────────────────────────────────────────────────── دکمه‌های بالا

$( '#btn-new' ).onclick = async () => {
	showView( 'chat' );
	await post( '/api/new', {} );
	clearThread();
	showWelcome();
	await refreshState();
	await refreshSessions();
	focusComposer();
};

$( '#btn-settings' ).onclick = () => showView( 'settings' );

// دکمهٔ «‹» بالای پنل: از هر صفحه‌ای به گفتگو برمی‌گردد — مثل تصویر.
$( '#btn-back' ).onclick = () => showView( 'chat' );

// منوی «⋯» — جایی که در Claude هم کارهای همین گفتگو می‌نشیند.
$( '#btn-more' ).onclick = ( e ) => {
	e.stopPropagation();
	const box = $( '#more-menu' );
	if ( ! box.hidden ) {
		box.hidden = true;
		return;
	}
	const item = ( ico, label, onClick ) =>
		h( 'div', { class: 'menu-item', onClick: () => {
			box.hidden = true;
			onClick();
		} }, [ h( 'span', { class: 'm-ico', text: ico } ), h( 'b', { text: label } ) ] );

	box.replaceChildren(
		item( '✎', 'تغییر نام گفتگو', () => $( '#session-title' ).click() ),
		item( '⤓', 'خروجی مارک‌داون', () => doExport( 'md' ) ),
		item( '⤓', 'خروجی JSON', () => doExport( 'json' ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( '↶', 'بازگشت به چک‌پوینت', () => openRewind( doRewind ) ),
		item( '⌫', 'پاک‌کردن گفتگو', () => post( '/api/message', { text: '/clear' } ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( '?', 'میان‌برها', () => openShortcuts() )
	);
	box.hidden = false;
};

document.addEventListener( 'click', ( e ) => {
	const box = $( '#more-menu' );
	if ( box && ! box.hidden && ! box.contains( e.target ) ) {
		box.hidden = true;
	}
} );

document.addEventListener( 'hoosha:rail', ( e ) => {
	document.body.classList.add( 'rail-open' );
	localStorage.setItem( 'hoosha-rail', '1' );
	document.querySelector( `.rail-tab[data-tab="${ e.detail }"]` )?.click();
} );
$( '#btn-account' ).onclick = () => openSettings( 'provider' );
$( '#btn-search' ).onclick = () => openPalette( paletteDeps() );
$( '#btn-menu' ).onclick = () => document.body.classList.toggle( 'sidebar-open' );

$( '#btn-rail' ).onclick = () => {
	document.body.classList.toggle( 'rail-open' );
	localStorage.setItem( 'hoosha-rail', document.body.classList.contains( 'rail-open' ) ? '1' : '' );
};

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

document.addEventListener( 'hoosha:settings', ( e ) => openSettings( e.detail ) );
document.addEventListener( 'hoosha:view', ( e ) => showView( e.detail ) );
document.addEventListener( 'hoosha:rewind', () => openRewind( doRewind ) );

// ───────────────────────────────────────────────── میان‌برهای صفحه

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
		showView( 'settings' );
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

	if ( ( ! typing || composerIsEmpty() ) && e.key === '?' ) {
		e.preventDefault();
		openShortcuts();
	}
} );

// ───────────────────────────────────────────────────────────── شروع

async function boot() {
	const s = await refreshState();
	await refreshSessions();

	if ( localStorage.getItem( 'hoosha-rail' ) ) {
		document.body.classList.add( 'rail-open' );
	}

	if ( s.transcript?.length ) {
		renderTranscript( s.transcript );
	} else {
		showWelcome();
		const g = $( '#greet-text' );
		if ( g ) {
			g.textContent = greeting();
		}
		const gm = $( '#greet-mark' );
		if ( gm && ! gm.innerHTML.trim() ) {
			gm.innerHTML = logoSvg( 34 );
		}
	}
	for ( const ask of s.pendingAsk || [] ) {
		handleEvent( ask );
	}

	connectEvents();
	focusComposer();
}

boot();
