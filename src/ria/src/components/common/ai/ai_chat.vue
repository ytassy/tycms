<template>
	<section :class="['ai-chat', { card: !embedded, 'ai-chat-embedded': embedded }]" aria-label="Conversation avec l’IA">
		<div v-if="!embedded" class="card-header d-flex align-items-center justify-content-between gap-2">
			<strong>{{ title }}</strong>
			<button v-if="closable" type="button" class="btn-close esc" aria-label="Fermer le chat" @click="$emit('close')" />
		</div>
		<div ref="history" :class="['ai-chat-history', { 'card-body': !embedded }]" role="log" aria-live="polite">
			<p v-if="!visibleMessages.length && !busy" class="text-body-secondary mb-0">Écrivez votre demande à l’IA.</p>
			<div v-for="(message, index) in visibleMessages" :key="index" :class="['ai-chat-message', message.role === 'user' ? 'ai-chat-user' : 'ai-chat-assistant']">
				<div class="small fw-semibold mb-1">
					<span v-if="message.role === 'user'">Moi</span>
					<i v-else class="fa-solid fa-wand-magic-sparkles"></i>				
				</div>
				<figure v-if="message.role === 'image'" class="ai-chat-image mb-0">
					<img :src="message.src" :alt="message.prompt" loading="lazy">
					<figcaption class="small text-body-secondary mt-2">{{ message.prompt }}</figcaption>
				</figure>
				<div v-else-if="message.role === 'assistant'" class="ai-chat-content ai-chat-markdown" v-html="renderMarkdown(message.content)" />
				<div v-else class="ai-chat-content ai-chat-plain">{{ message.content }}</div>
			</div>
			<div v-if="pendingReview" class="ai-chat-review border rounded p-3 my-3" aria-label="Proposition de modification">
				<h3 class="h6">{{ pendingReview.title }}</h3>
				<p v-if="pendingReview.summary" class="small mb-2">{{ pendingReview.summary }}</p>
				<p v-if="pendingReview.format === 'approval'" class="small text-body-secondary">Cet appel peut être facturé par le fournisseur IA.</p>
				<p v-if="pendingReview.format !== 'structure' && pendingReview.format !== 'approval'" class="small text-body-secondary">Texte supprimé en rouge, texte ajouté en vert.</p>
				<div v-if="pendingReview.format !== 'structure' && pendingReview.format !== 'approval'" class="ai-chat-diff border rounded p-2 mb-2" aria-label="Comparaison mot par mot"><span v-for="(part, index) in pendingReview.parts" :key="index" :class="part.type === 'same' ? '' : `ai-chat-${part.type}`">{{ part.text }}</span></div>
				<details v-if="pendingReview.format === 'html' || pendingReview.format === 'structure'" class="mb-3" :open="pendingReview.format === 'structure'">
					<summary>{{ pendingReview.format === 'html' ? 'Voir le HTML exact avant et après' : 'Voir les données avant et après' }}</summary>
					<div class="row g-2 mt-1"><div class="col-md-6"><strong class="small">Avant</strong><pre>{{ pendingReview.before }}</pre></div><div class="col-md-6"><strong class="small">Après</strong><pre>{{ pendingReview.after }}</pre></div></div>
				</details>
				<div class="d-flex flex-wrap gap-2">
					<button type="button" class="btn btn-primary btn-sm" @click="resolveReview(true)">{{ pendingReview.confirmLabel || 'Appliquer la modification' }}</button>
					<button type="button" class="btn btn-outline-secondary btn-sm" @click="resolveReview(false)">Refuser</button>
				</div>
			</div>
			<p v-if="busy && !pendingReview" class="small text-body-secondary mb-0" role="status">
				{{ activity }} <span class="ai-chat-thinking" aria-hidden="true"><span>●</span><span>●</span><span>●</span></span>
			</p>
			<p v-if="notice" class="small text-success mb-0" role="status">{{ notice }}</p>
			<p v-if="error" class="alert alert-warning mb-0" role="alert">{{ error }}</p>
		</div>
		<form :class="{ 'card-footer': !embedded, 'ai-chat-input': embedded }" @submit.prevent="send()">
			<textarea :id="inputId" v-model="draft" class="form-control" rows="3" :disabled="busy" placeholder="Décrivez ce que l’IA doit faire…" @keydown.enter="handleEnter" />
			<div class="d-flex align-items-center justify-content-between gap-2 mt-2">
				<span class="small text-body-secondary">Entrée pour envoyer · Maj+Entrée pour une nouvelle ligne</span>
				<div class="d-flex gap-2">
					<button v-if="busy && !pendingReview" type="button" class="btn btn-outline-secondary btn-sm" @click="stop()">Arrêter</button>
					<button type="submit" class="btn btn-primary btn-sm" :disabled="busy || !draft.trim()">Envoyer</button>
				</div>
			</div>
		</form>
	</section>
