<template>
	<button v-if="enabled" type="button" :class="classes" :disabled="loading" :aria-busy="loading" @click="run">
		<span v-if="loading" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
		{{ label }}
	</button>
	<span v-else :class="classes + ' disabled'" aria-disabled="true">{{ label }}</span>
	<common_app />
</template>

<script>
export default {
	props: {
		label: { type: String, default: 'Bouton' },
		classes: { type: String, default: 'btn btn-primary' },
		enabled: { type: Boolean, default: false },
		endpoint: { type: String, default: '/page/button' },
		pageId: { type: Number, required: true },
		nodeId: { type: String, required: true },
		locale: { type: String, default: 'fr' },
		buttonToken: { type: String, default: '' },
		reloadOnClose: { type: Boolean, default: false },
	},
	data: () => ({ loading: false }),
	methods: {
		async run() {
			if (!this.enabled || this.loading) return

			this.loading = true

			try {
				const result = await this.api.post(this.endpoint, {
					page_id: this.pageId,
					node_id: this.nodeId,
					locale: this.locale,
					button_token: this.buttonToken,
				})

				const message = typeof result?.message === 'string' ? result.message : ''
				const reload = result?.reload === true
				const onClose = reload ? () => window.location.reload() : null

				// Un mode sans message peut demander un rechargement immédiat.
				if (message === '') {
					if (reload) window.location.reload()
					return
				}

				switch (result.level) {
					case 'info':
						this.showModaleInfo(message, onClose)
						break
					case 'warning':
						this.showModaleWarning(message, onClose)
						break
					case 'danger':
						this.showModaleDanger(message, onClose)
						break
					case 'error':
						this.showModaleError(message, onClose)
						break
					default:
						this.showModaleConfirm(message, onClose)
				}
			} catch (error) {
				// L'API affiche déjà la modale pour les réponses en erreur.
				if (!error?.response?.data?.message) {
					this.showModaleError('L’action n’a pas pu être exécutée. Réessayez plus tard.')
				}
			} finally {
				this.loading = false
			}
		},
	},
}
</script>
