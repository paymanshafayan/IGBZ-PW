/**
 * ابزارهای هستهٔ هوشا — همان مجموعه‌ای که یک عامل کدنویس لازم دارد.
 *
 * هیچ‌کدام حذف نشده‌اند: خواندن، نوشتن، ویرایش، شل، جستجو، وب. محدودسازی کارِ لایهٔ
 * «مجوز» است، نه کارِ اینجا (تصمیم کارفرما: توانایی کامل بماند، دسترسی سیاست‌گذاری شود).
 *
 * دو قاعدهٔ ثابت:
 *   ۱) هر ابزار یک JSON Schema دقیق دارد — مدل هرچه دقیق‌تر بداند، کمتر اشتباه می‌کند.
 *   ۲) مسیرها همیشه داخل «پوشهٔ کاری» محدود می‌شوند مگر اینکه صریحاً مسیر دیگری اضافه شود.
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import { spawn } from 'node:child_process';

const MAX_READ_BYTES = 400 * 1024;
const MAX_OUTPUT_CHARS = 30_000;

/**
 * @typedef {Object} ToolContext
 * @property {string} workspace
 * @property {(text:string)=>void} [log]
 */

/**
 * مسیر را داخل پوشهٔ کاری نگه می‌دارد.
 * @param {ToolContext} ctx
 * @param {string} p
 */
function resolveInside( ctx, p ) {
	const target = path.resolve( ctx.workspace, p || '.' );
	const root = path.resolve( ctx.workspace );
	if ( target !== root && ! target.startsWith( root + path.sep ) ) {
		throw new Error( `مسیر بیرون از پوشهٔ کاری است: ${ p }` );
	}
	return target;
}

/** @param {string} s */
function clip( s ) {
	if ( s.length <= MAX_OUTPUT_CHARS ) {
		return s;
	}
	return s.slice( 0, MAX_OUTPUT_CHARS ) + `\n… (${ s.length - MAX_OUTPUT_CHARS } نویسهٔ دیگر بریده شد)`;
}

/** تبدیل الگوی glob به RegExp — پشتیبانی از **، * و ? */
function globToRegExp( pattern ) {
	let re = '';
	for ( let i = 0; i < pattern.length; i++ ) {
		const c = pattern[ i ];
		if ( c === '*' ) {
			if ( pattern[ i + 1 ] === '*' ) {
				re += '.*';
				i++;
				if ( pattern[ i + 1 ] === '/' ) {
					i++;
				}
			} else {
				re += '[^/]*';
			}
		} else if ( c === '?' ) {
			re += '[^/]';
		} else if ( '\\^$.|+()[]{}'.includes( c ) ) {
			re += '\\' + c;
		} else {
			re += c;
		}
	}
	return new RegExp( `^${ re }$` );
}

const SKIP_DIRS = new Set( [ 'node_modules', '.git', 'dist', 'build', '.next', 'vendor', '__pycache__', '.venv' ] );

/**
 * @param {string} dir
 * @param {string} root
 * @param {number} depth
 * @param {string[]} out
 */
async function walk( dir, root, depth, out, limit = 5000 ) {
	if ( out.length >= limit || depth > 12 ) {
		return;
	}
	let entries;
	try {
		entries = await fs.readdir( dir, { withFileTypes: true } );
	} catch {
		return;
	}
	for ( const e of entries ) {
		if ( out.length >= limit ) {
			return;
		}
		if ( e.name.startsWith( '.' ) && e.name !== '.env.example' && depth === 0 ) {
			// پوشه‌های مخفی ریشه را رد می‌کنیم مگر خواسته شود
		}
		const full = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			if ( SKIP_DIRS.has( e.name ) ) {
				continue;
			}
			await walk( full, root, depth + 1, out, limit );
		} else if ( e.isFile() ) {
			out.push( path.relative( root, full ) );
		}
	}
}

