<template>
	<div class="extensions-admin">
		<h1>Extensions</h1>

		<tabs :default-index="initialTabIndex">
			<tab label="Paramètres" icon="fa-solid fa-sliders">
				<div v-if="adminExtensionsStore.error" class="alert alert-danger alert-dismissible fade show" role="alert">
					<p v-text-ml="adminExtensionsStore.error" style="white-space: pre;"></p>
					<button type="button" class="btn-close" aria-label="Fermer" @click="adminExtensionsStore.clearError()" />
				</div>

				<div v-if="adminExtensionsStore.loading" class="py-5 text-center">
					<spinner />
				</div>

				<template v-else-if="adminExtensionsStore.extensions !== null">
					<section v-if="selectedExtension">
						<header class="mb-4">
							<button type="button" class="btn btn-outline-primary btn-sm mb-3" @click="leaveExtension">
								<i class="fa-solid fa-arrow-left-long" aria-hidden="true" /> Retour aux extensions
							</button>
							<h2 class="h3 mb-1">{{ selectedExtension.title || selectedExtension.name }}</h2>
							<p v-if="selectedExtension.description" class="text-body-secondary mb-0">
								{{ selectedExtension.description }}
							</p>
						</header>

						<div class="mb-4">
							<h3 class="h5 mb-3">Espaces</h3>
							<div v-if="spaces.length > 0" class="d-flex flex-wrap gap-2">
								<button
									v-for="space in spaces"
									:key="space.id"
									type="button"
									:class="['btn', 'btn-sm', 'rounded-pill', space.id === selectedSpace?.id ? 'btn-primary' : 'btn-outline-primary']"
									@click="selectSpace(space.id)"
								>
									{{ space.title || `Espace #${space.id}` }}
								</button>
							</div>
							<div v-else class="alert alert-warning mb-0">
								Aucun espace n’est disponible pour cette configuration.
							</div>
						</div>

						<component
							v-if="configView"
							:is="configView"
							:key="`${selectedExtension.name}:${selectedSpace?.id ?? ''}`"
							v-model:params="draftParams"
							:extension="selectedExtension"
							:spaces="spaces"
							:space="selectedSpace"
							:definition="configurationForm"
							:errors="fieldErrors"
							:saving="adminExtensionsStore.saving"
							:save="saveConfiguration"
						/>

						<template v-else>
							<div v-if="configViewMissing" class="alert alert-warning" role="alert">
								La vue de configuration de cette extension n’est pas disponible dans le bundle Vue chargé.
								{{ configurationForm ? 'Le formulaire générique est affiché à la place.' : 'Aucun formulaire de repli n’est déclaré.' }}
							</div>

							<form v-if="configurationForm" @submit.prevent="submitConfiguration">
								<form_renderer
									:key="`${selectedExtension.name}:${selectedSpace?.id ?? ''}`"
									v-model="draftParams"
									:definition="configurationForm"
									:context="{ spaceId: selectedSpace?.id ?? null }"
									:errors="fieldErrors"
								/>

								<div class="d-flex align-items-center gap-3 mt-4">
									<button type="submit" class="btn btn-primary ctrl-s" :disabled="adminExtensionsStore.saving || !selectedSpace">
										<span v-if="adminExtensionsStore.saving" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
										Enregistrer
									</button>
								</div>
							</form>

							<div v-else class="alert alert-secondary mb-0">
								Cette extension ne déclare pas de formulaire de configuration.
							</div>
						</template>
					</section>

					<section v-else>
						<p class="text-body-secondary">Choisissez une extension à configurer.</p>
						<div>
							<div v-for="extension in extensions" :key="extension.name"
								class="extension-card">
								<button type="button" class="card extensions-admin__tile  text-start" @click="enterExtension(extension.name)">
									<span class="tac fz-150 pb-3">
										<i :class="extension.icon" class="fa-lg mb-2 text-center" />
									</span>
									<span class="h5 mb-1">{{ extension.title || extension.name }}</span>
									<small class="text-body-secondary">{{ extension.description }}</small>
									<small v-if="extension.version" class="text-body-secondary mt-auto">v{{ extension.version }}</small>
								</button>
							</div>
						</div>
					</section>
				</template>
			</tab>

			<tab v-if="isRoot" label="Gestion" icon="fa-solid fa-list-check">
				<template #default="{ active }">
					<ExtensionsManagement :active="active" />
				</template>
			</tab>

			<tab v-if="isRoot" label="Mises à jour" icon="fa-solid fa-cloud-arrow-up">
				<template #default="{ active }">
					<ExtensionsUpdates :active="active" />
				</template>
			</tab>

			<tab v-if="isRoot" label="Installation" icon="fa-solid fa-box-open">
				<CatalogSources />
			</tab>
		</tabs>
	</div>
</template>

<script>
import CatalogSources from './CatalogSources.vue'
import ExtensionsManagement from './ExtensionsManagement.vue'
import ExtensionsUpdates from './ExtensionsUpdates.vue'
import { resolveExtensionConfigView } from '../../../application/configViewRegistry.js'
import './configViews/registerCoreConfigViews.js'