</template>

<script>
import { prepareTools, parseToolCall } from './aiTools.js'
import { wordDiff, htmlToText } from './wordDiff.js'
import { renderMarkdown } from '../../../application/markdown.js'
import { requestMessages } from './conversationBudget.js'
import { createImageGenerationTool } from './imageGenerationTool.js'

let nextInputId = 0
const delay = ms => new Promise(resolve => setTimeout(resolve, ms))
const imageToolInstruction = 'Si l’utilisateur demande de générer une image, utilise generate_image avec un prompt précis. L’image sera affichée dans ce chat après confirmation ; elle ne sera ni insérée dans le contenu ni enregistrée dans les médias.'

function assistantMessage(response) {
	const result = response?.result
	const message = result?.message ?? result?.choices?.[0]?.message ?? result?.data?.choices?.[0]?.message
	if (!message || message.role !== 'assistant' || (typeof message.content !== 'string' && message.content !== null)) {
		throw new Error('Le fournisseur a renvoyé un message IA invalide.')
	}
	return {
		role: 'assistant', content: message.content ?? '',
		...(Array.isArray(message.tool_calls) && message.tool_calls.length ? { tool_calls: message.tool_calls } : {}),
	}
}

function finishInterruptedToolCalls(messages) {
	const lastAssistant = messages.findLastIndex(message => message.role === 'assistant' && message.tool_calls?.length)
	if (lastAssistant < 0) return
	const answered = new Set(messages.slice(lastAssistant + 1).filter(message => message.role === 'tool').map(message => message.tool_call_id))
	for (const call of messages[lastAssistant].tool_calls) {
		if (!answered.has(call.id)) messages.push({ role: 'tool', tool_call_id: call.id,
			content: JSON.stringify({ applied: false, reason: 'Action interrompue.' }) })
	}
}

