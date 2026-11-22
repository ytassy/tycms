<template>
	<div class="max-content">
		<section v-if="editorVisible">
			<div class="d-flex align-items-start justify-content-between gap-3 mb-3">
				<div>
					<h1>{{ editingId === null ? 'Ajouter un fournisseur SSO' : 'Modifier un fournisseur SSO' }}</h1>
					<p class="text-body-secondary mb-0">Le fournisseur appartient à un espace. Chaque espace choisit ensuite les fournisseurs proposés et l’ordre de leurs boutons dans son propre éditeur.</p>
				</div>
				<button type="button" class="btn btn-outline-secondary esc" :disabled="saving" @click="closeEditor">Retour à la liste</button>
			</div>
			<div v-if="error" class="alert alert-warning">{{ error }}</div>
			<div v-if="loadingForm" class="py-5 text-center"><spinner /></div>
			<form v-else-if="formDefinition" @submit.prevent="save">
				<form_renderer v-model="draft" :definition="formDefinition" :errors="fieldErrors" :class="{ 'pe-none opacity-50': saving }">
					<template #section-footer="{ section }">
						<div v-if="section.id === 'general'" class="alert alert-info mt-3 mb-0">
							<strong>Disponibilité :</strong> la connexion OIDC fonctionne avec Google ou le pilote générique configuré. OAuth 2.0 permet les autorisations individuelles depuis la section « Mes intégrations » du composant Mon compte.
						</div>
						<div v-if="section.id === 'configuration'" class="alert alert-light border mt-3 mb-0">
							<strong>Adresse de callback :</strong> déclarez l’adresse exacte <code>https://votre-domaine/sso/callback</code> pour OIDC, ou <code>https://votre-domaine/integrations/oauth/callback</code> pour OAuth 2.0.
						</div>
						<details v-if="section.id === 'configuration'" class="card mt-3">
							<summary class="card-header cursor-pointer">Exemple de configuration et mapping</summary>
							<div class="card-body">
								<select v-if="draft.protocol === 'oidc'" v-model="oidcExample" class="form-select form-select-sm mb-2" aria-label="Exemple OIDC"><option value="google">Google</option><option value="generic">Générique</option></select>
								<p class="text-body-secondary small">Remplacez les valeurs d’exemple par celles de votre fournisseur. Le secret se saisit dans l’onglet dédié, jamais dans ce JSON.</p>
								<p class="fw-semibold mb-1">Configuration</p>
								<pre class="bg-body-tertiary border rounded p-2 overflow-auto"><code>{{ currentExample.config }}</code></pre>
								<p class="fw-semibold mb-1">Mapping</p>
								<pre class="bg-body-tertiary border rounded p-2 overflow-auto mb-2"><code>{{ currentExample.mapping }}</code></pre>
								<button v-if="editingId === null" type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="insertExample">Insérer cet exemple dans les champs</button>
							</div>
						</details>
						<div v-if="section.id === 'secret'" class="alert alert-secondary mt-3 mb-0">
							<strong>Secret :</strong> {{ hasSecret ? 'un secret est déjà enregistré. Sa valeur ne peut pas être relue.' : 'aucun secret n’est enregistré.' }} <template v-if="editingId !== null">Pour le remplacer, choisissez « Remplacer » puis saisissez le nouveau secret. Pour le conserver, laissez « Conserver » sélectionné.</template><template v-else>Vous pouvez le saisir maintenant ou enregistrer ce fournisseur comme brouillon désactivé.</template>
						</div>
					</template>
				</form_renderer>
				<div class="d-flex justify-content-end gap-2 mt-3">
					<button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="closeEditor">Annuler</button>
					<button type="submit" class="btn btn-primary ctrl-s" :disabled="saving">
						<span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer
					</button>
				</div>
			</form>
		</section>

		<section v-else>
			<div class="d-flex align-items-center justify-content-between gap-3 mb-3">
				<h1 class="mb-0">Fournisseurs SSO</h1>
				<button type="button" class="btn btn-primary btn-sm" :disabled="loadingList || saving" @click="openEditor(null)">
					<i class="fa-solid fa-plus" aria-hidden="true" /> Ajouter un fournisseur
				</button>
			</div>
			<p class="text-body-secondary">Créez ici les paramètres d’un fournisseur. Pour afficher son bouton de connexion, sélectionnez ensuite ce fournisseur dans l’éditeur de l’espace concerné. Un fournisseur d’un espace parent peut aussi être choisi par ses descendants.</p>
			<div v-if="error" class="alert alert-warning">{{ error }}</div>
			<div v-if="loadingList" class="py-5 text-center"><spinner /></div>
			<template v-else-if="providers !== null">
				<div class="row g-2 mb-3">
					<div class="col-sm-6 col-lg-4">
						<label class="form-label" for="sso-space-filter">Espace propriétaire</label>
						<select id="sso-space-filter" v-model="spaceFilter" class="form-select form-select-sm">
							<option value="">Tous les espaces</option>
							<option v-for="space in spaces" :key="space.id" :value="String(space.id)">{{ space.title }}</option>
						</select>
					</div>
					<div class="col-sm-6 col-lg-3">
						<label class="form-label" for="sso-protocol-filter">Protocole</label>
						<select id="sso-protocol-filter" v-model="protocolFilter" class="form-select form-select-sm">
							<option value="">Tous les protocoles</option>
							<option value="oidc">OpenID Connect</option>
							<option value="oauth2">OAuth 2.0</option>
						</select>
					</div>
					<div class="col-sm-6 col-lg-3">
						<label class="form-label" for="sso-status-filter">État</label>
						<select id="sso-status-filter" v-model="statusFilter" class="form-select form-select-sm">
							<option value="">Tous les états</option>
							<option value="enabled">Activés</option>
							<option value="disabled">Désactivés</option>
							<option value="configured">Connexion configurée</option>
							<option value="unavailable">Connexion indisponible</option>
						</select>
					</div>
				</div>
				<data-table :fields="fields" :items="filteredProviders" :pagination-enabled="true" :row-hover="true" default-sort-key="name">
					<template #cell_protocol="{ item }">{{ item.protocol.toUpperCase() }}</template>
					<template #cell_enabled="{ item }"><span :class="['badge', item.enabled ? 'text-bg-success' : 'text-bg-secondary']">{{ item.enabled ? 'Activé' : 'Désactivé' }}</span></template>
					<template #cell_availability="{ item }">
						<span v-if="item.login_available" class="badge text-bg-success">Connexion configurée</span>
						<span v-else-if="item.integration_available" class="badge text-bg-success">Intégration configurée</span>
						<span v-else-if="item.protocol === 'oauth2'" class="badge text-bg-secondary">Intégration désactivée</span>
						<span v-else class="badge text-bg-warning">Connexion indisponible</span>
					</template>
					<template #cell_actions="{ item }"><button type="button" class="btn btn-outline-primary btn-sm" :aria-label="`Modifier ${item.name}`" @click="openEditor(item.id)"><i class="fa-solid fa-pen-to-square" aria-hidden="true" /></button></template>
				</data-table>
			</template>
		</section>
	</div>
