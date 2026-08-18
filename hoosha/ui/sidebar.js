/**
 * نوار کناری — همان شکلی که در تصاویر Claude هست.
 *
 * ترتیب از بالا: واژه‌نشان، «گفتگوی تازه»، ناوبری، یک گروه امکانات، «اخیر» با فهرست
 * گفتگوها، و ته نوار ردیف حساب که منویش از همان‌جا بالا می‌آید.
 *
 * دو چیز عمداً عوض شد نسبت به نسخهٔ قبل:
 *   ۱) گفتگوهای اخیر از ستون میانی به همین‌جا آمدند — Claude ستون میانی ندارد.
 *   ۲) ردیف‌های اخیر فقط **عنوان**اند؛ نه کارت، نه قاب، نه زیرنویس.
 */

import { $, h, timeAgo, toast, confirmDialog, promptDialog } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';
import { logoSvg } from './lib/logo.js';
import { t, lang, LANGS } from './lib/i18n.js';

let onResume = () => {};
let onView = () => {};
let onCommand = () => {};
let sessions = [];

/** @param {{onResume:(id:string)=>void, onView:(view:string)=>void, onCommand:(name:string)=>void}} opts */
export function initSidebar( opts ) {
	onResume = opts.onResume;
	onView = opts.onView;
	onCommand = opts.onCommand || ( () => {} );

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

	$( '#btn-recents-more' ).onclick = () => onView( 'chats' );
	$( '#btn-account' ).onclick = ( e ) => {
		e.stopPropagation();
		toggleAccountMenu();
	};

	document.addEventListener( 'click', ( e ) => {
		const menu = $( '#account-menu' );
		if ( ! menu.hidden && ! menu.contains( e.target ) ) {
			menu.hidden = true;
		}
	} );
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

export function allSessions() {
	return sessions;
}

/** گروه‌بندی زمانی، همان‌طور که Claude گفتگوها را دسته می‌کند. */
export function groupOf( updatedAt, now = Date.now() ) {
	const day = 86_400_000;
	const diff = now - Number( updatedAt || 0 );
	if ( diff < day ) {
		return 'امروز';
	}
	if ( diff < 7 * day ) {
		return 'هفت روز گذشته';
	}
	if ( diff < 30 * day ) {
		return 'سی روز گذشته';
	}
	return 'قدیمی‌تر';
}

function paint() {
	const box = $( '#session-list' );
	if ( ! box ) {
		return;
	}
	box.replaceChildren();

	const s = getState();
	if ( ! sessions.length ) {
		box.appendChild( h( 'div', { class: 'empty small', text: t( 'هنوز گفتگویی نیست' ) } ) );
		return;
	}

	// در نوار کناری فقط چند تای آخر؛ بقیه در صفحهٔ «گفتگوها».
	for ( const item of sessions.slice( 0, 14 ) ) {
		box.appendChild(
			h( 'div', { class: `recent-item ${ s?.sessionId === item.id ? 'active' : '' }`, title: `${ item.title }\n${ timeAgo( item.updatedAt ) }` }, [
				h( 'button', {
					class: 'btn quiet row rt',
					text: item.title || t( 'بدون عنوان' ),
					onClick: () => onResume( item.id ),
				} ),
				h( 'button', {
					class: 'btn icon round quiet reveal row-menu',
					title: 'گزینه‌ها',
					text: '⋯',
					onClick: async ( e ) => {
						e.stopPropagation();
						await rowMenu( item );
					},
				} ),
			] )
		);
	}
}

/** منوی سه‌نقطهٔ هر گفتگو. */
async function rowMenu( item ) {
	const title = await promptDialog( 'نام تازهٔ گفتگو (خالی = حذف گفتگو):', item.title || '' );
	if ( title === null ) {
		return;
	}
	if ( title.trim() === '' ) {
		if ( ! ( await confirmDialog( 'این گفتگو حذف شود؟', { danger: true } ) ) ) {
			return;
		}
		const out = await post( '/api/sessions', { action: 'delete', id: item.id } );
		if ( out.error ) {
			toast( out.error, 'error' );
		}
	} else {
		await post( '/api/sessions', { action: 'rename', id: item.id, title } );
	}
	await refreshSessions();
}

/**
 * منوی حساب — نظیر همان منویی که در Claude از روی ردیف پایین باز می‌شود.
 * @returns {void}
 */
function toggleAccountMenu() {
	const menu = $( '#account-menu' );
	if ( ! menu.hidden ) {
		menu.hidden = true;
		return;
	}

	const s = getState() || {};
	const item = ( ico, label, end, onClick ) =>
		h( 'button', { class: 'btn quiet row menu-item', onClick: () => {
			menu.hidden = true;
			onClick();
		} }, [
			h( 'span', { class: 'm-ico', text: ico } ),
			h( 'span', { text: label } ),
			end ? h( 'span', { class: 'm-end', text: end } ) : null,
		] );

	// نام زبانِ **دیگر** را نشان می‌دهیم، چون این ردیف یک کلید تعویض است نه یک برچسب.
	const other = LANGS.find( ( l ) => l.code !== lang() );

	menu.replaceChildren(
		h( 'div', { class: 'menu-mail', text: String( s.config?.workspace || '' ) } ),
		item( '⚙', t( 'تنظیمات' ), 'Ctrl+,', () => onCommand( 'settings' ) ),
		item( '◐', t( 'ظاهر' ), '', () => onCommand( 'theme' ) ),
		item( '⟐', t( 'زبان' ), other.label, () => onCommand( 'lang' ) ),
		item( '?', t( 'راهنما و میان‌برها' ), '?', () => onCommand( 'shortcuts' ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( '⌁', t( 'مصرف و هزینه' ), '', () => onCommand( 'usage' ) ),
		item( '✚', t( 'وضعیت و تشخیص' ), '', () => onCommand( 'status' ) ),
		h( 'div', { class: 'menu-sep' } ),
		item( '↺', t( 'بارگذاری دوباره' ), '', () => onCommand( 'reload' ) )
	);
	menu.hidden = false;
}

/** @param {any} s */
export function paintSidebarState( s ) {
	const p = s.config.profiles?.[ s.config.activeProfile ] || {};
	const hub = s.hub?.active;
	$( '#account-name' ).textContent = hub ? t( 'هاب پرووایدر' ) : p.label || s.config.activeProfile || t( 'پروفایل' );
	$( '#chip-provider' ).textContent = hub
		? t( 'مسیریابی خودکار' )
		: `${ p.provider || '—' }${ p.model ? ` · ${ p.model }` : '' }`;
	// آواتار، نشان خودِ هوشاست — همان‌جایی که در Claude دایرهٔ حساب می‌نشیند.
	const dot = $( '#account-initial' );
	if ( ! dot.innerHTML.includes( 'svg' ) ) {
		dot.innerHTML = logoSvg( 18, 'logo avatar-logo' );
	}

	const changed = ( s.git?.files || [] ).length;
	const badge = $( '#nav-changes-count' );
	badge.hidden = ! changed;
	badge.textContent = String( changed );

	paint();
}