function cloneJson(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

export default {
	components: { CatalogSources, ExtensionsManagement, ExtensionsUpdates },
	data() {
		return {
			draftParams: {},
			fieldErrors: {},
		}
	},

	computed: {
		adminExtensionsStore() {
			return this.stores.useAdminExtensionsStore()
		},

		// La gestion des migrations modifie le schéma partagé : ROOT uniquement.
		isRoot() {
			return this.acl.isRoot()
		},

		initialTabIndex() {
			if (!this.isRoot) return 0

			return {
				management: 1,
				updates: 2,
				installation: 3,
			}[this.$route?.query?.tab] ?? 0
		},

		extensions() {
			return this.adminExtensionsStore.extensionList
		},

		spaces() {
			return this.adminExtensionsStore.spaceList
		},

		selectedExtension() {
			return this.adminExtensionsStore.selectedExtension
		},

		selectedSpace() {
			return this.adminExtensionsStore.selectedSpace
		},

		selectedConfiguration() {
			return this.adminExtensionsStore.selectedConfiguration
		},

		// Le Core ne connaît que les sections masquées déclarées par l’extension.
		configurationForm() {
			const form = this.selectedExtension?.form
			const hidden = this.selectedExtension?.hidden_sections_by_space?.[this.selectedSpace?.id] ?? []
			if (!form) return form
			return hidden.length === 0 ? form : { ...form, sections: form.sections.filter(section => !hidden.includes(section.id)) }
		},

		// Composant désigné par l’extension et résolu dans le registre de confiance.
		// null : formulaire générique, ou avertissement de repli s’il est déclaré.
		configView() {
			const name = this.selectedExtension?.config_view
			return name ? resolveExtensionConfigView(name) : null
		},

		configViewMissing() {
			return Boolean(this.selectedExtension?.config_view) && this.configView === null
		},

	},

	watch: {
		selectedExtension() {
			this.resetDraft()
		},

		selectedSpace() {
			this.resetDraft()
		},
	},

	async mounted() {
		try {
			await this.adminExtensionsStore.loadExtensions()
			this.resetDraft()
		} catch {
			// The store retains the message and the API service displays its modal.
		}
	},

	methods: {
		enterExtension(extensionName) {
			this.adminExtensionsStore.clearError()
			this.adminExtensionsStore.selectExtension(extensionName)
		},

		leaveExtension() {
			this.adminExtensionsStore.clearError()
			this.adminExtensionsStore.clearSelectedExtension()
		},

		selectSpace(spaceId) {
			this.adminExtensionsStore.clearError()
			this.adminExtensionsStore.selectSpace(spaceId)
		},

		resetDraft() {
			this.fieldErrors = {}

			const form = this.configurationForm
			if (!form) {
				this.draftParams = {}
				return
			}

			const defaults = form.initialValues ?? {}
			const currentValues = this.selectedConfiguration?.params ?? {}
			const fieldNames = (form.sections ?? [])
				.flatMap((section) => section.fields ?? [])
				.map((field) => field.name)
				.filter((name) => typeof name === 'string')

			const draft = {}
			for (const fieldName of fieldNames) {
				if (Object.prototype.hasOwnProperty.call(currentValues, fieldName)) {
					draft[fieldName] = cloneJson(currentValues[fieldName])
				} else if (Object.prototype.hasOwnProperty.call(defaults, fieldName)) {
					draft[fieldName] = cloneJson(defaults[fieldName])
				}
			}

			this.draftParams = draft
		},

		/**
		 * Filtrer sur les champs du formulaire de confiance. Une vue custom peut
		 * transmettre ses propres paramètres sans élargir ce qui est écrit dans
		 * la table config.
		 */
		submittedValues(values = null) {
			const source = values ?? this.draftParams
			const params = {}
			for (const field of this.configurationForm?.sections?.flatMap((section) => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(source, field.name)) continue
				params[field.name] = source[field.name]
			}
			return params
		},

		// Soumission du formulaire générique : le store affiche le message et
		// conserve le brouillon, sans rejet non géré dans le template.
		async submitConfiguration() {
			try {
				await this.saveConfiguration()
			} catch {
				// Le store conserve le message d’erreur.
			}
		},

		// Contrat fourni à une vue custom : elle peut enchaîner ses propres
		// appels avec la sauvegarde de config et interrompre la suite sur échec.
		async saveConfiguration(values = null) {
			if (!this.configurationForm || !this.selectedSpace) return null

			this.fieldErrors = {}
			const configuration = await this.adminExtensionsStore.saveConfiguration(
				this.selectedSpace.id,
				this.selectedExtension.name,
				this.submittedValues(values),
			)
			this.resetDraft()

			return configuration
		},
	},
}
</script>

<style scoped>
.extensions-admin__tile {
	aspect-ratio: 1;
	border-color: var(--bs-border-color);
	display: flex;
	flex-direction: column;
	justify-content: flex-start;
	padding: 1rem;
	transition: border-color .15s ease-in-out, box-shadow .15s ease-in-out, transform .15s ease-in-out;
}

.extensions-admin__tile:hover,
.extensions-admin__tile:focus-visible {
	border-color: var(--bs-primary);
	box-shadow: 0 .25rem .75rem rgb(0 0 0 / 12%);
	transform: translateY(-2px);
}


.extension-card{
	width: 250px;
	display: inline-block;
	padding-right: 1rem;
	padding-bottom: 1rem;

	.card{
		width: 100%;
	}
}
</style>
