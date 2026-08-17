/**
 * یک DOM بسیار کوچک، فقط برای اینکه بشود کد رابط را **واقعاً اجرا کرد**.
 *
 * چرا لازم شد: تست ساختاری (grep روی فایل) ثابت می‌کند رشته‌ای در کد هست، ولی ثابت
 * نمی‌کند تابع بدون خطا اجرا می‌شود. در این پروژه چند بار پیش آمد که صفحه‌ای در مرورگر
 * سفید می‌ماند در حالی که همهٔ تست‌ها سبز بودند. اینجا صفحه‌ها را می‌سازیم، دکمه‌ها را
 * می‌زنیم، و اگر چیزی throw کند تست قرمز می‌شود.
 *
 * عمداً کامل نیست: هرچه لازم شد اضافه می‌شود، نه بیشتر. یک شبیه‌ساز کامل مرورگر،
 * خودش یک پروژهٔ دیگر است.
 */

class FakeClassList {
	constructor( node ) {
		this.node = node;
	}
	add( ...names ) {
		const set = new Set( String( this.node.className || '' ).split( /\s+/ ).filter( Boolean ) );
		names.forEach( ( n ) => set.add( n ) );
		this.node.className = [ ...set ].join( ' ' );
	}
	remove( ...names ) {
		const set = new Set( String( this.node.className || '' ).split( /\s+/ ).filter( Boolean ) );
		names.forEach( ( n ) => set.delete( n ) );
		this.node.className = [ ...set ].join( ' ' );
	}
	toggle( name, on ) {
		if ( on ) {
			this.add( name );
		} else {
			this.remove( name );
		}
	}
	contains( name ) {
		return String( this.node.className || '' ).split( /\s+/ ).includes( name );
	}
}

class FakeNode {
	/** @param {string} tag */
	constructor( tag ) {
		this.tagName = String( tag || 'div' ).toUpperCase();
		this.children = [];
		this.parentNode = null;
		this.className = '';
		this.attributes = {};
		this.dataset = {};
		this.style = {};
		this.listeners = {};
		this.value = '';
		this.checked = false;
		this.disabled = false;
		this.hidden = false;
		this.textValue = '';
		this.classList = new FakeClassList( this );
	}

	get textContent() {
		return this.textValue || this.children.map( ( c ) => c.textContent ).join( '' );
	}
	set textContent( value ) {
		this.textValue = String( value ?? '' );
		this.children = [];
	}
	set innerHTML( value ) {
		this.textValue = String( value ?? '' );
	}

	appendChild( child ) {
		child.parentNode = this;
		this.children.push( child );
		return child;
	}
	append( ...nodes ) {
		nodes.filter( Boolean ).forEach( ( n ) => this.appendChild( n ) );
	}
	replaceChildren( ...nodes ) {
		this.children = [];
		nodes.filter( Boolean ).forEach( ( n ) => this.appendChild( n ) );
	}
	remove() {
		if ( this.parentNode ) {
			this.parentNode.children = this.parentNode.children.filter( ( c ) => c !== this );
			this.parentNode = null;
		}
	}
	setAttribute( name, value ) {
		this.attributes[ name ] = String( value );
		if ( name === 'id' ) {
			this.id = String( value );
		}
	}
	getAttribute( name ) {
		return this.attributes[ name ] ?? null;
	}
	addEventListener( type, fn ) {
		( this.listeners[ type ] = this.listeners[ type ] || [] ).push( fn );
	}
	removeEventListener() {}
	scrollIntoView() {}
	showModal() {}
	close() {}
	focus() {}

	/** همهٔ گره‌های زیر این گره. */
	all() {
		return this.children.flatMap( ( c ) => [ c, ...c.all() ] );
	}

	querySelector( sel ) {
		return this.querySelectorAll( sel )[ 0 ] || null;
	}

	querySelectorAll( sel ) {
		return this.all().filter( ( n ) => matches( n, sel ) );
	}

	/** شبیه‌سازی کلیک. */
	click() {
		for ( const fn of this.listeners.click || [] ) {
			fn( { preventDefault() {}, stopPropagation() {}, target: this } );
		}
	}
}

/** @param {FakeNode} node */
function matches( node, sel ) {
	const s = String( sel ).trim();
	if ( s.startsWith( '#' ) ) {
		return node.id === s.slice( 1 );
	}
	if ( s.startsWith( '.' ) ) {
		return node.classList.contains( s.slice( 1 ) );
	}
	return node.tagName === s.toUpperCase();
}

/**
 * یک محیط تازه می‌سازد و روی `globalThis` می‌نشاند.
 *
 * @param {{fetch?: (url:string, opts?:any)=>Promise<any>}} [opts]
 */
export function installFakeDom( opts = {} ) {
	const document = {
		createElement: ( tag ) => new FakeNode( tag ),
		createTextNode: ( text ) => {
			const n = new FakeNode( 'text' );
			n.textContent = text;
			return n;
		},
		body: new FakeNode( 'body' ),
		documentElement: new FakeNode( 'html' ),
		querySelector( sel ) {
			return this.body.querySelector( sel );
		},
		querySelectorAll( sel ) {
			return this.body.querySelectorAll( sel );
		},
		addEventListener() {},
		dispatchEvent() {},
	};

	const previous = {
		document: globalThis.document,
		fetch: globalThis.fetch,
		localStorage: globalThis.localStorage,
		CustomEvent: globalThis.CustomEvent,
	};

	globalThis.document = document;
	globalThis.CustomEvent = class {
		constructor( type, init ) {
			this.type = type;
			this.detail = init?.detail;
		}
	};
	const store = new Map();
	globalThis.localStorage = {
		getItem: ( k ) => ( store.has( k ) ? store.get( k ) : null ),
		setItem: ( k, v ) => store.set( k, String( v ) ),
		removeItem: ( k ) => store.delete( k ),
	};
	if ( opts.fetch ) {
		globalThis.fetch = opts.fetch;
	}

	return {
		document,
		restore() {
			globalThis.document = previous.document;
			globalThis.fetch = previous.fetch;
			globalThis.localStorage = previous.localStorage;
			globalThis.CustomEvent = previous.CustomEvent;
		},
	};
}

export { FakeNode };
