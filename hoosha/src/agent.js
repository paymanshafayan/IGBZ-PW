/**
 * حلقهٔ عامل هوشا.
 *
 * الگو همان چیزی است که در عمل جواب داده: مدل حرف می‌زند و ابزار می‌خواهد → دروازهٔ
 * مجوز → اجرای ابزار → نتیجه برمی‌گردد به مدل → تکرار تا وقتی مدل دیگر ابزاری نخواهد.
 *
 * دو تصمیم که عمدی‌اند:
 *   ۱) نتیجهٔ ابزار **همیشه** به مدل برمی‌گردد، حتی وقتی رد شده — وگرنه مدل نمی‌فهمد چرا
 *      کارش پیش نرفت و همان درخواست را تکرار می‌کند.
 *   ۲) سقف گام دارد. یک عامل بی‌سقف، یک قبض بی‌سقف است.
 */

import { TOOLS, toolSpecs } from './tools.js';
import { decide, describeCall } from './permissions.js';

const DEFAULT_MAX_STEPS = 24;

export class Agent {
	/**
	 * @param {{
	 *   provider: any,
	 *   model: string,
	 *   workspace: string,
	 *   rules: {mode:string, allow?:string[], ask?:string[], deny?:string[]},
	 *   systemPrompt?: string,
	 *   maxSteps?: number,
	 *   emit: (ev: any) => void,
	 * }} opts
	 */
	constructor( opts ) {
		this.provider = opts.provider;
		this.model = opts.model;
		this.workspace = opts.workspace;
		this.rules = opts.rules;
		this.systemPrompt = opts.systemPrompt || defaultSystemPrompt( opts.workspace );
		this.maxSteps = opts.maxSteps || DEFAULT_MAX_STEPS;
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

	/** @param {string} text */
	async run( text ) {
		if ( this.busy ) {
			throw new Error( 'یک درخواست در حال اجراست.' );
		}
		this.busy = true;
		this.controller = new AbortController();
		this.messages.push( { role: 'user', content: text } );
		this.emit( { type: 'user', text } );

		try {
			for ( let step = 0; step < this.maxSteps; step++ ) {
				const turn = await this.#oneTurn();
				if ( ! turn.toolCalls.length ) {
					break;
				}
				if ( step === this.maxSteps - 1 ) {
					this.emit( { type: 'notice', text: `به سقف ${ this.maxSteps } گام رسیدیم و متوقف شدم.` } );
				}
			}
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

		const stream = this.provider.stream( {
			model: this.model,
			system: this.systemPrompt,
			messages: this.messages,
			tools: toolSpecs(),
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
			const result = await this.#runTool( call );
			this.messages.push( { role: 'tool', toolCallId: call.id, content: result } );
		}

		return { text, toolCalls };
	}

	/** @param {import('./providers/types.js').ToolCall} call */
	async #runTool( call ) {
		const summary = describeCall( call.name, call.input );
		const verdict = decide( call.name, call.input, this.rules );

		if ( verdict.decision === 'deny' ) {
			this.emit( { type: 'tool_denied', id: call.id, name: call.name, summary, reason: verdict.reason } );
			return `اجرا نشد. ${ verdict.reason || 'اجازه داده نشد.' }`;
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
			const tool = TOOLS[ call.name ];
			const out = await tool.run( call.input || {}, {
				workspace: this.workspace,
				log: ( t ) => this.emit( { type: 'tool_log', id: call.id, text: t } ),
			} );
			this.emit( { type: 'tool_result', id: call.id, name: call.name, output: out } );
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
		'- برای تغییر فایل از edit_file استفاده کن، نه بازنویسی کامل، مگر فایل تازه باشد.',
		'- هر فرمان مخرب یا پرریسک را اول توضیح بده؛ کاربر باید بفهمد چه چیزی را تأیید می‌کند.',
		'- اگر کاربر اجازه نداد، اصرار نکن؛ راه دیگری پیشنهاد بده.',
		'- کوتاه و دقیق بنویس. چیزی را که ندیده‌ای، ادعا نکن.',
	].join( '\n' );
}