</template>

<script>
const GOOGLE_EXAMPLE = {
	config: JSON.stringify({
		driver: 'google',
		client_id: 'votre-client-id.apps.googleusercontent.com',
		redirect_uris: ['https://votre-domaine/sso/callback'],
	}, null, 2),
	mapping: JSON.stringify({
		subject: 'claims.sub',
		email: 'claims.email',
		email_verified: 'claims.email_verified',
		first_name: 'claims.given_name',
		last_name: 'claims.family_name',
	}, null, 2),
}

const GENERIC_OIDC_EXAMPLE = {
	config: JSON.stringify({
		driver: 'generic', client_id: 'votre-client-id',
		authorization_endpoint: 'https://identite.example/authorize',
		token_endpoint: 'https://identite.example/token',
		issuer: 'https://identite.example', jwks_uri: 'https://identite.example/jwks',
		redirect_uris: ['https://votre-domaine/sso/callback'], scopes: ['openid', 'profile', 'email'],
	}, null, 2),
	mapping: GOOGLE_EXAMPLE.mapping,
}

const OAUTH2_EXAMPLE = {
	config: JSON.stringify({
		driver: 'generic',
		client_id: 'votre-client-id',
		authorization_endpoint: 'https://fournisseur.example/authorize',
		token_endpoint: 'https://fournisseur.example/token',
		redirect_uris: ['https://votre-domaine/integrations/oauth/callback'],
		scopes: ['read'],
	}, null, 2),
	mapping: '{}',
}

