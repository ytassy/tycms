<template>
	<section>
		<div>
			<img :src="logoUrl"style="height:3em" />
		</div>
		<h2 class="h4 mt-3">
			Mises à jour du système
		</h2>
		<p class="text-muted">Vérifiez les versions disponibles pour votre installation. Aucune mise à jour n’est installée depuis cet écran.</p>
		<template v-if="store.context">
			<p>Version installée : <strong>{{ store.context.installed_version }}</strong> · PHP {{ store.context.php_version }}</p>
			<div v-if="store.context.configuration_error" class="alert alert-warning" role="alert">
				{{ store.context.configuration_error }}
			</div>
			<form @submit.prevent="check">
				<div v-if="store.context.simulation_allowed" class="alert alert-info">
					<label for="update-source" class="form-label">Version source à simuler en développement</label>
					<input id="update-source" v-model.trim="sourceVersion" type="text" class="form-control"
						maxlength="48" pattern="(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)" required
						:disabled="store.checking || store.downloading || store.preparing || store.resetting" @input="clearResult">
					<p class="mt-2 mb-0">La recherche utilise cette version simulée et la version réelle de PHP. Le lien secret permet ensuite de tester une installation réelle sur cette base de développement.</p>
				</div>
				<button type="submit" class="btn btn-primary" :disabled="store.checking || store.downloading || store.preparing || store.resetting || !!store.context.configuration_error">
					<i class="fa-solid fa-arrows-rotate me-2" aria-hidden="true"></i>
					{{ store.checking ? 'Vérification en cours…' : 'Vérifier les mises à jour' }}
				</button>
			</form>
			<button v-if="!store.context.cleanup_available" type="button" class="btn btn-outline-danger mt-3"
				:disabled="store.checking || store.downloading || store.preparing || store.resetting" @click="reset">
				{{ store.resetting ? 'Réinitialisation en cours…' : 'Réinitialiser la mise à jour' }}
			</button>
			<button v-else type="button" class="btn btn-outline-danger mt-3"
				:disabled="store.cleaning" @click="cleanup">
				{{ store.cleaning ? 'Nettoyage en cours…' : 'Nettoyer l’opération terminée' }}
			</button>
			<p v-if="store.context.cleanup_available" class="text-muted mt-2">Le nettoyage retire le paquet, le lien temporaire, le moteur, la sauvegarde SQL locale et les autres versions numériques avec leurs assets. La version active et les dossiers de développement sont conservés.</p>
			<div v-if="store.context.servers.length" class="mt-4">
				<h2 class="h5">Serveurs de mise à jour</h2>
				<ol><li v-for="server in store.context.servers" :key="server">{{ server }}</li></ol>
			</div>
		</template>
		<spinner v-else-if="loading" />
		<div v-if="failure" class="alert alert-danger mt-3" role="alert">{{ failure }}</div>

		<div v-if="store.result" class="mt-4" aria-live="polite">
			<div v-if="store.result.simulation" class="alert alert-info">Résultat simulé depuis la version {{ store.result.source_version }}.</div>
			<div v-if="store.result.dev_preview" class="alert alert-warning">
				Mode d’essai DEV : la dernière version publiée est proposée, même si la source est incompatible.
				Les signatures et les contrôles du ZIP restent obligatoires avant un essai réel depuis le lien secret.
			</div>
			<p class="text-muted">Catalogue vérifié depuis {{ store.result.server }}.</p>
			<ul v-if="store.result.failures.length" class="alert alert-warning list-unstyled">
				<li v-for="(item, index) in store.result.failures" :key="index">{{ item.server }} : {{ item.reason }}</li>
			</ul>
			<template v-if="store.result.available_update">
				<h2 class="h4">{{ store.result.dev_preview ? 'Version à tester' : 'Dernière version compatible' }} : {{ store.result.available_update.version }}</h2>
				<p>PHP requis : au moins {{ store.result.available_update.php_min }}, strictement inférieur à {{ store.result.available_update.php_max_exclusive }}.</p>
				<p v-if="!store.result.dev_preview && store.result.compatible_versions.length > 1">Versions compatibles : {{ store.result.compatible_versions.join(', ') }}.</p>
				<div v-if="store.context.download_error" class="alert alert-warning" role="alert">{{ store.context.download_error }}</div>
				<div v-if="store.result.dev_preview && !store.result.php_compatible" class="alert alert-warning" role="alert">
					Cette version ne convient pas au PHP actuel. Sa consultation est possible, mais son téléchargement est refusé.
				</div>
				<p v-if="store.result.simulation" class="text-muted">La source est simulée ; le ZIP est téléchargé et contrôlé localement avant l’essai réel dans l’Updater.</p>
				<button type="button" class="btn btn-primary" :disabled="store.checking || store.downloading || store.preparing || store.resetting || !!store.context.download_error || (store.result.dev_preview && !store.result.php_compatible)" @click="download">
					<i class="fa-solid fa-download me-2" aria-hidden="true"></i>
					{{ store.downloading ? 'Téléchargement et contrôle en cours…' : 'Télécharger et vérifier le ZIP' }}
				</button>
				<div v-if="store.downloaded" class="alert alert-success mt-3">
					<p class="mb-1">ZIP {{ store.downloaded.version }} {{ store.downloaded.reused ? 'déjà présent et vérifié à nouveau' : 'téléchargé et vérifié' }} ({{ store.downloaded.bytes.toLocaleString('fr-FR') }} octets).</p>
					<p class="mb-1 text-break">SHA-256 : <code>{{ store.downloaded.sha256 }}</code></p>
					<p class="mb-0">Le paquet est conservé dans le cache privé. Aucune mise à jour n’a été appliquée.</p>
				</div>
				<div v-if="store.downloaded" class="mt-3">
					<button type="button" class="btn btn-primary" :disabled="store.checking || store.downloading || store.preparing || store.resetting" @click="prepare">
						{{ store.preparing ? 'Préparation en cours…' : 'Préparer la mise à jour' }}
					</button>
				</div>
				<div v-if="store.prepared" class="alert alert-success mt-3" aria-live="polite">
					<p>La mise à jour {{ store.prepared.version }} est préparée. Conservez ce lien secret pour accéder à l’updater.</p>
					<p v-if="store.prepared.development">DEV : le paquet a été copié dans updater_test et assets_updater_test. Le lien ouvre le moteur de développement updater.</p>
					<a :href="store.prepared.url" target="_blank" rel="noopener noreferrer" referrerpolicy="no-referrer" class="btn btn-primary">Ouvrir l'application de mise à jour</a>
					<p class="text-break mt-2 mb-0">{{ updaterUrl }}</p>
					<p class="mt-2 mb-0">La préparation est terminée ; poursuivez les étapes depuis l’updater.</p>
				</div>
				<ul v-if="store.downloaded?.failures.length" class="alert alert-warning list-unstyled mt-3">
					<li v-for="(item, index) in store.downloaded.failures" :key="index">{{ item.server }} : {{ item.reason }}</li>
				</ul>
				<h3 class="h5 mt-4">Notes de version</h3>
				<pre v-if="store.result.notes !== null" class="release-notes border rounded p-3">{{ store.result.notes }}</pre>
				<p v-else class="text-muted">Notes de version indisponibles.</p>
			</template>
			<div v-else class="alert alert-success">{{ store.result.dev_preview ? 'Aucune version publiée disponible pour cet essai.' : 'Aucune mise à jour compatible disponible.' }}</div>
		</div>
	</section>
