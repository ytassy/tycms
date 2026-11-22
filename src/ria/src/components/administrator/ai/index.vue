<template>
	<div class="max-content">
		<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
			<div>
				<h1>Assistance IA</h1>
				<p class="text-body-secondary mb-0">Chaque espace possède ses propres connexions et choisit un fournisseur et un modèle pour chaque usage.</p>
			</div>
			<div class="ai-space-choice">
				<label class="form-label fw-semibold" for="ai-space">Espace à configurer</label>
				<select id="ai-space" :value="spaceId" class="form-select" :disabled="saving || spacesLoading" @change="spaceChanged">
					<option v-for="space in adminSpaces" :key="space.id" :value="space.id">{{ space.title }}</option>
				</select>
			</div>
		</div>

		<div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
		<div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>
		<div v-if="spacesLoading || loading" class="py-5 text-center"><spinner /></div>
		<div v-else-if="!adminSpaces.length" class="alert alert-info">Aucun espace administrable n’est disponible.</div>
		<div v-else-if="!loaded" class="mb-3"><button type="button" class="btn btn-outline-primary" @click="loadConfiguration">Réessayer</button></div>
		<template v-else-if="loaded">
			<section class="card mb-4">
				<div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
					<h2 class="h5 mb-0">Connexions</h2>
					<button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="openProvider(null)">
						<i class="fa-solid fa-plus" aria-hidden="true" /> Ajouter une connexion
					</button>
				</div>
				<div class="card-body">
					<p class="text-body-secondary">Une connexion contient l’adresse et la clé API du fournisseur. La clé enregistrée n’est jamais affichée.</p>
					<div v-if="!providers.length" class="text-body-secondary">Aucune connexion pour cet espace.</div>
					<div v-else class="table-responsive">
						<table class="table table-hover align-middle mb-0">
							<thead><tr><th>Nom</th><th>Fournisseur</th><th>Adresse</th><th>État</th><th class="text-end">Actions</th></tr></thead>
							<tbody>
								<tr v-for="provider in providers" :key="provider.id">
									<td class="fw-semibold">{{ provider.name }}</td>
									<td>{{ providerLabel(provider.type) }}</td>
									<td><code class="small text-break">{{ provider.base_url }}</code></td>
									<td><span :class="['badge', provider.enabled ? 'text-bg-success' : 'text-bg-secondary']">{{ provider.enabled ? 'Active' : 'Inactive' }}</span></td>
									<td class="text-end text-nowrap">
										<button type="button" class="btn btn-outline-primary btn-sm me-1" :disabled="saving" :aria-label="`Modifier ${provider.name}`" @click="openProvider(provider)"><i class="fa-solid fa-pen-to-square" aria-hidden="true" /></button>
										<button type="button" class="btn btn-outline-danger btn-sm" :disabled="saving || isAssigned(provider.id)" :title="isAssigned(provider.id) ? 'Retirez d’abord les affectations de cette connexion.' : 'Supprimer'" :aria-label="`Supprimer ${provider.name}`" @click="deleteProvider(provider)"><i class="fa-solid fa-trash" aria-hidden="true" /></button>
									</td>
								</tr>
							</tbody>
						</table>
					</div>
				</div>
			</section>

			<section v-if="providerDraft" class="card mb-4" aria-labelledby="ai-provider-editor-title">
				<div class="card-header d-flex align-items-center justify-content-between gap-2">
					<h2 id="ai-provider-editor-title" class="h5 mb-0">{{ editingProviderId === null ? 'Ajouter une connexion' : 'Modifier une connexion' }}</h2>
					<button type="button" class="btn-close" aria-label="Fermer" :disabled="saving" @click="closeProvider()" />
				</div>
				<form class="card-body" @submit.prevent="saveProvider">
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label" for="ai-provider-name">Nom de la connexion</label>
							<input id="ai-provider-name" v-model.trim="providerDraft.name" type="text" class="form-control" maxlength="150" required :disabled="saving" placeholder="Ex. OpenRouter du site principal" />
						</div>
						<div class="col-md-6">
							<label class="form-label" for="ai-provider-type">Fournisseur</label>
							<select id="ai-provider-type" v-model="providerDraft.type" class="form-select" :disabled="saving || editingProviderId !== null" @change="providerTypeChanged">
								<option v-for="type in providerTypes" :key="type.id" :value="type.id">{{ type.label }}</option>
							</select>
							<div v-if="editingProviderId !== null" class="form-text">Le type reste fixe après la création.</div>
						</div>
						<div class="col-md-8">
							<label class="form-label" for="ai-provider-url">URL de base</label>
							<input id="ai-provider-url" v-model.trim="providerDraft.base_url" type="url" class="form-control" maxlength="500" required :readonly="providerDraft.type !== 'openai_compatible'" :disabled="saving" placeholder="https://api.exemple.com/v1" />
							<div v-if="providerDraft.type === 'infomaniak'" class="form-text">Les routes v1 ou v2 sont choisies automatiquement selon l’opération.</div>
						</div>
						<div v-if="providerDraft.type === 'infomaniak'" class="col-md-4">
							<label class="form-label" for="ai-product-id">Product ID Infomaniak</label>
							<input id="ai-product-id" v-model.number="productId" type="number" class="form-control" min="1" step="1" required :disabled="saving" />
						</div>
						<div class="col-md-8">
							<label class="form-label" for="ai-provider-key">Clé API</label>
							<input id="ai-provider-key" v-model="providerDraft.api_key" type="password" class="form-control" autocomplete="new-password" :required="editingProviderId === null" :disabled="saving" />
							<div class="form-text">{{ editingProviderId === null ? 'Obligatoire à la création.' : 'Laissez vide pour conserver la clé enregistrée.' }}</div>
						</div>
						<div class="col-md-4 d-flex align-items-end">
							<div class="form-check form-switch mb-2">
								<input id="ai-provider-enabled" v-model="providerDraft.enabled" type="checkbox" class="form-check-input" :disabled="saving" />
								<label class="form-check-label" for="ai-provider-enabled">Connexion active</label>
							</div>
						</div>
					</div>
					<div class="d-flex justify-content-end gap-2 mt-4">
						<button type="button" class="btn btn-outline-secondary" :disabled="saving" @click="closeProvider()">Annuler</button>
						<button type="submit" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer la connexion</button>
					</div>
				</form>
			</section>

			<section class="card mb-4">
				<div class="card-header"><h2 class="h5 mb-0">Affectation des modèles</h2></div>
				<form class="card-body" @submit.prevent="saveModels">
					<p class="text-body-secondary">Pour chaque usage, sélectionnez une connexion de cet espace et saisissez l’identifiant exact du modèle. Laissez « Aucun » pour désactiver un usage.</p>
					<div v-for="useCase in useCases" :key="useCase.id" class="row g-2 align-items-end mb-3">
						<div class="col-md-4">
							<label class="form-label fw-semibold" :for="`ai-model-provider-${useCase.id}`">{{ useCase.label }}</label>
							<select :id="`ai-model-provider-${useCase.id}`" v-model.number="modelDraft[useCase.id].provider_id" class="form-select" :disabled="saving" @change="modelProviderChanged(useCase.id)">
								<option value="">Aucun</option>
								<option v-for="provider in providersFor(useCase.id)" :key="provider.id" :value="provider.id">{{ provider.name }}{{ provider.enabled ? '' : ' (inactive)' }}</option>
							</select>
							<div v-if="selectedProvider(useCase.id)?.enabled === false" class="form-text text-warning">Cette connexion doit être activée pour pouvoir utiliser ce modèle.</div>
						</div>
						<div class="col-md-8">
							<label class="form-label" :for="`ai-model-name-${useCase.id}`">Identifiant du modèle</label>
							<input :id="`ai-model-name-${useCase.id}`" v-model.trim="modelDraft[useCase.id].model" type="text" class="form-control" maxlength="200" :disabled="saving || !modelDraft[useCase.id].provider_id" placeholder="Identifiant fourni par le fournisseur" />
						</div>
					</div>
					<div class="d-flex justify-content-end">
						<button type="submit" class="btn btn-primary" :disabled="saving || !modelsDirty"><span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer les affectations</button>
					</div>
				</form>
			</section>
		</template>
	</div>
