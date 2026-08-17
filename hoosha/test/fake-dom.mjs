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

	get parentElement() {
		return this.parentNode;
	}
	get firstChild() {
		return this.children[ 0 ] || null;
	}
	get lastElementChild() {
		return this.children[ this.children.length - 1 ] || null;
	}
	get childElementCount() {
		return this.children.length;
	}
	get scrollHeight() {
		return 100;
	}
	get offsetHeight() {
		return 100;
	}
	getBoundingClientRect() {
		return { top: 0, left: 0, right: 0, bottom: 0, width: 100, height: 100 };
	}
	/** @param {string} sel */
	closest( sel ) {
		let n = this;
		while ( n ) {
			if ( matches( n, sel ) ) {
				return n;
			}
			n = n.parentNode;
		}
		return null;
	}
	/** @param {string} sel */
	matches( sel ) {
		return matches( this, sel );
	}
	insertBefore( node, before ) {
		const i = this.children.indexOf( before );
		node.parentNode = this;
		this.children.splice( i < 0 ? this.children.length : i, 0, node );
		return node;
	}
	scrollTo() {}
	blur() {}
	select() {}

	get textContent() {
		return this.textValue || this.children.map( ( c ) => c.textContent ).join( '' );
	}
	set textContent( value ) {
		this.textValue = String( value ?? '' );
		this.children = [];
	}
	set innerHTML( value ) {
		this.textValue = String( value ?? '' );
		this.children = [];
	}
	get innerHTML() {
		return this.textValue;
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
		// پشتیبانی از سلکتور نسبی: «‎#a button» یعنی هر button که جایی زیر ‎#a باشد.
		const parts = String( sel ).trim().split( /\s+/ );
		const last = parts[ parts.length - 1 ];
		const ancestors = parts.slice( 0, -1 );
		return this.all().filter( ( n ) => {
			if ( ! matches( n, last ) ) {
				return false;
			}
			let node = n.parentNode;
			for ( let i = ancestors.length - 1; i >= 0; i-- ) {
				while ( node && ! matches( node, ancestors[ i ] ) ) {
					node = node.parentNode;
				}
				if ( ! node ) {
					return false;
				}
				node = node.parentNode;
			}
			return true;
		} );
	}

	/**
	 * شبیه‌سازی کلیک.
	 *
	 * هم `addEventListener('click')` و هم ویژگی `onclick` را صدا می‌زند. نسخهٔ اول فقط
	 * اولی را می‌زد و نتیجه‌اش این بود که کلیک روی ناوبری «هیچ کاری نمی‌کرد» — در حالی
	 * که کد سالم بود و هارنس ناقص.
	 */
	click() {
		const ev = { preventDefault() {}, stopPropagation() {}, target: this };
		for ( const fn of this.listeners.click || [] ) {
			fn( ev );
		}
		if ( typeof this.onclick === 'function' ) {
			this.onclick( ev );
		}
	}
}

/**
 * تطبیق سلکتور — عمداً کوچک: تگ، کلاس، شناسه، و ویژگی. همین‌ها را رابط ما استفاده
 * می‌کند و بیشتر از این یعنی نوشتن یک مرورگر.
 *
 * @param {FakeNode} node
 * @param {string} sel
 */
function matches( node, sel ) {
	let s = String( sel ).trim();

	// بخش ویژگی: [data-view="tools"] یا [hidden]
	const attrs = [ ...s.matchAll( /\[([\w-]+)(?:=["']?([^\]"']*)["']?)?\]/g ) ];
	s = s.replace( /\[[^\]]*\]/g, '' );
	for ( const [ , name, value ] of attrs ) {
		const have = name === 'hidden' ? ( node.hidden ? '' : null ) : node.dataset[ camel( name ) ] ?? node.attributes[ name ];
		if ( have === undefined || have === null ) {
			return false;
		}
		if ( value !== undefined && String( have ) !== value ) {
			return false;
		}
	}

	if ( ! s ) {
		return true;
	}

	// بخش کلاس‌ها و شناسه
	const id = /#([\w-]+)/.exec( s )?.[ 1 ];
	if ( id && node.id !== id ) {
		return false;
	}
	for ( const cls of s.match( /\.[\w-]+/g ) || [] ) {
		if ( ! node.classList.contains( cls.slice( 1 ) ) ) {
			return false;
		}
	}

	const tag = /^[a-zA-Z][\w-]*/.exec( s )?.[ 0 ];
	if ( tag && node.tagName !== tag.toUpperCase() ) {
		return false;
	}
	return true;
}

