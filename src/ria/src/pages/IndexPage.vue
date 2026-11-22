<template>
	
	<div class="card shadow-sm mx-left" style="max-width: 550px">
		<div class="card-header bg-primary text-white">
			<h1 class="h5 mb-0">Exemple</h1>
		</div>

		<div class="card-body">
			<p>Composant vierge, prêt à accueillir votre application Vue.</p>

			<button type="button" class="btn btn-primary" :disabled="processing" @click="ping">
				Ping API
			</button>

			<div v-if="processing || error || pong" class="mt-3">
				<spinner v-if="processing" />
				<div v-if="error" class="alert alert-danger mt-3 mb-0" role="alert">{{ error }}</div>
				<div v-if="pong" class="alert alert-success mt-3 mb-0" role="status">{{ pong }}</div>
			</div>
		</div>
	</div>
</template>

<script>

export default {

	data() {
		return {
			pong: null,
			error: null,
			processing: false,
		}
	},

	methods: {
		async ping() {
			if (this.processing || !await this.stores.useAppStore().askYesNo('Sûr ?')) return

			this.error = null
			this.pong = null
			this.processing = true

			try {
				const response = await this.api.get('/api/ping')
				this.pong = response.message ?? 'Le serveur a répondu.'
			} catch (exception) {
				this.error = exception instanceof Error ? exception.message : 'Une erreur inconnue est survenue.'
			} finally {
				this.processing = false
			}
		},
	},
}
</script>
