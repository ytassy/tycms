<template>
	<modal :visible="visible" width="1200px" :large-body="true" :closable="!saving && !testSending" @close="$emit('close')">
		<template #header>{{ space ? 'Modifier un espace' : 'Ajouter un espace' }}</template>

		<template #body>
			<form id="space-editor-form" @submit.prevent="submit">
				<div v-if="error" class="alert alert-warning">{{ error }}</div>
				<div v-if="loading" class="py-5 text-center"><spinner /></div>
				<div v-else-if="formDefinition">
					<form_renderer
						:model-value="draft"
						:definition="formDefinition"
						:errors="fieldErrors"
						:context="{ spaceId: space?.id ?? 0 }"
						:class="{ 'pe-none opacity-50': saving || testSending }"
						@update:model-value="updateDraft"
					>
						<template #section-footer="{ section }">
							<div v-if="section.id === 'mail' && space" class="card mt-3">
								<div class="card-body">
									<label for="space-mail-test-recipient" class="form-label fw-semibold">Destinataire du mail de test</label>
									<div class="d-flex gap-2 align-items-start">
										<input id="space-mail-test-recipient" v-model.trim="testRecipient" type="text" inputmode="email" autocomplete="email" class="form-control" placeholder="destinataire@exemple.fr" :disabled="saving || testSending" />
										<button type="button" class="btn btn-outline-primary flex-shrink-0" :disabled="saving || testSending || !testRecipient || !draft.mail_online" @click="sendTestMail">
											<span v-if="testSending" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
											Tester l’envoi de mail
										</button>
									</div>
									<p class="form-text mb-0">Utilise les réglages SMTP saisis, sans les enregistrer. Un mot de passe laissé vide conserve celui de l’espace.</p>
									<p v-if="!draft.mail_online" class="form-text mb-0">Activez l’envoi d’e-mails pour effectuer un essai.</p>
									<div v-if="testSuccess" class="alert alert-success mt-2 mb-0" role="status">Le serveur SMTP a accepté le mail de test.</div>
									<div v-if="testError" class="alert alert-warning mt-2 mb-0" role="alert">{{ testError }}</div>
								</div>
							</div>
							<div v-if="section.id === 'sso' && ssoProviderField && selectedSsoProviders.length" class="card mt-3">
								<div class="card-body">
									<p class="fw-semibold mb-1">Ordre des fournisseurs sélectionnés</p>
									<p class="text-body-secondary small">Seuls les fournisseurs avec une connexion active affichent un bouton.</p>
									<ol class="list-group list-group-numbered">
										<li v-for="(provider, index) in selectedSsoProviders" :key="provider.id" class="list-group-item d-flex align-items-center justify-content-between gap-2">
											<span>{{ provider.label }}</span>
											<div class="btn-group btn-group-sm" role="group" :aria-label="`Ordre de ${provider.label}`">
												<button type="button" class="btn btn-outline-secondary" :disabled="saving || index === 0" @click="moveProvider(index, -1)">Monter</button>
												<button type="button" class="btn btn-outline-secondary" :disabled="saving || index === selectedSsoProviders.length - 1" @click="moveProvider(index, 1)">Descendre</button>
												<button type="button" class="btn btn-outline-danger" :disabled="saving" @click="removeProvider(provider.id)">Retirer</button>
											</div>
										</li>
									</ol>
								</div>
							</div>
						</template>
					</form_renderer>
				</div>
			</form>
		</template>

		<template #footer>
			<button v-if="canDelete" type="button" class="btn btn-outline-danger me-auto" :disabled="saving || testSending" @click="$emit('delete')">Supprimer</button>
			<button type="button" class="btn btn-outline-secondary me-2" :disabled="saving || testSending" @click="$emit('close')">Annuler</button>
			<button type="submit" form="space-editor-form" class="btn btn-primary" :disabled="saving || testSending || loading || !formDefinition">
				<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
				{{ space ? 'Enregistrer' : 'Ajouter' }}
			</button>
		</template>
	</modal>
</template>

<script>

