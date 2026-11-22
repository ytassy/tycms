const IMAGE_MIMES = new Set(['image/png', 'image/jpeg', 'image/webp'])

export function displayImage(image, prompt) {
	const mime = image?.media_type
	const base64 = image?.b64_json
	if (!IMAGE_MIMES.has(mime) || typeof base64 !== 'string' || base64.length > 14_000_000
		|| !/^[A-Za-z0-9+/]+={0,2}$/.test(base64)) {
		throw new Error('Image générée invalide ou non prise en charge.')
	}
	return { src: `data:${mime};base64,${base64}`, prompt }
}

/** Shared Tool: image bytes are published to the UI, never returned to the LLM. */
export function createImageGenerationTool({ generate, publish }) {
	let attempted = false
	const validPrompt = args => {
		if (typeof args.prompt !== 'string' || !args.prompt.trim() || args.prompt.length > 4000) {
			throw new Error('Le prompt image doit contenir de 1 à 4000 caractères.')
		}
		return args.prompt.trim()
	}
	return {
		name: 'generate_image',
		description: 'Générer et afficher une image à partir d’un prompt, après confirmation de l’utilisateur. Ne modifie aucun contenu ni média.',
		parameters: { type: 'object', properties: {
			prompt: { type: 'string', minLength: 1, maxLength: 4000, description: 'Description de l’image à générer.' },
		}, required: ['prompt'], additionalProperties: false },
		mode: 'approval',
		validate: validPrompt,
		approval: args => {
			if (attempted) throw new Error('Une seule génération d’image est possible par demande.')
			return { title: 'Générer une image', summary: validPrompt(args),
				confirmLabel: 'Générer l’image' }
		},
		execute: async args => {
			if (attempted) throw new Error('Une seule génération d’image est possible par demande.')
			attempted = true
			const prompt = validPrompt(args)
			const image = await generate(prompt)
			publish(displayImage(image, prompt))
			return { generated: true, displayed: true, count: 1 }
		},
	}
}
