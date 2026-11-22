<template>
	<section>
		<section v-if="editorVisible">
			<header class="d-flex align-items-start justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">{{ editingId === null ? 'Nouvelle source de catalogue' : 'Modifier la source' }}</h1>
					<p class="text-body-secondary mb-0">
						Une source est une racine de confiance adoptée manuellement. La clé publique n’est jamais déduite du catalogue.
					</p>
				</div>
				<button type="button" class="btn btn-outline-secondary esc" :disabled="store.saving" @click="closeEditor">
					Retour aux sources
				</button>
			</header>

			<div v-if="store.error" class="alert alert-warning" role="alert">{{ store.error }}</div>

			<form @submit.prevent="save">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label" for="extension-source-name">Nom</label>
						<input id="extension-source-name" v-model.trim="draft.name" type="text" class="form-control"
							maxlength="150" required>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="extension-source-url">URL de base</label>
						<input id="extension-source-url" v-model.trim="draft.base_url" type="url" class="form-control"
							maxlength="500" required placeholder="https://exemple.fr/tycms-catalog/mon-catalogue">
						<div class="form-text">
							Sans <code>/extensions</code>. HTTPS ; HTTP localhost toléré en DEV.
							L’URL doit être joignable depuis le serveur PHP, pas seulement depuis le navigateur.
						</div>
						<button type="button" class="btn btn-outline-secondary btn-sm mt-1" @click="fillOfficial">
							Utiliser le catalogue officiel
						</button>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="extension-source-key-id">Identifiant de clé</label>
						<input id="extension-source-key-id" v-model.trim="draft.key_id" type="text" class="form-control"
							maxlength="64" required placeholder="extensions-store-official">
					</div>
					<div class="col-md-6">
						<label class="form-label" for="extension-source-public-key">Clé publique Ed25519 (base64)</label>
						<input id="extension-source-public-key" v-model.trim="draft.public_key" type="text" class="form-control"
							maxlength="128" required>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="extension-source-license">Licence (optionnelle)</label>
						<input id="extension-source-license" v-model="draft.license" type="password" class="form-control"
							maxlength="1000" autocomplete="new-password">
						<div v-if="draft.has_license" class="form-text">
							Une licence est enregistrée. Laisser vide pour la conserver.
						</div>
						<div v-if="draft.has_license" class="form-check mt-1">
							<input id="extension-source-clear-license" v-model="draft.clear_license" type="checkbox" class="form-check-input">
							<label class="form-check-label" for="extension-source-clear-license">Supprimer la licence</label>
						</div>
					</div>
					<div class="col-md-6 d-flex align-items-end pb-2">
						<div class="form-check">
							<input id="extension-source-enabled" v-model="draft.enabled" type="checkbox" class="form-check-input">
							<label class="form-check-label" for="extension-source-enabled">Source active</label>
						</div>
					</div>
				</div>

				<div class="d-flex justify-content-end gap-2 mt-4">
					<button type="button" class="btn btn-outline-secondary" :disabled="store.saving" @click="closeEditor">Annuler</button>
					<button type="submit" class="btn btn-primary ctrl-s" :disabled="store.saving">
						<span v-if="store.saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer
					</button>
				</div>
			</form>
		</section>

		<section v-else>
			<div class="d-flex align-items-center justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">Sources de catalogues</h1>
					<p class="text-body-secondary mb-0">
						Consultation des catalogues externes et installation des extensions absentes.
					</p>
				</div>
				<button type="button" class="btn btn-primary btn-sm" :disabled="store.loading || store.saving" @click="openEditor(null)">
					<i class="fa-solid fa-plus" aria-hidden="true" /> Nouvelle source
				</button>
			</div>

			<div v-if="store.error" class="alert alert-warning" role="alert">{{ store.error }}</div>
			<div v-if="store.loading" class="py-5 text-center"><spinner /></div>

			<template v-else>
				<div v-if="sources.length === 0" class="alert alert-info">
					Aucune source de catalogue n’est enregistrée.
				</div>

				<div v-for="source in sources" :key="source.id" class="card mb-3">
					<div class="card-body d-flex flex-wrap align-items-center gap-3">
						<!-- Action principale : ouvrir le catalogue avant de le modifier. -->
						<button type="button" class="btn btn-primary btn-lg flex-shrink-0"
							:disabled="!source.enabled || store.consulting !== null" @click="consult(source)">
							<span v-if="store.consulting === source.id" class="spinner-border spinner-border-sm me-2"
								aria-hidden="true" />
							<i v-else class="fa-solid fa-book-open me-2" aria-hidden="true" />
							Ouvrir le catalogue
						</button>
						<div class="flex-grow-1">
							<div class="d-flex flex-wrap align-items-center gap-2">
								<h2 class="h5 mb-0">{{ source.name }}</h2>
								<span :class="['badge', source.enabled ? 'text-bg-success' : 'text-bg-secondary']">
									{{ source.enabled ? 'Active' : 'Désactivée' }}
								</span>
								<span v-if="source.has_license" class="badge text-bg-info">Licence</span>
							</div>
							<p class="mb-1 mt-1 small text-break"><code>{{ source.base_url }}</code></p>
							<p class="mb-0 small text-body-secondary">Clé : {{ source.key_id }}</p>
						</div>
						<button type="button" class="btn btn-outline-primary btn-sm" :disabled="store.saving" @click="openEditor(source)">
							<i class="fa-solid fa-pen-to-square" aria-hidden="true" /> Modifier
						</button>
					</div>
				</div>

				<div v-if="store.consultError" class="alert alert-warning" role="alert">{{ store.consultError }}</div>
				<div v-if="store.installError" class="alert alert-warning" role="alert">{{ store.installError }}</div>

				<section v-if="store.consultation" class="mt-4">
					<div class="d-flex align-items-start justify-content-between gap-3 mb-2">
						<div>
							<h2 class="h4 mb-1">{{ store.consultation.catalog.name || store.consultation.source.name }}</h2>
							<p class="text-body-secondary mb-0">{{ store.consultation.catalog.description }}</p>
						</div>
						<button type="button" class="btn btn-outline-secondary btn-sm" @click="store.clearConsultation()">Fermer</button>
					</div>

					<h3 class="h5 mt-4">Catalogues amis</h3>
					<p v-if="friends.length === 0" class="text-body-secondary">Aucun catalogue ami déclaré.</p>
					<ul v-else class="list-unstyled">
						<li v-for="friend in friends" :key="friend.url" class="mb-2">
							<strong>{{ friend.name }}</strong>
							<span class="small text-break"> — <code>{{ friend.url }}</code></span>
							<p v-if="friend.description" class="mb-0 small text-body-secondary">{{ friend.description }}</p>
						</li>
					</ul>

					<h3 class="h5 mt-4">Extensions</h3>
					<div v-if="extensions.length === 0" class="alert alert-info">Aucune extension publiée dans ce catalogue.</div>
					<div v-for="extension in extensions" :key="extension.identifier" class="card mb-2">
						<div class="card-body d-flex flex-wrap align-items-start gap-3">
							<img v-if="extension.image_url" :src="extension.image_url" alt="" class="extension-source-image"
								referrerpolicy="no-referrer">
							<div class="flex-grow-1">
								<div class="d-flex flex-wrap align-items-center gap-2">
									<strong>{{ extension.name }}</strong>
									<small class="text-body-secondary">{{ extension.identifier }}</small>
									<span :class="['badge', statusClass(extension.status)]">{{ statusLabel(extension.status) }}</span>
									<span v-if="extension.compatible === null" class="badge text-bg-info">DEV / non évalué</span>
									<span v-else-if="extension.compatible" class="badge text-bg-success">Compatible</span>
									<span v-else class="badge text-bg-danger">Incompatible</span>
								</div>
								<p v-if="extension.description" class="mb-1 mt-1 small text-body-secondary">{{ extension.description }}</p>
								<p class="mb-0 small">
									Installée : <strong>{{ extension.local_version || 'non' }}</strong>
									· Dernière : <strong>{{ extension.latest_version || '—' }}</strong>
								</p>
								<p v-if="extension.error" class="mb-0 small text-danger">{{ extension.error }}</p>
							</div>
							<button v-if="extension.status === 'not_installed' && extension.latest_version" type="button"
								class="btn btn-primary btn-sm"
								:disabled="store.installing !== null" @click="install(extension)">
								<span v-if="store.installing === extension.identifier"
									class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
								<i v-else class="fa-solid fa-download" aria-hidden="true" /> Installer
							</button>
							<span v-else-if="extension.status === 'update_available'"
								class="small text-body-secondary align-self-center">
								Mise à jour disponible dans l’onglet Mises à jour.
							</span>
							<span v-else-if="extension.status === 'update_blocked'"
								class="badge text-bg-warning align-self-center"
								title="La version cible n’autorise pas la mise à jour depuis la version installée">
								Mise à jour bloquée
							</span>
						</div>
					</div>
				</section>
			</template>
		</section>
	</section>
