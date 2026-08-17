/** نوار کناری: نشست‌ها، جستجو، تغییر نام، حذف، و اطلاعات پوشهٔ کاری. */

import { $, h, timeAgo, toast, confirmDialog, promptDialog } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';

let onResume = () => {};
let sessions = [];
let filter = '';

/** @param {{onResume:(id:string)=>void}} opts */
export function initSidebar( opts ) {
	onResume = opts.onResume;

	$( '#session-search' ).addEventListener( 'input', ( e ) => {
		filter = e.target.value.trim().toLowerCase();
		paint();
	} );

	$( '#btn-collapse' ).onclick = () => {
		document.body.classList.toggle( 'sidebar-collapsed' );
		localStorage.setItem( 'hoosha-sidebar', document.body.classList.contains( 'sidebar-collapsed' ) ? '1' : '' );
	};
	if ( localStorage.getItem( 'hoosha-sidebar' ) ) {
		document.body.classList.add( 'sidebar-collapsed' );
	}
}

export async function refreshSessions() {
	const out = await api( '/api/sessions' );
	sessions = out.sessions || [];
	paint();
	return sessions;
}

export function getSessions() {
	return sessions;
}

function group( ts ) {
	const day = 86_400_000;
	const diff = Date.now() - Number( ts || 0 );
	if ( diff < day ) {
		return 'امروز';
	}
	if ( diff < 2 * day ) {
		return 'دیروز';
	}
	if ( diff < 7 * day ) {
		return 'این هفته';
	}
	if ( diff < 30 * day ) {
		return 'این ماه';
	}
	return 'قدیمی‌تر';
}

function paint() {
	const box = $( '#session-list' );
	box.replaceChildren();

	const s = getState();
	const list = sessions.filter( ( x ) => ! filter || String( x.title || '' ).toLowerCase().includes( filter ) );

	if ( ! list.length ) {
		box.appendChild( h( 'div', { class: 'empty small', text: filter ? 'چیزی پیدا نشد.' : 'هنوز گفتگویی نداری.' } ) );
		return;
	}

	let last = '';
	for ( const item of list ) {
		const g = group( item.updatedAt );
		if ( g !== last ) {
			box.appendChild( h( 'div', { class: 'side-group', text: g } ) );
			last = g;
		}

		const active = s?.sessionId === item.id;
		const row = h( 'div', { class: `session ${ active ? 'active' : '' }` }, [
			h( 'button', {
				class: 'session-main',
				title: item.title,
				onClick: () => onResume( item.id ),
			}, [
				h( 'span', { class: 'session-title', text: item.title || 'بدون عنوان' } ),
				h( 'span', { class: 'session-meta', text: `${ timeAgo( item.updatedAt ) } · ${ item.messages } پیام` } ),
			] ),
			h( 'div', { class: 'session-actions' }, [
				h( 'button', {
					class: 'icon-btn tiny',
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
					class: 'icon-btn tiny danger',
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
		] );
		box.appendChild( row );
	}
}

/** تکه‌های پایین نوار کناری که به وضعیت وابسته‌اند. */
export function paintSidebarState( s ) {
	const ws = String( s.config.workspace || '' );
	const name = ws.split( /[\\/]/ ).filter( Boolean ).pop() || ws;
	$( '#chip-workspace' ).textContent = name;
	$( '#chip-workspace' ).title = ws;

	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#chip-provider' ).textContent = `${ p.provider || '—' } · ${ p.model || 'بدون مدل' }`;

	paint();
}
