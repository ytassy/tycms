<template>
	<div>
		<div v-if="loading" class="py-3 text-center"><spinner /></div>
		<div v-else-if="loaded">
			<div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
			<div class="row mb-3">
				<label :for="`space-home-${spaceId}`" class="col-md-4 col-form-label">Accueil général</label>
				<div class="col-md-8">
					<select :id="`space-home-${spaceId}`" v-model.number="draft.home_item_id" class="form-select" :disabled="disabled || !canEdit">
						<option :value="0">Aucun</option>
						<option v-for="item in homeOptions" :key="item.id" :value="item.id">{{ item.label }}</option>
					</select>
					<div class="form-text">Utilisé pour les langues sans accueil spécifique.</div>
				</div>
			</div>
			<div class="row mb-3">
				<label :for="`space-user-menu-${spaceId}`" class="col-md-4 col-form-label">Espace utilisateur</label>
				<div class="col-md-8">
					<select :id="`space-user-menu-${spaceId}`" v-model.number="draft.user_space_item_id" class="form-select" :disabled="disabled || !canEdit">
						<option :value="0">Aucun</option>
						<option v-for="item in userSpaceOptions" :key="item.id" :value="item.id">{{ item.label }}</option>
					</select>
				</div>
			</div>
            <div v-for="field in authFields" :key="field.key" class="row mb-3">
                <label :for="`space-${field.key}-${spaceId}`" class="col-md-4 col-form-label">{{ field.label }}</label>
                <div class="col-md-8">
                    <select :id="`space-${field.key}-${spaceId}`" v-model.number="draft[field.key]" class="form-select" :disabled="disabled || !canEdit">
                        <option :value="0">Aucune</option>
                        <option v-for="item in authOptions" :key="item.id" :value="item.id">{{ item.label }}</option>
                    </select>
                    <div class="form-text">{{ field.help }}</div>
                </div>
            </div>
			<div class="row mb-3">
				<div class="col-md-4 col-form-label">Accueil par langue</div>
				<div class="col-md-8">
					<div v-for="language in draft.languages" :key="language.locale" class="row align-items-center g-2 mb-2">
						<label :for="`space-home-${spaceId}-${language.locale}`" class="col-sm-2 col-form-label">{{ language.locale }}</label>
						<div class="col-sm-10">
							<select :id="`space-home-${spaceId}-${language.locale}`" v-model.number="language.home_item_id" class="form-select" :disabled="disabled || !canEdit">
								<option :value="0">Accueil général</option>
								<option v-for="item in homeOptions" :key="item.id" :value="item.id">{{ item.label }}</option>
							</select>
						</div>
					</div>
					<div class="form-text">Créez une entrée de menu par langue, puis sélectionnez ici celle qui sert d’accueil.</div>
				</div>
			</div>
			<div class="row mb-3">
				<div class="col-md-4 col-form-label">Espace utilisateur par langue</div>
				<div class="col-md-8">
					<div v-for="language in draft.languages" :key="language.locale" class="row align-items-center g-2 mb-2">
						<label :for="`space-user-${spaceId}-${language.locale}`" class="col-sm-2 col-form-label">{{ language.locale }}</label>
						<div class="col-sm-10">
							<select :id="`space-user-${spaceId}-${language.locale}`" v-model.number="language.user_space_item_id" class="form-select" :disabled="disabled || !canEdit">
								<option :value="0">Espace utilisateur général</option>
								<option v-for="item in userSpaceOptions" :key="item.id" :value="item.id">{{ item.label }}</option>
							</select>
						</div>
					</div>
					<div class="form-text">Chaque langue utilise son choix spécifique, ou l’entrée générale si aucun choix n’est fait.</div>
				</div>
			</div>
			<div v-if="canEdit" class="d-flex align-items-center gap-2 mt-3">
				<button type="button" class="btn btn-primary btn-sm" :disabled="disabled || !isDirty" @click="save">
					<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer la navigation
				</button>
				<span v-if="saved && !isDirty" class="text-success small" role="status">Navigation enregistrée.</span>
			</div>
		</div>
		<div v-else-if="error" class="alert alert-warning" role="alert">{{ error }} <button type="button" class="btn btn-link btn-sm" @click="load">Réessayer</button></div>
	</div>
