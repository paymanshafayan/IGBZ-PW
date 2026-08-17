/**
 * ریل سمت چپ: کارهای جاری، شل‌های پس‌زمینه، چک‌پوینت‌ها و تغییرات گیت.
 *
 * این‌ها در Claude Code جاهای مختلفی‌اند (پنل todo، Ctrl+B برای شل‌ها، /rewind). یک‌جا
 * جمعشان کردیم چون در وب‌اپ، فضای کناری هست و پنهان‌کردنشان پشت میان‌بر، یعنی کسی
 * پیدایشان نمی‌کند.
 */

import { $, h, timeAgo, toast } from './lib/dom.js';
import { api, post, getState } from './lib/api.js';

let tab = 'todos';
let onRewind = () => {};

/** @param {{onRewind:(id:string)=>void}} opts */
export function initRail( opts ) {
	onRewind = opts.onRewind;

	for ( const b of document.querySelectorAll( '.rail-tab' ) ) {
		b.onclick = () => {
			tab = b.dataset.tab;
			paintRail( getState() );
		};
	}

}

/** @param {any} s */
export async function paintRail( s ) {
	if ( ! s ) {
		return;
	}

	for ( const b of document.querySelectorAll( '.rail-tab' ) ) {
		b.classList.toggle( 'active', b.dataset.tab === tab );
	}

	const counts = {
		todos: ( s.todos || [] ).filter( ( t ) => t.status !== 'completed' ).length,
		shells: ( s.shells || [] ).filter( ( x ) => x.status === 'running' ).length,
		checkpoints: ( s.checkpoints || [] ).length,
		git: 0,
	};
	for ( const [ id, n ] of Object.entries( counts ) ) {
		const badge = document.querySelector( `.rail-tab[data-tab="${ id }"] .rail-count` );
		if ( badge ) {
			badge.textContent = n ? String( n ) : '';
		}
	}

	const box = $( '#rail-body' );
	box.replaceChildren();

	if ( tab === 'todos' ) {
		if ( ! ( s.todos || [] ).length ) {
			box.appendChild( h( 'div', { class: 'empty small', text: 'فهرست کاری ثبت نشده. وقتی کار چندمرحله‌ای بدهی، اینجا پر می‌شود.' } ) );
			return;
		}
		for ( const t of s.todos ) {
			box.appendChild(
				h( 'div', { class: `todo ${ t.status === 'completed' ? 'done' : t.status === 'in_progress' ? 'doing' : '' }` }, [
					h( 'span', { class: 'box', text: t.status === 'completed' ? '☑' : t.status === 'in_progress' ? '▸' : '☐' } ),
					h( 'span', { text: t.content } ),
				] )
			);
		}
		return;
	}

	if ( tab === 'shells' ) {
		if ( ! ( s.shells || [] ).length ) {
			box.appendChild( h( 'div', { class: 'empty small', text: 'شل پس‌زمینه‌ای در کار نیست.' } ) );
			return;
		}
		for ( const sh of s.shells ) {
			const out = h( 'pre', { class: 'mono terminal small', hidden: true } );
			box.appendChild(
				h( 'div', { class: 'rail-item' }, [
					h( 'div', { class: 'rail-item-head' }, [
						h( 'span', { class: `dot ${ sh.status === 'running' ? 'run' : sh.exitCode ? 'err' : 'ok' }`, text: '•' } ),
						h( 'b', { class: 'mono', text: sh.id } ),
						h( 'span', { class: 'note', text: sh.status } ),
					] ),
					h( 'p', { class: 'mono small', text: sh.command } ),
					h( 'div', { class: 'row' }, [
						h( 'button', {
							class: 'pill tiny',
							text: 'خروجی',
							onClick: async () => {
								const r = await post( '/api/shells', { action: 'read', id: sh.id } );
								out.textContent = r.text || '(خالی)';
								out.hidden = false;
							},
						} ),
						sh.status === 'running'
							? h( 'button', {
									class: 'pill tiny danger',
									text: 'توقف',
									onClick: async () => {
										await post( '/api/shells', { action: 'kill', id: sh.id } );
										toast( 'متوقف شد.' );
									},
							  } )
							: null,
					] ),
					out,
				] )
			);
		}
		return;
	}

	if ( tab === 'checkpoints' ) {
		if ( ! ( s.checkpoints || [] ).length ) {
			box.appendChild( h( 'div', { class: 'empty small', text: 'چک‌پوینتی نیست. هر پیام تو یک چک‌پوینت می‌سازد.' } ) );
			return;
		}
		for ( const c of [ ...s.checkpoints ].reverse() ) {
			box.appendChild(
				h( 'div', { class: 'rail-item' }, [
					h( 'b', { text: c.label || 'بدون عنوان' } ),
					h( 'p', { class: 'note', text: `${ timeAgo( c.at ) } · ${ c.fileCount } فایل` } ),
					h( 'button', { class: 'pill tiny', text: 'بازگشت به اینجا', onClick: () => onRewind( c.id ) } ),
				] )
			);
		}
		return;
	}

	if ( tab === 'git' ) {
		const out = await api( '/api/git' );
		if ( ! out.git ) {
			box.appendChild( h( 'div', { class: 'empty small', text: 'این پوشه مخزن گیت نیست.' } ) );
			return;
		}
		box.appendChild( h( 'div', { class: 'rail-item' }, [ h( 'b', { text: `شاخه: ${ out.git.branch }` } ) ] ) );
		if ( ! out.git.files.length ) {
			box.appendChild( h( 'div', { class: 'empty small', text: 'چیزی تغییر نکرده.' } ) );
			return;
		}
		for ( const f of out.git.files ) {
			box.appendChild(
				h( 'div', { class: 'git-row' }, [
					h( 'span', { class: `git-state s-${ f.state || 'x' }`, text: f.state || '?' } ),
					h( 'span', { class: 'mono small', text: f.path } ),
				] )
			);
		}
	}
}