export default {
	data() {
		return {
			fields: [
				{ key: 'name', label: 'Nom', sortable: true, filter: 'search' },
				{ key: 'space_title', label: 'Espace', sortable: true, filter: 'search' },
				{ key: 'protocol', label: 'Protocole', sortable: true },
				{ key: 'enabled', label: 'État' },
				{ key: 'availability', label: 'Disponibilité' },
				{ key: 'actions', label: '', class: 'text-end' },
			],
			spaceFilter: '', protocolFilter: '', statusFilter: '', editorVisible: false, editingId: null, oidcExample: 'google',
			formDefinition: null, draft: {}, original: '', hasSecret: false, fieldErrors: {},
			loadingList: false, loadingForm: false, saving: false, error: '', requestId: 0,
		}
	},
	computed: {
		store() { return this.stores.useAdminSsoProvidersStore() },
		providers() { return this.store.providers },
		spaces() { return this.store.spaces },
		currentExample() { return this.draft.protocol === 'oauth2' ? OAUTH2_EXAMPLE : (this.oidcExample === 'generic' ? GENERIC_OIDC_EXAMPLE : GOOGLE_EXAMPLE) },
		filteredProviders() {
			return (this.providers ?? []).filter(item =>
				(!this.spaceFilter || String(item.space_id) === this.spaceFilter)
				&& (!this.protocolFilter || item.protocol === this.protocolFilter)
				&& (!this.statusFilter || (
					(this.statusFilter === 'enabled' && item.enabled)
					|| (this.statusFilter === 'disabled' && !item.enabled)
					|| (this.statusFilter === 'configured' && item.login_available)
					|| (this.statusFilter === 'unavailable' && !item.login_available)
				)))
		},
	},
	async mounted() {
		this.loadingList = true
		try { await this.store.load() }
		catch { this.error = 'Impossible de charger les fournisseurs SSO.' }
		finally { this.loadingList = false }
	},
	methods: {
		async insertExample() {
			if ((this.draft.config_json !== '{}' || this.draft.mapping_json !== '{}')
				&& !await this.askYesNoAsync('Remplacer les deux JSON actuellement saisis par cet exemple ?')) return
			this.draft = {
				...this.draft,
				config_json: this.currentExample.config,
				mapping_json: this.currentExample.mapping,
			}
		},
		async openEditor(id) {
			this.editingId = id
			this.editorVisible = true
			this.loadingForm = true
			this.error = ''
			this.formDefinition = null
			this.fieldErrors = {}
			const requestId = ++this.requestId
			try {
				const response = await this.api.get('/administrator/api/sso-providers/form', { providerId: id })
				if (requestId !== this.requestId) return
				this.formDefinition = response.form
				this.draft = JSON.parse(JSON.stringify(response.form?.initialValues ?? {}))
				this.original = JSON.stringify(this.draft)
				this.hasSecret = response.hasSecret === true
			} catch {
				if (requestId === this.requestId) this.error = 'Impossible de charger le formulaire du fournisseur.'
			} finally {
				if (requestId === this.requestId) this.loadingForm = false
			}
		},
		async closeEditor() {
			if (this.saving) return
			if (this.formDefinition && JSON.stringify(this.draft) !== this.original
				&& !await this.askYesNoAsync('Abandonner les modifications non enregistrées ?')) return
			this.requestId++
			this.editorVisible = false
			this.editingId = null
			this.formDefinition = null
			this.draft = {}
			this.error = ''
		},
		async save() {
			if (this.saving || !this.formDefinition) return
			this.fieldErrors = {}
			for (const name of ['config_json', 'mapping_json']) {
				try {
					const value = JSON.parse(this.draft[name])
					if (!value || Array.isArray(value) || typeof value !== 'object') throw new Error('object required')
				} catch {
					this.fieldErrors[name] = ['Saisissez un objet JSON valide.']
				}
			}
			if (Object.keys(this.fieldErrors).length) return
			this.saving = true
			this.error = ''
			try {
				if (this.editingId === null) await this.store.create({ ...this.draft })
				else await this.store.update(this.editingId, { ...this.draft })
				this.original = JSON.stringify(this.draft)
				this.saving = false
				await this.closeEditor()
			} catch {
				this.error = 'Enregistrement impossible. Vérifiez les données du fournisseur.'
			} finally { this.saving = false }
		},
	},
}
</script>
