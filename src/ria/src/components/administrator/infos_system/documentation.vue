<template>
	<section>
		<h2 class="h4">Documentation</h2>
		<p class="text-muted">
			Configuration du CRON de l’installation et de la file de jobs sur un hébergement mutualisé.
			Les jetons ne sont jamais affichés : la page indique seulement s’ils sont configurés.
		</p>

		<spinner v-if="!store.documentation" />

		<template v-else>
			<div v-if="!documentation.cron.configured || !documentation.queue.configured" class="alert alert-warning" role="alert">
				Un jeton manque : la route correspondante répond <code>503</code>.
				Renseignez <code>cron_token</code> et <code>job_worker_token</code> dans
				<code>configuration.php</code>.
			</div>

			<h3 class="h5 mt-4">Deux lignes de CRON</h3>
			<p class="mb-2">
				Le CRON du système exécute les traitements récurrents des extensions. Celui de la file
				draine les jobs différés. Chacun a son propre secret et son propre budget.
			</p>

			<div class="table-responsive">
				<table class="table align-middle">
					<thead class="table-light">
						<tr>
							<th scope="col">Rôle</th>
							<th scope="col">Chemin</th>
							<th scope="col">Champ du jeton</th>
							<th scope="col">État</th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td>CRON du système</td>
							<td><code>{{ documentation.cron.path }}</code></td>
							<td><code>{{ documentation.cron.field }}</code></td>
							<td>
								<span class="badge" :class="documentation.cron.configured ? 'text-bg-success' : 'text-bg-danger'">
									{{ documentation.cron.configured ? 'configuré' : 'absent' }}
								</span>
							</td>
						</tr>
						<tr>
							<td>CRON de la file</td>
							<td><code>{{ documentation.queue.path }}</code></td>
							<td><code>{{ documentation.queue.field }}</code></td>
							<td>
								<span class="badge" :class="documentation.queue.configured ? 'text-bg-success' : 'text-bg-danger'">
									{{ documentation.queue.configured ? 'configuré' : 'absent' }}
								</span>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<h3 class="h5 mt-4">Exemple cPanel, Plesk ou Infomaniak</h3>
			<p class="mb-2 text-muted">
				À appeler chaque minute. Remplacez <code>&lt;jeton&gt;</code> par la valeur du champ correspondant ;
				préférez un fichier privé ou une variable de la crontab quand l’hébergeur le permet.
			</p>
			<pre class="bg-light rounded p-3 small mb-0">* * * * * curl -fsS "{{ origin }}{{ documentation.cron.path }}?{{ documentation.cron.field }}=&lt;jeton&gt;"
* * * * * curl -fsS "{{ origin }}{{ documentation.queue.path }}?{{ documentation.queue.field }}=&lt;jeton&gt;"</pre>
			<p class="text-muted small mt-2 mb-0">
				Infomaniak déclenche les URL en GET : le jeton dans l’URL reste nécessaire, avec une rotation régulière
				et un accès restreint aux journaux HTTP.
			</p>

			<h3 class="h5 mt-4">Déclenchement par le trafic</h3>
			<p class="mb-0">
				<span class="badge" :class="documentation.trafficTrigger ? 'text-bg-success' : 'text-bg-secondary'">
					{{ documentation.trafficTrigger ? 'actif' : 'désactivé' }}
				</span>
				<span class="ms-2 text-muted">
					Une page publique appelle <code>/api/jobs/ping</code> quand des jobs sont dus, au plus une fois
					par onglet toutes les cinq minutes. Ce déclenchement ne remplace pas le CRON : il l’épaule.
				</span>
			</p>

			<h3 class="h5 mt-4">Limites appliquées</h3>
			<dl class="row mb-0">
				<dt class="col-sm-4">CRON de la file</dt>
				<dd class="col-sm-8">{{ limits.cronJobs }} jobs, {{ limits.cronSeconds }} secondes par passage</dd>

				<dt class="col-sm-4">Trafic du site</dt>
				<dd class="col-sm-8">{{ limits.trafficJobs }} jobs, {{ limits.trafficSeconds }} secondes par appel</dd>

				<dt class="col-sm-4">Durée déclarée d’un job</dt>
				<dd class="col-sm-8">{{ limits.minTimeMax }} secondes au maximum</dd>

				<dt class="col-sm-4">Payload</dt>
				<dd class="col-sm-8">{{ limits.payloadBytes }} octets au maximum</dd>

				<dt class="col-sm-4">Rétention</dt>
				<dd class="col-sm-8">{{ limits.retentionDays }} jours pour les jobs terminés</dd>
			</dl>

			<h3 class="h5 mt-4">Dernier passage du worker</h3>
			<p class="mb-0">
				<span v-if="documentation.worker.lastRunAt">
					{{ documentation.worker.lastRunAt }}, source <code>{{ documentation.worker.lastSource }}</code>,
					{{ documentation.worker.lastRan }} job(s), {{ documentation.worker.lastBudgetMs }} ms.
				</span>
				<span v-else>Aucun passage enregistré.</span>
				<span v-if="documentation.worker.stale" class="badge text-bg-warning ms-2">
					inactif depuis plus de cinq minutes
				</span>
			</p>
		</template>
	</section>
</template>

<script>

export default {

	props: {
		active: { type: Boolean, default: true },
	},

	computed: {

		store() { return this.stores.useAdminJobsStore() },

		documentation() { return this.store.documentation ?? {} },

		limits() { return this.documentation.limits ?? {} },

		origin() { return window.location.origin },
	},

	mounted() {
		if (this.active) this.loadDocumentation()
	},

	watch: {
		active(now) { if (now) this.loadDocumentation() },
	},

	methods: {

		loadDocumentation() {
			if (!this.store.documentation) this.store.loadDocumentation()
		},
	},
}
</script>
