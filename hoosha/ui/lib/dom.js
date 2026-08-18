/** ابزارهای کوچک DOM — بدون فریم‌ورک، چون نه build داریم نه می‌خواهیم داشته باشیم. */

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
