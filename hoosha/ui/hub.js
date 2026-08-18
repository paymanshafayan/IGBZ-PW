/**
 * پنج صفحهٔ هاب پرووایدر (بند ۷ سند طراحی).
 *
 * چرا پنج صفحهٔ جدا و نه یک فرم بزرگ: شکایت اصلی کارفرما از رابط قبلی این بود که
 * «این صفحهٔ تنظیمات پرووایدر خیلی ساده و بدون امکانات است». ولی جواب، تلنبارکردن سی
 * فیلد در یک صفحه نیست — جواب این است که هر تصمیم در جای خودش پرسیده شود:
 *
 *   استاندارد → کلید بگیر و برو            سازگار  → هر پیچ و مهره‌ای که لازم است
 *   مدل‌ها     → چه داریم و کدام روشن است     مسیریابی → کدام درخواست به کجا می‌رود
 *   سلامت     → چه گذشت و چه یاد گرفتیم
 */

import { el, h, toast, confirmDialog } from './lib/dom.js';
import { api, post } from './lib/api.js';

/** @type {any} */
let snap = null;
let editing = null;

async function refresh() {
	snap = await api( '/api/hub' );
	return snap;
}

function section( title, hint ) {
	return h( 'div', { class: 'sec-head' }, [ h( 'h3', { text: title } ), hint ? h( 'p', { class: 'note', text: hint } ) : null ] );
}

function field( label, control, hint ) {
	return h( 'label', { class: 'field-label' }, [ h( 'span', { text: label } ), control, hint ? h( 'small', { class: 'note', text: hint } ) : null ] );
}

const row = ( ...children ) => h( 'div', { class: 'row' }, children );
const emptyBox = ( text ) => h( 'div', { class: 'empty', text } );

/** دوباره‌سازی همین صفحه — بعد از هر تغییر، تا کاربر نتیجه را ببیند نه فرم قدیمی را. */
async function again( box, page ) {
	editing = null;
	await mountHub( box, page );
}

/**
 * @param {HTMLElement} box
 * @param {string} page
 */
export async function mountHub( box, page ) {
	box.replaceChildren( el( 'div', 'loading', 'در حال خواندن هاب…' ) );
	await refresh();
	box.replaceChildren();

	box.appendChild( masterSwitch( box, page ) );

	if ( page === 'hub' ) {
		return renderConnections( box, page, false );
	}
	if ( page === 'hub-compat' ) {
		return renderConnections( box, page, true );
	}
	if ( page === 'hub-models' ) {
		return renderModels( box, page );
	}
	if ( page === 'hub-routing' ) {
		return renderRouting( box, page );
	}
	return renderHealth( box, page );
}

/** کلید اصلی: هاب روشن است یا پروفایل تک‌نفرهٔ قدیمی سرِ کار است. */
function masterSwitch( box, page ) {
	const hub = snap?.hub || {};
	const ready = snap?.ready || {};
	const on = Boolean( hub.enabled );

	return h( 'div', { class: `form-card hub-master ${ on ? 'on' : '' }` }, [
		h( 'div', { class: 'row' }, [
			h( 'div', { class: 'item-main' }, [
				h( 'b', { text: on ? 'هاب روشن است' : 'هاب خاموش است' } ),
				h( 'p', {
					class: `note ${ on && ! ready.ok ? 'error' : '' }`,
					text: on
						? ready.ok
							? 'مسیریابی با هاب انجام می‌شود؛ پروفایل تک‌نفره کنار گذاشته شده.'
							: `هاب روشن است ولی آماده نیست: ${ ready.reason } — فعلاً پروفایل قدیمی کار می‌کند.`
						: 'با روشن‌کردن هاب، هوشا بین چند اتصال و چند مدل خودش مسیریابی می‌کند.',
				} ),
			] ),
			h( 'span', { class: 'grow' } ),
			snap?.active ? h( 'span', { class: 'tag ok', text: 'فرمان با هاب' } ) : null,
			h( 'button', {
				class: `btn ${ on ? 'outline' : 'solid' }`,
				text: on ? 'خاموش کن' : 'روشن کن',
				onClick: async () => {
					const out = await post( '/api/hub', { action: 'toggle', enabled: ! on } );
					toast( out.active ? 'هاب فرمان را گرفت.' : 'هاب خاموش شد.' );
					await again( box, page );
				},
			} ),
		] ),
	] );
}

// ═══════════════════════════════════════════════════ اتصال‌ها

/**
 * @param {HTMLElement} box
 * @param {string} page
 * @param {boolean} compat صفحهٔ «سازگار» یا «استاندارد»
 */
