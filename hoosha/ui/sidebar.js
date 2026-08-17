/** نوار کناری: ناوبری مستقیم + فهرست گفتگوهای اخیر. */

import { $, h, timeAgo, toast, confirmDialog, promptDialog } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';

let onResume = () => {};
let onView = () => {};
let sessions = [];

/** @param {{onResume:(id:string)=>void, onView:(view:string)=>void}} opts */
export function initSidebar( opts ) {
	onResume = opts.onResume;
	onView = opts.onView;

	for ( const b of document.querySelectorAll( '.nav-item[data-view]' ) ) {
		b.onclick = () => onView( b.dataset.view );
	}

	$( '#btn-collapse' ).onclick = () => {
		document.body.classList.toggle( 'sidebar-collapsed' );
		localStorage.setItem( 'hoosha-sidebar', document.body.classList.contains( 'sidebar-collapsed' ) ? '1' : '' );
	};
	if ( localStorage.getItem( 'hoosha-sidebar' ) ) {
		document.body.classList.add( 'sidebar-collapsed' );
	}
}

/** @param {string} view */
export function markActiveView( view ) {
	for ( const b of document.querySelectorAll( '.nav-item[data-view]' ) ) {
		b.classList.toggle( 'active', b.dataset.view === view );
	}
}

export async function refreshSessions() {
	const out = await api( '/api/sessions' );
	sessions = out.sessions || [];
	paint();
	return sessions;
}

/**
 * گروه‌بندی مثل تصویر: نشست باز بالا، بعد امروز و این هفته و قدیمی‌تر.
 * @param {any} item
 * @param {any} state
 */
function groupOf( item, state ) {
	if ( state?.sessionId === item.id ) {
		return state?.busy ? 'در حال اجرا' : 'باز';
	}
	const day = 86_400_000;
	const diff = Date.now() - Number( item.updatedAt || 0 );
	if ( diff < day ) {
		return 'امروز';
	}
	if ( diff < 7 * day ) {
		return 'این هفته';
	}
	return 'قدیمی‌تر';
}

/** زیرنویس هر ردیف — جای همان `simonw/research` در تصویر. */
function subtitleOf( item, state ) {
	const ws = String( state?.config?.workspace || '' );
	const name = ws.split( /[\\/]/ ).filter( Boolean ).slice( -2 ).join( '/' );
	return `${ name || 'بدون پروژه' } · ${ item.messages } پیام`;
}

function paint() {
	const box = $( '#session-list' );
	if ( ! box ) {
		return;
	}
	box.replaceChildren();

	const s = getState();
	if ( ! sessions.length ) {
		box.appendChild( h( 'div', { class: 'empty small', text: 'هنوز گفتگویی نداری.' } ) );
		return;
	}

	let lastGroup = '';
	for ( const item of sessions.slice( 0, 60 ) ) {
		const active = s?.sessionId === item.id;
		const group = groupOf( item, s );
		if ( group !== lastGroup ) {
			box.appendChild( h( 'div', { class: 'list-group', text: group } ) );
			lastGroup = group;
		}

		box.appendChild(
			h( 'div', { class: `list-item ${ active ? 'active' : '' }` }, [
				h( 'button', {
					class: 'list-main',
					title: `${ item.title }\n${ timeAgo( item.updatedAt ) }`,
					onClick: () => onResume( item.id ),
				}, [
					h( 'span', { class: 'list-title', text: item.title || 'بدون عنوان' } ),
					h( 'span', { class: 'list-sub', text: subtitleOf( item, s ) } ),
				] ),
				h( 'div', { class: 'list-actions' }, [
					h( 'button', {
						class: 'round-ghost',
						title: 'تغییر نام',
						text: '✎',
						onClick: async ( e ) => {
							e.stopPropagation();
							const title = await promptDialog( 'نام تازهٔ گفتگو:', item.title || '' );
							if ( title === null ) {
								return;
							}
							await post( '/api/sessions', { action: 'rename', id: item.id, title } );
							await refreshSessions();
						},
					} ),
					h( 'button', {
						class: 'round-ghost',
						title: 'حذف',
						text: '×',
						onClick: async ( e ) => {
							e.stopPropagation();
							if ( ! ( await confirmDialog( 'این گفتگو حذف شود؟', { danger: true } ) ) ) {
								return;
							}
							const out = await post( '/api/sessions', { action: 'delete', id: item.id } );
							if ( out.error ) {
								toast( out.error, 'error' );
							}
							await refreshSessions();
						},
					} ),
				] ),
			] )
		);
	}
}

/**
 * «نشان‌شده» در نوار کناری — جای همان فهرست Starred در تصویر، ولی با چیزهایی که در یک
 * ابزار عامل واقعاً هر روز لازم می‌شوند.
 */
function paintPinned( s ) {
	const box = $( '#pinned-list' );
	if ( ! box ) {
		return;
	}
	const open = ( tab ) => () => document.dispatchEvent( new CustomEvent( 'hoosha:rail', { detail: tab } ) );
	const items = [
		[ '⌗', 'فهرست کار', open( 'todos' ), ( s.todos || [] ).filter( ( t ) => t.status !== 'completed' ).length ],
		[ '❯', 'شل‌های پس‌زمینه', open( 'shells' ), ( s.shells || [] ).filter( ( x ) => x.status === 'running' ).length ],
		[ '↶', 'چک‌پوینت‌ها', open( 'checkpoints' ), ( s.checkpoints || [] ).length ],
		[ '±', 'تغییرات گیت', open( 'git' ), 0 ],
	];

	box.replaceChildren();
	for ( const [ ico, label, onClick, count ] of items ) {
		box.appendChild(
			h( 'button', { class: 'nav-item pinned', onClick }, [
				h( 'span', { class: 'si-ico', text: ico } ),
				h( 'span', { text: label } ),
				count ? h( 'b', { class: 'pin-count', text: String( count ) } ) : null,
			] )
		);
	}
}

/** @param {any} s */
export function paintSidebarState( s ) {
	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#account-name' ).textContent = p.label || s.config.activeProfile || 'پروفایل';
	$( '#chip-provider' ).textContent = `${ p.provider || '—' }${ p.model ? ` · ${ p.model }` : '' }`;
	$( '#account-initial' ).textContent = ( p.label || p.provider || 'ه' ).slice( 0, 1 ).toUpperCase();
	paintPinned( s );
	paint();
}
