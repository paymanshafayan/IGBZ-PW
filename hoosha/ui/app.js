/**
 * نقطهٔ شروع رابط کاربری هوشا.
 *
 * چیدمان و رفتار، کپی از رابط Claude است: یک نوار کناری و یک ناحیهٔ محتوا؛ صفحه‌ها
 * (گفتگوها، پروژه‌ها، ابزارها، تغییرات، فضای کار) سربرگ سریفِ بزرگ دارند با دکمه‌های
 * عمل در انتهای همان سطر؛ و تنظیمات یک **مودال بزرگ** است، نه یک صفحه.
 *
 * تنها چیزی که در Claude نیست و عمداً ماند، نوار گیت زیر کامپوزر است — خواستهٔ صریح
 * کارفرما.
 */

import { $, h, toast, promptDialog, fmtTokens, timeAgo } from './lib/dom.js';
import { post, refreshState, subscribe, getState, api } from './lib/api.js';
import { paintStaleBar } from './lib/stale.js';
import { t, lang, setLang, initLang, translateDom, LANGS } from './lib/i18n.js';
import {
	mountThread,
	handleEvent,
	renderTranscript,
	clearThread,
	addError,
} from './thread.js';
import { initComposer, setBusy, setMode, fillComposer, composerIsEmpty, focusComposer, toggleDictation } from './composer.js';
import { initSidebar, refreshSessions, paintSidebarState, markActiveView, allSessions, groupOf } from './sidebar.js';
import { openSettingsModal, renderSection, initSettings, SETTINGS_TABS } from './settings.js';
import { openFile, openRewind, openPalette, openShortcuts } from './dialogs.js';
import { logoSvg } from './lib/logo.js';
import { initGitBar, paintGitBar, renderChanges } from './gitbar.js';

// زبان اول از همه: جهت صفحه و فونت به آن بسته‌اند و اگر بعد از رندر عوض شود، پرش دارد.
initLang();
translateDom();

// تم: تا وقتی کاربر خودش انتخاب نکرده، از تنظیم سیستم پیروی می‌کنیم — مثل Claude.
const savedTheme = localStorage.getItem( 'hoosha-theme' );
const systemDark = window.matchMedia?.( '(prefers-color-scheme: dark)' );
document.documentElement.dataset.theme = savedTheme || ( systemDark?.matches ? 'dark' : 'light' );
systemDark?.addEventListener?.( 'change', ( e ) => {
	if ( ! localStorage.getItem( 'hoosha-theme' ) ) {
		document.documentElement.dataset.theme = e.matches ? 'dark' : 'light';
	}
} );

/*
 * حالتِ کهنهٔ رابط را یک بار دور می‌ریزیم.
 *
 * چیدمان از سه ستون به دو ستون رفت و ریل حذف شد. کلید `hoosha-rail` در localStorage
 * می‌توانست کلاسی را نگه دارد که دیگر هیچ استایلی ندارد.
 */
const UI_STATE_VERSION = '4';
if ( localStorage.getItem( 'hoosha-ui-version' ) !== UI_STATE_VERSION ) {
	for ( const key of [ 'hoosha-sidebar', 'hoosha-rail', 'hoosha-density', 'hoosha-fontsize' ] ) {
		localStorage.removeItem( key );
	}
	document.body.classList.remove( 'rail-open', 'sidebar-collapsed' );
	localStorage.setItem( 'hoosha-ui-version', UI_STATE_VERSION );
}

// ───────────────────────────────────────────────────────── صفحه‌ها

/**
 * هر صفحه یک عنوان دارد و چند دکمهٔ عمل — همان الگوی «Chats / Projects / Artifacts».
 * @type {Record<string, {title:string, actions?:(host:HTMLElement)=>Node[], render:(host:HTMLElement)=>any}>}
 */
const PAGES = {
	chats: { title: 'گفتگوها', render: renderChatsPage, actions: chatsActions },
	projects: { title: 'پروژه‌ها', render: renderProjectsPage, actions: projectsActions },
	tools: { title: 'ابزارها', render: ( host ) => renderSection( 'tools', host ) },
	changes: { title: 'تغییرات', render: ( host ) => renderChanges( host ) },
	workspace: { title: 'فضای کار', render: renderWorkspacePage },
};

