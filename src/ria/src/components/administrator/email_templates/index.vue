<template>
	<div class="email-templates-admin">
		<h1>Modèles d’e-mails</h1>
		<p class="text-body-secondary">Les modèles sont fournis par les extensions. Une personnalisation dans un espace s’applique aussi à ses descendants.</p>
		<div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
		<div v-if="success" class="alert alert-success" role="status">{{ success }}</div>
		<div v-if="loading" class="py-4 text-center"><spinner /></div>
		<template v-else>
			<div v-if="!spaces.length" class="alert alert-secondary">Aucun espace accessible pour les modèles d’e-mails.</div>
			<template v-else>
				<div class="d-flex flex-wrap gap-3 mb-3">
					<div>
						<label class="form-label" for="email-template-space">Espace</label>
						<select id="email-template-space" class="form-select" :value="spaceId" :disabled="busy" @change="selectSpace(Number($event.target.value), $event.target)">
							<option v-for="space in spaces" :key="space.id" :value="space.id">{{ space.title }}</option>
						</select>
					</div>
					<div>
						<label class="form-label" for="email-template-locale">Langue</label>
						<select id="email-template-locale" class="form-select" :value="locale" :disabled="busy" @change="selectLocale($event.target.value, $event.target)">
							<option v-for="code in selectedSpace?.locales ?? []" :key="code" :value="code">{{ code.toUpperCase() }}</option>
						</select>
					</div>
				</div>

				<div v-if="entries.length" class="row g-3">
					<div class="col-12 col-lg-4">
						<div class="list-group" aria-label="Modèles disponibles">
							<button v-for="entry in entries" :key="entry.key" type="button" :class="['list-group-item', 'list-group-item-action', { active: entry.key === selectedKey }]"
								:disabled="busy" @click="selectEntry(entry.key)">
								<span class="d-block fw-semibold">{{ entry.label }}</span>
								<span class="small">{{ entry.owner }}</span>
							</button>
						</div>
					</div>
					<div v-if="current" class="col-12 col-lg-8">
						<div class="card"><div class="card-body">
							<h2 class="h5">{{ current.label }}</h2>
							<p class="small text-body-secondary">{{ current.description }}</p>
							<p class="small">Source : {{ sourceLabel(current) }} · Mise en page : {{ current.renderMode === 'default' ? 'avec cadre' : 'simple' }}</p>
							<p v-if="!current.customizable" class="alert alert-secondary">Ce modèle est verrouillé par son extension.</p>
							<div class="mb-3">
								<label class="form-label" for="email-template-subject">Objet</label>
								<input id="email-template-subject" v-model="draft.subject" class="form-control" maxlength="255" :readonly="!current.customizable || busy" />
							</div>
							<div class="mb-3">
								<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
									<span class="form-label mb-0">Contenu</span>
									<div class="btn-group btn-group-sm" role="group" aria-label="Mode d’édition du contenu">
										<button type="button" :class="['btn', editorMode === 'rich' ? 'btn-primary' : 'btn-outline-primary']"
											:aria-pressed="editorMode === 'rich'" :disabled="busy" @click="editorMode = 'rich'">Texte riche</button>
										<button type="button" :class="['btn', editorMode === 'code' ? 'btn-primary' : 'btn-outline-primary']"
											:aria-pressed="editorMode === 'code'" :disabled="busy" @click="editorMode = 'code'">HTML</button>
									</div>
								</div>
								<rich_text_editor v-if="editorMode === 'rich'" v-model="draft.bodyHtml"
									:disabled="!current.customizable || busy" :media-space-id="spaceId" :show-ai-button="false" />
								<code_editor v-else v-model="draft.bodyHtml" syntax="html" wrap-lines :max-bytes="131072"
									:read-only="!current.customizable || busy" aria-label="Contenu HTML du modèle" class="email-template-code-editor" />
								<div class="form-text">L’aperçu montre le HTML conservé après validation.</div>
							</div>
							<div v-if="current.variables.length" class="small mb-3">
								<strong>Variables :</strong>
								<code v-for="variable in current.variables" :key="variable.name" class="ms-2" :title="variable.type">{{ '%' + variable.name + '%' }}</code>
							</div>
							<div class="d-flex flex-wrap gap-2">
								<button type="button" class="btn btn-outline-primary" :disabled="busy" @click="showPreview">Aperçu</button>
								<button v-if="current.customizable" type="button" class="btn btn-primary" :disabled="busy || !dirty" @click="save">Enregistrer</button>
								<button v-if="current.customizable && current.local" type="button" class="btn btn-outline-danger" :disabled="busy" @click="restore">Restaurer la valeur héritée</button>
							</div>
							<div v-if="previewHtml !== null" class="mt-4">
								<h3 class="h6">Aperçu : {{ previewSubject }}</h3>
								<iframe class="w-100 border rounded" style="height: 800px" title="Aperçu du modèle d’e-mail" sandbox :srcdoc="previewHtml" />
							</div>
						</div></div>
					</div>
				</div>
				<div v-else class="alert alert-secondary">Aucun modèle déclaré par les extensions.</div>
			</template>
		</template>
	</div>
