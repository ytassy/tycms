<template>
	<modal :visible="visible" width="1000px" :large-body="true" :closable="true" @close="$emit('close')">
		<template #header>Historique de « {{ pageName }} »</template>

		<template #body>
			<p class="small text-muted">
				Les versions conservées sont les états remplacés lors d’un enregistrement.
				<span v-if="retention">Rétention : {{ retention.currentDayVersions }} versions aujourd’hui, puis 1 version par jour sur {{ retention.days }} jours.</span>
			</p>

			<div v-if="error" class="alert alert-warning">{{ error }}</div>
			<div v-if="loading" class="py-5 text-center"><spinner /></div>

			<template v-else>
				<p v-if="versions.length === 0" class="text-muted mb-0">
					Aucune version pour cette page. Une version apparaît dès qu’une page existante est modifiée.
				</p>

				<div v-else class="table-responsive">
					<table class="table table-sm table-hover align-middle mb-0">
						<thead class="table-light">
							<tr>
								<th scope="col">Version</th>
								<th scope="col">Auteur</th>
								<th scope="col">Type</th>
								<th scope="col" class="text-end">Taille du JSON</th>
								<th scope="col" class="text-end"></th>
							</tr>
						</thead>
						<tbody>
							<tr v-for="version in versions" :key="version.id">
								<td>{{ formatDate(version.created) }}</td>
								<td>{{ authorLabel(version) }}</td>
								<td>{{ typeLabel(version.type) }}</td>
								<td class="text-end">{{ formatBytes(version.dataSize) }}</td>
								<td class="text-end">
									<button type="button" class="btn btn-outline-primary btn-sm"
										:disabled="loadingVersion !== null"
										@click="selectVersion(version)">
										<spinner v-if="loadingVersion === version.id" size="sm" />
										<template v-else>Voir le JSON</template>
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>

				<div v-if="selectedVersion" class="mt-4">
					<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
						<h3 class="h6 mb-0">Version du {{ formatDate(selectedVersion.created) }}</h3>
						<text_copy :text-to-copy="jsonPreview" :always-visible="true" :show-text-confirmation="true"
							title="Copier le JSON de cette version" />
					</div>
					<pre class="history-json mb-0">{{ jsonPreview }}</pre>
				</div>
			</template>
		</template>

		<template #footer>
			<button type="button" class="btn btn-outline-secondary me-2" @click="$emit('close')">Fermer</button>
			<button type="button" class="btn btn-primary" :disabled="!selectedVersion || !canLoad"
				:title="canLoad ? null : 'Vous ne pouvez pas modifier cette page.'"
				@click="loadVersion">
				Charger dans l’éditeur
			</button>
		</template>
	</modal>
</template>

<script>
import { formatPageBytes } from './pageSize.js'

export default {
	props: {
		visible: { type: Boolean, default: false },
		pageId: { type: Number, default: null },
		pageName: { type: String, default: '' },
	},
	emits: ['close', 'load'],

	data() {
		return {
			versions: [],
			retention: null,
			selectedVersion: null,
			loading: false,
			loadingVersion: null,
			error: '',
		}
	},

	computed: {
		store() {
			return this.stores.useAdminPagesStore()
		},
		canLoad() {
			return Boolean(this.store.current?.canEdit)
		},
		jsonPreview() {
			if (!this.selectedVersion) return ''
			return JSON.stringify({
				name: this.selectedVersion.name,
				description: this.selectedVersion.description,
				type: this.selectedVersion.type,
				rawHtml: this.selectedVersion.rawHtml,
				rawScss: this.selectedVersion.rawScss,
				data: this.selectedVersion.data,
			}, null, 2)
		},
	},

	watch: {
		visible(visible) {
			if (visible) this.load()
		},
		pageId() {
			if (this.visible) this.load()
		},
	},

	methods: {
		async load() {
			this.error = ''
			this.selectedVersion = null
			this.loading = true

			try {
				const response = await this.store.loadHistory(this.pageId)
				this.versions = response.versions ?? []
				this.retention = response.retention ?? null
			} catch (error) {
				this.error = error.message
			} finally {
				this.loading = false
			}
		},

		async selectVersion(version) {
			if (this.loadingVersion !== null) return

			this.error = ''
			this.loadingVersion = version.id
			try {
				this.selectedVersion = await this.store.loadHistoryVersion(this.pageId, version.id)
			} catch (error) {
				this.error = error.message
			} finally {
				this.loadingVersion = null
			}
		},

		loadVersion() {
			if (!this.selectedVersion || !this.canLoad) return
			this.$emit('load', this.selectedVersion)
		},

		formatDate(value) {
			return this.utcToLocal(value)
		},

		formatBytes(bytes) {
			return Number(bytes) > 0 ? formatPageBytes(Number(bytes)) : '—'
		},

		typeLabel(type) {
			return Number(type) === 1 ? 'HTML brut' : 'Visuel'
		},

		authorLabel(version) {
			if (version.userName) return version.userName
			return version.userId ? `Compte #${version.userId}` : 'Inconnu'
		},
	},
}
</script>

<style scoped>
.history-json {
	max-height: 45vh;
	overflow: auto;
	padding: 0.75rem;
	font-size: 0.8125rem;
	white-space: pre-wrap;
	word-break: break-word;
	background: var(--bs-tertiary-bg);
	border: 1px solid var(--bs-border-color);
	border-radius: 0.375rem;
}
</style>