/** میان‌بر: زبان را عوض کن و صفحه را با همان نما دوباره بکش. */
async function switchLang( code ) {
	setLang( code );
	translateDom();
	await refreshState();
	await refreshSessions();
	if ( view !== 'chat' ) {
		await showView( view );
	}
	const g = $( '#greet-text' );
	if ( g ) {
		g.textContent = t( greeting() );
	}
}

let view = 'chat';

async function showView( next ) {
	// «سفارشی‌سازی» صفحه نیست؛ مثل Claude مودال تنظیمات را باز می‌کند.
	if ( next === 'customize' || next === 'settings' ) {
		openSettings( next === 'settings' ? undefined : 'skills' );
		return;
	}

	/*
	 * هر مقصدی که صفحه نیست ولی تبِ تنظیمات هست، همان تب را باز می‌کند.
	 *
	 * این یک باگ واقعی را بست: منوی «+» برای اسکیل‌ها و کانکتورها و زیرعامل‌ها
	 * `showView('skills')` صدا می‌زد، و چون «skills» صفحه نبود بی‌سروصدا به گفتگو
	 * برمی‌گشت — کلیک می‌کردی و هیچ اتفاقی نمی‌افتاد.
	 */
	if ( ! PAGES[ next ] && SETTINGS_TABS.some( ( t ) => t.id === next ) ) {
		openSettings( next );
		return;
	}

	view = PAGES[ next ] ? next : 'chat';
	markActiveView( view );

	const chat = $( '#view-chat' );
	const panel = $( '#view-panel' );
	$( '#btn-back' ).hidden = view === 'chat';

	if ( view === 'chat' ) {
		chat.hidden = false;
		panel.hidden = true;
		focusComposer();
		return;
	}

	chat.hidden = true;
	panel.hidden = false;

	const meta = PAGES[ view ];
	const box = $( '#panel-body' );
	const host = h( 'div', {} );

	box.replaceChildren(
		h( 'div', { class: 'page-head' }, [
			h( 'h1', { class: 'page-title', text: t( meta.title ) } ),
			h( 'div', { class: 'page-actions' }, meta.actions ? meta.actions( host ) : [] ),
		] ),
		host
	);
	box.scrollTop = 0;
	await meta.render( host );
}

/** میان‌بر: مودال تنظیمات روی یک تب مشخص. */
function openSettings( tab ) {
	openSettingsModal( tab );
}

// ───────────────────────────────────────────────── صفحهٔ گفتگوها

let chatFilter = '';

function chatsActions( host ) {
	const search = h( 'input', {
		class: 'field',
		placeholder: t( 'جستجو در گفتگوها…' ),
		value: chatFilter,
		style: 'width:220px;margin:0;',
		onInput: ( e ) => {
			chatFilter = e.target.value;
			renderChatsPage( host );
		},
	} );
	return [
		search,
		h( 'button', {
			class: 'pill primary',
			text: t( 'گفتگوی تازه' ),
			onClick: () => $( '#btn-new' ).click(),
		} ),
	];
}

async function renderChatsPage( host ) {
	const list = allSessions();
	const s = getState();
	const q = chatFilter.trim().toLowerCase();
	const rows = list.filter( ( x ) => ! q || String( x.title || '' ).toLowerCase().includes( q ) );

	host.replaceChildren();
	if ( ! rows.length ) {
		host.appendChild( emptyState( t( 'هنوز گفتگویی نیست' ), t( 'از «گفتگوی تازه» شروع کن؛ هر گفتگو خودش ذخیره می‌شود.' ) ) );
		return;
	}

	const box = h( 'div', { class: 'row-list' } );
	let group = '';
	for ( const item of rows ) {
		const g = groupOf( item.updatedAt );
		if ( g !== group ) {
			box.appendChild( h( 'div', { class: 'side-label', text: t( g ) } ) );
			group = g;
		}
		box.appendChild(
			h( 'div', { class: 'row-item', onClick: () => resumeSession( item.id ) }, [
				h( 'div', { class: 'row-main' }, [
					h( 'span', { class: 'row-title', text: item.title || t( 'بدون عنوان' ) } ),
				] ),
				h( 'div', { class: 'row-meta' }, [
					s?.sessionId === item.id ? h( 'span', { class: 'tag ok', text: t( 'باز' ) } ) : null,
					h( 'span', { text: `${ item.messages } ${ t( 'پیام' ) }` } ),
					h( 'span', { text: timeAgo( item.updatedAt ) } ),
				] ),
				h( 'button', {
					class: 'row-menu',
					text: '⋯',
					title: 'تغییر نام یا حذف',
					onClick: async ( e ) => {
						e.stopPropagation();
						const title = await promptDialog( 'نام تازه (خالی = حذف):', item.title || '' );
						if ( title === null ) {
							return;
						}
						await post( '/api/sessions', title.trim() ? { action: 'rename', id: item.id, title } : { action: 'delete', id: item.id } );
						await refreshSessions();
						renderChatsPage( host );
					},
				} ),
			] )
		);
	}
	host.appendChild( box );
}

