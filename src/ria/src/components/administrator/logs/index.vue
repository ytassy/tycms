<template>
	<section>
		<h2 class="h4">Journaux</h2>
		<p class="text-muted">Journaux de cette installation, tous Spaces confondus. La conservation dépend de la durée et du volume maximum de chaque niveau.</p>
		<div v-if="store.status && !store.status.available" class="alert alert-warning" role="alert">{{ store.status.storage_error }}</div>
		<div v-if="store.status?.available" class="d-flex gap-3 flex-wrap mb-3">
			<div v-for="(level, name) in store.status.levels" :key="name" class="border rounded p-2">
				<strong>{{ name }}</strong> : {{ megabytes(level.bytes) }} / {{ megabytes(level.max_bytes) }} Mo
				<div class="small text-muted">{{ level.retention_days }} jours maximum · {{ level.files }} fichier(s)</div>
			</div>
		</div>
		<form class="row g-2 align-items-end mb-3" @submit.prevent="refresh">
			<fieldset class="col-12">
				<legend class="fs-6">Niveaux</legend>
				<label v-for="level in levels" :key="level" class="form-check form-check-inline">
					<input v-model="filters.levels" type="checkbox" class="form-check-input" :value="level" :disabled="store.busy">
					<span class="form-check-label">{{ level }}</span>
				</label>
			</fieldset>
			<div class="col-sm-3"><label class="form-label" for="logs-from">Du (UTC)</label><input id="logs-from" v-model="filters.from" type="date" class="form-control"></div>
			<div class="col-sm-3"><label class="form-label" for="logs-to">Au (UTC)</label><input id="logs-to" v-model="filters.to" type="date" class="form-control"></div>
			<div class="col-sm-3"><label class="form-label" for="logs-channel">Canal</label><input id="logs-channel" v-model.trim="filters.channel" maxlength="61" class="form-control" placeholder="sso, application…"></div>
			<div class="col-sm-3"><label class="form-label" for="logs-space">ID du Space</label><input id="logs-space" v-model="spaceId" type="number" min="1" step="1" class="form-control"></div>
			<div class="col-sm-4"><label class="form-label" for="logs-code">Code exact</label><input id="logs-code" v-model.trim="filters.code" maxlength="81" class="form-control" placeholder="SSO_MEMBER_REQUIRED"></div>
			<div class="col-sm-5"><label class="form-label" for="logs-request">Référence de requête</label><input id="logs-request" v-model.trim="filters.request_id" maxlength="32" class="form-control"></div>
			<div class="col-sm-3"><button class="btn btn-primary" type="submit" :disabled="store.busy || !filters.levels.length">{{ store.busy ? 'Lecture…' : 'Appliquer / Actualiser' }}</button></div>
		</form>
		<div v-if="store.failure" class="alert alert-danger" role="alert">{{ store.failure }}</div>
		<p v-if="store.corruptLines" class="text-warning">Des lignes incomplètes ou invalides ont été ignorées pendant cette lecture.</p>
		<div class="table-responsive">
			<table class="table align-middle">
				<thead><tr><th>Date locale</th><th>Niveau</th><th>Canal</th><th>Événement</th><th>Space</th><th>Détail</th></tr></thead>
				<tbody>
					<tr v-for="entry in store.entries" :key="entry.id">
						<td class="text-nowrap">{{ date(entry.timestamp) }}</td>
						<td><span class="badge" :class="entry.level === 'ERROR' ? 'bg-danger' : entry.level === 'WARNING' ? 'bg-warning text-dark' : 'bg-info text-dark'">{{ entry.level }}</span></td>
						<td>{{ entry.channel }}</td>
						<td><code>{{ entry.code }}</code><div>{{ entry.message }}</div><div v-if="entry.truncated" class="small text-warning">Trace tronquée.</div></td>
						<td>{{ entry.context.space_id ?? '—' }}</td>
						<td><details><summary>Voir</summary><text_copy :text-to-copy="detail(entry)" :always-visible="true" :show-text-confirmation="true" title="Copier cette entrée" /><pre class="log-detail">{{ detail(entry) }}</pre></details></td>
					</tr>
					<tr v-if="!store.entries.length"><td colspan="6" class="text-muted">{{ store.busy ? 'Lecture en cours…' : 'Aucune entrée dans cette page de résultats.' }}</td></tr>
				</tbody>
			</table>
		</div>
		<button v-if="store.cursor" class="btn btn-outline-primary" :disabled="store.busy || changed" @click="store.load(query, true)">Poursuivre vers les entrées plus anciennes</button>
		<p v-if="store.cursor" class="small text-muted mt-2">La recherche est limitée par page. Poursuivez pour examiner la suite de l’historique ; Actualiser revient aux entrées récentes.</p>
	</section>
</template>

<script>
export default {
	data() {
		return {
			levels: ['INFO', 'WARNING', 'ERROR'], spaceId: '',
			filters: { levels: ['INFO', 'WARNING', 'ERROR'], from: '', to: '', channel: '', code: '', request_id: '' },
		}
	},
	computed: {
		store() { return this.stores.useAdminLogsStore() },
		query() { return { ...this.filters, space_id: this.spaceId === '' ? null : Number(this.spaceId) } },
		changed() { return JSON.stringify(this.query) !== this.store.applied },
	},
	props: {
		active: { type: Boolean, default: true },
	},

	mounted() { if (this.active) this.refresh() },

	watch: {
		active(now) { if (now) this.refresh() },
	},
	beforeUnmount() { this.store.clear() },
	methods: {
		refresh() { this.store.load(this.query) },
		megabytes(bytes) { return (bytes / 1_000_000).toLocaleString('fr-FR', { maximumFractionDigits: 2 }) },
		date(value) { return new Date(value.replace(/(\.\d{3})\d+Z$/, '$1Z')).toLocaleString('fr-FR') },
		detail(entry) { return JSON.stringify(entry, null, 2) },
	},
}
</script>

<style scoped>
.log-detail { white-space: pre-wrap; overflow-wrap: anywhere; min-width: 280px; max-width: 600px; max-height: 400px; overflow: auto; }
</style>
