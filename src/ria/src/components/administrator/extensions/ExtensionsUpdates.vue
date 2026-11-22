<template>
	<section>
		<div class="d-flex align-items-start justify-content-between gap-3 mb-3">
			<div>
				<h2 class="h4 mb-1">Mises à jour des extensions</h2>
				<p class="text-body-secondary mb-0">
					Chaque extension installée est vérifiée auprès de son catalogue d’origine.
					Seule la dernière version publiée peut être installée.
				</p>
			</div>
			<button type="button" class="btn btn-outline-primary btn-sm"
				:disabled="store.updatesLoading" @click="refresh">
				<span v-if="store.updatesLoading" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
				<i v-else class="fa-solid fa-rotate me-1" aria-hidden="true" />
				Vérifier les mises à jour
			</button>
		</div>

		<div v-if="store.updatesError" class="alert alert-warning" role="alert">{{ store.updatesError }}</div>
		<div v-if="store.updateError" class="alert alert-warning" role="alert">{{ store.updateError }}</div>

		<div v-if="store.updatesLoading && updates.length === 0" class="py-5 text-center">
			<spinner />
		</div>

		<template v-else>
			<div v-if="updates.length === 0" class="alert alert-info mb-0">
				Aucune extension installée n’est disponible pour une mise à jour.
			</div>

			<div v-for="extension in updates" :key="extension.name" class="card mb-3">
				<div class="card-body d-flex flex-wrap align-items-center gap-3">
					<div class="flex-grow-1">
						<div class="d-flex flex-wrap align-items-center gap-2">
							<h3 class="h5 mb-0">{{ extension.title || extension.name }}</h3>
							<span class="badge text-bg-secondary">Installée</span>
							<span :class="['badge', statusClass(extension.status)]">{{ statusLabel(extension.status) }}</span>
						</div>

						<small class="text-body-secondary">
							{{ extension.name }} · v{{ extension.version || '—' }}
							<template v-if="extension.latest_version"> · Dernière : {{ extension.latest_version }}</template>
							<template v-if="extension.source_name"> · Source : {{ extension.source_name }}</template>
						</small>

						<p v-if="extension.error" class="text-danger small mb-0 mt-1">{{ extension.error }}</p>
						<p v-if="extension.warning" class="text-body-secondary small mb-0 mt-1">{{ extension.warning }}</p>
					</div>

					<div class="text-end">
						<button v-if="extension.status === 'update_available'" type="button"
							class="btn btn-primary" :disabled="store.updateRunning !== null"
							@click="update(extension)">
							<span v-if="store.updateRunning === extension.name"
								class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
							<i v-else class="fa-solid fa-cloud-arrow-up me-1" aria-hidden="true" /> Mettre à jour
						</button>
						<span v-else-if="extension.status === 'update_blocked'"
							class="badge text-bg-warning"
							title="La version cible n’autorise pas la mise à jour depuis la version installée">
							Mise à jour bloquée
						</span>
					</div>

					<div v-if="extension.pending_update" class="w-100">
						<div class="alert alert-warning mb-0 d-flex flex-wrap align-items-center gap-2">
							<span class="me-auto">
								Mise à jour interrompue de
								<strong>{{ extension.pending_update.from_version }}</strong> vers
								<strong>{{ extension.pending_update.to_version }}</strong>.
							</span>
							<button type="button" class="btn btn-warning btn-sm"
								:disabled="store.updateRunning !== null"
								@click="resume(extension)">
								<i class="fa-solid fa-play" aria-hidden="true" /> Reprendre
							</button>
							<button type="button" class="btn btn-outline-secondary btn-sm"
								:disabled="store.updateRunning !== null"
								@click="rollback(extension)">
								<i class="fa-solid fa-rotate-left" aria-hidden="true" /> Restaurer l’ancienne version
							</button>
						</div>
					</div>
				</div>
			</div>
		</template>
	</section>
