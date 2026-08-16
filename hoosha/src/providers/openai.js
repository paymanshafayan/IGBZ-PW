/**
 * آداپتور سازگار با OpenAI: POST {baseUrl}/chat/completions با stream=true.
 *
 * همان قراردادی که تقریباً همهٔ سرویس‌دهنده‌ها (OpenRouter، DeepSeek، Groq، Ollama،
 * LM Studio، سرویس‌های ایرانی و…) پیاده کرده‌اند. هیچ SDK ای لازم نیست؛ fetch خود Node کافی است.
 */

import { sseLines } from './sse.js';

/** @param {import('./types.js').ProviderConfig} cfg */
export function createOpenAiProvider( cfg ) {
	const base = ( cfg.baseUrl || '' ).replace( /\/+$/, '' );

	/** @type {Record<string,string>} */
	const headers = {
		'Content-Type': 'application/json',
	};
	if ( cfg.apiKey ) {
		headers.Authorization = `Bearer ${ cfg.apiKey }`;
	}
	// OpenRouter این دو را برای شناسایی برنامه می‌خواهد (اختیاری ولی مؤدبانه).
	headers['HTTP-Referer'] = 'https://github.com/paymanshafayan/IGBZ-WP';
	headers['X-Title'] = 'Hoosha';

	return {
		id: cfg.providerId,
		kind: /** @type {const} */ ( 'openai' ),

		async listModels() {
			const res = await fetch( `${ base }/models`, { headers } );
			if ( ! res.ok ) {
				throw new Error( `فهرست مدل‌ها گرفته نشد (${ res.status })` );
			}
			const body = await res.json();
			const rows = Array.isArray( body?.data ) ? body.data : Array.isArray( body ) ? body : [];
			return rows.map( ( m ) => String( m.id || m.name || '' ) ).filter( Boolean ).sort();
		},

		/**
		 * @param {import('./types.js').ChatRequest} req
		 * @returns {AsyncGenerator<import('./types.js').StreamEvent>}
		 */
		async *stream( req ) {
			/** @type {any[]} */
			const messages = [];
			if ( req.system ) {
				messages.push( { role: 'system', content: req.system } );
			}
			for ( const m of req.messages ) {
				if ( m.role === 'tool' ) {
					messages.push( {
						role: 'tool',
						tool_call_id: m.toolCallId,
						content: typeof m.content === 'string' ? m.content : JSON.stringify( m.content ),
					} );
					continue;
				}
				if ( m.role === 'assistant' && m.toolCalls?.length ) {
					messages.push( {
						role: 'assistant',
						content: m.content || null,
						tool_calls: m.toolCalls.map( ( c ) => ( {
							id: c.id,
							type: 'function',
							function: { name: c.name, arguments: JSON.stringify( c.input ?? {} ) },
						} ) ),
					} );
					continue;
				}
				messages.push( { role: m.role, content: m.content } );
			}

			const payload = {
				model: req.model,
				messages,
				stream: true,
				...( req.temperature !== undefined ? { temperature: req.temperature } : {} ),
				...( req.maxTokens ? { max_tokens: req.maxTokens } : {} ),
				...( req.tools?.length
					? {
							tools: req.tools.map( ( t ) => ( {
								type: 'function',
								function: {
									name: t.name,
									description: t.description,
									parameters: t.parameters,
								},
							} ) ),
							tool_choice: 'auto',
					  }
					: {} ),
			};

			const res = await fetch( `${ base }/chat/completions`, {
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
				if ( data === '[DONE]' ) {
					break;
				}
				let chunk;
				try {
					chunk = JSON.parse( data );
				} catch {
					continue;
				}
				if ( chunk.usage ) {
					usage = chunk.usage;
				}
				const delta = chunk.choices?.[ 0 ]?.delta;
				if ( ! delta ) {
					continue;
				}
				if ( delta.content ) {
					yield { type: 'text', text: delta.content };
				}
				for ( const call of delta.tool_calls || [] ) {
					const idx = call.index ?? 0;
					const cur = pending.get( idx ) || { id: '', name: '', args: '' };
					if ( call.id ) {
						cur.id = call.id;
					}
					if ( call.function?.name ) {
						cur.name = call.function.name;
					}
					if ( call.function?.arguments ) {
						cur.args += call.function.arguments;
					}
					pending.set( idx, cur );
				}
			}

			for ( const [ , call ] of [ ...pending.entries() ].sort( ( a, b ) => a[ 0 ] - b[ 0 ] ) ) {
				if ( ! call.name ) {
					continue;
				}
				let input = {};
				try {
					input = call.args ? JSON.parse( call.args ) : {};
				} catch {
					input = { __raw: call.args };
				}
				yield {
					type: 'tool_call',
					id: call.id || `call_${ Math.random().toString( 36 ).slice( 2, 10 ) }`,
					name: call.name,
					input,
				};
			}

			if ( usage ) {
				yield {
					type: 'usage',
					inputTokens: usage.prompt_tokens ?? 0,
					outputTokens: usage.completion_tokens ?? 0,
				};
			}
		},
	};
}
