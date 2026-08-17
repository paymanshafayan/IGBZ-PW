/**
 * زمان اجرا — جایی که همهٔ تکه‌ها به هم وصل می‌شوند.
 *
 * دلیل وجود این فایل: سرور نباید بداند اسکیل و پلاگین و MCP چطور کار می‌کنند، و عامل هم
 * نباید. یک جا لازم است که «دنیای فعلی» را بسازد: کدام پرووایدر، کدام ابزارها، کدام
 * اسکیل‌ها، کدام هوک‌ها — و هر وقت چیزی عوض شد، دوباره بسازدش.
 */

import fs from 'node:fs/promises';
import path from 'node:path';

import { loadConfig, HOME } from './config.js';
import { createProvider, validateProfile, providerInfo } from './providers/index.js';
import { TOOLS } from './tools.js';
import { Agent, defaultSystemPrompt } from './agent.js';
import { McpManager } from './mcp.js';
import { HookRunner } from './hooks.js';
import { collectSkills, makeSkillTool, skillsPromptSection } from './skills.js';
import { collectCommands } from './commands.js';
import { collectAgents } from './agents.js';
import { activePlugins } from './plugins.js';
import { makeTaskTool } from './subagent.js';

export class Runtime {
	/** @param {(ev:any)=>void} emit */
	constructor( emit ) {
		this.emit = emit;
		this.mcp = new McpManager();
		/** @type {import('./skills.js').Skill[]} */
		this.skills = [];
		/** @type {import('./commands.js').UserCommand[]} */
		this.commands = [];
		/** @type {import('./agents.js').AgentDef[]} */
		this.agents = [];
		/** @type {any} */
		this.checkpoints = null;
		/** @type {{name:string,dir:string}[]} */
		this.plugins = [];
		/** @type {Agent|null} */
		this.agent = null;
		this.hooks = new HookRunner( { workspace: process.cwd(), emit } );
		this.config = null;
		this.ready = { ok: false, missing: [ 'پرووایدر' ] };
	}

