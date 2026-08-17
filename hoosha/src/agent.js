/**
 * حلقهٔ عامل هوشا.
 *
 * الگو همان چیزی است که در عمل جواب داده: مدل حرف می‌زند و ابزار می‌خواهد → هوک و دروازهٔ
 * مجوز → اجرای ابزار → نتیجه برمی‌گردد به مدل → تکرار تا وقتی مدل دیگر ابزاری نخواهد.
 *
 * چند تصمیم که عمدی‌اند:
 *   ۱) نتیجهٔ ابزار **همیشه** به مدل برمی‌گردد، حتی وقتی رد شده — وگرنه مدل نمی‌فهمد چرا
 *      کارش پیش نرفت و همان درخواست را تکرار می‌کند.
 *   ۲) سقف گام دارد. یک عامل بی‌سقف، یک قبض بی‌سقف است.
 *   ۳) رجیستری ابزار **پویاست** (تابع است، نه فهرست ثابت)، چون MCP و پلاگین‌ها وسط کار
 *      ابزار اضافه و کم می‌کنند.
 */

import { decide, describeCall } from './permissions.js';
import { shouldCompact, compact } from './subagent.js';

const DEFAULT_MAX_STEPS = 24;

export class Agent {
	/**
	 * @param {{
	 *   provider: any,
	 *   model: string,
	 *   workspace: string,
	 *   rules: {mode:string, allow?:string[], ask?:string[], deny?:string[]},
	 *   getTools: () => Record<string, any>,
	 *   systemPrompt?: string,
	 *   extraPrompt?: string,
	 *   maxSteps?: number,
	 *   hooks?: import('./hooks.js').HookRunner,
	 *   autoCompact?: boolean,
	 *   emit: (ev: any) => void,
	 * }} opts
	 */
	constructor( opts ) {
		this.provider = opts.provider;
		this.model = opts.model;
		this.workspace = opts.workspace;
		this.rules = opts.rules;
		this.getTools = opts.getTools;
		this.baseSystemPrompt = opts.systemPrompt || defaultSystemPrompt( opts.workspace );
		this.extraPrompt = opts.extraPrompt || '';
		this.maxSteps = opts.maxSteps || DEFAULT_MAX_STEPS;
		this.hooks = opts.hooks || null;
		this.autoCompact = opts.autoCompact !== false;
		this.emit = opts.emit;

		/** @type {import('./providers/types.js').Message[]} */
		this.messages = [];
		/** @type {Map<string,(d:'allow'|'deny')=>void>} */
		this.pending = new Map();
		this.busy = false;
		/** @type {AbortController|null} */
		this.controller = null;
		this.usage = { inputTokens: 0, outputTokens: 0 };
	}

	get systemPrompt() {
		return [ this.baseSystemPrompt, this.extraPrompt ].filter( Boolean ).join( '\n' );
	}

	/** پاسخ کاربر به یک دروازهٔ تأیید. */
	resolvePermission( id, decision ) {
		const fn = this.pending.get( id );
		if ( fn ) {
			this.pending.delete( id );
			fn( decision === 'allow' ? 'allow' : 'deny' );
			return true;
		}
		return false;
	}

	stop() {
		this.controller?.abort();
		for ( const [ id, fn ] of this.pending ) {
			this.pending.delete( id );
			fn( 'deny' );
		}
	}

	/** فشرده‌سازی دستی (دستور /compact). */
	async compactNow() {
		const before = this.messages.length;
		this.messages = await compact( {
			provider: this.provider,
			model: this.model,
			messages: this.messages,
		} );
		this.emit( { type: 'compacted', before, after: this.messages.length } );
		return { before, after: this.messages.length };
	}

	/** @param {string} text */
	async run( text ) {
		if ( this.busy ) {
			throw new Error( 'یک درخواست در حال اجراست.' );
		}
		this.busy = true;
		this.controller = new AbortController();

		try {
			if ( this.hooks ) {
				const res = await this.hooks.run( 'UserPromptSubmit', { prompt: text } );
				if ( res.blocked ) {
					this.emit( { type: 'notice', text: `هوک جلوی این پیام را گرفت: ${ res.reason }` } );
					return;
				}
				if ( res.context.length ) {
					text = `${ text }\n\n[کانتکست از هوک]\n${ res.context.join( '\n' ) }`;
				}
			}

			this.messages.push( { role: 'user', content: text } );
			this.emit( { type: 'user', text } );

			if ( this.autoCompact && shouldCompact( this.messages ) ) {
				this.emit( { type: 'notice', text: 'گفتگو طولانی شد؛ خلاصه‌اش می‌کنم.' } );
				await this.compactNow();
			}

			for ( let step = 0; step < this.maxSteps; step++ ) {
				const turn = await this.#oneTurn();
				if ( ! turn.toolCalls.length ) {
					break;
				}
				if ( step === this.maxSteps - 1 ) {
					this.emit( { type: 'notice', text: `به سقف ${ this.maxSteps } گام رسیدیم و متوقف شدم.` } );
				}
			}

			await this.hooks?.run( 'Stop', {} );
		} catch ( e ) {
			this.emit( { type: 'error', error: e?.message || String( e ) } );
		} finally {
			this.busy = false;
			this.controller = null;
			this.emit( { type: 'idle', usage: this.usage } );
		}
	}

