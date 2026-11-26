<template>
	<form v-if="definition" @submit.prevent="submit">
		<div class="alert alert-info" role="status">
			<strong>Réglages du catalogue LMS.</strong>
			<p class="mb-0">
				Ces valeurs s’appliquent aux listes de cours de cet espace. La vue est fournie
				par l’extension et peut compléter le formulaire commun par ses propres appels.
			</p>
		</div>

		<form_renderer
			:key="`${extension.name}:${space?.id ?? ''}`"
			v-model="draft"
			:definition="definition"
			:context="{ spaceId: space?.id ?? null }"
			:errors="errors"
		/>

		<div class="card mt-3">
			<div class="card-body">
				<h3 class="h5">Aperçu de la liste</h3>
				<p class="mb-0">
					La liste affichera jusqu’à <strong>{{ draft.courses_per_page ?? '—' }}</strong> cours par page
					{{ draft.show_course_count ? ' et indiquera le nombre total de cours.' : '.' }}
				</p>
			</div>
		</div>

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
export default {
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
	},

	methods: {
		async submit() {
			if (this.saving || !this.space) return

			try {
				await this.save(this.draft)
			} catch {
				// Le store du Core affiche le message ; le brouillon reste en place.
			}
		},
	},
}
</script>
