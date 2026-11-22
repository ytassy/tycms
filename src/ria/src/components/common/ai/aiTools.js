const TOOL_NAME = /^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/

// The callback and review policy never leave the browser. Only this public
// description is sent to the LLM through Core's existing API.
export function prepareTools(tools) {
	if (!Array.isArray(tools) || tools.length > 30) throw new Error('Liste de Tools IA invalide.')
	const byName = new Map()
	const definitions = []
	for (const tool of tools) {
		if (!tool || !TOOL_NAME.test(tool.name) || byName.has(tool.name)
			|| typeof tool.description !== 'string' || !tool.parameters || Array.isArray(tool.parameters)
			|| tool.parameters.type !== 'object' || typeof tool.execute !== 'function'
			|| !['direct', 'review', 'approval'].includes(tool.mode ?? 'direct')
			|| (tool.mode === 'review' && typeof tool.preview !== 'function')
			|| (tool.mode === 'approval' && typeof tool.approval !== 'function')) {
			throw new Error('Définition de Tool IA invalide ou dupliquée.')
		}
		byName.set(tool.name, tool)
		definitions.push({ type: 'function', function: {
			name: tool.name, description: tool.description, parameters: tool.parameters,
		} })
	}
	return { byName, definitions }
}

export function parseToolCall(call, byName) {
	if (typeof call?.id !== 'string' || !call.id) throw new Error('Identifiant de Tool IA invalide.')
	const name = call?.function?.name
	const tool = byName.get(name)
	if (!tool) throw new Error(`Tool non autorisé : ${name ?? 'inconnu'}.`)
	let args
	try { args = JSON.parse(call.function.arguments) } catch { throw new Error(`Arguments JSON invalides pour ${name}.`) }
	if (args === null || Array.isArray(args) || typeof args !== 'object') {
		throw new Error(`Arguments invalides pour ${name}.`)
	}
	if (typeof tool.validate === 'function') tool.validate(args)
	return { tool, args }
}

// Both modes use the same executor. A caller can make a Tool immediate or
// require an explicit review without changing the model-facing schema.
export function createHtmlContentTools({ read, write, canWrite = () => true, mode = 'review' }) {
	if (!['direct', 'review'].includes(mode)) throw new Error('Mode de Tool invalide.')
	let snapshot = null
	const ensureCurrent = () => {
		if (!canWrite()) throw new Error('Le contenu est en lecture seule.')
		if (snapshot === null) throw new Error('Le contenu doit être lu avant sa modification.')
		if (read() !== snapshot) throw new Error('Le contenu a changé depuis sa lecture. Relancez la correction.')
	}
	return [
		{
			name: 'get_content',
			description: 'Lire le contenu HTML actuel du champ de texte riche avant toute modification.',
			parameters: { type: 'object', properties: {}, additionalProperties: false },
			mode: 'direct',
			execute: () => {
				snapshot = read()
				return { html: snapshot }
			},
		},
		{
			name: 'replace_content',
			description: 'Remplacer le contenu HTML entier du champ par la version corrigée. Préserver le sens et les liens.',
			parameters: {
				type: 'object',
				properties: { html: { type: 'string', description: 'Nouveau contenu HTML complet.' } },
				required: ['html'], additionalProperties: false,
			},
			mode,
			validate: args => {
				if (typeof args.html !== 'string') throw new Error('Le Tool attend un champ html texte.')
			},
			preview: args => {
				ensureCurrent()
				return { before: snapshot, after: args.html, format: 'html' }
			},
			execute: args => {
				ensureCurrent()
				write(args.html)
				snapshot = read()
				return { applied: true }
			},
		},
	]
}
