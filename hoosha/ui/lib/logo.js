/**
 * نشان هوشا، به‌شکل تابع — تا هرجای رابط لازم شد بدون کپی‌کردن SVG استفاده شود.
 *
 * دو حالت دارد: ساکن (لوگو) و متحرک (وقتی مدل مشغول است). حالت متحرک با SMIL نوشته شده،
 * پس اگر کاربر «کاهش حرکت» را در سیستمش روشن کرده باشد، در CSS خاموشش می‌کنیم.
 */

const GRAD = ( id ) => `
	<defs>
		<linearGradient id="${ id }" x1="6" y1="4" x2="26" y2="28" gradientUnits="userSpaceOnUse">
			<stop offset="0" stop-color="#e8895f" />
			<stop offset="1" stop-color="#c2593a" />
		</linearGradient>
	</defs>`;

const RAYS = `
	<rect x="14.7" y="1.6" width="2.6" height="11" rx="1.3" />
	<rect x="14.7" y="19.4" width="2.6" height="11" rx="1.3" />
	<rect x="1.6" y="14.7" width="11" height="2.6" rx="1.3" />
	<rect x="19.4" y="14.7" width="11" height="2.6" rx="1.3" />
	<rect x="14.9" y="5.6" width="2.2" height="8" rx="1.1" transform="rotate(45 16 16)" />
	<rect x="14.9" y="18.4" width="2.2" height="8" rx="1.1" transform="rotate(45 16 16)" />
	<rect x="14.9" y="5.6" width="2.2" height="8" rx="1.1" transform="rotate(-45 16 16)" />
	<rect x="14.9" y="18.4" width="2.2" height="8" rx="1.1" transform="rotate(-45 16 16)" />
	<circle cx="16" cy="16" r="3.1" />`;

let seq = 0;

/**
 * نشان ساکن.
 * @param {number} size
 * @param {string} [cls]
 */
export function logoSvg( size = 22, cls = 'logo' ) {
	const id = `hg${ ++seq }`;
	return `<svg class="${ cls }" viewBox="0 0 32 32" width="${ size }" height="${ size }" aria-hidden="true">${ GRAD( id ) }<g fill="url(#${ id })">${ RAYS }</g></svg>`;
}

/**
 * نشان متحرک — چرخش آرام + روشن و خاموش‌شدن نوبتی پرتوها.
 * @param {number} size
 */
export function logoLiveSvg( size = 20 ) {
	const id = `hl${ ++seq }`;
	const pulse = ( begin, from = 1, to = 0.25 ) =>
		`<animate attributeName="opacity" values="${ from };${ to };${ from }" dur="1.2s" begin="${ begin }s" repeatCount="indefinite" />`;

	return `<svg class="logo live" viewBox="0 0 32 32" width="${ size }" height="${ size }" aria-hidden="true">${ GRAD( id ) }
		<g fill="url(#${ id })">
			<animateTransform attributeName="transform" attributeType="XML" type="rotate" from="0 16 16" to="360 16 16" dur="3s" repeatCount="indefinite" />
			<rect x="14.7" y="1.6" width="2.6" height="11" rx="1.3">${ pulse( 0 ) }</rect>
			<rect x="19.4" y="14.7" width="11" height="2.6" rx="1.3">${ pulse( 0.15 ) }</rect>
			<rect x="14.7" y="19.4" width="2.6" height="11" rx="1.3">${ pulse( 0.3 ) }</rect>
			<rect x="1.6" y="14.7" width="11" height="2.6" rx="1.3">${ pulse( 0.45 ) }</rect>
			<rect x="14.9" y="5.6" width="2.2" height="8" rx="1.1" transform="rotate(45 16 16)">${ pulse( 0.07, 0.9, 0.2 ) }</rect>
			<rect x="14.9" y="18.4" width="2.2" height="8" rx="1.1" transform="rotate(45 16 16)">${ pulse( 0.37, 0.9, 0.2 ) }</rect>
			<rect x="14.9" y="5.6" width="2.2" height="8" rx="1.1" transform="rotate(-45 16 16)">${ pulse( 0.52, 0.9, 0.2 ) }</rect>
			<rect x="14.9" y="18.4" width="2.2" height="8" rx="1.1" transform="rotate(-45 16 16)">${ pulse( 0.22, 0.9, 0.2 ) }</rect>
			<circle cx="16" cy="16" r="3.1"><animate attributeName="r" values="3.1;2.2;3.1" dur="1.2s" repeatCount="indefinite" /></circle>
		</g>
	</svg>`;
}
