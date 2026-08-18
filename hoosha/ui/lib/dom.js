/** ابزارهای کوچک DOM — بدون فریم‌ورک، چون نه build داریم نه می‌خواهیم داشته باشیم. */
import { iconSvg } from './icons.js';

export const $ = ( s, root = document ) => root.querySelector( s );
export const $$ = ( s, root = document ) => [ ...root.querySelectorAll( s ) ];

/**
 * ساخت المان.
 * @param {string} tag
 * @param {string|null} [cls]
 * @param {string} [text]
 */
export function el( tag, cls, text ) {
	const n = document.createElement( tag );
	if ( cls ) {
		n.className = cls;
	}
	if ( text !== undefined ) {
		n.textContent = text;
	}
	return n;
}

/**
 * ساخت المان با ویژگی‌ها و فرزندان — برای فرم‌های تنظیمات که بدون این، خواندنشان سخت است.
 * @param {string} tag
 * @param {Record<string, any>} [attrs]
 * @param {(Node|string|null|undefined|false)[]} [children]
 */
export function h( tag, attrs = {}, children = [] ) {
	const n = document.createElement( tag );
	for ( const [ k, v ] of Object.entries( attrs || {} ) ) {
		if ( v === undefined || v === null || v === false ) {
			continue;
		}
		if ( k === 'class' ) {
			n.className = v;
		} else if ( k === 'text' ) {
			n.textContent = v;
		} else if ( k === 'html' ) {
			n.innerHTML = v;
		} else if ( k.startsWith( 'on' ) && typeof v === 'function' ) {
			n.addEventListener( k.slice( 2 ).toLowerCase(), v );
		} else if ( k === 'dataset' ) {
			Object.assign( n.dataset, v );
		} else if ( v === true ) {
			n.setAttribute( k, '' );
		} else {
			n.setAttribute( k, String( v ) );
		}
	}
	for ( const c of children.flat() ) {
		if ( c === null || c === undefined || c === false ) {
			continue;
		}
		n.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
	}
	return n;
}

export const esc = ( s ) =>
	String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ] ) );

/** @param {number} ts */
export function timeAgo( ts ) {
	const diff = Date.now() - Number( ts || 0 );
	const min = Math.round( diff / 60_000 );
	if ( min < 1 ) {
		return 'همین حالا';
	}
	if ( min < 60 ) {
		return `${ min } دقیقه پیش`;
	}
	const hr = Math.round( min / 60 );
	if ( hr < 24 ) {
		return `${ hr } ساعت پیش`;
	}
	const day = Math.round( hr / 24 );
	if ( day < 30 ) {
		return `${ day } روز پیش`;
	}
	return new Date( ts ).toLocaleDateString( 'fa-IR' );
}

/** @param {number} n */
export function fmtTokens( n ) {
	const v = Number( n ) || 0;
	if ( v < 1000 ) {
		return String( v );
	}
	if ( v < 1_000_000 ) {
		return `${ ( v / 1000 ).toFixed( 1 ) }k`;
	}
	return `${ ( v / 1_000_000 ).toFixed( 2 ) }M`;
}

/** یک toast کوچک، چون alert مرورگر وسط کار زشت است. */
export function toast( text, kind = '' ) {
	let host = $( '#toasts' );
	if ( ! host ) {
		host = el( 'div' );
		host.id = 'toasts';
		document.body.appendChild( host );
	}
	const t = el( 'div', `toast ${ kind }`, text );
	host.appendChild( t );
	setTimeout( () => t.classList.add( 'in' ), 10 );
	setTimeout( () => {
		t.classList.remove( 'in' );
		setTimeout( () => t.remove(), 300 );
	}, 3600 );
}

/**
 * دیالوگ تأیید — جایگزین confirm بومی، تا با تم برنامه یکی باشد.
 * @param {string} message
 * @param {{confirmText?:string, danger?:boolean}} [opts]
 * @returns {Promise<boolean>}
 */
export function confirmDialog( message, opts = {} ) {
	return new Promise( ( resolve ) => {
		const dlg = h( 'dialog', { class: 'modal small' }, [
			h( 'div', { class: 'modal-body' }, [
				h( 'p', { class: 'confirm-text', text: message } ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => done( false ) } ),
					h( 'button', {
						class: `btn ${ opts.danger ? 'outline danger' : 'solid' }`,
						text: opts.confirmText || 'تأیید',
						onClick: () => done( true ),
					} ),
				] ),
			] ),
		] );

		function done( value ) {
			dlg.close();
			dlg.remove();
			resolve( value );
		}

		document.body.appendChild( dlg );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			done( false );
		} );
		dlg.showModal();
	} );
}

/**
 * دیالوگ ورودی متنی.
 * @param {string} label
 * @param {string} [value]
 * @returns {Promise<string|null>}
 */
export function promptDialog( label, value = '' ) {
	return new Promise( ( resolve ) => {
		const input = h( 'input', { type: 'text', value, class: 'field' } );
		const dlg = h( 'dialog', { class: 'modal small' }, [
			h( 'div', { class: 'modal-body' }, [
				h( 'label', { class: 'field-label' }, [ label, input ] ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => done( null ) } ),
					h( 'button', { class: 'btn solid', text: 'ذخیره', onClick: () => done( input.value ) } ),
				] ),
			] ),
		] );

		function done( v ) {
			dlg.close();
			dlg.remove();
			resolve( v );
		}

		document.body.appendChild( dlg );
		dlg.addEventListener( 'cancel', ( e ) => {
			e.preventDefault();
			done( null );
		} );
		dlg.showModal();
		input.focus();
		input.select();
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				done( input.value );
			}
		} );
	} );
}