// ───────────────────────────────────────────────── صفحهٔ پروژه‌ها

/** پروژه‌های اخیر را در همین مرورگر نگه می‌داریم؛ سرور فقط یکی را می‌شناسد. */
function recentProjects() {
	try {
		return JSON.parse( localStorage.getItem( 'hoosha-projects' ) || '[]' );
	} catch {
		return [];
	}
}

/** @param {string} dir */
function rememberProject( dir ) {
	const list = [ dir, ...recentProjects().filter( ( p ) => p !== dir ) ].slice( 0, 12 );
	localStorage.setItem( 'hoosha-projects', JSON.stringify( list ) );
}

async function switchProject( dir ) {
	const out = await post( '/api/workspace', { path: dir } );
	if ( out.error ) {
		toast( out.error, 'error' );
		return;
	}
	rememberProject( dir );
	await refreshState();
	toast( `پروژه شد: ${ dir }` );
}

function projectsActions( host ) {
	return [
		h( 'button', {
			class: 'pill primary',
			text: t( 'پروژهٔ تازه' ),
			onClick: async () => {
				const next = await promptDialog( 'مسیر پروژه:', getState()?.config?.workspace || '' );
				if ( next ) {
					await switchProject( next );
					renderProjectsPage( host );
				}
			},
		} ),
	];
}

async function renderProjectsPage( host ) {
	const current = getState()?.config?.workspace || '';
	rememberProject( current );
	const list = recentProjects();

	host.replaceChildren();
	const grid = h( 'div', { class: 'card-grid' } );
	for ( const p of list ) {
		grid.appendChild(
			h( 'div', { class: 'grid-card', onClick: () => switchProject( p ).then( () => renderProjectsPage( host ) ) }, [
				h( 'b', { text: p.split( /[\\/]/ ).filter( Boolean ).pop() || p } ),
				h( 'p', { class: 'mono', text: p } ),
				h( 'span', { class: 'gc-date', text: t( p === current ? 'پروژهٔ فعلی' : 'باز کن' ) } ),
			] )
		);
	}
	host.appendChild( grid );
}

// ───────────────────────────────────────────────── صفحهٔ فضای کار

const WORKSPACE_TABS = [
	{ id: 'memory', label: 'حافظهٔ پروژه' },
	{ id: 'permissions', label: 'مجوزها' },
	{ id: 'sandbox', label: 'سندباکس' },
	{ id: 'todos', label: 'فهرست کار' },
	{ id: 'shells', label: 'شل‌های پس‌زمینه' },
	{ id: 'checkpoints', label: 'چک‌پوینت‌ها' },
	{ id: 'usage', label: 'مصرف و هزینه' },
	{ id: 'status', label: 'وضعیت' },
];
let workspaceTab = 'memory';