function renderConnections( box, page, compat ) {
	const hub = snap?.hub || {};
	const catalog = snap?.catalog || [];
	const custom = new Set( [ 'openai-compatible', 'anthropic-compatible' ] );

	box.appendChild(
		section(
			compat ? 'پرووایدرهای سازگار' : 'پرووایدرهای استاندارد',
			compat
				? 'هر سرویسی که مسیر سازگار با OpenAI یا Anthropic دارد: آدرس پایه، سبک احراز، هدر دلخواه و مسیر فهرست مدل، همه دست خودت.'
				: 'از کاتالوگ انتخاب کن، کلید بده، تست کن. می‌توانی از یک سرویس چند حساب داشته باشی — هر حساب یک سهمیهٔ جدا.'
		)
	);

	const list = el( 'div', 'card-list' );
	const conns = Object.values( hub.connections || {} ).filter( ( c ) => custom.has( c.provider ) === compat );

	if ( ! conns.length ) {
		list.appendChild( emptyBox( compat ? 'هنوز اتصال سازگاری نساخته‌ای.' : 'هنوز اتصالی از کاتالوگ نساخته‌ای.' ) );
	}

	for ( const c of conns ) {
		const models = Object.values( hub.models || {} ).filter( ( m ) => m.connectionId === c.id );
		const health = Object.entries( snap?.health || {} ).filter( ( [ key ] ) => key.startsWith( `${ c.id }::` ) );
		const broken = health.filter( ( [ , v ] ) => v.circuit === 'open' || v.exhausted ).length;

		list.appendChild(
			h( 'div', { class: `item ${ c.enabled === false ? 'off' : '' }` }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: c.label } ),
					h( 'p', { class: 'mono', text: `${ c.provider } · ${ c.baseUrl || '—' }` } ),
					h( 'p', {
						class: 'note',
						text: `${ models.length } مدل · ${ models.filter( ( m ) => m.enabled ).length } روشن · اولویت ${ c.priority } · هم‌زمانی ${ c.maxConcurrent }${
							c.dailyCap ? ` · سقف روزانه ${ c.dailyCap }` : ''
						}`,
					} ),
					broken ? h( 'p', { class: 'note error', text: `${ broken } مدل این اتصال الان از مدار خارج است.` } ) : null,
					( c.patches || [] ).length
						? h( 'p', { class: 'note', text: `${ c.patches.length } وصلهٔ دائمی روی این اتصال: ${ c.patches.map( ( x ) => x.op ).join( '، ' ) }` } )
						: null,
				] ),
				h( 'span', { class: `tag ${ c.hasKey ? 'ok' : '' }`, text: c.hasKey ? 'کلید ✓' : 'بدون کلید' } ),
				h( 'button', {
					class: 'btn outline',
					text: 'کشف مدل‌ها',
					onClick: async () => {
						toast( 'در حال گرفتن فهرست مدل‌ها…' );
						const out = await post( '/api/hub', { action: 'discover', id: c.id } );
						toast( out.ok ? `${ out.added } مدل تازه، ${ out.kept } مدل قبلی، ${ out.missing } ناپیدا.` : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
						await again( box, page );
					},
				} ),
				h( 'button', {
					class: 'btn outline',
					text: 'تست',
					onClick: async () => {
						toast( 'در حال آزمودن…' );
						const out = await post( '/api/hub', { action: 'test-connection', id: c.id } );
						toast( out.ok ? out.message : `${ out.error }${ out.hint ? ' — ' + out.hint : '' }`, out.ok ? 'ok' : 'error' );
					},
				} ),
				h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => form( c ) } ),
				h( 'button', {
					class: 'btn quiet danger',
					text: 'حذف',
					onClick: async () => {
						if ( ! ( await confirmDialog( `اتصال «${ c.label }» و همهٔ مدل‌هایش حذف شود؟`, { danger: true } ) ) ) {
							return;
						}
						await post( '/api/hub', { action: 'remove-connection', id: c.id } );
						await again( box, page );
					},
				} ),
			] )
		);
	}
	box.appendChild( list );

	const formHost = el( 'div', 'form-host' );
	box.appendChild( row( h( 'button', { class: 'btn solid', text: '+ اتصال تازه', onClick: () => form( null ) } ) ) );
	box.appendChild( formHost );

	if ( editing && conns.some( ( c ) => c.id === editing ) ) {
		form( hub.connections[ editing ] );
	}

	function form( conn ) {
		editing = conn?.id || null;
		formHost.replaceChildren();

		const options = catalog.filter( ( p ) => custom.has( p.id ) === compat && p.id !== 'mock' );
		const current = conn || {
			label: '',
			provider: options[ 0 ]?.id || 'openai-compatible',
			kind: options[ 0 ]?.kind || 'openai',
			baseUrl: options[ 0 ]?.baseUrl || '',
			authStyle: 'bearer',
			headers: {},
			priority: 100,
			weight: 1,
			maxConcurrent: 4,
			enabled: true,
		};

		const label = h( 'input', { class: 'field', value: current.label || '' } );
		const provider = h( 'select', { class: 'field' } );
		for ( const p of options ) {
			provider.appendChild( h( 'option', { value: p.id, text: p.label } ) );
		}
		provider.value = current.provider;

		const baseUrl = h( 'input', { class: 'field', dir: 'ltr', value: current.baseUrl || '', placeholder: 'https://…' } );
		const apiKey = h( 'input', { class: 'field', dir: 'ltr', type: 'password', placeholder: current.hasKey ? '••••••• (خالی بگذار تا بماند)' : '' } );
		const authStyle = h( 'select', { class: 'field' } );
		for ( const a of snap?.authStyles || [] ) {
			authStyle.appendChild( h( 'option', { value: a.id, text: a.label } ) );
		}
		authStyle.value = current.authStyle || 'bearer';
		const authHeader = h( 'input', { class: 'field', dir: 'ltr', value: current.authHeader || '', placeholder: 'X-Custom-Key' } );
		const modelsPath = h( 'input', { class: 'field', dir: 'ltr', value: current.modelsPath || '', placeholder: '/models' } );
		const headers = h( 'textarea', {
			class: 'field mono',
			dir: 'ltr',
			rows: 3,
			placeholder: 'X-Org: acme\nX-Region: eu',
			value: Object.entries( current.headers || {} ).map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '\n' ),
		} );
		const priority = h( 'input', { class: 'field', type: 'number', min: 1, value: current.priority ?? 100 } );
		const maxConcurrent = h( 'input', { class: 'field', type: 'number', min: 1, value: current.maxConcurrent ?? 4 } );
		const dailyCap = h( 'input', { class: 'field', type: 'number', min: 0, value: current.dailyCap ?? '' } );
		const enabled = h( 'input', { type: 'checkbox', checked: current.enabled !== false } );

		const note = h( 'p', { class: 'note' } );
		// آدرس پایهٔ پرووایدر استاندارد فقط **نمایش** داده می‌شود، نه ویرایش.
		const baseShown = h( 'p', { class: 'note mono' } );
		const keyField = h( 'div', {} );

		const sync = () => {
			const info = options.find( ( p ) => p.id === provider.value );
			note.textContent = info?.note || '';
			if ( ! conn && info?.baseUrl ) {
				baseUrl.value = info.baseUrl;
			}
			authHeader.disabled = ! [ 'header', 'query' ].includes( authStyle.value );

			if ( ! compat ) {
				// در حالت استاندارد، آدرس از کاتالوگ می‌آید و کاربر لازم نیست بداند.
				baseShown.textContent = info?.baseUrl || '—';
				keyField.hidden = info?.needsKey === false;
				apiKey.placeholder = info?.needsKey === false
					? 'این سرویس کلید نمی‌خواهد'
					: current.hasKey
					? '••••••• (خالی بگذار تا بماند)'
					: 'کلید را از پنل سرویس‌دهنده کپی کن';
			}
		};
		provider.onchange = sync;
		authStyle.onchange = sync;
		sync();

		const save = async () => {
			const info = options.find( ( p ) => p.id === provider.value );
			const out = await post( '/api/hub', {
				action: 'save-connection',
				connection: {
					id: conn?.id,
					label: label.value.trim() || info?.label || 'اتصال',
					provider: provider.value,
					kind: info?.kind || 'openai',
					baseUrl: compat ? baseUrl.value.trim() : info?.baseUrl || baseUrl.value.trim(),
					apiKey: apiKey.value.trim(),
					authStyle: authStyle.value,
					authHeader: authHeader.value.trim(),
					modelsPath: modelsPath.value.trim(),
					headers: parseHeaders( headers.value ),
					priority: Number( priority.value ) || 100,
					maxConcurrent: Number( maxConcurrent.value ) || 4,
					dailyCap: dailyCap.value === '' ? null : Number( dailyCap.value ),
					enabled: enabled.checked,
				},
			} );
			if ( out.error ) {
				toast( out.error, 'error' );
				return null;
			}
			return out.connection;
		};

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: conn ? `ویرایش «${ conn.label }»` : 'اتصال تازه' } ),
				field( 'نام', label, 'هرچه که در فهرست‌ها می‌خواهی ببینی — مثلاً «OpenRouter حساب اصلی».' ),
				field( 'سرویس', provider ),
				note,

				/*
				 * دو فرم متفاوت، نه یک فرم با چند فیلد خاموش.
				 *
				 * کارفرما درست گفت که این دو صفحه شبیه هم شده بودند. پرووایدر استاندارد
				 * آدرس پایه‌اش را از کاتالوگ دارد و پرسیدنش از کاربر، هم اضافی است و هم
				 * جای اشتباه‌کردن باز می‌کند. سبک احراز و مسیر فهرست مدل هم همین‌طور.
				 */
				compat ? field( 'آدرس پایه', baseUrl, 'اجباری — همان چیزی که سرویس‌دهنده می‌دهد.' ) : null,
				! compat ? field( 'آدرس پایه', baseShown, 'از کاتالوگ می‌آید؛ لازم نیست چیزی وارد کنی.' ) : null,

				h( 'div', {}, [ keyField ] ),
				field( 'کلید API', apiKey, 'در فایل تنظیمات محلی و با دسترسی ۶۰۰ ذخیره می‌شود و هیچ‌وقت به رابط برنمی‌گردد.' ),
				compat ? field( 'سبک احراز', authStyle ) : null,
				compat ? field( 'نام هدر یا پارامتر احراز', authHeader, 'فقط برای سبک «هدر دلخواه» و «پارامتر آدرس».' ) : null,
				compat ? field( 'مسیر فهرست مدل', modelsPath, 'اگر سرویس مسیر غیراستاندارد دارد.' ) : null,
				compat ? field( 'هدرهای سفارشی', headers, 'هر خط یک هدر: نام: مقدار' ) : null,
				row( field( 'اولویت', priority ), field( 'سقف هم‌زمانی', maxConcurrent ), field( 'سقف روزانه (تعداد تماس)', dailyCap ) ),
				h( 'label', { class: 'check' }, [ enabled, h( 'span', { text: 'این اتصال روشن باشد' } ) ] ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'button', {
						class: 'btn outline',
						text: 'ذخیره و کشف مدل‌ها',
						onClick: async () => {
							const saved = await save();
							if ( ! saved ) {
								return;
							}
							const out = await post( '/api/hub', { action: 'discover', id: saved.id } );
							toast( out.ok ? `${ out.added } مدل تازه پیدا شد.` : out.error, out.ok ? 'ok' : 'error' );
							await again( box, page );
						},
					} ),
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => { editing = null; formHost.replaceChildren(); } } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							if ( await save() ) {
								toast( 'ذخیره شد.' );
								await again( box, page );
							}
						},
					} ),
				] ),
			] )
		);
		formHost.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}
}

