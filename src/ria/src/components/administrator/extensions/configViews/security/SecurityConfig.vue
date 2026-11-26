<template>
	<form v-if="editableForm" @submit.prevent="submit">
		<form_renderer
			:key="`${extension.name}:${space?.id ?? ''}`"
			v-model="draft"
			:definition="editableForm"
			:context="{ spaceId: space?.id ?? null }"
			:errors="errors"
		>
			<template #section-footer="{ section }">
				<WafInheritedConfiguration
					v-if="section.id === 'waf' && inheritsRootWaf"
					:configuration="extension.conf_by_spaces?.[1]?.params ?? null"
				/>
			</template>
		</form_renderer>

		<div class="d-flex align-items-center gap-3 mt-4">
			<button type="submit" class="btn btn-primary ctrl-s" :disabled="saving || !space">
				<span v-if="saving" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
				Enregistrer
			</button>
		</div>
	</form>

	<div v-else class="alert alert-secondary mb-0">
		Cette extension ne déclare pas de formulaire de configuration.
	</div>
</template>

<script>
import WafInheritedConfiguration from './WafInheritedConfiguration.vue'
import { securityWafForm } from './securityWafForm.js'

export default {
	components: { WafInheritedConfiguration },
	props: {
		extension: { type: Object, required: true },
		spaces: { type: Array, default: () => [] },
		space: { type: Object, default: null },
		definition: { type: Object, default: null },
		params: { type: Object, required: true },
		errors: { type: Object, default: () => ({}) },
		saving: { type: Boolean, default: false },
		save: { type: Function, required: true },
	},

	computed: {
		draft: {
			get() { return this.params },
			set(value) { this.$emit('update:params', value) },
		},

		// Le Space Root ne peut pas hériter de lui-même : son choix reste désactivé.
		configurationForm() {
			return securityWafForm(this.definition, this.space?.id ?? null, false)
		},

		inheritsRootWaf() {
			return this.space !== null && this.space.id !== 1 && this.draft.waf_use_root === true
		},

		// L’héritage masque seulement les contrôles locaux, sans changer la soumission.
		editableForm() {
			if (!this.inheritsRootWaf) return this.configurationForm
			return securityWafForm(this.configurationForm, this.space?.id ?? null, true)
		},
	},

	methods: {
		async submit() {
			if (this.saving || !this.space) return

			try {
				await this.save(this.draft)
			} catch {
				// Le store conserve le message ; le brouillon reste affiché.
			}
		},
	},
}
</script>
