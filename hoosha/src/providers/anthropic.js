/**
 * آداپتور سازگار با Anthropic: POST {baseUrl}/v1/messages با stream=true.
 *
 * تفاوت‌های مهم با OpenAI که اینجا مدیریت می‌شوند:
 *   - system یک پارامتر جداست، نه یک پیام.
 *   - نتیجهٔ ابزار، یک پیام `user` با بلوک `tool_result` است.
 *   - max_tokens اجباری است.
 */

import { sseLines } from './sse.js';

const ANTHROPIC_VERSION = '2023-06-01';

/** @param {import('./types.js').ProviderConfig} cfg */
export function createAnthropicProvider( cfg ) {
	const base = ( cfg.baseUrl || 'https://api.anthropic.com' ).replace( /\/+$/, '' );

	/** @type {Record<string,string>} */
	const headers = {
		'Content-Type': 'application/json',
		'anthropic-version': ANTHROPIC_VERSION,
	};
	if ( cfg.apiKey ) {
		headers['x-api-key'] = cfg.apiKey;
	}

	return {
		id: cfg.providerId,
		kind: /** @type {const} */ ( 'anthropic' ),

		async listModels() {
			const res = await fetch( `${ base }/v1/models`, { headers } );
			if ( ! res.ok ) {
				throw new Error( `فهرست مدل‌ها گرفته نشد (${ res.status })` );
			}
			const body = await res.json();
			const rows = Array.isArray( body?.data ) ? body.data : [];
			return rows.map( ( m ) => String( m.id || '' ) ).filter( Boolean ).sort();
		},

		/**
		 * @param {import('./types.js').ChatRequest} req
		 * @returns {AsyncGenerator<import('./types.js').StreamEvent>}
		 */
		async *stream( req ) {
			/** @type {any[]} */
			const messages = [];

			for ( const m of req.messages ) {
				if ( m.role === 'tool' ) {
					const last = messages[ messages.length - 1 ];
					const block = {
						type: 'tool_result',
						tool_use_id: m.toolCallId,
						content: typeof m.content === 'string' ? m.content : JSON.stringify( m.content ),
					};
					// نتیجه‌های پشت‌سرهم در یک پیام user جمع می‌شوند — قرارداد خود Anthropic.
					if ( last && last.role === 'user' && Array.isArray( last.content ) ) {
						last.content.push( block );
					} else {
						messages.push( { role: 'user', content: [ block ] } );
					}
					continue;
				}

				if ( m.role === 'assistant' && m.toolCalls?.length ) {
					/** @type {any[]} */
					const content = [];
					if ( m.content ) {
						content.push( { type: 'text', text: m.content } );
					}
					for ( const c of m.toolCalls ) {
						content.push( { type: 'tool_use', id: c.id, name: c.name, input: c.input ?? {} } );
					}
					messages.push( { role: 'assistant', content } );
					continue;
				}

				messages.push( { role: m.role, content: toAnthropicContent( m.content ) } );
			}

			const payload = {
				model: req.model,
				max_tokens: req.maxTokens || 8192,
				stream: true,
				...( req.system ? { system: req.system } : {} ),
				...( req.temperature !== undefined ? { temperature: req.temperature } : {} ),
				messages,
				...( req.tools?.length
					? {
							tools: req.tools.map( ( t ) => ( {
								name: t.name,
								description: t.description,
								input_schema: t.parameters,
							} ) ),
					  }
					: {} ),
			};

			const res = await fetch( `${ base }/v1/messages`, {
				method: 'POST',
				headers,
				body: JSON.stringify( payload ),
				signal: req.signal,
			} );

			if ( ! res.ok || ! res.body ) {
				const text = await res.text().catch( () => '' );
				yield { type: 'error', error: `پاسخ ${ res.status } از پرووایدر: ${ text.slice( 0, 500 ) }` };
				return;
			}

			/** @type {Map<number,{id:string,name:string,args:string}>} */
			const pending = new Map();
			let usage = null;

			for await ( const data of sseLines( res.body ) ) {
				let ev;
				try {
					ev = JSON.parse( data );
				} catch {
					continue;
				}

				if ( ev.type === 'content_block_start' && ev.content_block?.type === 'tool_use' ) {
					pending.set( ev.index, { id: ev.content_block.id, name: ev.content_block.name, args: '' } );
				} else if ( ev.type === 'content_block_delta' ) {
					if ( ev.delta?.type === 'text_delta' && ev.delta.text ) {
						yield { type: 'text', text: ev.delta.text };
					} else if ( ev.delta?.type === 'thinking_delta' && ev.delta.thinking ) {
						yield { type: 'thinking', text: ev.delta.thinking };
					} else if ( ev.delta?.type === 'input_json_delta' ) {
						const cur = pending.get( ev.index );
						if ( cur ) {
							cur.args += ev.delta.partial_json || '';
						}
					}
				} else if ( ev.type === 'message_delta' && ev.usage ) {
					usage = { ...( usage || {} ), output_tokens: ev.usage.output_tokens };
				} else if ( ev.type === 'message_start' && ev.message?.usage ) {
					usage = { ...( usage || {} ), input_tokens: ev.message.usage.input_tokens };
				} else if ( ev.type === 'error' ) {
					yield { type: 'error', error: ev.error?.message || 'خطای نامشخص از پرووایدر' };
					return;
				}
			}

			for ( const [ , call ] of [ ...pending.entries() ].sort( ( a, b ) => a[ 0 ] - b[ 0 ] ) ) {
				let input = {};
				try {
					input = call.args ? JSON.parse( call.args ) : {};
				} catch {
					input = { __raw: call.args };
				}
				yield { type: 'tool_call', id: call.id, name: call.name, input };
			}

			if ( usage ) {
				yield {
					type: 'usage',
					inputTokens: usage.input_tokens ?? 0,
					outputTokens: usage.output_tokens ?? 0,
				};
			}
		},
	};
}

/**
 * محتوای پیام کاربر به شکل Anthropic.
 * @param {any} content
 */
function toAnthropicContent( content ) {
	if ( ! Array.isArray( content ) ) {
		return content;
	}
	return content.map( ( part ) =>
		part.type === 'image'
			? { type: 'image', source: { type: 'base64', media_type: part.mediaType, data: part.data } }
			: { type: 'text', text: part.text || '' }
	);
}
