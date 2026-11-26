<template>
	<div class="email-templates-admin">
		<div class="d-flex justify-content-between align-items-start gap-2 mb-3">
			<div>
				<h1>Modèles d’e-mails</h1>
				<p class="text-body-secondary">
					Les modèles des extensions peuvent être personnalisés par espace. Ajoutez aussi des modèles propres à un espace,
					hérités par ses descendants et utilisables dans les scénarios d’automatisation.
				</p>
			</div>
			<button v-if="spaces.length" type="button" class="btn btn-primary btn-sm" :disabled="busy" @click="openCreate">
				Ajouter un modèle
			</button>
		</div>

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
								<span class="d-flex align-items-start justify-content-between gap-2">
									<span class="fw-semibold">{{ entry.label }}</span>
									<span v-if="entry.custom" class="badge text-bg-info flex-shrink-0">Personnalisé</span>
								</span>
								<span class="small">{{ entry.custom ? 'Espace ' + entry.ownerSpaceTitle : entry.owner }}</span>
							</button>
						</div>
					</div>
					<div v-if="current" class="col-12 col-lg-8">
						<div class="card"><div class="card-body">
							<h2 class="h5">{{ current.label }}</h2>
							<p class="small text-body-secondary">{{ current.description || 'Aucune description.' }}</p>
							<p class="small mb-2">
								Clé : <code>{{ current.key }}</code>
								· Source : {{ sourceLabel(current) }}
								· Mise en page : {{ current.renderMode === 'default' ? 'avec cadre' : 'simple' }}
							</p>
							<p v-if="current.custom && !canManage" class="small text-body-secondary">
								Modèle personnalisé de l’espace « {{ current.ownerSpaceTitle }} ».
								L’enregistrement crée une version propre à cet espace.
							</p>
							<p v-if="current.effectiveLocale !== current.locale" class="small text-body-secondary">
								{{ current.custom
									? 'Aucun contenu dans cette langue : le contenu de base en ' + current.effectiveLocale.toUpperCase() + ' est utilisé.'
									: 'Le code ne fournit pas cette langue : la version française est affichée.' }}
							</p>
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
								<button v-if="current.customizable" type="button" :class="['btn', 'btn-primary', { 'ctrl-s': !formVisible }]" :disabled="busy || !dirty" @click="save">Enregistrer</button>
								<button v-if="canManage" type="button" class="btn btn-outline-secondary" :disabled="busy" @click="openMeta">Modifier les informations</button>
								<button v-if="canRestore" type="button" class="btn btn-outline-danger" :disabled="busy" @click="restore">Restaurer la valeur héritée</button>
								<button v-if="canManage" type="button" class="btn btn-outline-danger ms-auto" :disabled="busy" @click="deleteCurrent">Supprimer</button>
							</div>
							<div v-if="previewHtml !== null" class="mt-4">
								<h3 class="h6">Aperçu : {{ previewSubject }}</h3>
								<iframe class="w-100 border rounded" style="height: 800px" title="Aperçu du modèle d’e-mail" sandbox :srcdoc="previewHtml" />
							</div>
						</div></div>
					</div>
				</div>
				<div v-else class="alert alert-secondary">Aucun modèle déclaré par les extensions ni créé pour cet espace.</div>
			</template>
		</template>

		<modal v-if="formVisible" :visible="true" width="900px" :closable="!formSaving" @close="closeForm">
			<template #header>{{ formMode === 'create' ? 'Ajouter un modèle' : 'Informations du modèle' }}</template>
			<template #body>
				<form id="email-template-form" @submit.prevent="saveForm">
					<div v-if="formError" class="alert alert-warning" role="alert">{{ formError }}</div>
					<div class="mb-3">
						<label for="email-template-label" class="form-label">Libellé</label>
						<input id="email-template-label" v-model.trim="formDraft.label" class="form-control" maxlength="150"
							required :disabled="formSaving" />
					</div>
					<div class="mb-3">
						<label for="email-template-description" class="form-label">Description</label>
						<textarea id="email-template-description" v-model="formDraft.description" class="form-control"
							rows="2" maxlength="500" :disabled="formSaving" />
					</div>
					<div class="mb-3">
						<label for="email-template-render-mode" class="form-label">Mise en page</label>
						<select id="email-template-render-mode" v-model="formDraft.renderMode" class="form-select" :disabled="formSaving">
							<option value="default">Avec cadre</option>
							<option value="blank">Simple</option>
						</select>
					</div>
					<div class="mb-3">
						<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
							<span class="form-label mb-0">Variables</span>
							<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="formSaving || formDraft.variables.length >= 20"
								@click="addVariable">Ajouter une variable</button>
						</div>
						<p class="form-text">Variables texte, obligatoires à l’envoi. Utilisez %nom% dans l’objet et le contenu ; elles ne peuvent pas servir de lien.</p>
						<div v-for="(variable, index) in formDraft.variables" :key="index" class="row g-2 mb-2 align-items-center">
							<div class="col-5">
								<input v-model.trim="variable.name" class="form-control form-control-sm" maxlength="50" placeholder="nom"
									pattern="[a-z][a-z0-9_]*" aria-label="Nom de la variable" :disabled="formSaving" />
							</div>
							<div class="col-5">
								<input v-model="variable.example" class="form-control form-control-sm" maxlength="200" placeholder="Exemple"
									aria-label="Exemple de la variable" :disabled="formSaving" />
							</div>
							<div class="col-2 text-end">
								<button type="button" class="btn btn-outline-danger btn-sm" :disabled="formSaving"
									:aria-label="'Retirer la variable ' + (variable.name || index + 1)" @click="removeVariable(index)">
									<i class="fa-solid fa-trash" aria-hidden="true" />
								</button>
							</div>
						</div>
						<div v-if="!formDraft.variables.length" class="text-body-secondary small">Aucune variable.</div>
					</div>
					<template v-if="formMode === 'create'">
						<div class="mb-3">
							<label for="email-template-form-subject" class="form-label">Objet initial</label>
							<input id="email-template-form-subject" v-model="formDraft.subject" class="form-control" maxlength="255"
								required :disabled="formSaving" />
						</div>
						<div class="mb-3">
							<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
								<span class="form-label mb-0">Contenu initial</span>
								<div class="btn-group btn-group-sm" role="group" aria-label="Mode d’édition du contenu initial">
									<button type="button" :class="['btn', formEditorMode === 'rich' ? 'btn-primary' : 'btn-outline-primary']"
										:aria-pressed="formEditorMode === 'rich'" :disabled="formSaving" @click="formEditorMode = 'rich'">Texte riche</button>
									<button type="button" :class="['btn', formEditorMode === 'code' ? 'btn-primary' : 'btn-outline-primary']"
										:aria-pressed="formEditorMode === 'code'" :disabled="formSaving" @click="formEditorMode = 'code'">HTML</button>
								</div>
							</div>
							<rich_text_editor v-if="formEditorMode === 'rich'" v-model="formDraft.bodyHtml"
								:disabled="formSaving" :media-space-id="spaceId" :show-ai-button="false" />
							<code_editor v-else v-model="formDraft.bodyHtml" syntax="html" wrap-lines :max-bytes="131072"
								:read-only="formSaving" aria-label="Contenu HTML initial du modèle" class="email-template-code-editor" />
							<div class="form-text">
								Le modèle est créé dans l’espace « {{ selectedSpace?.title }} » en langue {{ locale.toUpperCase() }}.
								Les autres langues reprendront ce contenu de base.
							</div>
						</div>
					</template>
					<p v-else class="form-text">
						Le contenu se modifie dans la page, après avoir sélectionné ce modèle. Retirer ou renommer une variable
						utilisée par un texte existant est refusé.
					</p>
				</form>
			</template>
			<template #footer>
				<button type="button" class="btn btn-outline-secondary me-2 esc" :disabled="formSaving" @click="closeForm">Annuler</button>
				<button type="submit" form="email-template-form" class="btn btn-primary ctrl-s" :disabled="formSaving">
					{{ formMode === 'create' ? 'Ajouter' : 'Enregistrer' }}
				</button>
			</template>
		</modal>
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
			formVisible: false, formMode: 'create', formSaving: false, formError: '', formEditorMode: 'rich',
			formDraft: { key: '', label: '', description: '', renderMode: 'default', variables: [], subject: '', bodyHtml: '' },
		}
	},
	computed: {
		selectedSpace() { return this.spaces.find(space => space.id === this.spaceId) ?? null },
		dirty() { return Boolean(this.current && (this.draft.subject !== this.current.subject || this.draft.bodyHtml !== this.current.bodyHtml)) },
		canManage() { return Boolean(this.current?.custom && this.current.ownerSpaceId === this.spaceId) },
		canRestore() {
			if (!this.current?.local || !this.current.customizable) return false
			// Le contenu de base d'un modèle personnalisé ne peut pas être retiré.
			return !(this.current.custom && this.current.ownerSpaceId === this.spaceId && this.current.locale === this.current.baseLocale)
		},
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
			if (entry.source === 'parent') return `espace « ${entry.sourceSpaceTitle ?? entry.sourceSpaceId} »`
			if (entry.source === 'custom') return `contenu de base de l’espace « ${entry.sourceSpaceTitle ?? entry.ownerSpaceId} »`
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
			if (!this.canRestore || this.busy || !await this.askYesNoAsync('Restaurer la valeur héritée pour cette langue ?')) return
			this.busy = true
			this.error = ''
			try {
				await this.store().restore(this.spaceId, { key: this.selectedKey, locale: this.locale })
				await this.loadEntries(this.selectedKey)
				this.success = 'Valeur héritée restaurée.'
			} catch (_) { this.error = 'La personnalisation n’a pas pu être retirée.' }
			finally { this.busy = false }
		},
		async deleteCurrent() {
			if (!this.canManage || this.busy) return
			const label = this.current.label
			const message = `Supprimer le modèle « ${label} » ? Les personnalisations des espaces descendants seront aussi supprimées `
				+ 'et les scénarios qui utilisent sa clé échoueront.'
			if (!await this.askYesNoAsync(message)) return
			this.busy = true
			this.error = ''
			try {
				await this.store().remove(this.spaceId, { key: this.current.key })
				this.current = null
				this.selectedKey = null
				await this.loadEntries()
				this.success = 'Modèle supprimé.'
			} catch (_) { this.error = 'Le modèle n’a pas pu être supprimé.' }
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
		async openCreate() {
			if (this.busy || !this.spaces.length || !await this.canLeave()) return
			this.formMode = 'create'
			this.formDraft = { key: '', label: '', description: '', renderMode: 'default', variables: [], subject: '', bodyHtml: '' }
			this.formEditorMode = 'rich'
			this.formError = ''
			this.formVisible = true
		},
		async openMeta() {
			if (!this.canManage || this.busy || !await this.canLeave()) return
			this.formMode = 'meta'
			this.formDraft = {
				key: this.current.key,
				label: this.current.label,
				description: this.current.description,
				renderMode: this.current.renderMode,
				variables: this.current.variables.map(variable => ({ name: variable.name, example: variable.example ?? '' })),
				subject: '',
				bodyHtml: '',
			}
			this.formEditorMode = 'rich'
			this.formError = ''
			this.formVisible = true
		},
		closeForm() {
			if (this.formSaving) return
			this.formVisible = false
			this.formError = ''
		},
		addVariable() {
			if (this.formSaving || this.formDraft.variables.length >= 20) return
			this.formDraft.variables.push({ name: '', example: '' })
		},
		removeVariable(index) {
			if (this.formSaving) return
			this.formDraft.variables.splice(index, 1)
		},
		normalizedVariables() {
			return this.formDraft.variables
				.map(variable => ({ name: variable.name.trim(), example: variable.example }))
				.filter(variable => variable.name !== '' || variable.example.trim() !== '')
		},
		async saveForm() {
			if (this.formSaving || this.busy) return
			if (!this.formDraft.label.trim()) { this.formError = 'Le libellé est obligatoire.'; return }
			if (this.formMode === 'create' && (!this.formDraft.subject.trim() || !this.formDraft.bodyHtml.trim())) {
				this.formError = 'L’objet et le contenu initiaux sont obligatoires.'
				return
			}

			const variables = this.normalizedVariables()
			this.formSaving = true
			this.formError = ''
			try {
				if (this.formMode === 'create') {
					const result = await this.store().create(this.spaceId, {
						label: this.formDraft.label.trim(),
						description: this.formDraft.description.trim(),
						renderMode: this.formDraft.renderMode,
						locale: this.locale,
						subject: this.formDraft.subject,
						bodyHtml: this.formDraft.bodyHtml,
						variables,
					})
					this.formVisible = false
					await this.loadEntries(result.key)
					this.success = 'Modèle ajouté.'
				} else {
					await this.store().update(this.spaceId, {
						key: this.formDraft.key,
						label: this.formDraft.label.trim(),
						description: this.formDraft.description.trim(),
						renderMode: this.formDraft.renderMode,
						variables,
					})
					this.formVisible = false
					await this.loadEntries(this.formDraft.key)
					this.success = 'Informations du modèle enregistrées.'
				}
			} catch (error) {
				this.formError = error?.message ?? (this.formMode === 'create'
					? 'Impossible d’ajouter ce modèle. Vérifiez ses variables, son objet et son contenu.'
					: 'Impossible d’enregistrer ces informations.')
			} finally { this.formSaving = false }
		},
	},
}
</script>

<style scoped>
.email-template-code-editor :deep(.cm-editor) { min-height: 320px; }
.email-template-code-editor :deep(.cm-scroller) { max-height: 60vh; overflow: auto; }
</style>
