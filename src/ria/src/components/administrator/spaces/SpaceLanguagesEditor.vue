<template>
	<div class="mt-2">
		<div v-if="loading" class="py-3 text-center"><spinner /></div>
		<div v-else>
			<div v-if="error" class="alert alert-warning" role="alert">{{ error }} <button v-if="!loaded" type="button" class="btn btn-link btn-sm" @click="load">Réessayer</button></div>
			<template v-if="loaded">
				<p class="text-body-secondary small mb-3">Choisissez les langues disponibles dans cet espace. Configurez ensuite leur accueil dans Navigation.</p>
				<div class="table-responsive">
					<table class="table table-sm align-middle mb-2">
						<thead><tr><th scope="col">Langue</th><th scope="col" class="text-center">Active</th><th scope="col" class="text-center">Par défaut</th><th scope="col" class="text-end">Ordre</th></tr></thead>
						<tbody>
							<tr v-for="(row, index) in rows" :key="row.locale">
								<td><strong>{{ row.locale }}</strong><span v-if="!row.persisted" class="badge text-bg-info ms-2">Nouvelle</span></td>
								<td class="text-center"><input class="form-check-input" type="checkbox" :aria-label="`Activer ${row.locale}`" :checked="row.enabled" :disabled="!canEdit || disabled || row.is_default" @change="row.enabled = $event.target.checked" /></td>
								<td class="text-center"><input class="form-check-input" type="radio" :name="`default-locale-${spaceId}`" :aria-label="`${row.locale} par défaut`" :checked="row.is_default" :disabled="!canEdit || disabled || !row.enabled" @change="setDefault(row.locale)" /></td>
								<td class="text-end text-nowrap">
									<button type="button" class="btn btn-outline-secondary btn-sm me-1" :aria-label="`Monter ${row.locale}`" :disabled="!canEdit || disabled || index === 0" @click="move(index, -1)">↑</button>
									<button type="button" class="btn btn-outline-secondary btn-sm" :aria-label="`Descendre ${row.locale}`" :disabled="!canEdit || disabled || index === rows.length - 1" @click="move(index, 1)">↓</button>
									<button v-if="!row.persisted" type="button" class="btn btn-outline-danger btn-sm ms-1" :aria-label="`Retirer ${row.locale}`" :disabled="!canEdit || disabled" @click="removeNew(index)">Retirer</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				<div v-if="canEdit" class="d-flex flex-wrap gap-2 align-items-end">
					<div class="flex-grow-1" style="min-width: 180px; max-width: 300px">
						<label :for="`new-space-locale-${spaceId}`" class="form-label mb-1">Ajouter une langue</label>
						<input :id="`new-space-locale-${spaceId}`" v-model.trim="newLocale" type="text" class="form-control form-control-sm" autocomplete="off" placeholder="ex. en ou fr-CA" :disabled="disabled || rows.length >= 20" @keydown.enter.prevent="addLanguage" />
					</div>
					<button type="button" class="btn btn-outline-primary btn-sm" :disabled="disabled || rows.length >= 20 || !newLocale" @click="addLanguage">Ajouter</button>
				</div>
				<p v-if="canEdit" class="form-text">Une langue enregistrée peut être désactivée, mais pas supprimée. Son code reste stable pour les futurs contenus.</p>
				<div v-if="notice" class="alert alert-warning py-2" role="alert">{{ notice }}</div>
				<div v-if="validationMessage" class="alert alert-warning py-2" role="alert">{{ validationMessage }}</div>
				<div v-if="canEdit" class="d-flex align-items-center gap-2 mt-3">
					<button type="button" class="btn btn-primary btn-sm" :disabled="disabled || !isDirty || !!validationMessage" @click="save">
						<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer les langues
					</button>
					<span v-if="saved && !isDirty" class="text-success small" role="status">Langues enregistrées.</span>
				</div>
			</template>
		</div>
	</div>
</template>

<script>
const localePattern = /^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/

export default {
	props: {
		spaceId: { type: Number, required: true },
		busy: { type: Boolean, default: false },
	},
	emits: ['busy-change', 'dirty-change', 'saved'],
	data() {
		return {
			rows: [], savedValues: '[]', newLocale: '', canEdit: false,
			loading: false, loaded: false, saving: false, error: '', notice: '', saved: false, requestId: 0,
		}
	},
	computed: {
		disabled() { return this.busy || this.saving },
		values() { return this.rows.map(({ locale, enabled, is_default }) => ({ locale, enabled, is_default })) },
		isDirty() { return JSON.stringify(this.values) !== this.savedValues },
		validationMessage() {
			if (!this.loaded) return ''
			if (!this.rows.length) return 'Ajoutez au moins une langue.'
			if (this.rows.filter(row => row.is_default && row.enabled).length !== 1) return 'Choisissez une seule langue active par défaut.'
			return ''
		},
	},
	watch: {
		spaceId: { immediate: true, handler() { this.load() } },
		isDirty(value) { this.$emit('dirty-change', this.loaded && value) },
	},
	methods: {
		async load() {
			const requestId = ++this.requestId
			this.loading = true
			this.loaded = false
			this.error = ''
			this.notice = ''
			this.saved = false
			try {
				const response = await this.stores.useAdminSpacesStore().getLanguages(this.spaceId)
				if (requestId !== this.requestId) return
				this.rows = (response.languages ?? []).map(row => ({ ...row, persisted: true }))
				this.savedValues = JSON.stringify(this.values)
				this.canEdit = response.canEdit === true
				this.loaded = true
				this.$emit('dirty-change', false)
			} catch (_) {
				if (requestId === this.requestId) this.error = 'Impossible de charger les langues de cet espace.'
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},
		addLanguage() {
			this.notice = ''
			this.saved = false
			const locale = this.newLocale.trim().toLowerCase()
			if (!localePattern.test(locale) || locale.length > 35) {
				this.notice = 'Saisissez un code de langue comme fr, en ou fr-CA.'
				return
			}
			if (this.rows.some(row => row.locale.toLowerCase() === locale)) {
				this.notice = 'Cette langue existe déjà dans cet espace.'
				return
			}
			this.rows.push({ locale, enabled: true, is_default: this.rows.length === 0, persisted: false })
			this.newLocale = ''
		},
		setDefault(locale) {
			this.saved = false
			this.rows.forEach(row => { row.is_default = row.locale === locale })
		},
		move(index, direction) {
			this.saved = false
			const target = index + direction
			if (target < 0 || target >= this.rows.length) return
			const [row] = this.rows.splice(index, 1)
			this.rows.splice(target, 0, row)
		},
		removeNew(index) {
			if (this.rows[index]?.persisted) return
			this.saved = false
			this.rows.splice(index, 1)
		},
		async save() {
			if (!this.canEdit || this.disabled || !this.isDirty || this.validationMessage) return
			this.saving = true
			this.$emit('busy-change', true)
			this.error = ''
			this.notice = ''
			this.saved = false
			try {
				const response = await this.stores.useAdminSpacesStore().saveLanguages(this.spaceId, this.values)
				this.rows = (response.languages ?? []).map(row => ({ ...row, persisted: true }))
				this.savedValues = JSON.stringify(this.values)
				this.saved = true
				this.$emit('saved')
			} catch (_) {
				this.error = 'Les langues n’ont pas pu être enregistrées.'
			} finally {
				this.saving = false
				this.$emit('busy-change', false)
			}
		},
	},
}
</script>
