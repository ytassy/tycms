<template>
	<div class="card mt-3">
		<div class="card-body">
			<h3 class="h5">Tester l’e-mail {{ mode === 'default' ? 'avec cadre' : 'simple' }}</h3>
			<label :for="inputId" class="form-label fw-semibold">Destinataire du mail de test</label>
			<div class="d-flex flex-wrap gap-2 align-items-start">
				<input :id="inputId" v-model.trim="recipient" type="email" autocomplete="email" class="form-control flex-grow-1"
					placeholder="destinataire@exemple.fr" :disabled="saving || sending" />
				<button type="button" class="btn btn-outline-primary flex-shrink-0" :disabled="saving || sending || !recipient || !mainHost" @click="sendTest">
					<span v-if="sending" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />
					Tester l’envoi de mail
				</button>
			</div>
			<p class="form-text mb-0">Utilise les réglages saisis sans les enregistrer et le SMTP de l’espace.</p>
			<div v-if="success" class="alert alert-success mt-2 mb-0" role="status">Le serveur SMTP a accepté le mail de test.</div>
			<div v-if="error" class="alert alert-warning mt-2 mb-0" role="alert">{{ error }}</div>
		</div>
	</div>
</template>

<script>
export default {
	props: {
		mode: { type: String, required: true },
		spaceId: { type: Number, required: true },
		senderEmail: { type: String, default: '' },
		mainHost: { type: String, default: '' },
		definition: { type: Object, required: true },
		draftParams: { type: Object, required: true },
		saving: { type: Boolean, default: false },
	},
	data() {
		return { recipient: this.senderEmail, sending: false, success: false, error: '' }
	},
	computed: {
		inputId() { return `template-mail-test-${this.spaceId}-${this.mode}` },
	},
	watch: {
		senderEmail(value) { this.recipient = value },
		recipient() { this.success = false; this.error = '' },
	},
	methods: {
		submittedValues() {
			const params = {}
			for (const field of this.definition.sections?.flatMap(section => section.fields ?? []) ?? []) {
				if (!field.name || field.submittable === false || !Object.prototype.hasOwnProperty.call(this.draftParams, field.name)) continue
				params[field.name] = this.draftParams[field.name]
			}
			return params
		},
		async sendTest() {
			if (this.saving || this.sending || !this.recipient || !this.mainHost) return
			this.sending = true
			this.success = false
			this.error = ''
			try {
				// L’appel appartient à la Template : le store commun ne connaît pas
				// les endpoints propres à une extension.
				const response = await this.api.post('/administrator/api/templates/main/mail/test', {
					spaceId: this.spaceId, recipient: this.recipient, mode: this.mode,
					params: this.submittedValues(),
				})
				this.success = response.sent === true
				if (!this.success) this.error = 'L’envoi d’e-mails est désactivé pour cet espace.'
			} catch (_) {
				this.error = 'Le mail de test n’a pas pu être envoyé.'
			} finally {
				this.sending = false
			}
		},
	},
}
</script>
