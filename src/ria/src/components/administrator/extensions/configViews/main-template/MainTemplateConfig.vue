<template>
	<form v-if="definition" @submit.prevent="submit">
		<form_renderer
			:key="`${extension.name}:${space?.id ?? ''}`"
			v-model="draft"
			:definition="definition"
			:context="{ spaceId: space?.id ?? null }"
			:errors="errors"
		>
			<template #section-header="{ section }">
				<p v-if="section.id === 'theme_typography'" class="text-body-secondary">
					Les polices téléchargées dans la rubrique « Polices » de l’administration sont proposées ici après installation.
				</p>
				<div v-if="['email_default', 'email_blank'].includes(section.id) && !space?.params?.main_host"
					class="alert alert-warning mb-3" role="alert">
					Le domaine principal de cet espace n’est pas renseigné. Ajoutez-le dans les paramètres de l’espace pour utiliser les e-mails avec Template.
				</div>
			</template>
			<template #section-footer="{ section }">
				<EmailTestPanel
					v-if="canTestMail && ['email_default', 'email_blank'].includes(section.id)"
					:key="`${space.id}:${section.id}`"
					:mode="section.id === 'email_default' ? 'default' : 'blank'"
					:space-id="space.id" :sender-email="space.mail_from" :main-host="space.params?.main_host"
					:definition="definition" :draft-params="draft" :saving="saving"
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
import EmailTestPanel from './EmailTestPanel.vue'

export default {
	components: { EmailTestPanel },
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

		// L’essai d’envoi n’a de sens que pour un espace qui utilise cette Template.
		canTestMail() {
			return this.space?.template === 'tpl_main'
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
