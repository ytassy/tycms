<template>
	<div class="mail-detail p-2">
		<spinner v-if="!state || state.loading" />
		<div v-else-if="state.error" class="alert alert-danger mb-0" role="alert">{{ state.error }}</div>

		<template v-else-if="detail">
			<div class="d-flex flex-wrap gap-3 align-items-center mb-2 small">
				<span class="badge" :class="statusClass(detail.status)">{{ statusLabel(detail.status) }}</span>
				<span class="text-muted">{{ date(detail.created_at) }}</span>
				<span><strong>De :</strong> {{ fromLabel }}</span>
				<span><strong>À :</strong> {{ addressList(detail.to) }}</span>
				<span v-if="detail.cc?.length"><strong>Cc :</strong> {{ addressList(detail.cc) }}</span>
				<span v-if="detail.bcc?.length"><strong>Cci :</strong> {{ addressList(detail.bcc) }}</span>
			</div>

			<iframe
				v-if="frameHtml"
				:key="`mail-${detail.id}`"
				class="mail-preview"
				:srcdoc="frameHtml"
				sandbox=""
				referrerpolicy="no-referrer"
				title="Aperçu de l'e-mail"
			></iframe>
			<pre v-else-if="detail.body_text" class="mail-text">{{ detail.body_text }}</pre>
			<p v-else class="text-muted mb-0">Aucun contenu HTML ni texte n'a été enregistré.</p>

			<div v-if="detail.error_code || detail.status === 'failed'" class="alert alert-danger mt-2 mb-0" role="alert">
				<strong>{{ detail.error_code || 'Échec d’envoi' }}</strong>
				<span v-if="detail.error_class" class="small text-muted"> · {{ detail.error_class }}</span>
				<pre v-if="detail.error_message" class="mt-1 mb-0">{{ detail.error_message }}</pre>
			</div>
			<div v-else-if="detail.status === 'processing'" class="alert alert-warning mt-2 mb-0 py-2">
				Envoi en cours, ou interrompu avant la mise à jour du statut. Aucune erreur n'a été enregistrée.
			</div>
			<div v-else class="alert alert-success mt-2 mb-0 py-2">
				Aucune erreur d'envoi enregistrée.
			</div>

			<details class="mt-2 small">
				<summary>Métadonnées et pièces jointes</summary>
				<dl class="row mb-0 mt-2">
					<dt class="col-sm-3">Objet</dt>
					<dd class="col-sm-9">{{ detail.subject }}</dd>
					<dt class="col-sm-3">Modèle</dt>
					<dd class="col-sm-9">
						<code v-if="detail.template_key">{{ detail.template_key }}</code>
						<span v-else>—</span>
						<span v-if="detail.locale" class="text-muted"> ({{ detail.locale }})</span>
					</dd>
					<dt class="col-sm-3">Space</dt>
					<dd class="col-sm-9">{{ spaceLabel }}</dd>
					<dt class="col-sm-3">Message-ID</dt>
					<dd class="col-sm-9 text-break">{{ detail.message_id || '—' }}</dd>
					<dt class="col-sm-3">Durée</dt>
					<dd class="col-sm-9">{{ detail.duration_ms === null ? '—' : `${detail.duration_ms} ms` }}</dd>
					<dt class="col-sm-3">Terminé</dt>
					<dd class="col-sm-9">{{ detail.finished_at ? date(detail.finished_at) : '—' }}</dd>
					<dt class="col-sm-3">Job / utilisateur</dt>
					<dd class="col-sm-9">{{ detail.job_id ?? '—' }} / {{ detail.user_id ?? '—' }}</dd>
					<dt class="col-sm-3">Pièces jointes</dt>
					<dd class="col-sm-9">
						<span v-if="!detail.attachments?.length">—</span>
						<span v-for="attachment in detail.attachments" :key="attachment.name" class="d-block">
							{{ attachment.name }}
							<span v-if="attachment.size !== null" class="text-muted">({{ size(attachment.size) }})</span>
						</span>
					</dd>
				</dl>
			</details>
		</template>
	</div>
</template>

<script>
export default {
	props: {
		id: { type: Number, required: true },
	},

	computed: {
		store() { return this.stores.useAdminMailLogsStore() },
		state() { return this.store.details[this.id] },
		detail() { return this.state?.item ?? null },

		fromLabel() {
			if (!this.detail) return '—'
			if (!this.detail.from_name) return this.detail.from_email || '—'
			return `${this.detail.from_name} <${this.detail.from_email}>`
		},

		frameHtml() {
			const html = this.detail?.body_html ?? ''
			if (html === '') return ''

			// Le sandbox bloque déjà les scripts. La CSP limite en plus les
			// ressources externes et autorise uniquement les images.
			const csp = "default-src 'none'; img-src http: https: data:; style-src 'unsafe-inline'; font-src data:;"
			const meta = `<meta http-equiv="Content-Security-Policy" content="${csp}">`

			if (/<head(\s[^>]*)?>/i.test(html)) {
				return html.replace(/<head(\s[^>]*)?>/i, (match) => `${match}${meta}`)
			}
			if (/<html(\s[^>]*)?>/i.test(html)) {
				return html.replace(/<html(\s[^>]*)?>/i, (match) => `${match}<head>${meta}</head>`)
			}

			return `<!doctype html><html><head><meta charset="utf-8">${meta}</head><body>${html}</body></html>`
		},

		spaceLabel() {
			if (!this.detail) return '—'
			return this.detail.space_title
				|| (this.detail.space_id ? `Espace #${this.detail.space_id}` : '—')
		},
	},

	mounted() {
		this.load()
	},

	watch: {
		id() {
			this.load()
		},
	},

	methods: {
		async load() {
			try {
				await this.store.loadDetail(this.id)
			} catch (_) {
				// Le message est déjà posé dans l'état du détail.
			}
		},

		date(value) {
			return new Date(`${value.replace(' ', 'T')}Z`).toLocaleString('fr-FR')
		},

		addressList(addresses) {
			if (!addresses?.length) return '—'
			return addresses
				.map((address) => address.name ? `${address.name} <${address.email}>` : address.email)
				.join(', ')
		},

		size(bytes) {
			if (bytes === null) return ''
			if (bytes < 1024) return `${bytes} o`
			if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} Ko`
			return `${(bytes / (1024 * 1024)).toFixed(1)} Mo`
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
	},
}
</script>

<style scoped>
.mail-detail {
	background: var(--bs-body-bg);
}

.mail-preview {
	width: 100%;
	height: 600px;
	border: 1px solid var(--bs-border-color);
	background: #fff;
}

.mail-text {
	max-height: 600px;
	overflow: auto;
	white-space: pre-wrap;
	overflow-wrap: anywhere;
	background: var(--bs-tertiary-bg);
	padding: 0.75rem;
	border-radius: 0.25rem;
}

.mail-detail pre {
	white-space: pre-wrap;
	overflow-wrap: anywhere;
}
</style>
