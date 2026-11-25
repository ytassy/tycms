<template>
	<div class="security-report">

		<div v-if="error" class="alert alert-danger mt-3" role="alert">{{ error }}</div>

		<spinner v-if="processing" />

		<template v-else-if="report">

			<div v-if="!report.groups.length" class="alert alert-info mt-3" role="status">
				Aucun point d'analyse n'est disponible.
			</div>

			<div v-else>

				<div class="d-flex flex-wrap gap-2 my-3">
					<span v-for="badge in badges" :key="badge.status" :class="['badge', badge.css]">
						{{ badge.label }} : {{ report.summary[badge.status] ?? 0 }}
					</span>
				</div>

				<section v-for="group in report.groups" :key="group.id" class="mb-4">
					<h2 class="h5 border-bottom pb-2 mb-3">
						{{ group.label }}
						<span v-if="group.counts.critical" class="badge bg-danger ms-2">{{ group.counts.critical }}</span>
						<span v-else-if="group.counts.danger" class="badge bg-warning text-dark ms-2">{{ group.counts.danger }}</span>
					</h2>

					<div v-for="(finding, index) in group.findings" :key="index"
						:class="['card', 'mb-2', 'border-start', 'border-4', statusBorder(finding.status)]">
						<div class="card-body py-3">
							<div class="d-flex justify-content-between align-items-start gap-2">
								<strong>{{ finding.title }}</strong>
								<span :class="['badge', statusCss(finding.status)]">{{ statusLabel(finding.status) }}</span>
							</div>

							<p class="mb-0 mt-2">{{ finding.description }}</p>

							<p v-if="finding.fix" class="mb-0 mt-2 small text-muted">
								<i class="fa-solid fa-wrench me-1" aria-hidden="true"></i>{{ finding.fix }}
								<span v-if="finding.location" class="ms-1">({{ finding.location }})</span>
							</p>
						</div>
					</div>
				</section>
			</div>
		</template>
	</div>
</template>

<script>

export default {

	name: 'security_report',

	data() {
		return {
			error: null,
			processing: true,
			report: null,
		}
	},

	computed: {

		badges() {
			return [
				{ status: 'critical', label: 'Critiques', css: 'bg-danger' },
				{ status: 'danger', label: 'Alertes', css: 'bg-warning text-dark' },
				{ status: 'info', label: 'Informations', css: 'bg-info text-dark' },
				{ status: 'ok', label: 'Conformes', css: 'bg-success' },
			]
		},
	},

	async mounted() {
		try {
			this.report = await this.api.get('/administrator/api/system/security-report')
		} catch (exception) {
			this.error = exception instanceof Error ? exception.message : 'Une erreur inconnue est survenue.'
		} finally {
			this.processing = false
		}
	},

	methods: {

		statusCss(status) {
			return {
				critical: 'bg-danger',
				danger: 'bg-warning text-dark',
				info: 'bg-info text-dark',
				ok: 'bg-success',
			}[status] || 'bg-secondary'
		},

		statusBorder(status) {
			return {
				critical: 'border-danger',
				danger: 'border-warning',
				info: 'border-info',
				ok: 'border-success',
			}[status] || 'border-secondary'
		},

		statusLabel(status) {
			return {
				critical: 'Critique',
				danger: 'Alerte',
				info: 'Information',
				ok: 'OK',
			}[status] || status
		},
	},
}
</script>