</template>

<script>
const BASE_URLS = {
	openai: 'https://api.openai.com/v1',
	openrouter: 'https://openrouter.ai/api/v1',
	infomaniak: 'https://api.infomaniak.com',
	anthropic: 'https://api.anthropic.com/v1',
	openai_compatible: '',
}

const USE_CASES = [
	{ id: 'decision', label: 'Décision' },
	{ id: 'proofreading', label: 'Correction orthographique' },
	{ id: 'design', label: 'Conception' },
	{ id: 'image_generation', label: 'Génération d’image' },
]

function emptyModels() {
	return Object.fromEntries(USE_CASES.map(item => [item.id, { provider_id: '', model: '' }]))
}

function copyModels(models) {
	const result = emptyModels()
	for (const item of USE_CASES) {
		const selection = models?.[item.id]
		if (selection) result[item.id] = { provider_id: selection.provider_id, model: selection.model }
	}
	return result
}

export default {
	data() {
		return {
			spaceId: null, spacesLoading: true, loading: false, loaded: false, saving: false,
			error: '', notice: '', requestId: 0, providerDraft: null, providerOriginal: '', editingProviderId: null,
			productId: '', modelDraft: emptyModels(), modelsOriginal: JSON.stringify(emptyModels()),
			providerTypes: [
				{ id: 'openai', label: 'OpenAI' },
				{ id: 'openai_compatible', label: 'API compatible OpenAI' },
				{ id: 'openrouter', label: 'OpenRouter' },
				{ id: 'infomaniak', label: 'Infomaniak' },
				{ id: 'anthropic', label: 'Anthropic' },
			],
			useCases: USE_CASES,
		}
	},
	computed: {
		spaceStore() { return this.stores.useAdminSpacesStore() },
		aiStore() { return this.stores.useAdminAiStore() },
		adminSpaces() { return (this.spaceStore.spaces ?? []).filter(space => space.can_admin) },
		providers() { return this.aiStore.spaceId === this.spaceId ? this.aiStore.providers : [] },
		modelsDirty() { return JSON.stringify(this.modelDraft) !== this.modelsOriginal },
		providerDirty() { return this.providerDraft !== null && JSON.stringify([this.providerDraft, this.productId]) !== this.providerOriginal },
	},
	watch: {
		spaceId() { this.loadConfiguration() },
	},
	async mounted() {
		try {
			await this.spaceStore.loadSpaces()
			this.spaceId = this.adminSpaces[0]?.id ?? null
		} catch {
			this.error = 'Impossible de charger les espaces.'
		} finally {
			this.spacesLoading = false
		}
	},
	methods: {
		async canLeave() {
			return (!this.modelsDirty && !this.providerDirty)
				|| await this.askYesNoAsync('Quitter la page IA sans enregistrer les modifications en cours ?')
		},
		async spaceChanged(event) {
			const select = event.target
			const nextId = Number(select.value)
			if (nextId === this.spaceId) return
			if ((this.modelsDirty || this.providerDirty)
				&& !await this.askYesNoAsync('Quitter cet espace sans enregistrer les modifications en cours ?')) {
				select.value = String(this.spaceId)
				return
			}
			this.spaceId = nextId
		},
		providerLabel(type) { return this.providerTypes.find(item => item.id === type)?.label ?? type },
		providersFor(useCase) {
			return this.providers.filter(provider => useCase === 'decision'
				? provider.type === 'openrouter'
				: useCase === 'image_generation' ? provider.type !== 'anthropic' : true)
		},
		selectedProvider(useCase) {
			return this.providers.find(provider => provider.id === this.modelDraft[useCase].provider_id) ?? null
		},
		isAssigned(providerId) {
			return Object.values(this.aiStore.models ?? {}).some(selection => selection?.provider_id === providerId)
				|| Object.values(this.modelDraft).some(selection => selection?.provider_id === providerId)
		},
		async loadConfiguration() {
			const requestId = ++this.requestId
			this.providerDraft = null
			this.editingProviderId = null
			this.error = ''
			this.notice = ''
			this.loaded = false
			if (!this.spaceId) return
			this.loading = true
			try {
				const current = await this.aiStore.load(this.spaceId)
				if (requestId !== this.requestId || !current) return
				this.modelDraft = copyModels(this.aiStore.models)
				this.modelsOriginal = JSON.stringify(this.modelDraft)
				this.loaded = true
			} catch {
				if (requestId === this.requestId) this.error = 'Impossible de charger la configuration IA de cet espace.'
			} finally {
				if (requestId === this.requestId) this.loading = false
			}
		},
		async openProvider(provider) {
			if (this.providerDirty
				&& !await this.askYesNoAsync('Abandonner les modifications de la connexion en cours ?')) return
			this.error = ''
			this.notice = ''
			this.editingProviderId = provider?.id ?? null
			this.providerDraft = provider ? {
				name: provider.name, type: provider.type, base_url: provider.base_url,
				enabled: provider.enabled, config: { ...(provider.config ?? {}) }, api_key: '',
			} : { name: '', type: 'openai', base_url: BASE_URLS.openai, enabled: false, config: {}, api_key: '' }
			this.productId = provider?.config?.product_id ?? ''
			this.providerOriginal = JSON.stringify([this.providerDraft, this.productId])
		},
		providerTypeChanged() {
			if (!this.providerDraft || this.editingProviderId !== null) return
			this.providerDraft.base_url = BASE_URLS[this.providerDraft.type] ?? ''
			this.providerDraft.config = {}
			this.productId = ''
		},
		async closeProvider(saved = false) {
			if (this.saving) return
			if (!saved && this.providerDirty
				&& !await this.askYesNoAsync('Abandonner les modifications de cette connexion ?')) return
			this.providerDraft = null
			this.providerOriginal = ''
			this.editingProviderId = null
			this.productId = ''
		},
		async saveProvider() {
			if (!this.providerDraft || this.saving || !this.spaceId) return
			this.error = ''
			this.notice = ''
			const draft = this.providerDraft
			const data = { ...draft, config: { ...draft.config } }
			if (draft.type === 'infomaniak') {
				if (!Number.isInteger(this.productId) || this.productId < 1) {
					this.error = 'Le Product ID Infomaniak doit être un entier positif.'
					return
				}
				data.config.product_id = this.productId
			}
			this.saving = true
			try {
				if (this.editingProviderId === null) await this.aiStore.create(this.spaceId, data)
				else await this.aiStore.update(this.spaceId, this.editingProviderId, data)
				this.notice = 'Connexion enregistrée.'
			} catch {
				this.error = 'Impossible d’enregistrer la connexion. Vérifiez ses paramètres.'
			} finally {
				this.saving = false
			}
			if (!this.error) await this.closeProvider(true)
		},
		async deleteProvider(provider) {
			if (this.saving || this.isAssigned(provider.id)
				|| !await this.askYesNoAsync(`Supprimer définitivement la connexion « ${provider.name} » ?`)) return
			this.error = ''
			this.notice = ''
			this.saving = true
			try {
				await this.aiStore.remove(this.spaceId, provider.id)
				if (this.editingProviderId === provider.id) {
					this.providerDraft = null
					this.editingProviderId = null
				}
				this.notice = 'Connexion supprimée.'
			} catch {
				this.error = 'Impossible de supprimer cette connexion. Retirez ses affectations avant de réessayer.'
			} finally {
				this.saving = false
			}
		},
		modelProviderChanged(useCase) {
			this.modelDraft[useCase].model = ''
		},
		async saveModels() {
			if (this.saving || !this.spaceId) return
			this.error = ''
			this.notice = ''
			const models = {}
			for (const useCase of USE_CASES) {
				const row = this.modelDraft[useCase.id]
				if (!row.provider_id) continue
				const model = row.model.trim()
				if (!model) {
					this.error = `Renseignez l’identifiant du modèle pour « ${useCase.label} ».`
					return
				}
				models[useCase.id] = { provider_id: row.provider_id, model }
			}
			this.saving = true
			try {
				await this.aiStore.saveModels(this.spaceId, models)
				this.modelDraft = copyModels(this.aiStore.models)
				this.modelsOriginal = JSON.stringify(this.modelDraft)
				this.notice = 'Affectations enregistrées.'
			} catch {
				this.error = 'Impossible d’enregistrer les affectations.'
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.ai-space-choice { min-width: min(100%, 17rem); }
</style>