/** @type {Record<string, {spec: import('./providers/types.js').ToolSpec, risk:'read'|'write'|'exec'|'network', run:(input:any,ctx:ToolContext)=>Promise<string>}>} */
export const TOOLS = {
	list_dir: {
		risk: 'read',
		spec: {
			name: 'list_dir',
			description: 'فهرست فایل‌ها و پوشه‌های یک مسیر را برمی‌گرداند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'مسیر نسبی؛ پیش‌فرض ریشهٔ پوشهٔ کاری' },
				},
			},
		},
		async run( input, ctx ) {
			const dir = resolveInside( ctx, input.path || '.' );
			const entries = await fs.readdir( dir, { withFileTypes: true } );
			if ( ! entries.length ) {
				return '(خالی)';
			}
			const lines = entries
				.sort( ( a, b ) => Number( b.isDirectory() ) - Number( a.isDirectory() ) || a.name.localeCompare( b.name ) )
				.map( ( e ) => ( e.isDirectory() ? `${ e.name }/` : e.name ) );
			return clip( lines.join( '\n' ) );
		},
	},

	read_file: {
		risk: 'read',
		spec: {
			name: 'read_file',
			description: 'محتوای یک فایل متنی را می‌خواند. خروجی شماره‌گذاری‌شده است.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string', description: 'مسیر فایل' },
					offset: { type: 'integer', description: 'از کدام خط شروع شود (۱ به بالا)' },
					limit: { type: 'integer', description: 'حداکثر چند خط' },
				},
				required: [ 'path' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			const stat = await fs.stat( file );
			if ( stat.size > MAX_READ_BYTES ) {
				return `فایل بزرگ‌تر از حد مجاز است (${ stat.size } بایت). با offset و limit بخوان.`;
			}
			const text = await fs.readFile( file, 'utf8' );
			const lines = text.split( '\n' );
			const start = Math.max( 0, ( input.offset ? input.offset - 1 : 0 ) );
			const end = input.limit ? start + input.limit : lines.length;
			const body = lines
				.slice( start, end )
				.map( ( l, i ) => `${ String( start + i + 1 ).padStart( 5 ) }→${ l }` )
				.join( '\n' );
			return clip( body || '(فایل خالی است)' );
		},
	},

	write_file: {
		risk: 'write',
		spec: {
			name: 'write_file',
			description: 'یک فایل را می‌سازد یا کاملاً بازنویسی می‌کند.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string' },
					content: { type: 'string' },
				},
				required: [ 'path', 'content' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			await fs.mkdir( path.dirname( file ), { recursive: true } );
			const existed = await fs
				.access( file )
				.then( () => true )
				.catch( () => false );
			await fs.writeFile( file, String( input.content ?? '' ), 'utf8' );
			const bytes = Buffer.byteLength( String( input.content ?? '' ) );
			return `${ existed ? 'بازنویسی شد' : 'ساخته شد' }: ${ path.relative( ctx.workspace, file ) } (${ bytes } بایت)`;
		},
	},

	edit_file: {
		risk: 'write',
		spec: {
			name: 'edit_file',
			description:
				'جایگزینی دقیق یک رشته در یک فایل. رشتهٔ قدیمی باید دقیقاً یکتا باشد مگر replace_all بدهی.',
			parameters: {
				type: 'object',
				properties: {
					path: { type: 'string' },
					old_string: { type: 'string' },
					new_string: { type: 'string' },
					replace_all: { type: 'boolean' },
				},
				required: [ 'path', 'old_string', 'new_string' ],
			},
		},
		async run( input, ctx ) {
			const file = resolveInside( ctx, input.path );
			const text = await fs.readFile( file, 'utf8' );
			const count = text.split( input.old_string ).length - 1;
			if ( count === 0 ) {
				throw new Error( 'رشتهٔ موردنظر در فایل پیدا نشد.' );
			}
			if ( count > 1 && ! input.replace_all ) {
				throw new Error( `رشته ${ count } بار تکرار شده؛ یا متن بیشتری بده یا replace_all را true کن.` );
			}
			const out = input.replace_all
				? text.split( input.old_string ).join( input.new_string )
				: text.replace( input.old_string, input.new_string );
			await fs.writeFile( file, out, 'utf8' );
			return `ویرایش شد: ${ path.relative( ctx.workspace, file ) } (${ input.replace_all ? count : 1 } جایگزینی)`;
		},
	},

	glob: {
		risk: 'read',
		spec: {
			name: 'glob',
			description: 'پیداکردن فایل‌ها با الگو، مثل src/**/*.js',
			parameters: {
				type: 'object',
				properties: {
					pattern: { type: 'string' },
					path: { type: 'string', description: 'پوشهٔ شروع' },
				},
				required: [ 'pattern' ],
			},
		},
		async run( input, ctx ) {
			const root = resolveInside( ctx, input.path || '.' );
			/** @type {string[]} */
			const files = [];
			await walk( root, root, 0, files );
			const re = globToRegExp( input.pattern );
			const hits = files.filter( ( f ) => re.test( f ) || re.test( path.basename( f ) ) ).slice( 0, 300 );
			return hits.length ? clip( hits.join( '\n' ) ) : '(چیزی پیدا نشد)';
		},
	},

	grep: {
		risk: 'read',
		spec: {
			name: 'grep',
			description: 'جستجوی یک عبارت (regex) در محتوای فایل‌ها.',
			parameters: {
				type: 'object',
				properties: {
					pattern: { type: 'string' },
					path: { type: 'string' },
					glob: { type: 'string', description: 'فیلتر نام فایل، مثل *.php' },
					max_results: { type: 'integer' },
				},
				required: [ 'pattern' ],
			},
		},
		async run( input, ctx ) {
			const root = resolveInside( ctx, input.path || '.' );
			/** @type {string[]} */
			const files = [];
			await walk( root, root, 0, files );
			const filter = input.glob ? globToRegExp( input.glob ) : null;
			let re;
			try {
				re = new RegExp( input.pattern, 'i' );
			} catch {
				throw new Error( 'الگوی regex معتبر نیست.' );
			}
			const max = Math.min( input.max_results || 100, 500 );
			/** @type {string[]} */
			const out = [];
			for ( const rel of files ) {
				if ( filter && ! filter.test( rel ) && ! filter.test( path.basename( rel ) ) ) {
					continue;
				}
				if ( out.length >= max ) {
					break;
				}
				let text;
				try {
					const st = await fs.stat( path.join( root, rel ) );
					if ( st.size > MAX_READ_BYTES ) {
						continue;
					}
					text = await fs.readFile( path.join( root, rel ), 'utf8' );
				} catch {
					continue;
				}
				const lines = text.split( '\n' );
				for ( let i = 0; i < lines.length && out.length < max; i++ ) {
					if ( re.test( lines[ i ] ) ) {
						out.push( `${ rel }:${ i + 1 }: ${ lines[ i ].trim().slice( 0, 200 ) }` );
					}
				}
			}
			return out.length ? clip( out.join( '\n' ) ) : '(چیزی پیدا نشد)';
		},
	},

	bash: {
		risk: 'exec',
		spec: {
			name: 'bash',
			description: 'اجرای یک فرمان در پوستهٔ سیستم، داخل پوشهٔ کاری.',
			parameters: {
				type: 'object',
				properties: {
					command: { type: 'string' },
					timeout_ms: { type: 'integer', description: 'پیش‌فرض ۶۰۰۰۰' },
				},
				required: [ 'command' ],
			},
		},
		run( input, ctx ) {
			return new Promise( ( resolve, reject ) => {
				const timeout = Math.min( input.timeout_ms || 60_000, 600_000 );
				const child = spawn( input.command, {
					shell: true,
					cwd: ctx.workspace,
					env: process.env,
				} );

				let out = '';
				let err = '';
				const timer = setTimeout( () => {
					child.kill( 'SIGKILL' );
					reject( new Error( `فرمان بعد از ${ timeout } میلی‌ثانیه متوقف شد.` ) );
				}, timeout );

				child.stdout.on( 'data', ( d ) => {
					out += d.toString();
				} );
				child.stderr.on( 'data', ( d ) => {
					err += d.toString();
				} );
				child.on( 'error', ( e ) => {
					clearTimeout( timer );
					reject( e );
				} );
				child.on( 'close', ( code ) => {
					clearTimeout( timer );
					const body = [ out.trim(), err.trim() ].filter( Boolean ).join( '\n--- stderr ---\n' );
					resolve( clip( `exit=${ code }\n${ body || '(بدون خروجی)' }` ) );
				} );
			} );
		},
	},

	web_fetch: {
		risk: 'network',
		spec: {
			name: 'web_fetch',
			description: 'گرفتن محتوای یک آدرس اینترنتی به‌صورت متن.',
			parameters: {
				type: 'object',
				properties: { url: { type: 'string' } },
				required: [ 'url' ],
			},
		},
		async run( input ) {
			const res = await fetch( input.url, { headers: { 'User-Agent': 'Hoosha/0.1' } } );
			const text = await res.text();
			const stripped = text
				.replace( /<script[\s\S]*?<\/script>/gi, '' )
				.replace( /<style[\s\S]*?<\/style>/gi, '' )
				.replace( /<[^>]+>/g, ' ' )
				.replace( /\s+/g, ' ' )
				.trim();
			return clip( `HTTP ${ res.status }\n\n${ stripped }` );
		},
	},

	todo_write: {
		risk: 'read',
		spec: {
			name: 'todo_write',
			description: 'ثبت یا به‌روزرسانی فهرست کارهای این نشست، تا کار چندمرحله‌ای گم نشود.',
			parameters: {
				type: 'object',
				properties: {
					todos: {
						type: 'array',
						items: {
							type: 'object',
							properties: {
								content: { type: 'string' },
								status: { type: 'string', enum: [ 'pending', 'in_progress', 'completed' ] },
							},
							required: [ 'content', 'status' ],
						},
					},
				},
				required: [ 'todos' ],
			},
		},
		async run( input, ctx ) {
			const todos = Array.isArray( input.todos ) ? input.todos : [];
			ctx.log?.( JSON.stringify( { todos } ) );
			const icon = { pending: '☐', in_progress: '▸', completed: '☑' };
			return todos.map( ( t ) => `${ icon[ t.status ] || '☐' } ${ t.content }` ).join( '\n' ) || '(خالی)';
		},
	},
};

/** @returns {import('./providers/types.js').ToolSpec[]} */
export function toolSpecs() {
	return Object.values( TOOLS ).map( ( t ) => t.spec );
}
