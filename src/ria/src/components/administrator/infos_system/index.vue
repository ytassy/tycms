<template>
	<section>
		<h1>Système</h1>

		<div v-if="error" class="alert alert-danger mt-3" role="alert">{{ error }}</div>

		<tabs>
			<tab label="Mises à jour" icon="fa-solid fa-arrows-rotate">
				<system_updates />
			</tab>
			<tab label="Routes" icon="fa-solid fa-location-arrow">
				<spinner v-if="processing" />
				<routes v-else-if="data" :data="data.routes"></routes>
			</tab>
			<tab label="PHP" icon="fa-brands fa-php">
				<spinner v-if="processing" />
				<php v-else-if="data" :data="data.php"></php>
			</tab>
		</tabs>
	</section>
</template>

<script>

import php from './php.vue'
import routes from './routes.vue'
import system_updates from '../system_updates/index.vue'

export default {

	components: { php, routes, system_updates },

	data() {
		return {
			error: null,
			processing: true,
			data: null,
		}
	},

	async mounted() {

		// Les onglets Routes et PHP dépendent de cette lecture ; les mises à jour
		// chargent leurs propres données dans leur store.
		try {
			const response = await this.api.get('/administrator/api/infos_system')
			this.data = response;
		} catch (exception) {
			this.error = exception instanceof Error ? exception.message : 'Une erreur inconnue est survenue.'
		} finally {
			this.processing = false
		}
	},

	methods: {

	},
}
</script>