function parseHeaders( text ) {
	/** @type {Record<string,string>} */
	const out = {};
	for ( const line of String( text || '' ).split( '\n' ) ) {
		const i = line.indexOf( ':' );
		if ( i > 0 ) {
			out[ line.slice( 0, i ).trim() ] = line.slice( i + 1 ).trim();
		}
	}
	return out;
}

// ═══════════════════════════════════════════════════ مدل‌ها

function renderModels( box, page ) {
	const hub = snap?.hub || {};
	const models = Object.values( hub.models || {} );
	const categories = snap?.categories || [];

	box.appendChild(
		section(
			'مدل‌ها',
			'کشف خودکار یک نقطهٔ شروع است. برچسبی که اینجا می‌زنی بر آن می‌چربد، و آنچه هوشا از نتیجهٔ واقعی یاد می‌گیرد بر هر دو.'
		)
	);

	if ( ! models.length ) {
		box.appendChild( emptyBox( 'هنوز مدلی کشف نشده. در صفحهٔ پرووایدرها، روی «کشف مدل‌ها» بزن.' ) );
		return;
	}

	const filter = h( 'input', { class: 'field', placeholder: 'جستجو در مدل‌ها…' } );
	box.appendChild( row( filter, h( 'span', { class: 'note', text: `${ models.filter( ( m ) => m.enabled ).length } از ${ models.length } روشن` } ) ) );

	const list = el( 'div', 'card-list' );
	box.appendChild( list );

	const draw = () => {
		const q = filter.value.trim().toLowerCase();
		list.replaceChildren();
		for ( const m of models.filter( ( x ) => ! q || x.key.toLowerCase().includes( q ) ) ) {
			const conn = hub.connections?.[ m.connectionId ];
			const stat = snap?.health?.[ m.key ];
			list.appendChild(
				h( 'div', { class: `item ${ m.enabled ? '' : 'off' } ${ m.missing ? 'missing' : '' }` }, [
					h( 'div', { class: 'item-main' }, [
						h( 'b', { text: m.label || m.modelId } ),
						h( 'p', { class: 'mono', text: `${ conn?.label || m.connectionId } · ${ m.modelId }` } ),
						h( 'p', {
							class: 'note',
							text: [
								m.context ? `کانتکست ${ Intl.NumberFormat( 'fa' ).format( m.context ) }` : null,
								m.priceIn !== null ? `ورودی $${ m.priceIn }/M` : null,
								m.priceOut !== null ? `خروجی $${ m.priceOut }/M` : null,
								m.caps?.vision ? 'بینا' : null,
								m.caps?.reasoning ? 'استدلالی' : null,
								m.caps?.tools ? 'ابزار' : 'بدون ابزار',
								stat ? `نرخ موفقیت ${ Math.round( stat.successRate * 100 ) }٪` : null,
								stat?.p95 ? `p95 ${ stat.p95 }ms` : null,
							].filter( Boolean ).join( ' · ' ),
						} ),
						h( 'div', { class: 'tag-row' }, ( m.tags || [] ).map( ( t ) => h( 'span', { class: 'tag', text: categories.find( ( c ) => c.id === t )?.label || t } ) ) ),
						m.missing ? h( 'p', { class: 'note error', text: 'در آخرین کشف، سرویس این مدل را برنگرداند.' } ) : null,
					] ),
					h( 'button', {
						class: 'btn outline',
						text: m.enabled ? 'خاموش' : 'روشن',
						onClick: async () => {
							await post( '/api/hub', { action: 'toggle-model', key: m.key, enabled: ! m.enabled } );
							await again( box, page );
						},
					} ),
					h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => modelForm( m ) } ),
				] )
			);
		}
	};
	filter.oninput = draw;
	draw();

	const formHost = el( 'div', 'form-host' );
	box.appendChild( formHost );

	function modelForm( m ) {
		formHost.replaceChildren();
		const label = h( 'input', { class: 'field', value: m.label || '' } );
		const context = h( 'input', { class: 'field', type: 'number', min: 0, value: m.context || 0 } );
		const priceIn = h( 'input', { class: 'field', type: 'number', step: '0.01', value: m.priceIn ?? '' } );
		const priceOut = h( 'input', { class: 'field', type: 'number', step: '0.01', value: m.priceOut ?? '' } );
		const priority = h( 'input', { class: 'field', type: 'number', min: 1, value: m.priority ?? 100 } );
		const weight = h( 'input', { class: 'field', type: 'number', min: 0, value: m.weight ?? 1 } );

		const caps = {};
		const capRow = h( 'div', { class: 'row' } );
		for ( const [ id, name ] of [ [ 'tools', 'ابزار' ], [ 'vision', 'بینایی' ], [ 'reasoning', 'استدلال' ], [ 'stream', 'استریم' ], [ 'json', 'JSON' ] ] ) {
			caps[ id ] = h( 'input', { type: 'checkbox', checked: Boolean( m.caps?.[ id ] ) } );
			capRow.appendChild( h( 'label', { class: 'check' }, [ caps[ id ], h( 'span', { text: name } ) ] ) );
		}

		const tags = {};
		const tagRow = h( 'div', { class: 'row wrap' } );
		for ( const c of categories ) {
			tags[ c.id ] = h( 'input', { type: 'checkbox', checked: ( m.tags || [] ).includes( c.id ) } );
			tagRow.appendChild( h( 'label', { class: 'check' }, [ tags[ c.id ], h( 'span', { text: c.label } ) ] ) );
		}

		formHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: `ویرایش «${ m.modelId }»` } ),
				field( 'نام نمایشی', label ),
				row( field( 'پنجرهٔ کانتکست', context ), field( 'قیمت ورودی ($/M)', priceIn ), field( 'قیمت خروجی ($/M)', priceOut ) ),
				row( field( 'اولویت', priority ), field( 'وزن', weight ) ),
				field( 'توانایی‌ها', capRow ),
				field( 'برچسب زمینه', tagRow, 'مسیریاب از این برچسب‌ها شروع می‌کند و بعد با نتیجهٔ واقعی اصلاحشان می‌کند.' ),
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => formHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							await post( '/api/hub', {
								action: 'save-model',
								model: {
									key: m.key,
									label: label.value.trim(),
									context: Number( context.value ) || 0,
									priceIn: priceIn.value === '' ? null : Number( priceIn.value ),
									priceOut: priceOut.value === '' ? null : Number( priceOut.value ),
									priority: Number( priority.value ) || 100,
									weight: Number( weight.value ) || 1,
									caps: Object.fromEntries( Object.entries( caps ).map( ( [ k, v ] ) => [ k, v.checked ] ) ),
									tags: Object.entries( tags ).filter( ( [ , v ] ) => v.checked ).map( ( [ k ] ) => k ),
								},
							} );
							toast( 'ذخیره شد.' );
							await again( box, page );
						},
					} ),
				] ),
			] )
		);
		formHost.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
	}
}

