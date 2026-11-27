<template>
	<section>
		<h2 class="h4">Journal des e-mails</h2>
		<p class="text-muted">
			Envois sortants enregistrés par le CMS. Les secrets connus sont remplacés par [SECRET].
		</p>

		<div v-if="store.status" class="row g-3 mb-4">
			<div class="col-md-4">
				<div class="border rounded p-3 h-100">
					<div class="text-muted small">Stockage</div>
					<strong>{{ store.status.enabled ? 'Activé' : 'Désactivé' }}</strong>
					<div v-if="!store.status.enabled" class="small text-warning mt-1">
						Les nouveaux envois ne sont plus enregistrés. La purge reste disponible.
					</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="border rounded p-3 h-100">
					<div class="text-muted small">Rétention</div>
					<strong>{{ store.status.retentionDays }} jours</strong>
					<div class="small text-muted mt-1">Purge automatique horaire, en plus des boutons ci-dessous.</div>
				</div>
			</div>
			<div class="col-md-4">
				<div class="border rounded p-3 h-100">
					<div class="text-muted small">Période conservée</div>
					<strong v-if="store.status.oldest">{{ date(store.status.oldest) }} → {{ date(store.status.newest) }}</strong>
					<strong v-else>Aucune ligne</strong>
				</div>
			</div>
		</div>

		<div v-if="store.failure" class="alert alert-danger" role="alert">{{ store.failure }}</div>

		<div class="d-flex flex-wrap gap-2">
			<button type="button" class="btn btn-outline-danger" :disabled="store.busy" @click="purge(0, 'toutes les lignes')">
				Tout purger
			</button>
			<button type="button" class="btn btn-outline-danger" :disabled="store.busy" @click="purge(7, 'les lignes de plus d’une semaine')">
				Purger à plus d'1 semaine
			</button>
			<button type="button" class="btn btn-outline-danger" :disabled="store.busy" @click="purge(30, 'les lignes de plus d’un mois')">
				Purger à plus d'1 mois
			</button>
		</div>

		<p class="small text-muted mt-3">
			La suppression est définitive et s'effectue par lots bornés. Relancez la purge si un historique
			volumineux n'a pas été entièrement supprimé.
		</p>
	</section>
</template>

<script>
export default {
	props: {
		active: { type: Boolean, default: true },
	},

	computed: {
		store() { return this.stores.useAdminMailLogsStore() },
	},

	mounted() {
		if (this.active) this.load()
	},

	watch: {
		active(now) {
			if (now) this.load()
		},
	},

	methods: {
		async load() {
			try {
				await this.store.load()
			} catch (_) {
				// Le message est déjà posé par le store.
			}
		},

		async purge(days, label) {
			if (!await this.askYesNoAsync(`Supprimer définitivement ${label} du journal des e-mails ?`)) return

			this.showModaleWait()
			let deleted = null
			try {
				const result = await this.store.purge(days)
				deleted = result?.deleted ?? 0
			} catch (_) {
				this.showModaleError('La purge du journal des e-mails a échoué.')
			} finally {
				this.hideModaleWait()
			}

			if (deleted !== null) {
				this.showModaleSuccess(deleted === 1 ? '1 ligne supprimée.' : `${deleted} lignes supprimées.`)
				await this.load()
			}
		},

		date(value) {
			return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('fr-FR')
		},
	},
}
</script>