export default {
	props: {
		spaceId: { type: [Number, String], required: true },
		useCase: { type: String, default: 'proofreading' },
		customTools: { type: Array, default: () => [] },
		systemPrompt: { type: String, default: '' },
		initialMessage: { type: String, default: '' },
		initialDraft: { type: String, default: '' },
		title: { type: String, default: 'Assistance IA' },
		closable: { type: Boolean, default: false },
		embedded: { type: Boolean, default: false },
		maxToolRounds: { type: Number, default: 6 },
		maxToolCalls: { type: Number, default: 40 },
	},
	emits: ['close'],
	data() {
		return {
			inputId: `ai-chat-input-${++nextInputId}`, draft: this.initialDraft, messages: [],
			busy: false, activity: '', error: '', notice: '', pendingReview: null, requestId: 0,
			generatedImages: [],
		}
	},
	computed: {
		aiStore() { return this.stores.useAiChatStore() },
		visibleMessages() {
			const entries = []
			for (let index = 0; index <= this.messages.length; index++) {
				for (const image of this.generatedImages) if (image.afterIndex === index) entries.push({ role: 'image', ...image })
				const message = this.messages[index]
				if ((message?.role === 'user' || message?.role === 'assistant') && message.content) entries.push(message)
			}
			return entries
		},
	},
	watch: {
		visibleMessages() { this.scrollToBottom() },
		busy() { this.scrollToBottom() },
		pendingReview() { this.scrollToBottom() },
		notice() { this.scrollToBottom() },
		error() { this.scrollToBottom() },
	},
	mounted() {
		if (this.initialMessage.trim()) this.send(this.initialMessage)
	},
	beforeUnmount() { this.stop() },
	methods: {
		renderMarkdown,
		handleEnter(event) {
			if (event.shiftKey || event.isComposing || event.keyCode === 229) return
			event.preventDefault()
			this.send()
		},
		scrollToBottom() {
			this.$nextTick(() => {
				const history = this.$refs.history
				if (history) history.scrollTop = history.scrollHeight
			})
		},
		async send(message = null) {
			if (this.busy) return
			const content = (message ?? this.draft).trim()
			if (!content) return
			this.draft = ''
			this.error = ''
			this.notice = ''
			this.busy = true
			this.activity = 'L’IA réfléchit…'
			const currentRequest = ++this.requestId
			try {
				let imageEnabled = false
				try { imageEnabled = (await this.aiStore.capabilities(this.spaceId)).imageGeneration === true }
				catch {
					if (currentRequest === this.requestId) this.error = 'Impossible de vérifier la disponibilité de la génération d’image. Les autres actions IA restent disponibles.'
				}
				if (currentRequest !== this.requestId) return
				const imageTool = imageEnabled ? createImageGenerationTool({
					generate: async prompt => {
						const response = await this.aiStore.generateImage(this.spaceId, prompt)
						if (currentRequest !== this.requestId) throw new Error('Demande interrompue.')
						const completed = await this.waitForResult(response, currentRequest)
						if (currentRequest !== this.requestId) throw new Error('Demande interrompue.')
						if (!Array.isArray(completed?.result?.images) || completed.result.images.length !== 1) {
							throw new Error('Le fournisseur n’a pas renvoyé une image exploitable.')
						}
						return completed.result.images[0]
					},
					publish: image => {
						if (currentRequest !== this.requestId) throw new Error('Demande interrompue.')
						this.generatedImages.push({ ...image, afterIndex: this.messages.length })
					},
				}) : null
				const registry = prepareTools(imageTool ? [...this.customTools, imageTool] : this.customTools)
				finishInterruptedToolCalls(this.messages)
				const systemContent = [this.systemPrompt.trim(), imageEnabled ? imageToolInstruction : ''].filter(Boolean).join('\n\n')
				if (!this.messages.length) this.messages.push({ role: 'system', content: systemContent })
				else this.messages[0].content = systemContent
				this.messages.push({ role: 'user', content })
				let toolCallCount = 0
				const repeatedResults = new Map()
				for (let round = 0; round <= this.maxToolRounds; round++) {
					const definitions = registry.definitions.length ? registry.definitions : undefined
					let response
					try { response = await this.aiStore.complete(this.spaceId, this.useCase, requestMessages(this.messages), definitions) }
					catch (error) {
						if (!/context|token|trop volumineus|maximum/i.test(error?.message ?? '')) throw error
						response = await this.aiStore.complete(this.spaceId, this.useCase,
							requestMessages(this.messages, { maxBytes: 60_000, maxMessages: 30 }), definitions)
					}
					const completed = await this.waitForResult(response, currentRequest)
					if (currentRequest !== this.requestId) return
					const assistant = assistantMessage(completed)
					this.messages.push(assistant)
					if (!assistant.tool_calls?.length) return
					if (round === this.maxToolRounds || toolCallCount + assistant.tool_calls.length > this.maxToolCalls) {
						finishInterruptedToolCalls(this.messages)
						throw new Error('Budget de Tools atteint. Vous pouvez poursuivre par une nouvelle demande.')
					}
					toolCallCount += assistant.tool_calls.length
					this.activity = 'L’IA utilise les actions autorisées…'
					let rejected = false
					for (const call of assistant.tool_calls) {
						if (currentRequest !== this.requestId) return
						let result
						try {
							if (rejected) result = { applied: false, reason: 'Action ignorée après un refus ou une erreur.' }
							else {
								const { tool, args } = parseToolCall(call, registry.byName)
								if (tool.mode === 'review') {
									const preview = await tool.preview(args)
									if (currentRequest !== this.requestId) return
									const accepted = await this.askReview(tool, preview)
									if (currentRequest !== this.requestId) return
									if (!accepted) {
										result = { applied: false, reason: 'Modification refusée par l’utilisateur.' }
										this.notice = 'Modification refusée.'
										rejected = true
									} else result = await tool.execute(args)
								} else if (tool.mode === 'approval') {
									const accepted = await this.askApproval(tool, args)
									if (currentRequest !== this.requestId) return
									if (!accepted) {
										result = { generated: false, reason: 'Génération refusée par l’utilisateur.' }
										this.notice = 'Génération refusée.'
										rejected = true
									} else result = await tool.execute(args)
								} else result = await tool.execute(args)
								if (currentRequest !== this.requestId) return
								if (!result?.applied && !result?.error) {
									const key = `${tool.name}:${JSON.stringify(args)}:${JSON.stringify(result)}`
									const repeats = (repeatedResults.get(key) ?? 0) + 1
									repeatedResults.set(key, repeats)
									if (repeats >= 3) throw new Error('L’IA répète la même lecture sans progresser. Reformulez la demande.')
								}
								if (result?.applied) this.notice = 'Modification appliquée dans l’éditeur.'
								if (result?.generated) this.notice = 'Image générée dans la conversation.'
							}
						} catch (error) {
							if (currentRequest !== this.requestId) return
							result = { applied: false, error: error?.message || 'Échec du Tool.' }
							this.error = result.error
							rejected = true
						}
						this.messages.push({ role: 'tool', tool_call_id: call.id, content: JSON.stringify(result ?? { ok: true }) })
					}
					if (rejected) return
					this.activity = 'L’IA prépare sa réponse…'
				}
			} catch (error) {
				if (currentRequest === this.requestId) this.error = error?.message || 'L’assistance IA a échoué.'
			} finally {
				if (currentRequest === this.requestId) { this.busy = false; this.activity = '' }
				this.scrollToBottom()
			}
		},
		async waitForResult(response, requestId) {
			let current = response
			for (let attempt = 0; current?.status === 'pending' && attempt < 180; attempt++) {
				if (requestId !== this.requestId) return null
				if (!current.batchId && !response.batchId) throw new Error('Identifiant de batch IA manquant.')
				this.activity = 'Réponse IA en cours de traitement…'
				await delay(2500)
				if (requestId !== this.requestId) return null
				current = await this.aiStore.status(response.batchId)
			}
			if (current?.status === 'pending') throw new Error('Le traitement IA prend trop de temps. Vous pouvez réessayer.')
			if (current?.status !== 'completed') throw new Error('Le traitement IA a échoué.')
			return current
		},
		askReview(tool, preview) {
			if (!preview || typeof preview.before !== 'string' || typeof preview.after !== 'string') {
				throw new Error('La comparaison du Tool est invalide.')
			}
			return new Promise(resolve => {
				this.pendingReview = {
					title: tool.reviewTitle || 'Vérifier la modification',
					...preview,
						parts: preview.format === 'structure' ? [] : wordDiff(
							preview.format === 'html' ? htmlToText(preview.before) : preview.before,
							preview.format === 'html' ? htmlToText(preview.after) : preview.after),
					resolve,
				}
			})
		},
		askApproval(tool, args) {
			const approval = tool.approval(args)
			if (!approval || typeof approval.title !== 'string' || typeof approval.summary !== 'string') {
				throw new Error('Confirmation du Tool invalide.')
			}
			return new Promise(resolve => {
				this.pendingReview = { ...approval, format: 'approval', resolve }
			})
		},
		resolveReview(accepted) {
			const review = this.pendingReview
			if (!review) return
			this.pendingReview = null
			review.resolve(accepted)
		},
		stop() {
			this.requestId++
			this.resolveReview(false)
			this.busy = false
			this.activity = ''
			this.notice = 'Demande arrêtée.'
		},
	},
}
</script>