	/**
	 * بازساخت کامل دنیا از روی تنظیمات روی دیسک.
	 * @param {{keepHistory?:boolean}} [opts]
	 */
	async reload( opts = {} ) {
		const cfg = await loadConfig();
		this.config = cfg;

		const plugins = await activePlugins( HOME );
		this.plugins = plugins.map( ( p ) => ( { name: p.name, dir: p.dir } ) );

		this.skills = await collectSkills( {
			home: HOME,
			workspace: cfg.workspace,
			pluginDirs: this.plugins,
		} );

		this.commands = await collectCommands( {
			home: HOME,
			workspace: cfg.workspace,
			pluginDirs: this.plugins,
		} );

		this.agents = await collectAgents( {
			home: HOME,
			workspace: cfg.workspace,
			pluginDirs: this.plugins,
		} );

		this.hooks = new HookRunner( {
			workspace: cfg.workspace,
			emit: this.emit,
			hooks: await this.#collectHooks( cfg ),
		} );

		await this.mcp.connectAll( {
			home: HOME,
			workspace: cfg.workspace,
			servers: { ...( cfg.mcpServers || {} ), ...( await this.#pluginMcpServers() ) },
		} );

		const profile = cfg.profiles?.[ cfg.activeProfile ];
		this.ready = profile ? validateProfile( profile ) : { ok: false, missing: [ 'پروفایل' ] };

		const previous = opts.keepHistory !== false ? this.agent : null;
		this.agent = this.#makeAgent( {} );
		if ( previous ) {
			this.agent.messages = previous.messages;
			this.agent.usage = previous.usage;
		}

		return this;
	}

	/**
	 * رجیستری ابزار: داخلی + اسکیل + زیرعامل + MCP.
	 *
	 * `depth` عمق تودرتویی عامل است. زیرعامل ابزار `task` نمی‌گیرد — یعنی نمی‌تواند
	 * زیرعامل بسازد. این جلوی بازگشت بی‌پایان را می‌گیرد؛ در آزمایش زنده دیدیم که یک مدل
	 * می‌تواند خودش را بی‌نهایت بار صدا بزند و تنها چیزی که جلویش را می‌گرفت، سقف گام بود.
	 *
	 * @param {number} [depth]
	 */
	tools( depth = 0, allowedTools = null ) {
		const all = {
			...TOOLS,
			skill: makeSkillTool( () => this.skills ),
			...( depth === 0
				? {
						task: makeTaskTool( {
							emit: this.emit,
							getAgents: () => this.agents,
							makeAgent: ( o ) => this.#makeAgent( { ...o, depth: depth + 1 } ),
						} ),
				  }
				: {} ),
			...this.mcp.toolEntries(),
			...( this.mcp.resourceEntries().length ? { read_mcp_resource: this.#resourceTool() } : {} ),
		};

		if ( ! allowedTools?.length ) {
			return all;
		}

		// عاملِ تعریف‌شده می‌تواند فهرست ابزار محدود داشته باشد؛ نام ناشناخته را ساکت رد می‌کنیم
		// تا یک اشتباه تایپی در فایل عامل، کل زیرعامل را بی‌ابزار نکند.
		/** @type {Record<string, any>} */
		const picked = {};
		for ( const name of allowedTools ) {
			if ( all[ name ] ) {
				picked[ name ] = all[ name ];
			}
		}
		return Object.keys( picked ).length ? picked : all;
	}

	/** ابزار خواندن منابع MCP — فقط وقتی ساخته می‌شود که سروری منبع داشته باشد. */
	#resourceTool() {
		const list = () => this.mcp.resourceEntries();
		return {
			risk: /** @type {const} */ ( 'read' ),
			spec: {
				name: 'read_mcp_resource',
				description: `خواندن یک منبع از سرورهای MCP. منابع موجود: ${ list()
					.slice( 0, 20 )
					.map( ( r ) => `${ r.server }:${ r.uri }` )
					.join( '، ' ) }`,
				parameters: {
					type: 'object',
					properties: {
						server: { type: 'string' },
						uri: { type: 'string' },
					},
					required: [ 'server', 'uri' ],
				},
			},
			run: async ( input ) => this.mcp.readResource( String( input.server ), String( input.uri ) ),
		};
	}

	/**
	 * @param {{systemPrompt?:string, maxSteps?:number, emit?:(ev:any)=>void, depth?:number}} opts
	 */
	#makeAgent( opts ) {
		const depth = opts.depth || 0;
		const allowedTools = opts.allowedTools || null;
		const cfg = this.config;
		const profile = cfg.profiles?.[ cfg.activeProfile ] || {};
		const info = providerInfo( profile.provider );

		let provider;
		try {
			provider = createProvider( profile );
		} catch {
			provider = null;
		}

		return new Agent( {
			provider,
			model: opts.model || profile.model || info?.defaultModel || '',
			baseUrl: profile.baseUrl || info?.baseUrl || '',
			workspace: cfg.workspace,
			rules: cfg.permissions,
			getTools: () => this.tools( depth, allowedTools ),
			systemPrompt: opts.systemPrompt || defaultSystemPrompt( cfg.workspace ),
			extraPrompt: this.#extraPrompt(),
			maxSteps: opts.maxSteps,
			hooks: this.hooks,
			checkpoints: depth === 0 ? this.checkpoints : null,
			onTurnEnd: depth === 0 ? this.onTurnEnd : null,
			emit: opts.emit || this.emit,
		} );
	}

	/** چیزهایی که باید همیشه در پرامپت سیستمی باشند: اسکیل‌ها و حافظهٔ پروژه. */
	#extraPrompt() {
		return [ this.projectMemory || '', skillsPromptSection( this.skills ) ].filter( Boolean ).join( '\n' );
	}

	/** فایل HOOSHA.md پروژه — همان نقش CLAUDE.md را دارد. */
	async loadProjectMemory() {
		const cfg = this.config || ( await loadConfig() );
		for ( const name of [ 'HOOSHA.md', '.hoosha/HOOSHA.md' ] ) {
			const text = await fs.readFile( path.join( cfg.workspace, name ), 'utf8' ).catch( () => null );
			if ( text ) {
				this.projectMemory = `\n[دستورالعمل این پروژه — از ${ name }]\n${ text.trim().slice( 0, 8000 ) }`;
				if ( this.agent ) {
					this.agent.extraPrompt = this.#extraPrompt();
				}
				return true;
			}
		}
		this.projectMemory = '';
		return false;
	}

	/** @param {any} cfg */
	async #collectHooks( cfg ) {
		/** @type {Record<string, any[]>} */
		const merged = {};
		const add = ( hooks ) => {
			for ( const [ event, list ] of Object.entries( hooks || {} ) ) {
				merged[ event ] = [ ...( merged[ event ] || [] ), ...( Array.isArray( list ) ? list : [] ) ];
			}
		};

		add( cfg.hooks );
		for ( const p of this.plugins ) {
			add( await readJson( path.join( p.dir, 'hooks.json' ) ) );
		}
		add( await readJson( path.join( cfg.workspace, '.hoosha', 'hooks.json' ) ) );

		return merged;
	}

	async #pluginMcpServers() {
		/** @type {Record<string,any>} */
		const out = {};
		for ( const p of this.plugins ) {
			const data = await readJson( path.join( p.dir, '.mcp.json' ) );
			Object.assign( out, data?.mcpServers || {} );
		}
		return out;
	}

	async close() {
		await this.mcp.close();
	}
}

async function readJson( p ) {
	try {
		return JSON.parse( await fs.readFile( p, 'utf8' ) );
	} catch {
		return null;
	}
}