async function renderWorkspacePage( host ) {
	const s = getState();

	host.appendChild(
		h( 'div', { class: 'set-row' }, [
			h( 'div', { class: 'set-row-label' }, [
				h( 'b', { text: 'پوشهٔ کاری' } ),
				h( 'p', { class: 'set-row-desc mono', text: s.config.workspace } ),
			] ),
			h( 'div', { class: 'set-row-control' }, [
				h( 'button', {
					class: 'pill',
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

/** @param {string} title @param {string} text */
function emptyState( title, text ) {
	return h( 'div', { class: 'empty-state' }, [
		h( 'span', { html: logoSvg( 44 ) } ),
		h( 'h2', { text: title } ),
		h( 'p', { text } ),
	] );
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

initSidebar( {
	onResume: resumeSession,
	onView: showView,
	onCommand: ( name ) => {
		if ( name === 'settings' ) {
			openSettings();
		} else if ( name === 'theme' ) {
			const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
			document.documentElement.dataset.theme = next;
			localStorage.setItem( 'hoosha-theme', next );
		} else if ( name === 'shortcuts' ) {
			openShortcuts();
		} else if ( name === 'usage' ) {
			openSettings( 'usage' );
		} else if ( name === 'status' ) {
			openSettings( 'status' );
		} else if ( name === 'reload' ) {
			location.reload();
		} else if ( name === 'lang' ) {
			switchLang( lang() === 'fa' ? 'en' : 'fa' );
		}
	},
} );
initGitBar( { onView: showView } );
initSettings();

// ───────────────────────────────────────────────────────── وضعیت

subscribe( ( s ) => {
	/*
	 * نسخه و **مهر ساخت**.
	 *
	 * شکایت کارفرما: «باز هم نسخهٔ قبلی را نشان می‌دهد». عدد نسخه فقط وقتی عوض می‌شود
	 * که یادم بماند بالا ببرمش؛ شناسهٔ کامیت خودکار با هر تغییر عوض می‌شود. پس عدد را
	 * نشان می‌دهیم و ساخت را در تیتر همان، تا با نگه‌داشتن ماوس معلوم شود کدام بیلد
	 * است — بدون اینکه گوشهٔ صفحه شلوغ شود.
	 */
	$( '#brand-version' ).textContent = `v${ s.version }`;
	$( '#brand-version' ).title = s.install?.buildLine ? `ساخت: ${ s.install.buildLine }` : '';
	document.title = `هوشا ${ s.version }`;
	paintStaleBar( $( '#stale-bar' ), s );

	paintSidebarState( s );
	paintGitBar( s );
	setMode( s.config.permissions?.mode || 'default' );

	/*
	 * نوار بالا دو حالت دارد، مثل تصویرها:
	 *   گفتگوی خالی → نام پروژه، وسط‌چین (جای «Free plan · Upgrade»)
	 *   گفتگوی واقعی → نام گفتگو با فلش در ابتدا، و «اشتراک» در انتها
	 */
	const ws = String( s.config.workspace || '' );
	const inChat = ( s.transcript || [] ).length > 0 || ! $( '#welcome' );
	$( '#plan-text' ).textContent = ws.split( /[\\/]/ ).filter( Boolean ).pop() || t( 'بدون پروژه' );
	$( '#plan-chip' ).title = ws;
	$( '#plan-chip' ).hidden = inChat && view === 'chat';
	$( '#session-title' ).hidden = ! ( inChat && view === 'chat' );
	$( '#btn-share' ).hidden = ! ( inChat && view === 'chat' );
	$( '#session-title-text' ).textContent = s.sessionTitle || 'گفتگوی تازه';

	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#model-name' ).textContent = s.hub?.active ? 'خودکار' : p.model || 'مدل تنظیم نشده';
	$( '#pill-model' ).title = s.hub?.active ? 'هاب پرووایدر — مدل را خودش انتخاب می‌کند' : `${ p.provider || '' } · ${ p.model || '' }`;

	const used = s.context?.used || 0;
	const win = s.context?.window || 200_000;
	const pct = Math.min( 100, Math.round( ( used / win ) * 100 ) );
	$( '#meter-text' ).textContent = `${ pct }٪`;
	$( '#context-meter' ).title = `کانتکست: ${ fmtTokens( used ) } از ${ fmtTokens( win ) }${
		s.usage?.cost ? ` · $${ Number( s.usage.cost ).toFixed( 3 ) }` : ''
	}`;
	$( '#context-meter' ).classList.toggle( 'high', pct > 75 );

	setBusy( Boolean( s.busy ) );
	syncEmptyState();

	if ( ! s.ready?.ok ) {
		showSetupBanner( s );
	} else {
		$( '#setup-banner' )?.remove();
	}
} );

/**
 * وقتی گفتگو خالی است، تیتر و کامپوزر با هم وسط صفحه می‌نشینند — مثل صفحهٔ گفتگوی
 * تازهٔ Claude. به‌محض آمدن اولین پیام، کامپوزر به کف برمی‌گردد.
 */
export function syncEmptyState() {
	const empty = Boolean( $( '#welcome' ) );
	$( '#view-chat' ).classList.toggle( 'empty', empty );
}

function showSetupBanner( s ) {
	if ( $( '#setup-banner' ) ) {
		return;
	}
	const banner = h( 'div', { class: 'banner danger', id: 'setup-banner' }, [
		h( 'b', { text: 'هنوز آمادهٔ کار نیست' } ),
		h( 'span', { text: `کم است: ${ ( s.ready?.missing || [] ).join( '، ' ) }` } ),
		h( 'button', { class: 'pill primary', text: 'تنظیم پرووایدر', onClick: () => openSettings( 'hub' ) } ),
	] );
	$( '.main-card' ).insertBefore( banner, $( '#view-chat' ) );
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

			case 'git':
			case 'profile':
			case 'workspace':
			case 'checkpoint':
			case 'shell_start':
			case 'shell_exit':
			case 'usage':
			case 'hub':
				refreshState();
				return;

			case 'open_panel':
				openSettings( ev.tab );
				return;

			case 'open_view':
				showView( ev.view );
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
			case 'hub-route':
			case 'hub-repair':
			case 'hub-diagnose':
			case 'hub-failover':
			case 'hub-cache-hit':
			case 'hub-budget-warn':
				return;

			default:
				if ( view !== 'chat' ) {
					showView( 'chat' );
				}
				handleEvent( ev );
				syncEmptyState();
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
				h( 'span', { text: t( greeting() ) } ),
			] ),
		] )
	);
	syncEmptyState();
}

/** سلامِ وابسته به ساعت — همان «Evening, how are things?» در تصویر. */
export function greeting( hour = new Date().getHours() ) {
	if ( hour < 5 ) {
		return 'شب‌بخیر، چه خبر؟';
	}
	if ( hour < 12 ) {
		return 'صبح‌بخیر، چه خبر؟';
	}
	if ( hour < 17 ) {
		return 'ظهر بخیر، چه خبر؟';
	}
	return 'عصر بخیر، چه خبر؟';
}

// ─────────────────────────────────────────────────── دکمه‌ها و منوها

$( '#btn-new' ).onclick = async () => {
	showView( 'chat' );
	await post( '/api/new', {} );
	clearThread();
	showWelcome();
	await refreshState();
	await refreshSessions();
	focusComposer();
};

$( '#btn-back' ).onclick = () => showView( 'chat' );
$( '#btn-search' ).onclick = () => openPalette( paletteDeps() );
$( '#btn-export' ).onclick = () => doExport( 'md' );
$( '#session-title' ).onclick = renameSession;
$( '#btn-share' ).onclick = () => doExport( 'md' );

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
		item( '✎', 'تغییر نام گفتگو', renameSession ),
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

async function renameSession() {
	const s = getState();
	const title = await promptDialog( 'نام گفتگو:', s?.sessionTitle || '' );
	if ( title === null ) {
		return;
	}
	await post( '/api/sessions', { action: 'rename', id: s.sessionId, title } );
	await refreshState();
	await refreshSessions();
}

document.addEventListener( 'click', ( e ) => {
	const box = $( '#more-menu' );
	if ( box && ! box.hidden && ! box.contains( e.target ) ) {
		box.hidden = true;
	}
} );

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
	if ( ( e.ctrlKey || e.metaKey ) && e.key.toLowerCase() === 'm' ) {
		e.preventDefault();
		toggleDictation();
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

	if ( ( ! typing || composerIsEmpty() ) && e.key === '?' ) {
		e.preventDefault();
		openShortcuts();
	}
} );

// ───────────────────────────────────────────────────────────── شروع

async function boot() {
	const s = await refreshState();
	await refreshSessions();

	if ( s.transcript?.length ) {
		renderTranscript( s.transcript );
	} else {
		showWelcome();
		const g = $( '#greet-text' );
		if ( g ) {
			g.textContent = t( greeting() );
		}
		const gm = $( '#greet-mark' );
		if ( gm && ! gm.innerHTML.trim() ) {
			gm.innerHTML = logoSvg( 34 );
		}
	}
	syncEmptyState();
	for ( const ask of s.pendingAsk || [] ) {
		handleEvent( ask );
	}

	markActiveView( 'chat' );
	connectEvents();
	focusComposer();
}

boot();

export { api };