/** کپی در کلیپ‌بورد با پیام. */
export async function copyText( text ) {
	try {
		await navigator.clipboard.writeText( text );
		toast( 'کپی شد.' );
	} catch {
		toast( 'کپی نشد — دسترسی کلیپ‌بورد نداریم.', 'error' );
	}
}

/**
 * منوی راست‌کلیک — یک منوی شناور سرِ جای نشانگر.
 *
 * خواستهٔ کارفرما از روی رابط Claude: راست‌کلیک روی هر گفتگو باید منو باز کند. منو
 * `position: fixed` است چون باید از هر ظرفِ اسکرول‌داری بیرون بزند، و با کلیک بیرون،
 * Escape یا اسکرول بسته می‌شود.
 *
 * @param {{x:number, y:number, items:({label:string, ico?:string, hint?:string, danger?:boolean, onPick:()=>any}|'-')[]}} opts
 */
export function contextMenu( { x, y, items } ) {
	closeContextMenu();

	const menu = h( 'div', { class: 'pop-menu ctx-menu', id: 'ctx-menu' } );

	/**
	 * یک لایه از منو را می‌کشد.
	 *
	 * زیرمنو به‌جای بازشدن در کنار، **جای همین منو** می‌نشیند و یک ردیف «بازگشت» بالایش
	 * می‌آید. دلیلش ساده است: منوی کناری کنار لبهٔ صفحه جا نمی‌شود و با صفحه‌کلید هم
	 * دردسر دارد؛ این شکل، همان کار را بدون هیچ‌کدامِ آن‌ها انجام می‌دهد.
	 */
	const draw = ( list, back ) => {
		const rows = [];
		if ( back ) {
			rows.push(
				h( 'button', { class: 'btn quiet row menu-item', onClick: () => draw( back, null ) }, [
					h( 'span', { class: 'm-ico', html: iconSvg( 'chevron-right', 14 ) } ),
					h( 'span', { text: 'بازگشت' } ),
				] ),
				h( 'div', { class: 'menu-sep' } )
			);
		}
		for ( const item of list ) {
			if ( item === '-' ) {
				rows.push( h( 'div', { class: 'menu-sep' } ) );
				continue;
			}
			rows.push(
				h( 'button', {
					class: `btn quiet row menu-item ${ item.danger ? 'danger' : '' }`,
					onClick: () => {
						if ( item.submenu ) {
							draw( item.submenu(), list );
							return;
						}
						closeContextMenu();
						item.onPick();
					},
				}, [
					h( 'span', { class: 'm-ico', html: item.ico ? iconSvg( item.ico, 16 ) : '' } ),
					h( 'span', { text: item.label } ),
					item.submenu ? h( 'span', { class: 'm-end', text: '›' } ) : item.hint ? h( 'span', { class: 'm-end', text: item.hint } ) : null,
				] )
			);
		}
		menu.replaceChildren( ...rows );
	};

	draw( items, null );

	document.body.appendChild( menu );
	// نگذار از لبهٔ پایین/کنار صفحه بیرون بزند.
	const w = 240;
	const h1 = Math.min( items.length * 34 + 12, 420 );
	const vw = globalThis.innerWidth || 1280;
	const vh = globalThis.innerHeight || 800;
	menu.style.left = `${ Math.max( 8, Math.min( x, vw - w - 8 ) ) }px`;
	menu.style.top = `${ Math.max( 8, Math.min( y, vh - h1 - 8 ) ) }px`;

	/*
	 * بستن با کلیکِ بیرون — نه با هر کلیکی.
	 *
	 * نسخهٔ اول یک شنوندهٔ `{ once: true }` روی document می‌گذاشت؛ کلیک روی خودِ ردیف‌های
	 * منو هم به document می‌رسید و منو را همان لحظه می‌بست. نتیجه این بود که «افزودن به
	 * پروژه» در مرورگر کار نمی‌کرد: زیرمنو باز می‌شد و بلافاصله می‌رفت. (تست هم آن را
	 * نمی‌گرفت، چون کلیکِ هارنس اصلاً بالا نمی‌رفت — آن هم درست شد.)
	 */
	setTimeout( () => {
		document.addEventListener( 'click', onCtxClick );
		document.addEventListener( 'keydown', onCtxKey );
	}, 0 );
	return menu;
}

function onCtxKey( e ) {
	if ( e.key === 'Escape' ) {
		closeContextMenu();
	}
}

function onCtxClick( e ) {
	const menu = $( '#ctx-menu' );
	if ( menu && ! menu.contains( e.target ) ) {
		closeContextMenu();
	}
}

/** بستن منوی راست‌کلیک، اگر بازی هست. */
export function closeContextMenu() {
	document.removeEventListener( 'keydown', onCtxKey );
	document.removeEventListener( 'click', onCtxClick );
	$( '#ctx-menu' )?.remove();
}
