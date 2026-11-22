<template>
	<modal :visible="visible" width="650px" :large-body="true" :closable="!saving" @close="$emit('close')">
		<template #header>{{ menu ? 'Modifier un menu' : 'Ajouter un menu' }}</template>

		<template #body>
			<form id="menu-editor-form" @submit.prevent="submit">
				<div v-if="error" class="alert alert-warning">{{ error }}</div>
				<div v-if="loading" class="py-5 text-center"><spinner /></div>
				<form_renderer
					v-else-if="formDefinition"
					v-model="draft"
					:definition="formDefinition"
					:errors="fieldErrors"
					:class="{ 'pe-none opacity-50': saving }"
				/>
			</form>
		</template>

		<template #footer>
			<button type="button" class="btn btn-outline-secondary me-2" :disabled="saving" @click="$emit('close')">Annuler</button>
			<button type="submit" form="menu-editor-form" class="btn btn-primary" :disabled="saving || loading || !formDefinition">
				<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
				{{ menu ? 'Enregistrer' : 'Ajouter' }}
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
		menu: { type: Object, default: null },
		saving: { type: Boolean, default: false },
	},
	emits: ['close', 'save'],

	data() {
		return {
			draft: {},
			formDefinition: null,
			fieldErrors: {},
			loading: false,
			error: '',
			requestId: 0,
		}
	},

	watch: {
		visible(visible) {
			if (visible) this.loadForm()
		},
		menu() {
			if (this.visible) this.loadForm()
		},
	},

	methods: {
		async loadForm() {
			const requestId = ++this.requestId
			this.error = ''
			this.fieldErrors = {}
			this.loading = true
			this.formDefinition = null
			this.draft = {}

			try {
				const response = await this.api.get('/administrator/api/menus/form', {
					menuId: this.menu?.id ?? null,
				})
				if (requestId !== this.requestId) return

				this.formDefinition = response.form ?? null
				this.draft = cloneJson(this.formDefinition?.initialValues ?? {})
			} catch (_) {
				if (requestId === this.requestId) this.error = 'Impossible de charger le formulaire du menu.'
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},

		submit() {
			if (this.saving || this.loading || !this.formDefinition) return

			this.fieldErrors = {}
			const values = {}
			for (const field of this.formDefinition.sections?.flatMap((section) => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(this.draft, field.name)) continue
				values[field.name] = this.draft[field.name]
			}

			this.$emit('save', values)
		},
	},
}
</script>