<style scoped>
.ai-chat { width: 100%; max-height: min(86vh, 60rem); }
.ai-chat-embedded { display: flex; flex-direction: column; max-height: min(calc(100vh - 11rem), 60rem); }
.ai-chat-history { overflow-y: auto; min-height: 8rem; }
.ai-chat-embedded .ai-chat-history { flex: 1 1 auto; }
.ai-chat-input { border-top: 1px solid var(--bs-border-color); padding-top: .75rem; }
.ai-chat-message { margin-bottom: 1rem; padding: .65rem .8rem; border-radius: .5rem; overflow-wrap: anywhere; }
.ai-chat-image img { display: block; max-width: 100%; max-height: 28rem; object-fit: contain; border-radius: .375rem; }
.ai-chat-user { background: var(--bs-primary-bg-subtle); margin-left: 12%; }
.ai-chat-assistant { background: var(--bs-secondary-bg); margin-right: 12%; }
.ai-chat-plain { white-space: pre-wrap; }
.ai-chat-markdown { font-family: var(--bs-body-font-family); font-size: .95rem; line-height: 1.5; color: var(--bs-body-color); overflow-x: auto; }
.ai-chat-markdown :deep(> :last-child) { margin-bottom: 0; }
.ai-chat-markdown :deep(:is(h1, h2, h3, h4, h5, h6)) { margin: .85rem 0 .4rem; font-family: inherit; font-style: normal; font-weight: 700; line-height: 1.35; letter-spacing: normal; text-transform: none; color: inherit; }
.ai-chat-markdown :deep(> :first-child) { margin-top: 0; }
.ai-chat-markdown :deep(h1) { font-size: 1.3rem; }
.ai-chat-markdown :deep(h2) { font-size: 1.18rem; }
.ai-chat-markdown :deep(h3) { font-size: 1.08rem; }
.ai-chat-markdown :deep(:is(h4, h5, h6)) { font-size: 1rem; }
.ai-chat-markdown :deep(p) { margin: 0 0 .65rem; }
.ai-chat-markdown :deep(:is(ul, ol)) { margin: 0 0 .75rem; padding-left: 1.5rem; }
.ai-chat-markdown :deep(ul) { list-style: disc; }
.ai-chat-markdown :deep(ol) { list-style: decimal; }
.ai-chat-markdown :deep(li > :is(ul, ol)) { margin-bottom: 0; }
.ai-chat-markdown :deep(hr) { margin: .75rem 0; opacity: .35; }
.ai-chat-markdown :deep(pre) { max-width: 100%; overflow-x: auto; padding: .75rem; border-radius: .375rem; background: var(--bs-body-bg); }
.ai-chat-markdown :deep(table) { width: 100%; margin: .75rem 0; border-collapse: collapse; background: var(--bs-body-bg); }
.ai-chat-markdown :deep(th), .ai-chat-markdown :deep(td) { border: 1px solid var(--bs-border-color); padding: .5rem .65rem; vertical-align: top; overflow-wrap: break-word; word-break: normal; }
.ai-chat-markdown :deep(th) { background: var(--bs-tertiary-bg); font-weight: 700; }
.ai-chat-markdown :deep(tbody tr:nth-child(even)) { background: var(--bs-secondary-bg); }
.ai-chat-markdown :deep(:is(th, td):nth-child(1)) { min-width: 8rem; }
.ai-chat-markdown :deep(:is(th, td):nth-child(2)) { min-width: 11rem; }
.ai-chat-markdown :deep(:is(th, td):nth-child(3)) { min-width: 16rem; }
.ai-chat-markdown :deep(:is(th, td):nth-child(4)) { min-width: 10rem; }
.ai-chat-markdown :deep(blockquote) { margin: .75rem 0; padding: .25rem .75rem; border: 0; border-left: .2rem solid var(--bs-border-color); background: transparent; color: inherit; font-family: inherit; font-size: inherit; font-style: normal; font-weight: 400; line-height: inherit; text-align: left; }
.ai-chat-markdown :deep(blockquote::before), .ai-chat-markdown :deep(blockquote::after) { content: none; display: none; }
.ai-chat-markdown :deep(blockquote > p:last-child:not(:first-child)) { margin-right: 0; color: inherit; font-style: inherit; font-weight: inherit; }
.ai-chat-thinking { display: inline-flex; align-items: center; gap: .2rem; margin-left: .2rem; }
.ai-chat-thinking span { display: inline-block; font-size: .65rem; animation: ai-chat-pulse 1.2s ease-in-out infinite; }
.ai-chat-thinking span:nth-child(2) { animation-delay: .2s; }
.ai-chat-thinking span:nth-child(3) { animation-delay: .4s; }
@keyframes ai-chat-pulse { 0%, 60%, 100% { opacity: .25; transform: translateY(0); } 30% { opacity: 1; transform: translateY(-.2rem); } }
@media (prefers-reduced-motion: reduce) { .ai-chat-thinking span { animation: none; } }
.ai-chat-diff { white-space: pre-wrap; overflow-wrap: anywhere; max-height: 16rem; overflow-y: auto; }
.ai-chat-added { background: #d1e7dd; color: #0a3622; text-decoration: none; }
.ai-chat-removed { background: #f8d7da; color: #58151c; text-decoration: line-through; }
.ai-chat-review pre { max-height: 12rem; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; font-size: .75rem; }
</style>
