/**
 * قراردادهای مشترک لایهٔ پرووایدر.
 *
 * این فایل فقط تعریف تایپ است (JSDoc) و در زمان اجرا کاری نمی‌کند؛ هدفش این است که
 * افزودن یک پرووایدر تازه یعنی نوشتن یک آداپتور با همین شکل، نه دست‌زدن به هستهٔ عامل.
 */

/**
 * @typedef {Object} ProviderConfig
 * @property {string} providerId
 * @property {'openai'|'anthropic'|'mock'} kind
 * @property {string} baseUrl
 * @property {string} [apiKey]
 * @property {string} model
 */

/**
 * @typedef {Object} ToolSpec
 * @property {string} name
 * @property {string} description
 * @property {object} parameters   JSON Schema
 */

/**
 * @typedef {Object} ToolCall
 * @property {string} id
 * @property {string} name
 * @property {any} input
 */

/**
 * @typedef {Object} Message
 * @property {'user'|'assistant'|'tool'} role
 * @property {string} content
 * @property {ToolCall[]} [toolCalls]
 * @property {string} [toolCallId]
 */

/**
 * @typedef {Object} ChatRequest
 * @property {string} model
 * @property {string} [system]
 * @property {Message[]} messages
 * @property {ToolSpec[]} [tools]
 * @property {number} [maxTokens]
 * @property {number} [temperature]
 * @property {AbortSignal} [signal]
 */

/**
 * @typedef {{type:'text',text:string}
 *   | {type:'tool_call',id:string,name:string,input:any}
 *   | {type:'usage',inputTokens:number,outputTokens:number}
 *   | {type:'error',error:string}} StreamEvent
 */

export {};
