<template>
	<section>
		<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
			<div>
				<h2 class="h4 mb-0">File de jobs</h2>
				<p class="text-muted mb-0">Traitements différés de l’installation. Le worker n’exécute qu’un job à la fois.</p>
			</div>
			<div class="d-flex flex-wrap gap-2">
				<span v-for="(count, status) in counts" :key="status" class="badge rounded-pill" :class="statusClass(status)">
					{{ status }} : {{ count }}
				</span>
			</div>
		</div>

		<div v-if="store.result?.worker.stale" class="alert alert-warning" role="alert">
			Le worker de la file n’a pas tourné depuis plus de cinq minutes.
			Vérifiez que la seconde ligne de CRON appelle <code>{{ queuePath }}</code> avec
			<code>job_worker_token</code>, ou que <code>job_traffic_trigger</code> est actif.
		</div>

		<form class="row g-2 align-items-end mb-3" @submit.prevent="applyFilters">
			<div class="col-auto">
				<label class="form-label" for="jobs-status">Statut</label>
				<select id="jobs-status" v-model="store.filters.status" class="form-select form-select-sm">
					<option value="">Tous</option>
					<option v-for="status in statuses" :key="status" :value="status">{{ status }}</option>
				</select>
			</div>
			<div class="col-auto">
				<label class="form-label" for="jobs-type">Type</label>
				<select id="jobs-type" v-model="store.filters.type" class="form-select form-select-sm">
					<option value="">Tous</option>
					<option v-for="type in store.result?.types ?? []" :key="type" :value="type">{{ type }}</option>
				</select>
			</div>
			<div class="col-auto">
				<label class="form-label" for="jobs-space">Espace</label>
				<input id="jobs-space" v-model="store.filters.spaceId" type="number" min="1" step="1"
					class="form-control form-control-sm" style="width: 7rem;" placeholder="Tous">
			</div>
			<div class="col-auto">
				<button type="submit" class="btn btn-primary btn-sm">Filtrer</button>
			</div>
			<div class="col-auto ms-auto">
				<button type="button" class="btn btn-outline-danger btn-sm" :disabled="store.loading" @click="purge">
					Purger les terminés
				</button>
			</div>
		</form>

		<spinner v-if="store.loading && !store.result" />

		<div v-else-if="store.result" class="card shadow-sm border-0">
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0">
					<thead class="table-light">
						<tr>
							<th scope="col">#</th>
							<th scope="col">Type</th>
							<th scope="col">Statut</th>
							<th scope="col">Essais</th>
							<th scope="col">Espace</th>
							<th scope="col">Disponible</th>
							<th scope="col">Erreur</th>
							<th scope="col" class="text-end">Actions</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="job in store.result.items" :key="job.id">
							<td class="fw-semibold">{{ job.id }}</td>
							<td>
								<code>{{ job.type }}</code>
								<div class="text-muted small">{{ job.producer }}</div>
							</td>
							<td><span class="badge" :class="statusClass(job.status)">{{ job.status }}</span></td>
							<td>{{ job.attempts }}/{{ job.maxAttempts }}</td>
							<td>{{ job.spaceId ?? '—' }}</td>
							<td class="small text-nowrap">{{ job.availableAt }}</td>
							<td class="small text-truncate" style="max-width: 22rem;" :title="job.lastError ?? ''">
								{{ job.lastError || '—' }}
							</td>
							<td class="text-end text-nowrap">
								<button type="button" class="btn btn-outline-secondary btn-sm me-1" @click="openDetail(job.id)">
									Détail
								</button>
								<button v-if="canRetry(job)" type="button" class="btn btn-outline-primary btn-sm me-1"
									@click="retry(job)">
									Relancer
								</button>
								<button v-if="canCancel(job)" type="button" class="btn btn-outline-danger btn-sm"
									@click="cancel(job)">
									Annuler
								</button>
							</td>
						</tr>
						<tr v-if="store.result.items.length === 0">
							<td colspan="8" class="text-center text-muted py-4">Aucun job pour ces filtres.</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>

		<nav v-if="pages > 1" class="mt-3" aria-label="Pagination de la file">
			<ul class="pagination pagination-sm mb-0">
				<li class="page-item" :class="{ disabled: store.page <= 1 }">
					<button type="button" class="page-link" @click="goPage(store.page - 1)">Précédent</button>
				</li>
				<li class="page-item disabled">
					<span class="page-link">Page {{ store.page }} / {{ pages }}</span>
				</li>
				<li class="page-item" :class="{ disabled: store.page >= pages }">
					<button type="button" class="page-link" @click="goPage(store.page + 1)">Suivant</button>
				</li>
			</ul>
		</nav>

		<div v-if="store.detail" class="card shadow-sm border-0 mt-4">
			<div class="card-header d-flex justify-content-between align-items-center">
				<span class="fw-semibold">Job #{{ store.detail.id }} — {{ store.detail.type }}</span>
				<button type="button" class="btn-close" aria-label="Fermer le détail" @click="store.detail = null"></button>
			</div>
			<div class="card-body">
				<dl class="row mb-0 small">
					<dt class="col-sm-3">Statut</dt>
					<dd class="col-sm-9"><span class="badge" :class="statusClass(store.detail.status)">{{ store.detail.status }}</span></dd>

					<dt class="col-sm-3">Producteur</dt>
					<dd class="col-sm-9">{{ store.detail.producer }}</dd>

					<dt class="col-sm-3">Priorité</dt>
					<dd class="col-sm-9">{{ store.detail.priority }}</dd>

					<dt class="col-sm-3">Tentatives</dt>
					<dd class="col-sm-9">{{ store.detail.attempts }} / {{ store.detail.maxAttempts }}</dd>

					<dt class="col-sm-3">Principal</dt>
					<dd class="col-sm-9">
						{{ store.detail.principal }}
						<span v-if="store.detail.execUserId">(compte {{ store.detail.execUserId }})</span>
					</dd>

					<dt class="col-sm-3">Espace / auteur</dt>
					<dd class="col-sm-9">
						{{ store.detail.spaceId ?? '—' }} / {{ store.detail.createdBy ?? '—' }}
					</dd>

					<dt class="col-sm-3">Déduplication</dt>
					<dd class="col-sm-9"><code v-if="store.detail.dedupKey">{{ store.detail.dedupKey }}</code><span v-else>—</span></dd>

					<dt class="col-sm-3">Disponible à</dt>
					<dd class="col-sm-9">{{ store.detail.availableAt ?? '—' }}</dd>

					<dt class="col-sm-3">Bail</dt>
					<dd class="col-sm-9">
						{{ store.detail.reservedUntil ?? '—' }}
						<span v-if="store.detail.cancelRequested" class="badge text-bg-warning ms-2">arrêt demandé</span>
					</dd>

					<dt class="col-sm-3">Créé / démarré / fini</dt>
					<dd class="col-sm-9">
						{{ store.detail.created }} · {{ store.detail.startedAt ?? '—' }} · {{ store.detail.finishedAt ?? '—' }}
					</dd>

					<dt class="col-sm-3">Durée</dt>
					<dd class="col-sm-9">{{ store.detail.durationMs === null ? '—' : store.detail.durationMs + ' ms' }}</dd>
				</dl>

				<h3 class="h6 mt-4">Erreur</h3>
				<pre class="bg-light rounded p-2 small text-wrap mb-0">{{ store.detail.lastError || 'Aucune erreur enregistrée.' }}</pre>

				<h3 class="h6 mt-4">Payload (lecture seule)</h3>
				<pre class="bg-light rounded p-2 small text-wrap mb-0">{{ payloadText }}</pre>
			</div>
		</div>
	</section>