function cloneJson(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

export default {
	props: {
		visible: { type: Boolean, default: false },
		space: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},
	emits: ['close', 'save', 'delete'],

	data() {
		return {
			draft: {}, formDefinition: null, fieldErrors: {}, loading: false, error: '',
			canDelete: false, requestId: 0, testRecipient: '', testSending: false, testSuccess: false, testError: '',
		}
	},

	watch: {
		visible(visible) { if (visible) this.loadForm() },
		space() { if (this.visible) this.loadForm() },
		testRecipient() { this.testSuccess = false; this.testError = '' },
	},
	computed: {
			ssoProviderField() {
				return this.formDefinition?.sections?.flatMap(section => section.fields ?? [])
					.find(field => field.name === 'sso_provider_ids') ?? null
			},
			selectedSsoProviders() {
				const options = new Map((this.ssoProviderField?.options ?? []).map(option => [option.value, option.label]))
				return (this.draft.sso_provider_ids ?? []).map(id => ({
					id,
					label: options.get(id) ?? `Fournisseur #${id} indisponible`,
				}))
			},
	},

	methods: {
		updateDraft(values) {
			this.testSuccess = false
			this.testError = ''
			const selected = values.sso_provider_ids
			const previous = this.draft.sso_provider_ids
			if (Array.isArray(selected) && Array.isArray(previous)) {
				const selectedIds = new Set(selected)
				const previousIds = new Set(previous)
				values = {
					...values,
					sso_provider_ids: [
						...previous.filter(id => selectedIds.has(id)),
						...selected.filter(id => !previousIds.has(id)),
					],
				}
			}
			this.draft = values
		},
		moveProvider(index, direction) {
			const ids = [...(this.draft.sso_provider_ids ?? [])]
			const target = index + direction
			if (target < 0 || target >= ids.length) return
			const moving = ids[index]
			ids[index] = ids[target]
			ids[target] = moving
			this.draft = { ...this.draft, sso_provider_ids: ids }
		},
		removeProvider(id) {
			this.draft = {
				...this.draft,
				sso_provider_ids: (this.draft.sso_provider_ids ?? []).filter(selectedId => selectedId !== id),
			}
		},
		async loadForm() {
			const requestId = ++this.requestId
			this.error = ''
			this.fieldErrors = {}
			this.loading = true
			this.formDefinition = null
			this.draft = {}
			this.canDelete = false
			this.testRecipient = ''
			this.testSuccess = false
			this.testError = ''

			try {
				const response = await this.api.get('/administrator/api/spaces/form', { spaceId: this.space?.id ?? null })
				if (requestId !== this.requestId) return
				this.formDefinition = response.form ?? null
				this.draft = cloneJson(this.formDefinition?.initialValues ?? {})
				this.canDelete = response.canDelete === true
			} catch (_) {
				if (requestId === this.requestId) this.error = 'Impossible de charger le formulaire de l’espace.'
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},

		submittedValues() {
			const values = {}
			for (const field of this.formDefinition.sections?.flatMap(section => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(this.draft, field.name)) continue
				values[field.name] = this.draft[field.name]
			}
			return values
		},
		async sendTestMail() {
			if (!this.space || this.saving || this.testSending || !this.formDefinition || !this.draft.mail_online) return
			const values = this.submittedValues()
			const settings = Object.fromEntries([
				'mail_online', 'mailer', 'mail_from', 'mail_from_name', 'mail_smtp_auth',
				'mail_smtp_user', 'mail_smtp_pass', 'mail_smtp_host', 'mail_smtp_secure', 'mail_smtp_port',
			].map(key => [key, values[key]]))
			this.testSending = true
			this.testSuccess = false
			this.testError = ''
			try {
				const response = await this.stores.useAdminSpacesStore().sendTestMail(this.space.id, this.testRecipient, settings)
				this.testSuccess = response.sent === true
			} catch (_) {
				this.testError = 'Le mail de test n’a pas pu être envoyé.'
			} finally {
				this.testSending = false
			}
		},
		async submit() {
			if (this.saving || this.loading || !this.formDefinition) return
			const requestId = this.requestId
			const values = this.submittedValues()
			const currentDir = this.formDefinition.initialValues?.medias_dir
			if (this.space && currentDir !== undefined && values.medias_dir !== currentDir) {
				if (!await this.askYesNoAsync('Le dossier des médias sera renommé avec ses fichiers. Les anciennes URL et références ne seront pas modifiées et devront être corrigées manuellement. Continuer ?')) return
				if (!this.visible || this.requestId !== requestId || this.saving || JSON.stringify(values) !== JSON.stringify(this.submittedValues())) return
			}
			this.fieldErrors = {}
			this.$emit('save', values)
		},
	},
}
</script>
