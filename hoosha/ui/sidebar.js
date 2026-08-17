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

	for ( const item of sessions.slice( 0, 40 ) ) {
		const active = s?.sessionId === item.id;
		box.appendChild(
			h( 'div', { class: `session ${ active ? 'active' : '' }` }, [
				h( 'button', {
					class: 'session-main',
					title: `${ item.title }\n${ timeAgo( item.updatedAt ) } · ${ item.messages } پیام`,
					text: item.title || 'بدون عنوان',
					onClick: () => onResume( item.id ),
				} ),
				h( 'div', { class: 'session-actions' }, [
					h( 'button', {
						class: 'ghost-icon',
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
						class: 'ghost-icon',
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

/** @param {any} s */
export function paintSidebarState( s ) {
	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	$( '#chip-provider' ).textContent = p.provider || '—';
	$( '#account-initial' ).textContent = ( p.provider || 'ه' ).slice( 0, 1 ).toUpperCase();
	paint();
}