</template>

<script>
export default {
	data() {
		return {
			spaces: [], spaceId: null, locale: '', entries: [], selectedKey: null,
			current: null, draft: { subject: '', bodyHtml: '' },
			previewHtml: null, previewSubject: '', loading: true, busy: false, editorMode: 'rich',
			error: '', success: '',
		}
	},
	computed: {
		selectedSpace() { return this.spaces.find(space => space.id === this.spaceId) ?? null },
		dirty() { return Boolean(this.current && (this.draft.subject !== this.current.subject || this.draft.bodyHtml !== this.current.bodyHtml)) },
	},
	async mounted() {
		try {
			const result = await this.store().spaces()
			this.spaces = result.spaces ?? []
			if (this.spaces.length) await this.loadSpace(this.spaces[0])
		} catch (_) { this.error = 'Impossible de charger les modèles d’e-mails.' }
		finally { this.loading = false }
	},
	methods: {
		store() { return this.stores.useAdminEmailTemplatesStore() },
		async canLeave() { return !this.dirty || await this.askYesNoAsync('Abandonner les modifications non enregistrées ?') },
		sourceLabel(entry) {
			if (entry.source === 'space') return 'cet espace'
			if (entry.source === 'parent') return `espace parent ${entry.sourceSpaceId}`
			if (entry.source === 'code_fr_fallback') return 'code français (repli)'
			return 'code de l’extension'
		},
		async loadSpace(space) {
			this.spaceId = space.id
			this.locale = space.defaultLocale
			await this.loadEntries()
		},
		async loadEntries(preferredKey = null) {
			this.busy = true
			this.error = ''
			this.previewHtml = null
			try {
				const result = await this.store().entries(this.spaceId, this.locale)
				this.entries = result.entries ?? []
				this.openEntry(this.entries.find(entry => entry.key === preferredKey) ?? this.entries[0] ?? null)
			} catch (_) { this.error = 'Impossible de charger les modèles de cet espace.' }
			finally { this.busy = false }
		},
		openEntry(entry) {
			this.current = entry
			this.selectedKey = entry?.key ?? null
			this.draft = { subject: entry?.subject ?? '', bodyHtml: entry?.bodyHtml ?? '' }
			this.previewHtml = null
			this.success = ''
		},
		async selectSpace(id, target) {
			if (!await this.canLeave()) { target.value = String(this.spaceId); return }
			const space = this.spaces.find(item => item.id === id)
			if (space) await this.loadSpace(space)
		},
		async selectLocale(locale, target) {
			if (!await this.canLeave()) { target.value = this.locale; return }
			this.locale = locale
			await this.loadEntries(this.selectedKey)
		},
		async selectEntry(key) {
			if (!await this.canLeave()) return
			this.openEntry(this.entries.find(entry => entry.key === key) ?? null)
		},
		payload() { return { key: this.selectedKey, locale: this.locale, ...this.draft } },
		async save() {
			if (!this.current?.customizable || !this.dirty || this.busy) return
			this.busy = true
			this.error = ''
			try {
				await this.store().save(this.spaceId, this.payload())
				await this.loadEntries(this.selectedKey)
				this.success = 'Modèle enregistré.'
			} catch (_) { this.error = 'Le modèle n’a pas pu être enregistré. Vérifiez son objet, son HTML et ses variables.' }
			finally { this.busy = false }
		},
		async restore() {
			if (!this.current?.local || this.busy || !await this.askYesNoAsync('Restaurer la valeur héritée pour cette langue ?')) return
			this.busy = true
			this.error = ''
			try {
				await this.store().restore(this.spaceId, { key: this.selectedKey, locale: this.locale })
				await this.loadEntries(this.selectedKey)
				this.success = 'Valeur héritée restaurée.'
			} catch (_) { this.error = 'La personnalisation n’a pas pu être retirée.' }
			finally { this.busy = false }
		},
		async showPreview() {
			if (this.busy) return
			this.busy = true
			this.error = ''
			try {
				const result = await this.store().preview(this.spaceId, this.payload())
				this.previewSubject = result.subject
				this.previewHtml = result.html
			} catch (_) { this.error = 'Aperçu indisponible. Vérifiez le domaine de l’espace, le HTML et les variables.' }
			finally { this.busy = false }
		},
	},
}
</script>

<style scoped>
.email-template-code-editor :deep(.cm-editor) { min-height: 320px; }
.email-template-code-editor :deep(.cm-scroller) { max-height: 60vh; overflow: auto; }
</style>