</template>

<script>
function emptyDraft() {
	return {
		id: null,
		name: '',
		base_url: '',
		key_id: '',
		public_key: '',
		license: '',
		clear_license: false,
		enabled: true,
		has_license: false,
	}
}

export default {
	data() {
		return {
			editorVisible: false,
			editingId: null,
			draft: emptyDraft(),
		}
	},

	computed: {
		store() {
			return this.stores.useAdminExtensionSourcesStore()
		},

		sources() {
			return this.store.sources
		},

		friends() {
			return this.store.consultation?.friends ?? []
		},

		extensions() {
			return this.store.consultation?.extensions ?? []
		},
	},

	async mounted() {
		try {
			await this.store.load()
		} catch {
			// Le store conserve le message.
		}
	},

	methods: {
		openEditor(source) {
			this.store.clearError()
			this.store.clearConsultation()
			this.editingId = source?.id ?? null
			this.draft = source === null || source === undefined
				? emptyDraft()
				: {
					id: source.id,
					name: source.name,
					base_url: source.base_url,
					key_id: source.key_id,
					public_key: source.public_key,
					license: '',
					clear_license: false,
					enabled: source.enabled,
					has_license: source.has_license,
				}
			this.editorVisible = true
		},

		closeEditor() {
			if (this.store.saving) return
			this.editorVisible = false
			this.editingId = null
			this.draft = emptyDraft()
			this.store.clearError()
		},

		fillOfficial() {
			this.draft.base_url = this.store.officialUrl || 'https://www.tycms.fr/tycms-catalog/official'
			if (this.draft.name === '') this.draft.name = 'Catalogue officiel'
			if (this.draft.key_id === '') this.draft.key_id = 'extensions-store-official'
			// Clé officielle fournie par le serveur ; adoptée explicitement lors
			// de l'enregistrement de la source.
			if (this.store.officialPublicKey !== '') {
				this.draft.public_key = this.store.officialPublicKey
			}
		},

		async save() {
			if (this.store.saving) return

			try {
				await this.store.save({
					id: this.editingId,
					name: this.draft.name,
					baseUrl: this.draft.base_url,
					keyId: this.draft.key_id,
					publicKey: this.draft.public_key,
					license: this.draft.license === '' ? null : this.draft.license,
					clearLicense: this.draft.clear_license,
					enabled: this.draft.enabled,
				})
				this.showModaleSuccess('Source enregistrée.')
				this.editorVisible = false
				this.editingId = null
				this.draft = emptyDraft()
			} catch {
				// Le client API présente le refus.
			}
		},

		async consult(source) {
			if (this.store.consulting !== null || !source.enabled) return
			try {
				await this.store.consult(source.id)
			} catch {
				// Le store conserve le message.
			}
		},

		async install(extension) {
			if (this.store.installing !== null
				|| extension.status !== 'not_installed'
				|| !extension.latest_version || !this.store.consultation) return

			// Mémoriser la cible avant la confirmation : une attente ne doit pas
			// faire appliquer l'installation à une autre source ou version.
			const sourceId = this.store.consultation.source.id
			const identifier = extension.identifier
			const version = extension.latest_version

			// En DEV, la compatibilité n'est pas évaluée : l'utilisateur est
			// explicitement averti avant de poursuivre.
			const warning = extension.compatible === null
				? ' La compatibilité CMS/PHP n’est pas évaluée en release DEV.'
				: ''
			const confirmed = await this.askYesNoAsync(
				`Installer « ${extension.name} » en version ${version} ?${warning}`,
			)
			if (!confirmed) return

			try {
				const result = await this.store.install(sourceId, identifier, version)
				const message = `Extension « ${identifier} » installée en version ${result.version}.`
				if (result.warning) this.showModaleWarning(`${message} ${result.warning}`)
				else this.showModaleSuccess(message)
			} catch {
				// Le store conserve le message d'échec.
			}
		},

		statusLabel(status) {
			return {
				up_to_date: 'À jour',
				update_available: 'Mise à jour disponible',
				not_installed: 'Non installée',
				incompatible: 'Incompatible',
				update_blocked: 'Mise à jour bloquée',
				no_version: 'Aucune version',
				error: 'Erreur',
			}[status] || status
		},

		statusClass(status) {
			return {
				up_to_date: 'text-bg-success',
				update_available: 'text-bg-warning',
				not_installed: 'text-bg-info',
				incompatible: 'text-bg-danger',
				update_blocked: 'text-bg-warning',
				no_version: 'text-bg-secondary',
				error: 'text-bg-danger',
			}[status] || 'text-bg-secondary'
		},
	},
}
</script>

<style scoped>
.extension-source-image {
	max-width: 64px;
	max-height: 64px;
	object-fit: contain;
}
</style>
