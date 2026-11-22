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

						<form v-if="editableForm" @submit.prevent="saveConfiguration">
							<form_renderer
								:key="`${selectedExtension.name}:${selectedSpace?.id ?? ''}`"
								v-model="draftParams"
								:definition="editableForm"
								:context="{ spaceId: selectedSpace?.id ?? null }"
								:errors="fieldErrors"
							>
								<template #section-header="{ section }">
									<p v-if="selectedExtension.name === 'tpl_main' && section.id === 'theme_typography'" class="text-body-secondary">
										Les polices téléchargées dans la rubrique « Polices » de l’administration sont proposées ici après installation.
									</p>
									<div v-if="selectedExtension.name === 'tpl_main' && ['email_default', 'email_blank'].includes(section.id) && !selectedSpace?.params?.main_host"
										class="alert alert-warning mb-3" role="alert">
										Le domaine principal de cet espace n’est pas renseigné. Ajoutez-le dans les paramètres de l’espace pour utiliser les e-mails avec Template.
									</div>
								</template>
								<template #section-footer="{ section }">
									<WafInheritedConfiguration v-if="section.id === 'waf' && inheritsRootWaf"
										:configuration="selectedExtension.conf_by_spaces?.[1]?.params ?? null" />
									<EmailTestPanel v-if="canTestMail && ['email_default', 'email_blank'].includes(section.id)"
										:key="`${selectedSpace.id}:${section.id}`"
										:mode="section.id === 'email_default' ? 'default' : 'blank'"
										:space-id="selectedSpace.id" :sender-email="selectedSpace.mail_from" :main-host="selectedSpace.params?.main_host"
										:definition="editableForm" :draft-params="draftParams" :saving="adminExtensionsStore.saving" />
								</template>
							</form_renderer>

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
import EmailTestPanel from './EmailTestPanel.vue'
import CatalogSources from './CatalogSources.vue'
import ExtensionsManagement from './ExtensionsManagement.vue'
import ExtensionsUpdates from './ExtensionsUpdates.vue'
import WafInheritedConfiguration from './WafInheritedConfiguration.vue'
import { securityWafForm } from './securityWafForm.js'

function cloneJson(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

export default {
	components: { CatalogSources, EmailTestPanel, ExtensionsManagement, ExtensionsUpdates, WafInheritedConfiguration },
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
		configurationForm() {
			const form = this.selectedExtension?.form
			const hidden = this.selectedExtension?.hidden_sections_by_space?.[this.selectedSpace?.id] ?? []
			if (!form) return form
			const visible = hidden.length === 0 ? form : { ...form, sections: form.sections.filter(section => !hidden.includes(section.id)) }
			return this.selectedExtension?.name === 'plg_security'
				? securityWafForm(visible, this.selectedSpace?.id, false) : visible
		},

		inheritsRootWaf() {
			return this.selectedExtension?.name === 'plg_security' && this.selectedSpace?.id !== 1
				&& this.draftParams.waf_use_root === true
		},

		editableForm() {
			const form = this.configurationForm
			if (!form || !this.inheritsRootWaf) return form
			return securityWafForm(form, this.selectedSpace?.id, true)
		},

		canTestMail() {
			return this.selectedExtension?.name === 'tpl_main' && this.selectedSpace?.template === 'tpl_main'
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

		submittedValues() {
			const params = {}
			for (const field of this.configurationForm?.sections?.flatMap((section) => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(this.draftParams, field.name)) continue
				params[field.name] = this.draftParams[field.name]
			}
			return params
		},

		async saveConfiguration() {
			if (!this.editableForm || !this.selectedSpace) return

			this.fieldErrors = {}
			try {
				await this.adminExtensionsStore.saveConfiguration(
					this.selectedSpace.id,
					this.selectedExtension.name,
					this.submittedValues(),
				)
				this.resetDraft()
			} catch {
				// The API service displays the server message; keep the current draft.
			}
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