/** data-view → view */
function camel( name ) {
	return String( name ).replace( /^data-/, '' ).replace( /-([a-z])/g, ( _, c ) => c.toUpperCase() );
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

/**
 * یک تجزیه‌گر HTML کوچک.
 *
 * چرا: تا امروز تست‌های رابط یا متن فایل را grep می‌کردند یا یک المان دستی می‌ساختند.
 * هیچ‌کدام ثابت نمی‌کرد که برنامه با `index.html` واقعی بالا می‌آید. این تجزیه‌گر فقط
 * همان HTML خودمان را می‌فهمد — نه یک مرورگر است و نه ادعایش را دارد — ولی همین‌قدر
 * کافی است که `app.js` را واقعاً بوت کنیم و ببینیم چیزی نمی‌شکند.
 *
 * @param {string} html
 * @param {FakeNode} root
 */
export function parseHtml( html, root ) {
	const VOID = new Set( [ 'br', 'hr', 'img', 'input', 'meta', 'link', 'source', 'path', 'circle', 'rect', 'line', 'use' ] );
	const stack = [ root ];
	let i = 0;

	while ( i < html.length ) {
		const lt = html.indexOf( '<', i );
		if ( lt === -1 ) {
			break;
		}
		const text = html.slice( i, lt ).trim();
		if ( text && stack.length > 1 ) {
			const t = new FakeNode( 'text' );
			t.textContent = text;
			stack[ stack.length - 1 ].appendChild( t );
		}

		if ( html.startsWith( '<!--', lt ) ) {
			i = html.indexOf( '-->', lt ) + 3;
			continue;
		}
		if ( html.startsWith( '<!', lt ) ) {
			i = html.indexOf( '>', lt ) + 1;
			continue;
		}

		const gt = findTagEnd( html, lt );
		const raw = html.slice( lt + 1, gt );
		i = gt + 1;

		if ( raw.startsWith( '/' ) ) {
			if ( stack.length > 1 ) {
				stack.pop();
			}
			continue;
		}

		const name = ( /^[\w-]+/.exec( raw ) || [ 'div' ] )[ 0 ].toLowerCase();
		const node = new FakeNode( name );
		for ( const m of raw.slice( name.length ).matchAll( /([:\w-]+)(?:="([^"]*)")?/g ) ) {
			const key = m[ 1 ];
			const value = m[ 2 ] ?? '';
			if ( key === 'class' ) {
				node.className = value;
			} else if ( key === 'id' ) {
				node.id = value;
				node.attributes.id = value;
			} else if ( key === 'hidden' ) {
				node.hidden = true;
			} else if ( key.startsWith( 'data-' ) ) {
				node.dataset[ camel( key ) ] = value;
				node.attributes[ key ] = value;
			} else {
				node.attributes[ key ] = value;
			}
		}
		stack[ stack.length - 1 ].appendChild( node );

		const selfClosing = raw.endsWith( '/' ) || VOID.has( name );
		if ( ! selfClosing ) {
			stack.push( node );
		}
		// محتوای script و style را نمی‌خواهیم.
		if ( name === 'script' || name === 'style' ) {
			const close = html.indexOf( `</${ name }>`, i );
			i = close === -1 ? html.length : close + name.length + 3;
			stack.pop();
		}
	}
	return root;
}

/** پایان یک تگ، با احترام به `>` داخل مقدار ویژگی. */
function findTagEnd( html, start ) {
	let quoted = false;
	for ( let k = start + 1; k < html.length; k++ ) {
		const ch = html[ k ];
		if ( ch === '"' ) {
			quoted = ! quoted;
		} else if ( ch === '>' && ! quoted ) {
			return k;
		}
	}
	return html.length;
}

export { FakeNode };