// ═══════════════════════════════════════════════════ مسیریابی

function renderRouting( box, page ) {
	const hub = snap?.hub || {};
	const strategies = snap?.strategies || [];
	const categories = snap?.categories || [];
	const models = Object.values( hub.models || {} ).filter( ( m ) => m.enabled );

	box.appendChild( section( 'هاب و مسیریابی', 'ترکیب یعنی یک زنجیرهٔ نام‌دار از مدل‌ها با یک راهبرد. دستهٔ کار می‌گوید کدام ترکیب برای چه جنسی از درخواست.' ) );

	// ——— راهبرد کلی
	const strategy = h( 'select', { class: 'field' } );
	for ( const s of strategies ) {
		strategy.appendChild( h( 'option', { value: s.id, text: s.label } ) );
	}
	strategy.value = hub.routing?.strategy || 'auto';
	const stratNote = h( 'p', { class: 'note' } );
	const syncStrat = () => {
		stratNote.textContent = strategies.find( ( s ) => s.id === strategy.value )?.note || '';
	};
	strategy.onchange = syncStrat;
	syncStrat();

	const fallback = h( 'input', { type: 'checkbox', checked: hub.routing?.fallback !== false } );
	const maxAttempts = h( 'input', { class: 'field', type: 'number', min: 1, max: 6, value: hub.routing?.maxAttempts ?? 3 } );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'h4', { text: 'راهبرد پیش‌فرض' } ),
			field( 'راهبرد', strategy ),
			stratNote,
			row( field( 'حداکثر تلاش', maxAttempts ) ),
			h( 'label', { class: 'check' }, [ fallback, h( 'span', { text: 'اگر مدل اول شکست خورد، بی‌صدا برو سراغ بعدی' } ) ] ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						await post( '/api/hub', {
							action: 'update',
							patch: { routing: { strategy: strategy.value, fallback: fallback.checked, maxAttempts: Number( maxAttempts.value ) || 3 } },
						} );
						toast( 'ذخیره شد.' );
						await again( box, page );
					},
				} ),
			] ),
		] )
	);

	// ——— ترکیب‌ها
	box.appendChild( section( 'ترکیب‌ها', 'ترتیب مدل‌ها در هر ترکیب مهم است — راهبردهای اولویتی از بالا شروع می‌کنند.' ) );
	const combos = Object.values( hub.combos || {} );
	const list = el( 'div', 'card-list' );
	if ( ! combos.length ) {
		list.appendChild( emptyBox( 'هنوز ترکیبی نساخته‌ای. بدون ترکیب، همهٔ مدل‌های روشن با راهبرد پیش‌فرض نامزد می‌شوند.' ) );
	}
	for ( const c of combos ) {
		list.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: c.label } ),
					h( 'p', { class: 'note', text: `${ strategies.find( ( s ) => s.id === c.strategy )?.label || c.strategy } · ${ c.members?.length || 0 } مدل` } ),
					h( 'p', { class: 'mono note', text: ( c.members || [] ).join( ' → ' ) || 'همهٔ مدل‌های روشن' } ),
				] ),
				h( 'button', { class: 'btn outline', text: 'ویرایش', onClick: () => comboForm( c ) } ),
				h( 'button', {
					class: 'btn quiet danger',
					text: 'حذف',
					onClick: async () => {
						await post( '/api/hub', { action: 'remove-combo', id: c.id } );
						await again( box, page );
					},
				} ),
			] )
		);
	}
	box.appendChild( list );
	const comboHost = el( 'div', 'form-host' );
	box.appendChild( row( h( 'button', { class: 'btn solid', text: '+ ترکیب تازه', onClick: () => comboForm( null ) } ) ) );
	box.appendChild( comboHost );

	function comboForm( c ) {
		comboHost.replaceChildren();
		const label = h( 'input', { class: 'field', value: c?.label || '' } );
		const strat = h( 'select', { class: 'field' } );
		for ( const s of strategies ) {
			strat.appendChild( h( 'option', { value: s.id, text: s.label } ) );
		}
		strat.value = c?.strategy || 'auto';

		const chosen = [ ...( c?.members || [] ) ];
		const picked = el( 'div', 'card-list compact' );
		const drawPicked = () => {
			picked.replaceChildren();
			if ( ! chosen.length ) {
				picked.appendChild( emptyBox( 'هیچ مدلی انتخاب نشده — یعنی همهٔ مدل‌های روشن.' ) );
			}
			chosen.forEach( ( key, i ) => {
				picked.appendChild(
					h( 'div', { class: 'item' }, [
						h( 'span', { class: 'tag', text: String( i + 1 ) } ),
						h( 'div', { class: 'item-main' }, [ h( 'p', { class: 'mono', text: key } ) ] ),
						h( 'button', { class: 'btn outline', text: '↑', onClick: () => { if ( i > 0 ) { [ chosen[ i - 1 ], chosen[ i ] ] = [ chosen[ i ], chosen[ i - 1 ] ]; drawPicked(); } } } ),
						h( 'button', { class: 'btn outline', text: '↓', onClick: () => { if ( i < chosen.length - 1 ) { [ chosen[ i + 1 ], chosen[ i ] ] = [ chosen[ i ], chosen[ i + 1 ] ]; drawPicked(); } } } ),
						h( 'button', { class: 'btn quiet danger', text: '×', onClick: () => { chosen.splice( i, 1 ); drawPicked(); } } ),
					] )
				);
			} );
		};
		drawPicked();

		const add = h( 'select', { class: 'field' } );
		add.appendChild( h( 'option', { value: '', text: '— مدل اضافه کن —' } ) );
		for ( const m of models ) {
			add.appendChild( h( 'option', { value: m.key, text: `${ m.label || m.modelId }` } ) );
		}
		add.onchange = () => {
			if ( add.value && ! chosen.includes( add.value ) ) {
				chosen.push( add.value );
				drawPicked();
			}
			add.value = '';
		};

		comboHost.appendChild(
			h( 'div', { class: 'form-card' }, [
				h( 'h4', { text: c ? `ویرایش «${ c.label }»` : 'ترکیب تازه' } ),
				field( 'نام', label ),
				field( 'راهبرد', strat ),
				field( 'مدل‌ها به ترتیب', picked ),
				add,
				h( 'div', { class: 'modal-actions' }, [
					h( 'span', { class: 'grow' } ),
					h( 'button', { class: 'btn outline', text: 'انصراف', onClick: () => comboHost.replaceChildren() } ),
					h( 'button', {
						class: 'btn solid',
						text: 'ذخیره',
						onClick: async () => {
							await post( '/api/hub', { action: 'save-combo', combo: { id: c?.id, label: label.value.trim() || 'ترکیب', strategy: strat.value, members: chosen } } );
							toast( 'ذخیره شد.' );
							await again( box, page );
						},
					} ),
				] ),
			] )
		);
	}

	// ——— دستهٔ کار → ترکیب
	box.appendChild( section( 'دستهٔ کار', 'هوشا جنس درخواست را خودش تشخیص می‌دهد؛ اینجا فقط می‌گویی هر جنس به کدام ترکیب برود.' ) );
	const mapCard = h( 'div', { class: 'form-card' } );
	/** @type {Record<string, HTMLSelectElement>} */
	const selects = {};
	for ( const cat of categories ) {
		const sel = h( 'select', { class: 'field' } );
		sel.appendChild( h( 'option', { value: '', text: '— پیش‌فرض —' } ) );
		for ( const c of combos ) {
			sel.appendChild( h( 'option', { value: c.id, text: c.label } ) );
		}
		sel.value = hub.categoryCombo?.[ cat.id ] || '';
		selects[ cat.id ] = sel;
		mapCard.appendChild( field( cat.label, sel ) );
	}
	mapCard.appendChild(
		h( 'div', { class: 'modal-actions' }, [
			h( 'span', { class: 'grow' } ),
			h( 'button', {
				class: 'btn solid',
				text: 'ذخیره',
				onClick: async () => {
					const patch = Object.fromEntries( Object.entries( selects ).map( ( [ k, v ] ) => [ k, v.value ] ) );
					await post( '/api/hub', { action: 'update', patch: { categoryCombo: patch } } );
					toast( 'ذخیره شد.' );
					await again( box, page );
				},
			} ),
		] )
	);
	box.appendChild( mapCard );

	// ——— آزمون مسیر
	box.appendChild( section( 'این درخواست به کجا می‌رود؟', 'یک متن نمونه بنویس و ببین هوشا آن را چه جنسی می‌فهمد و به کدام مدل می‌فرستد — بدون اینکه تماسی گرفته شود.' ) );
	const probe = h( 'textarea', { class: 'field', rows: 3, placeholder: 'مثلاً: این تابع خطا می‌دهد، دیباگش کن' } );
	const withImage = h( 'input', { type: 'checkbox' } );
	const withTools = h( 'input', { type: 'checkbox', checked: true } );
	const result = el( 'div', 'route-result' );
	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			probe,
			row(
				h( 'label', { class: 'check' }, [ withImage, h( 'span', { text: 'همراه تصویر' } ) ] ),
				h( 'label', { class: 'check' }, [ withTools, h( 'span', { text: 'با ابزار' } ) ] ),
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ببین کجا می‌رود',
					onClick: async () => {
						const out = await post( '/api/hub', {
							action: 'explain',
							text: probe.value,
							hasImages: withImage.checked,
							tools: withTools.checked ? [ 'bash', 'edit_file' ] : [],
						} );
						result.replaceChildren(
							h( 'p', {
								text: `جنس درخواست: ${ categories.find( ( c ) => c.id === out.classification?.category )?.label || out.classification?.category } (اطمینان ${ Math.round(
									( out.classification?.confidence || 0 ) * 100
								) }٪) · راهبرد: ${ strategies.find( ( s ) => s.id === out.strategy )?.label || out.strategy }`,
							} ),
							h( 'p', { class: 'note', text: `دلیل: ${ ( out.classification?.reasons || [] ).join( '، ' ) || '—' }` } ),
							h( 'ol', { class: 'route-list' },
								( out.candidates || [] ).slice( 0, 5 ).map( ( c ) =>
									h( 'li', {}, [
										h( 'b', { text: c.label } ),
										h( 'span', { class: 'note', text: ` امتیاز ${ c.score } · ${ c.connectionLabel }${ c.cost !== null ? ` · ~$${ c.cost.toFixed( 5 ) }` : '' }` } ),
									] )
								)
							),
							( out.blocked || [] ).length
								? h( 'p', { class: 'note error', text: `کنارگذاشته‌شده: ${ out.blocked.map( ( b ) => `${ b.key } (${ b.reason })` ).join( '، ' ) }` } )
								: null,
							! out.budget?.allowed ? h( 'p', { class: 'note error', text: out.budget.reason } ) : null
						);
					},
				} )
			),
			result,
		] )
	);
}