</template>

<script>

export default {

	data() {
		return {
			statuses: ['pending', 'processing', 'completed', 'failed', 'cancelled'],
			queuePath: '/queue-worker',
		}
	},

	computed: {

		store() { return this.stores.useAdminJobsStore() },

		counts() { return this.store.result?.counts ?? {} },

		pages() {
			if (!this.store.result) return 1
			return Math.max(1, Math.ceil(this.store.result.total / this.store.result.perPage))
		},

		// Le payload est affiché tel qu'il a été enregistré : le contrat des
		// handlers interdit d'y placer un secret.
		payloadText() {
			const detail = this.store.detail
			if (!detail) return ''
			if (detail.payload !== null && detail.payload !== undefined) {
				return JSON.stringify(detail.payload, null, 2)
			}
			return detail.payloadText || ''
		},
	},

	mounted() {
		this.store.load()
	},

	methods: {

		applyFilters() {
			this.store.page = 1
			this.store.load()
		},

		goPage(page) {
			if (page < 1 || page > this.pages) return
			this.store.page = page
			this.store.load()
		},

		openDetail(id) {
			return this.store.openDetail(id)
		},

		canRetry(job) { return job.status === 'failed' || job.status === 'cancelled' },

		canCancel(job) { return job.status === 'pending' || job.status === 'processing' },

		async retry(job) {
			if (!await this.askYesNoAsync(`Relancer le job #${job.id} ? Ses tentatives seront remises à zéro.`)) return

			this.showModaleWait()
			let done = false
			try {
				await this.store.retry(job.id)
				done = true
			} finally {
				this.hideModaleWait()
			}

			if (done) this.showModaleSuccess()
		},

		async cancel(job) {
			if (!await this.askYesNoAsync(`Annuler le job #${job.id} ?`)) return

			this.showModaleWait()
			let done = false
			try {
				await this.store.cancel(job.id)
				done = true
			} finally {
				this.hideModaleWait()
			}

			if (done) this.showModaleSuccess()
		},

		async purge() {
			const answer = await this.askInput('Purger les jobs terminés', 'Jours de rétention', '14')
			if (answer === null) return

			const days = Number.parseInt(answer, 10)
			if (!Number.isInteger(days) || days < 1 || days > 365) {
				this.showModaleWarning('Indiquez un nombre de jours entre 1 et 365.')
				return
			}

			if (!await this.askYesNoAsync(`Supprimer définitivement les jobs terminés depuis plus de ${days} jours ?`)) return

			this.showModaleWait()
			let deleted = null
			try {
				const result = await this.store.purge(days)
				deleted = result?.deleted ?? 0
			} finally {
				this.hideModaleWait()
			}

			if (deleted !== null) this.showModaleSuccess(deleted === 1 ? '1 job supprimé.' : `${deleted} jobs supprimés.`)
		},

		statusClass(status) {
			return {
				pending: 'text-bg-secondary',
				processing: 'text-bg-primary',
				completed: 'text-bg-success',
				failed: 'text-bg-danger',
				cancelled: 'text-bg-warning',
			}[status] ?? 'text-bg-light'
		},
	},
}
</script>