</template>

<script>
const cloneJson = value => JSON.parse(JSON.stringify(value))

export default {
	props: {
		spaceId: { type: Number, required: true },
		busy: { type: Boolean, default: false },
	},
	emits: ['busy-change', 'dirty-change'],
	data() {
		return {
			draft: { home_item_id: 0, user_space_item_id: 0, login_item_id: 0, register_item_id: 0, languages: [] },
            authFields: [
                { key: 'login_item_id', label: 'Page de connexion', help: 'Page contenant le composant Login. Sans choix, /login-fb sert de secours.' },
                { key: 'register_item_id', label: 'Page d’inscription', help: 'Destination des liens d’inscription et du composant Changer de Space. Facultative pour les composants Register.' },
            ],
			savedValues: '', options: [], canEdit: false,
			loading: false, loaded: false, saving: false, saved: false, error: '', requestId: 0,
		}
	},
	computed: {
		homeOptions() { return this.options.filter(item => item.homeCapable) },
		authOptions() { return this.options.filter(item => item.authCapable) },
		userSpaceOptions() { return this.options.filter(item => item.userSpaceCapable) },
		disabled() { return this.busy || this.saving },
		isDirty() { return this.loaded && JSON.stringify(this.draft) !== this.savedValues },
	},
	watch: {
		spaceId: { immediate: true, handler() { this.load() } },
		isDirty(value) { this.$emit('dirty-change', value) },
	},
	methods: {
		async load() {
			const requestId = ++this.requestId
			this.loading = true
			this.loaded = false
			this.error = ''
			this.saved = false
			try {
				const response = await this.stores.useAdminSpacesStore().getNavigation(this.spaceId)
				if (requestId !== this.requestId) return
				this.draft = {
					home_item_id: response.home_item_id ?? 0,
					user_space_item_id: response.user_space_item_id ?? 0,
                    login_item_id: response.login_item_id ?? 0,
                    register_item_id: response.register_item_id ?? 0,
					languages: cloneJson(response.languages ?? []),
				}
				this.options = response.options ?? []
				this.canEdit = response.canEdit === true
				this.savedValues = JSON.stringify(this.draft)
				this.loaded = true
				this.$emit('dirty-change', false)
			} catch (_) {
				if (requestId === this.requestId) this.error = 'Impossible de charger la navigation de cet espace.'
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},
		async refreshLanguages() {
			// Conserver les choix en cours quand les langues du Space changent.
			const previous = this.isDirty ? cloneJson(this.draft) : null
			await this.load()
			if (!previous || !this.loaded) return
			this.draft.home_item_id = previous.home_item_id
			this.draft.user_space_item_id = previous.user_space_item_id
            this.draft.login_item_id = previous.login_item_id
            this.draft.register_item_id = previous.register_item_id
			const selected = new Map(previous.languages.map(row => [row.locale, row]))
			this.draft.languages.forEach(row => {
				if (selected.has(row.locale)) {
					row.home_item_id = selected.get(row.locale).home_item_id
					row.user_space_item_id = selected.get(row.locale).user_space_item_id
				}
			})
		},
		async save() {
			if (!this.canEdit || this.disabled || !this.isDirty) return
			this.saving = true
			this.$emit('busy-change', true)
			this.error = ''
			this.saved = false
			try {
				const response = await this.stores.useAdminSpacesStore().saveNavigation(this.spaceId, this.draft)
				this.draft = {
					home_item_id: response.home_item_id ?? 0,
					user_space_item_id: response.user_space_item_id ?? 0,
                    login_item_id: response.login_item_id ?? 0,
                    register_item_id: response.register_item_id ?? 0,
					languages: cloneJson(response.languages ?? []),
				}
				this.options = response.options ?? []
				this.savedValues = JSON.stringify(this.draft)
				this.saved = true
			} catch (_) {
				this.error = 'La navigation n’a pas pu être enregistrée.'
			} finally {
				this.saving = false
				this.$emit('busy-change', false)
			}
		},
	},
}
</script>