</template>

<script>
export default {
	props: {
		active: { type: Boolean, default: false },
	},

	computed: {
		store() {
			return this.stores.useAdminExtensionsStore()
		},

		updates() {
			return this.store.updateList
		},
	},

	watch: {
		active(value) {
			if (value) this.refresh()
		},
	},

	async mounted() {
		if (this.active) await this.refresh()
	},

	methods: {
		async refresh() {
			if (this.store.updatesLoading) return

			try {
				await this.store.loadUpdates()
			} catch {
				// Le store conserve le message.
			}
		},

		statusLabel(status) {
			return {
				update_available: 'Mise à jour disponible',
				up_to_date: 'À jour',
				update_blocked: 'Mise à jour bloquée',
				incompatible: 'Incompatible',
				source_unavailable: 'Source indisponible',
				inconsistent: 'Provenance incohérente',
				unmanaged: 'Hors catalogue',
				pending_update: 'Mise à jour interrompue',
				error: 'Erreur',
			}[status] || status
		},

		statusClass(status) {
			return {
				update_available: 'text-bg-warning',
				up_to_date: 'text-bg-success',
				update_blocked: 'text-bg-warning',
				incompatible: 'text-bg-danger',
				source_unavailable: 'text-bg-secondary',
				inconsistent: 'text-bg-danger',
				unmanaged: 'text-bg-secondary',
				pending_update: 'text-bg-warning',
				error: 'text-bg-danger',
			}[status] || 'text-bg-secondary'
		},

		async update(extension) {
			if (this.store.updateRunning !== null || !extension.latest_version) return

			const label = extension.title || extension.name
			const warning = extension.warning ? ` ${extension.warning}` : ''
			const confirmed = await this.askYesNoAsync(
				`Mettre à jour « ${label} » de ${extension.version} vers ${extension.latest_version} ?${warning}`,
			)
			if (!confirmed) return

			this.showModaleWait()
			try {
				const result = await this.store.runUpdate(
					extension.name,
					extension.source_id,
					extension.latest_version,
				)
				const message = `Extension « ${extension.name} » mise à jour en version ${result.version}.`
				if (result.warning) this.showModaleWarning(`${message} ${result.warning}`)
				else this.showModaleSuccess(message)
			} catch {
				// Le store conserve le message et recharge l’état interrompu.
			} finally {
				this.hideModaleWait()
			}
		},

		async resume(extension) {
			if (this.store.updateRunning !== null || !extension.pending_update) return

			const label = extension.title || extension.name
			const confirmed = await this.askYesNoAsync(
				`Reprendre la finalisation de « ${label} » vers ${extension.pending_update.to_version} ?`,
			)
			if (!confirmed) return

			this.showModaleWait()
			try {
				const result = await this.store.resumeUpdate(
					extension.name,
					extension.pending_update.operation_id,
				)
				this.showModaleSuccess(`Mise à jour finalisée en version ${result.version}.`)
			} catch {
				// Le store conserve le message d’échec.
			} finally {
				this.hideModaleWait()
			}
		},

		async rollback(extension) {
			if (this.store.updateRunning !== null || !extension.pending_update) return

			const label = extension.title || extension.name
			const confirmed = await this.askYesNoAsync(
				`Restaurer « ${label} » en version ${extension.pending_update.from_version} ?`
				+ ' Les migrations SQL déjà exécutées ne seront pas annulées.',
			)
			if (!confirmed) return

			this.showModaleWait()
			try {
				const result = await this.store.rollbackUpdate(
					extension.name,
					extension.pending_update.operation_id,
				)
				const message = `Version ${result.version} restaurée.`
				if (result.warning) this.showModaleWarning(`${message} ${result.warning}`)
				else this.showModaleSuccess(message)
			} catch {
				// Le store conserve le message d’échec.
			} finally {
				this.hideModaleWait()
			}
		},
	},
}
</script>