// ═══════════════════════════════════════════════════ سلامت، مصرف، عیب‌یاب

function renderHealth( box, page ) {
	const hub = snap?.hub || {};
	const health = snap?.health || {};
	const budget = snap?.budget || {};
	const ledger = snap?.ledger || [];
	const diag = snap?.diagnoser || {};
	const learning = snap?.learning || {};
	const categories = snap?.categories || [];

	// ——— سلامت
	box.appendChild( section( 'سلامت مسیرها', 'صدک تأخیر، نرخ موفقیت و وضعیت مدارشکن هر مدل. مدار باز یعنی هوشا فعلاً سراغ آن نمی‌رود.' ) );
	const rows = Object.entries( health );
	const list = el( 'div', 'card-list' );
	if ( ! rows.length ) {
		list.appendChild( emptyBox( 'هنوز تماسی ثبت نشده.' ) );
	}
	for ( const [ key, v ] of rows ) {
		list.appendChild(
			h( 'div', { class: `item ${ v.circuit === 'open' ? 'bad' : '' }` }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: hub.models?.[ key ]?.label || key } ),
					h( 'p', {
						class: 'note',
						text: `${ v.ok } موفق · ${ v.fail } ناموفق · نرخ ${ Math.round( v.successRate * 100 ) }٪${ v.p50 ? ` · p50 ${ v.p50 }ms` : '' }${
							v.p95 ? ` · p95 ${ v.p95 }ms` : ''
						} · امروز ${ v.usedToday }`,
					} ),
					v.lastError ? h( 'p', { class: 'note error', text: v.lastError } ) : null,
				] ),
				v.exhausted ? h( 'span', { class: 'tag err', text: 'اعتبار تمام' } ) : null,
				h( 'span', { class: `tag ${ v.circuit === 'closed' ? 'ok' : 'err' }`, text: v.circuit === 'closed' ? 'سالم' : v.circuit === 'open' ? 'مدار باز' : 'نیمه‌باز' } ),
				v.circuit !== 'closed' || v.exhausted
					? h( 'button', {
							class: 'btn outline',
							text: 'بازکردن دوباره',
							onClick: async () => {
								await post( '/api/hub', { action: 'reset-breaker', key } );
								await again( box, page );
							},
					  } )
					: null,
			] )
		);
	}
	box.appendChild( list );

	// ——— بودجه
	box.appendChild( section( 'سقف هزینه', 'سقف خالی یعنی بی‌سقف. عبور از سقف، درخواست را رد می‌کند — نه اینکه فقط هشدار بدهد.' ) );
	const daily = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.daily ?? '' } );
	const perAdmin = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.perAdmin ?? '' } );
	const perTask = h( 'input', { class: 'field', type: 'number', step: '0.5', min: 0, value: hub.budget?.perTask ?? '' } );
	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'p', { class: 'note', text: `امروز (${ budget.day || '—' }): $${ budget.total ?? 0 }${ budget.usedRatio !== null && budget.usedRatio !== undefined ? ` — ${ Math.round( budget.usedRatio * 100 ) }٪ سقف` : '' }` } ),
			row( field( 'سقف روزانهٔ کل ($)', daily ), field( 'سقف هر مدیر ($)', perAdmin ), field( 'سقف هر کار ($)', perTask ) ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						await post( '/api/hub', {
							action: 'update',
							patch: {
								budget: {
									daily: daily.value === '' ? null : Number( daily.value ),
									perAdmin: perAdmin.value === '' ? null : Number( perAdmin.value ),
									perTask: perTask.value === '' ? null : Number( perTask.value ),
								},
							},
						} );
						toast( 'ذخیره شد.' );
						await again( box, page );
					},
				} ),
			] ),
		] )
	);

	// ——— یادگیری
	box.appendChild( section( 'چه یاد گرفته', 'امتیاز هر مدل در هر دسته، از نتیجهٔ واقعی همین نصب — نه از یک جدول ثابت.' ) );
	const learnBox = el( 'div', 'card-list' );
	const learnRows = Object.entries( learning );
	if ( ! learnRows.length ) {
		learnBox.appendChild( emptyBox( 'هنوز چیزی یاد نگرفته — چند نوبت کار لازم است.' ) );
	}
	for ( const [ cat, items ] of learnRows ) {
		learnBox.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: categories.find( ( c ) => c.id === cat )?.label || cat } ),
					h( 'p', { class: 'note', text: items.slice( 0, 4 ).map( ( i ) => `${ hub.models?.[ i.modelKey ]?.label || i.modelKey }: ${ i.score } (${ i.n } نوبت)` ).join( ' · ' ) } ),
				] ),
			] )
		);
	}
	box.appendChild( learnBox );

	// ——— عیب‌یاب
	box.appendChild(
		section( 'عیب‌یاب هاب', 'جدا از هاب تنظیم می‌شود — چیزی که قرار است هاب را تعمیر کند نباید از داخل خود هاب مسیر بگیرد.' )
	);
	const dEnabled = h( 'input', { type: 'checkbox', checked: hub.diagnoser?.enabled !== false } );
	const dConn = h( 'select', { class: 'field' } );
	dConn.appendChild( h( 'option', { value: '', text: '— بدون مدل عیب‌یاب (فقط پله‌های یک و دو) —' } ) );
	for ( const c of Object.values( hub.connections || {} ) ) {
		dConn.appendChild( h( 'option', { value: c.id, text: c.label } ) );
	}
	dConn.value = hub.diagnoser?.connectionId || '';
	const dModel = h( 'input', { class: 'field', dir: 'ltr', value: hub.diagnoser?.model || '', placeholder: 'gpt-4.1-mini' } );
	const dMin = h( 'input', { class: 'field', type: 'number', min: 1, value: hub.diagnoser?.minFailures ?? 2 } );
	const dPerHour = h( 'input', { class: 'field', type: 'number', min: 1, value: hub.diagnoser?.perSignaturePerHour ?? 1 } );
	const dBudget = h( 'input', { class: 'field', type: 'number', min: 0, value: hub.diagnoser?.dailyBudget ?? '' } );
	const dNet = h( 'input', { type: 'checkbox', checked: Boolean( hub.diagnoser?.internet ) } );
	const dPromote = h( 'input', { type: 'checkbox', checked: Boolean( hub.diagnoser?.autoPromote ) } );

	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'label', { class: 'check' }, [ dEnabled, h( 'span', { text: 'عیب‌یاب روشن باشد' } ) ] ),
			field( 'اتصال عیب‌یاب', dConn ),
			field( 'مدل عیب‌یاب', dModel, 'یک مدل کوچک و ارزان کافی است؛ کارش خواندن متن خطا و پیشنهاد یک وصلهٔ ساختاریافته است.' ),
			row( field( 'حداقل شکست هم‌امضا', dMin ), field( 'سقف تماس هر امضا در ساعت', dPerHour ), field( 'سقف تماس روزانه', dBudget ) ),
			h( 'label', { class: 'check' }, [ dNet, h( 'span', { text: 'اجازهٔ جستجوی اینترنتی — فقط متن خطای پاک‌سازی‌شده بیرون می‌رود' } ) ] ),
			h( 'label', { class: 'check' }, [ dPromote, h( 'span', { text: 'وصله‌های موفق بدون تأیید من ماندگار شوند' } ) ] ),
			h( 'p', { class: 'note', text: `امروز ${ diag.spentToday || 0 } تماس عیب‌یابی · ${ diag.hasModel ? 'مدل تنظیم شده' : 'بدون مدل' }` } ),
			h( 'div', { class: 'modal-actions' }, [
				h( 'span', { class: 'grow' } ),
				h( 'button', {
					class: 'btn solid',
					text: 'ذخیره',
					onClick: async () => {
						await post( '/api/hub', {
							action: 'update',
							patch: {
								diagnoser: {
									enabled: dEnabled.checked,
									connectionId: dConn.value,
									model: dModel.value.trim(),
									minFailures: Number( dMin.value ) || 2,
									perSignaturePerHour: Number( dPerHour.value ) || 1,
									dailyBudget: dBudget.value === '' ? null : Number( dBudget.value ),
									internet: dNet.checked,
									autoPromote: dPromote.checked,
								},
							},
						} );
						toast( 'ذخیره شد.' );
						await again( box, page );
					},
				} ),
			] ),
		] )
	);

	// ——— دفتر راه‌حل‌ها
	box.appendChild( section( 'دفتر راه‌حل‌ها', 'هرچه هوشا یاد گرفته، با تاریخ و شمار موفقیت. هر ردیف با یک دکمه پاک می‌شود.' ) );
	const ledgerBox = el( 'div', 'card-list' );
	if ( ! ledger.length ) {
		ledgerBox.appendChild( emptyBox( 'دفتر خالی است — یعنی هنوز خطایی نبوده که راه‌حلش آزموده شده باشد.' ) );
	}
	for ( const e of ledger ) {
		ledgerBox.appendChild(
			h( 'div', { class: 'item' }, [
				h( 'div', { class: 'item-main' }, [
					h( 'b', { text: e.why || 'وصلهٔ ثبت‌شده' } ),
					h( 'p', { class: 'mono note', text: e.signature } ),
					h( 'p', { class: 'mono note', text: ( e.patches || [] ).map( ( p ) => p.op ).join( ' + ' ) } ),
					h( 'p', { class: 'note', text: `از ${ String( e.discovered ).slice( 0, 10 ) } · ${ e.ok } بار جواب داد · منبع: ${ e.origin === 'model' ? 'مدل' : 'قاعده' }` } ),
				] ),
				h( 'span', { class: `tag ${ e.state === 'permanent' ? 'ok' : '' }`, text: e.state === 'permanent' ? 'دائمی' : 'موقت' } ),
				e.state !== 'permanent'
					? h( 'button', {
							class: 'btn outline',
							title: 'وصله روی خود اتصال می‌نشیند و دفعهٔ بعد پیش از اولین تلاش اعمال می‌شود.',
							text: 'ماندگار کن',
							onClick: async () => {
								await post( '/api/hub', { action: 'promote-patch', signature: e.signature } );
								await again( box, page );
							},
					  } )
					: null,
				h( 'button', {
					class: 'btn quiet danger',
					text: 'فراموش کن',
					onClick: async () => {
						await post( '/api/hub', { action: 'forget-patch', signature: e.signature } );
						await again( box, page );
					},
				} ),
			] )
		);
	}
	box.appendChild( ledgerBox );

	// ——— دفتر رویداد عیب‌یاب
	if ( ( diag.journal || [] ).length ) {
		box.appendChild( section( 'آخرین کارهای عیب‌یاب', '' ) );
		box.appendChild(
			h( 'div', { class: 'card-list compact' }, diag.journal.map( ( j ) =>
				h( 'div', { class: 'item' }, [
					h( 'div', { class: 'item-main' }, [
						h( 'p', { class: 'mono note', text: `${ String( j.at ).slice( 11, 19 ) } · ${ j.step }` } ),
						j.why ? h( 'p', { class: 'note', text: j.why } ) : null,
					] ),
				] )
			) )
		);
	}

	// ——— کش
	box.appendChild(
		h( 'div', { class: 'form-card' }, [
			h( 'h4', { text: 'کش پاسخ' } ),
			h( 'p', { class: 'note', text: `${ snap?.cache?.size || 0 } پاسخ در کش · ${ snap?.cache?.hits || 0 } اصابت · ${ snap?.cache?.misses || 0 } خطا` } ),
			h( 'p', { class: 'note', text: 'پاسخی که فراخوانی ابزار دارد کش نمی‌شود — چون اجرای دوبارهٔ ابزار، دنیای بیرون را عوض می‌کند.' } ),
			row( h( 'button', { class: 'btn outline', text: 'خالی کردن کش', onClick: async () => { await post( '/api/hub', { action: 'clear-cache' } ); toast( 'کش خالی شد.' ); await again( box, page ); } } ) ),
		] )
	);
}