</template>

<script>

import { getCoreAssetsBase } from '../../../application/coreAssets.js'

export default {
	data: () => ({ sourceVersion: '1.0.0', loading: true, failure: null }),
	computed: {
		store() { return this.stores.useAdminSystemUpdatesStore() },
		updaterUrl() { return this.store.prepared ? new URL(this.store.prepared.url, window.location.origin).href : '' },
		logoUrl() { return `${getCoreAssetsBase()}images/logo_tycms_1.webp` },
	},
	async mounted() {
		try {
			await this.store.load()
		} catch {
			this.failure = 'Impossible de charger les paramètres de mise à jour.'
		} finally {
			this.loading = false
		}
	},
	methods: {
		clearResult() {
			this.store.result = null
			this.store.downloaded = null
			this.failure = null
		},
		async check() {
			if (this.store.checking || this.store.downloading || this.store.preparing || this.store.resetting) return
			this.failure = null
			this.showModaleWait()
			try {
				await this.store.check(this.sourceVersion)
			} catch {
				// Le client API présente le détail de l'erreur ; ne pas conserver un ancien résultat.
				this.failure = 'La vérification a échoué. Aucun résultat de mise à jour n’est retenu.'
			} finally {
				this.hideModaleWait()
			}
		},
		async reset() {
			if (this.store.checking || this.store.downloading || this.store.preparing || this.store.resetting) return
			if (!await this.askYesNoAsync('Réinitialiser la mise à jour ? Les ZIP, fichiers préparés, liens secrets et anciennes sauvegardes locales des opérations closes seront supprimés. La version active et les données du site seront conservées.')) return
			this.failure = null
			this.showModaleWait()
			try {
				if (await this.store.reset()) this.showModaleSuccess('Le processus de mise à jour a été réinitialisé.')
			} catch {
				this.failure = 'La réinitialisation a échoué. Consultez le détail de l’erreur avant de réessayer.'
			} finally {
				this.hideModaleWait()
			}
		},
		async cleanup() {
			if (this.store.cleaning) return
			if (!await this.askYesNoAsync('Nettoyer l’opération terminée ? Le paquet, le lien temporaire, le moteur, la sauvegarde SQL locale et les autres versions numériques avec leurs assets seront supprimés définitivement. La version active et les dossiers de développement seront conservés.')) return
			this.failure = null
			this.showModaleWait()
			try {
				if (await this.store.cleanup()) this.showModaleSuccess('Les fichiers de l’opération ont été nettoyés.')
			} catch {
				this.failure = 'Le nettoyage a échoué. Vous pouvez réessayer.'
			} finally {
				this.hideModaleWait()
			}
		},
		async prepare() {
			if (this.store.checking || this.store.downloading || this.store.preparing || this.store.resetting) return
			this.failure = null
			this.showModaleWait()
			try {
				await this.store.prepare()
			} catch {
				this.failure = 'La préparation a échoué. Consultez le détail de l’erreur avant de réessayer.'
			} finally {
				this.hideModaleWait()
			}
		},
		async download() {
			if (this.store.checking || this.store.downloading || this.store.preparing || this.store.resetting) return
			this.failure = null
			this.showModaleWait()
			try {
				await this.store.download()
			} catch {
				this.failure = 'Le téléchargement ou le contrôle du ZIP a échoué. Aucun nouveau paquet n’est retenu.'
			} finally {
				this.hideModaleWait()
			}
		},
	},
}
</script>

<style scoped>
.release-notes { white-space: pre-wrap; overflow-wrap: anywhere; font-family: inherit; }
</style>
