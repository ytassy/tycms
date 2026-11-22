<template>
	<modal :visible="visible" width="800px" :large-body="true" :closable="!saving" @close="$emit('close')">
		<template #header>{{ item ? 'Modifier un élément de menu' : 'Ajouter un élément de menu' }}</template>

		<template #body>
			<form id="menu-item-editor-form" @submit.prevent="submit">
				<div v-if="error" class="alert alert-warning">{{ error }}</div>

				<div v-if="loading" class="py-5 text-center">
					<spinner />
				</div>
				<form_renderer
					v-else-if="formDefinition"
					v-model="draft"
					:definition="formDefinition"
					:errors="fieldErrors"
					:class="{ 'pe-none opacity-50': saving }"
				>
					<template #section-footer="{ section }">
						<div v-if="section.id === 'general' && paramsLoading" class="py-4 text-center">
							<spinner />
						</div>
						<form_renderer
							v-else-if="section.id === 'general' && paramsFormDefinition"
							v-model="paramsDraft"
							:definition="inlineParamsFormDefinition"
							:errors="fieldErrors.params ?? {}"
							:class="{ 'pe-none opacity-50': saving }"
						/>
						<div v-if="section.id === 'general' && languagesDraft.length" class="row mb-3">
							<div class="menu-languages__label col-form-label">Langues</div>
							<div class="menu-languages__control d-flex flex-column align-items-start gap-2">
								<div v-for="language in languagesDraft" :key="language.locale" class="form-check form-switch mb-0">
									<input :id="`menu-language-${language.locale}`" v-model="language.enabled" class="form-check-input" type="checkbox" :disabled="saving || requiredLocales.includes(language.locale)">
									<label class="form-check-label" :for="`menu-language-${language.locale}`">{{ language.locale }}</label>
								</div>
							</div>
						</div>
					</template>
				</form_renderer>

			</form>
		</template>

		<template #footer>
			<button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="$emit('close')">Annuler</button>
			<button type="submit" form="menu-item-editor-form" class="btn btn-primary" :disabled="saving || loading || paramsLoading || !formDefinition">
				<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
				{{ item ? 'Enregistrer' : 'Ajouter' }}
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
		menu: { type: Object, required: true },
		item: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},
	emits: ['close', 'save'],

	data() {
		return {
			draft: {},
			formDefinition: null,
			paramsDraft: {},
			paramsFormDefinition: null,
			fieldErrors: {},
			loading: false,
			paramsLoading: false,
			error: '',
			requestId: 0,
			paramsRequestId: 0,
			activeDefinitionKey: null,
			languagesDraft: [],
			homeLocales: [],
			userSpaceLocales: [],
			requiredLocales: [],
		}
	},

	computed: {
		inlineParamsFormDefinition() {
			return this.paramsFormDefinition
				? { ...this.paramsFormDefinition, hideSingleSectionTab: true }
				: null
		},
	},

	watch: {
		visible(visible) {
			if (visible) this.loadForm()
		},
		item() {
			if (this.visible) this.loadForm()
		},
		'draft.definitionKey'(definitionKey) {
			if (this.loading || definitionKey === this.activeDefinitionKey) return
			this.activeDefinitionKey = definitionKey
			this.loadParamsForm(definitionKey)
		},
	},

	methods: {
		async loadForm() {
			const requestId = ++this.requestId
			this.error = ''
			this.fieldErrors = {}
			this.loading = true
			++this.paramsRequestId
			this.paramsLoading = false
			this.formDefinition = null
			this.draft = {}
			this.activeDefinitionKey = null
			this.paramsFormDefinition = null
			this.paramsDraft = {}
			this.languagesDraft = []
			this.homeLocales = []
			this.userSpaceLocales = []
			this.requiredLocales = []

			try {
				const response = await this.api.get('/administrator/api/menus/item/form', {
					menuId: this.menu.id,
					menuItemId: this.item?.id ?? null,
				})
				if (requestId !== this.requestId) return

				this.formDefinition = response.form ?? null
				this.draft = cloneJson(this.formDefinition?.initialValues ?? {})
				this.activeDefinitionKey = this.draft.definitionKey ?? null
				this.paramsFormDefinition = response.paramsForm ?? null
				this.paramsDraft = cloneJson(this.paramsFormDefinition?.initialValues ?? {})
				this.languagesDraft = cloneJson(response.languages ?? [])
				this.homeLocales = response.homeLocales ?? []
				this.userSpaceLocales = response.userSpaceLocales ?? []
				this.requiredLocales = response.requiredLocales ?? []
			} catch (_) {
				if (requestId === this.requestId) {
					this.error = 'Impossible de charger le formulaire de l’élément de menu.'
				}
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},

		async loadParamsForm(definitionKey) {
			const requestId = ++this.paramsRequestId
			this.error = ''
			this.paramsLoading = true
			this.paramsFormDefinition = null
			this.paramsDraft = {}

			if (!definitionKey) {
				this.paramsLoading = false
				return
			}

			try {
				const response = await this.api.get('/administrator/api/menus/item/form', {
					menuId: this.menu.id,
					menuItemId: this.item?.id ?? null,
					definitionKey,
				})
				if (requestId !== this.paramsRequestId || definitionKey !== this.draft.definitionKey) return

				this.paramsFormDefinition = response.paramsForm ?? null
				this.paramsDraft = cloneJson(this.paramsFormDefinition?.initialValues ?? {})
			} catch (_) {
				if (requestId === this.paramsRequestId) {
					this.error = 'Impossible de charger les paramètres du type sélectionné.'
				}
			} finally {
				if (requestId === this.paramsRequestId) this.paramsLoading = false
			}
		},

		submit() {
			if (this.saving || this.loading || this.paramsLoading || !this.formDefinition) return

			this.fieldErrors = {}
			const values = this.getSubmittedValues(this.formDefinition, this.draft)
			values.params = this.paramsFormDefinition
				? this.getSubmittedValues(this.paramsFormDefinition, this.paramsDraft)
				: {}
			values.languages = cloneJson(this.languagesDraft)

			this.$emit('save', values)
		},

		getSubmittedValues(definition, values) {
			const submittedValues = {}
			for (const field of definition.sections?.flatMap((section) => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(values, field.name)) continue
				submittedValues[field.name] = values[field.name]
			}

			return submittedValues
		},
	},
}
</script>

<style scoped>
@media (min-width: 768px) {
	.menu-languages__label {
		flex: 0 0 min(33.333333%, 350px);
		width: min(33.333333%, 350px);
	}

	.menu-languages__control {
		flex: 1 0 0;
		width: auto;
	}
}
</style>
