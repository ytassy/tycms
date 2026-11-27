<template>
	<section>
		<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
			<div>
				<h2 class="h4 mb-0">Journal des e-mails</h2>
				<p class="text-muted mb-0">
					Envois sortants enregistrés par le CMS. Les secrets connus sont remplacés par [SECRET].
				</p>
			</div>
			<button type="button" class="btn btn-outline-primary btn-sm" :disabled="store.loading || store.busy" @click="load">
				<i class="fa-solid fa-rotate me-1" aria-hidden="true"></i>Actualiser
			</button>
		</div>

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

		<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-4 mb-2">
			<h3 class="h5 mb-0">E-mails enregistrés</h3>
			<span v-if="store.truncated" class="badge text-bg-secondary">2000 plus récents</span>
		</div>
		<p v-if="store.truncated" class="small text-muted mb-2">
			Seuls les 2000 e-mails les plus récents sont affichés. La purge ou la rétention réduit l'historique.
		</p>

		<spinner v-if="store.loading && !store.items.length" />
		<data-table
			v-else
			:fields="fields"
			:items="store.items"
			:pagination-enabled="true"
			:initial-per-page="20"
			:row-hover="true"
			:clickable-row="true"
			:on-click-open-row="true"
			:auto-scroll-enabled="true"
			default-sort-key="created_at"
			:default-sort-key-desc="true"
		>
			<template #cell_created_at="{ item }">{{ date(item.created_at) }}</template>
			<template #cell_status="{ item }">
				<span class="badge" :class="statusClass(item.status)">{{ statusLabel(item.status) }}</span>
			</template>
			<template #cell_kind="{ item }">{{ kindLabel(item.kind) }}</template>
			<template #cell_space_title="{ item }">{{ spaceLabel(item) }}</template>
			<template #cell_template_key="{ item }">
				<code v-if="item.template_key">{{ item.template_key }}</code>
				<span v-else class="text-muted">—</span>
			</template>
			<template #cell_first_to="{ item }">
				<span v-if="item.first_to">
					{{ item.first_to }}<span v-if="item.to_count > 1" class="text-muted"> +{{ item.to_count - 1 }}</span>
				</span>
				<span v-else class="text-muted">—</span>
			</template>
			<template #cell_subject="{ item }">
				<span class="mail-subject" :title="item.subject">{{ item.subject }}</span>
			</template>
			<template #cell_error_code="{ item }">
				<code v-if="item.error_code" class="text-danger">{{ item.error_code }}</code>
				<span v-else class="text-muted">—</span>
			</template>
			<template #cell_duration_ms="{ item }">
				{{ item.duration_ms === null ? '—' : `${item.duration_ms} ms` }}
			</template>
			<template #nested_row="{ item }">
				<mail_log_detail :id="item.id" />
			</template>
		</data-table>
	</section>
</template>

<script>
import mail_log_detail from './mail_log_detail.vue'

export default {
	components: { mail_log_detail },

	props: {
		active: { type: Boolean, default: true },
	},

	data() {
		return {
			fields: [
				{ key: 'created_at', label: 'Date', sortable: true },
				{ key: 'status', label: 'Statut', sortable: true, filter: 'select' },
				{ key: 'kind', label: 'Type', sortable: true },
				{ key: 'space_title', label: 'Space', sortable: true },
				{ key: 'template_key', label: 'Modèle', sortable: true },
				{ key: 'first_to', label: 'Destinataire', sortable: true, filter: 'search' },
				{ key: 'subject', label: 'Objet', sortable: true, filter: 'search' },
				{ key: 'error_code', label: 'Erreur', sortable: true },
				{ key: 'duration_ms', label: 'Durée', sortable: true },
			],
		}
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

		statusClass(status) {
			return {
				processing: 'text-bg-primary',
				done: 'text-bg-success',
				failed: 'text-bg-danger',
			}[status] ?? 'text-bg-light'
		},

		statusLabel(status) {
			return {
				processing: 'En cours',
				done: 'Envoyé',
				failed: 'Échec',
			}[status] ?? status
		},

		kindLabel(kind) {
			return {
				html: 'Libre',
				template: 'Modèle',
				test: 'Test',
			}[kind] ?? kind
		},

		spaceLabel(item) {
			return item.space_title || (item.space_id ? `Espace #${item.space_id}` : '—')
		},
	},
}
</script>

<style scoped>
.mail-subject {
	display: inline-block;
	max-width: 26rem;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	vertical-align: middle;
}

@media (max-width: 1400px) {
	.mail-subject {
		max-width: 16rem;
	}
}
</style>