	async #oneTurn() {
		this.emit( { type: 'assistant_start' } );

		let text = '';
		/** @type {import('./providers/types.js').ToolCall[]} */
		const toolCalls = [];

		const tools = this.getTools();
		const specs = Object.values( tools ).map( ( t ) => t.spec );

		const stream = this.provider.stream( {
			model: this.model,
			system: this.systemPrompt,
			messages: this.messages,
			tools: specs,
			signal: this.controller?.signal,
		} );

		for await ( const ev of stream ) {
			if ( ev.type === 'text' ) {
				text += ev.text;
				this.emit( { type: 'text', text: ev.text } );
			} else if ( ev.type === 'tool_call' ) {
				toolCalls.push( { id: ev.id, name: ev.name, input: ev.input } );
			} else if ( ev.type === 'usage' ) {
				this.usage.inputTokens += ev.inputTokens || 0;
				this.usage.outputTokens += ev.outputTokens || 0;
			} else if ( ev.type === 'error' ) {
				throw new Error( ev.error );
			}
		}

		this.messages.push( {
			role: 'assistant',
			content: text,
			...( toolCalls.length ? { toolCalls } : {} ),
		} );
		this.emit( { type: 'assistant_end', text, toolCalls } );

		for ( const call of toolCalls ) {
			const result = await this.#runTool( call, tools );
			this.messages.push( { role: 'tool', toolCallId: call.id, content: result } );
		}

		return { text, toolCalls };
	}

	/**
	 * @param {import('./providers/types.js').ToolCall} call
	 * @param {Record<string,any>} tools
	 */
	async #runTool( call, tools ) {
		const tool = tools[ call.name ];
		const summary = describeCall( call.name, call.input );

		if ( ! tool ) {
			this.emit( { type: 'tool_error', id: call.id, name: call.name, error: 'ابزار ناشناخته' } );
			return `ابزار «${ call.name }» وجود ندارد. ابزارهای موجود: ${ Object.keys( tools ).join( ', ' ) }`;
		}

		const verdict = decide( call.name, call.input, this.rules, tools );

		if ( verdict.decision === 'deny' ) {
			this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: verdict.reason } );
			return `اجرا نشد. ${ verdict.reason || 'اجازه داده نشد.' }`;
		}

		// هوک PreToolUse حتی جلوی ابزار «مجاز» را هم می‌تواند بگیرد — این نقطه، جای
		// سیاست‌های سازمانی است.
		if ( this.hooks ) {
			const res = await this.hooks.run( 'PreToolUse', { tool: call.name, input: call.input, summary } );
			if ( res.blocked ) {
				this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: res.reason } );
				return `اجرا نشد. هوک جلویش را گرفت: ${ res.reason }`;
			}
		}

		if ( verdict.decision === 'ask' ) {
			this.emit( { type: 'permission_request', id: call.id, name: call.name, summary, input: call.input } );
			const answer = await new Promise( ( resolve ) => this.pending.set( call.id, resolve ) );
			if ( answer !== 'allow' ) {
				this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: 'کاربر رد کرد.' } );
				return 'کاربر اجازهٔ این کار را نداد. کار دیگری پیشنهاد بده یا دلیل بخواه.';
			}
		}

		this.emit( { type: 'tool_start', id: call.id, name: call.name, summary, input: call.input } );

		try {
			const out = await tool.run( call.input || {}, {
				workspace: this.workspace,
				log: ( t ) => this.emit( { type: 'tool_log', id: call.id, text: t } ),
			} );
			this.emit( { type: 'tool_result', id: call.id, name: call.name, output: out } );
			await this.hooks?.run( 'PostToolUse', { tool: call.name, input: call.input, output: out } );
			return out;
		} catch ( e ) {
			const msg = e?.message || String( e );
			this.emit( { type: 'tool_error', id: call.id, name: call.name, error: msg } );
			return `خطا در اجرای ابزار: ${ msg }`;
		}
	}
}

/** @param {string} workspace */
export function defaultSystemPrompt( workspace ) {
	return [
		'تو «هوشا» هستی: یک دستیار عامل که روی دستگاه خود کاربر اجرا می‌شود و ابزار واقعی در اختیار دارد.',
		'',
		`پوشهٔ کاری: ${ workspace }`,
		'',
		'قواعد کار:',
		'- به فارسی جواب بده مگر کاربر زبان دیگری بخواهد.',
		'- قبل از حدس‌زدن، با ابزارها واقعیت را ببین (list_dir، read_file، grep).',
		'- کار چندمرحله‌ای را با todo_write ثبت کن تا چیزی گم نشود.',
		'- برای کاوش‌های طولانی که فقط جوابِ کوتاهش لازم است، از ابزار task استفاده کن.',
		'- برای تغییر فایل از edit_file استفاده کن، نه بازنویسی کامل، مگر فایل تازه باشد.',
		'- هر فرمان مخرب یا پرریسک را اول توضیح بده؛ کاربر باید بفهمد چه چیزی را تأیید می‌کند.',
		'- اگر کاربر اجازه نداد، اصرار نکن؛ راه دیگری پیشنهاد بده.',
		'- کوتاه و دقیق بنویس. چیزی را که ندیده‌ای، ادعا نکن.',
	].join( '\n' );
}
