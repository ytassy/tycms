<template>
	<modal :visible="true" full-screen closable @close="$emit('close')">
		<template #header><i :class="icon" class="me-2" />{{ entry.name }}</template>
		<template #body>
			<div class="media-preview">
				<div v-if="mediaError" class="media-preview__unavailable text-muted"><i class="fa-solid fa-file fa-3x" /><span>Votre navigateur ne peut pas prévisualiser ce fichier.</span></div>
				<img v-else-if="kind === 'image'" class="media-preview__image" :src="entry.url" :alt="entry.name" @error="mediaError = true">
				<iframe v-else-if="kind === 'pdf'" class="media-preview__frame" :src="entry.url" :title="`Aperçu de ${entry.name}`" @error="mediaError = true" />
				<video v-else-if="kind === 'video'" class="media-preview__video" :src="entry.url" controls preload="metadata" @error="mediaError = true">Votre navigateur ne peut pas lire cette vidéo.</video>
				<audio v-else-if="kind === 'audio'" class="media-preview__audio" :src="entry.url" controls preload="metadata" @error="mediaError = true">Votre navigateur ne peut pas lire cet audio.</audio>
				<template v-else-if="kind === 'text'">
					<div v-if="loading" class="text-muted">Chargement de l’aperçu…</div>
					<pre v-else-if="textContent !== null" class="media-preview__text">{{ textContent }}</pre>
					<div v-else class="media-preview__unavailable text-muted">{{ previewError }}</div>
				</template>
				<div v-else class="media-preview__unavailable text-muted"><i class="fa-solid fa-file fa-3x" /><span>Ce type de fichier ne peut pas être prévisualisé ici.</span></div>
			</div>
		</template>
		<template #footer>
			<a :href="entry.url" target="_blank" rel="noopener" class="btn btn-outline-primary me-2">Ouvrir dans un nouvel onglet</a>
			<a :href="entry.url" :download="entry.name" class="btn btn-outline-primary me-2">Télécharger</a>
			<button type="button" class="btn btn-primary esc" @click="$emit('close')">Fermer</button>
		</template>
	</modal>
</template>

<script>

const imageExtensions = new Set(['apng', 'avif', 'bmp', 'gif', 'heic', 'heif', 'ico', 'jfif', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp'])
const audioExtensions = new Set(['aac', 'aif', 'aiff', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav'])
const videoExtensions = new Set(['m4v', 'mov', 'mp4', 'ogv', 'webm'])
const textExtensions = new Set(['conf', 'css', 'csv', 'env', 'html', 'ini', 'js', 'json', 'jsx', 'log', 'map', 'md', 'php', 'py', 'scss', 'sh', 'sql', 'srt', 'toml', 'ts', 'txt', 'vue', 'vtt', 'xml', 'yaml', 'yml'])
const textMimeTypes = new Set(['application/javascript', 'application/json', 'application/ld+json', 'application/toml', 'application/x-javascript', 'application/x-ndjson', 'application/x-sh', 'application/x-yaml', 'application/xml', 'application/yaml'])
const maxTextPreviewSize = 1024 * 1024

export default {
	props: { entry: { type: Object, required: true } },
	emits: ['close'],
	data() { return { loading: false, textContent: null, previewError: '', mediaError: false, abortController: null } },
	computed: {
		kind() {
			const extension = this.entry.extension?.toLowerCase() ?? ''
			const mime = this.entry.mime?.toLowerCase() ?? ''
			if (imageExtensions.has(extension) || mime.startsWith('image/')) return 'image'
			if (extension === 'pdf' || mime === 'application/pdf') return 'pdf'
			if (videoExtensions.has(extension) || mime.startsWith('video/')) return 'video'
			if (audioExtensions.has(extension) || mime.startsWith('audio/')) return 'audio'
			if (textExtensions.has(extension) || mime.startsWith('text/') || textMimeTypes.has(mime) || mime.endsWith('+json') || mime.endsWith('+xml')) return 'text'
			return 'other'
		},
		icon() {
			if (this.kind === 'image') return 'fa-solid fa-image'
			if (this.kind === 'pdf') return 'fa-solid fa-file-pdf'
			if (this.kind === 'video') return 'fa-solid fa-file-video'
			if (this.kind === 'audio') return 'fa-solid fa-file-audio'
			return 'fa-solid fa-file'
		},
	},
	mounted() { if (this.kind === 'text') this.loadText() },
	beforeUnmount() { this.abortController?.abort() },
	methods: {
		async loadText() {
			if (this.entry.size > maxTextPreviewSize) {
				this.previewError = 'Ce fichier est trop volumineux pour un aperçu texte.'
				return
			}
			this.loading = true
			this.abortController = new AbortController()
			try {
				const response = await fetch(this.entry.url, { signal: this.abortController.signal })
				if (!response.ok) throw new Error('Aperçu indisponible')
				this.textContent = await response.text()
			} catch (error) {
				if (error.name !== 'AbortError') this.previewError = 'Impossible de charger l’aperçu de ce fichier.'
			} finally { this.loading = false }
		},
	},
}
</script>

<style scoped>
.media-preview { min-height: 50vh; display: grid; place-items: center; }
.media-preview__image, .media-preview__video { max-width: 100%; max-height: calc(100vh - 15rem); object-fit: contain; }
.media-preview__frame { display: block; width: 100%; height: calc(100vh - 15rem); min-height: 380px; border: 0; background: #fff; }
.media-preview__audio { width: min(100%, 650px); }
.media-preview__text { width: 100%; max-height: calc(100vh - 15rem); overflow: auto; padding: 1rem; margin: 0; background: #f8f9fa; white-space: pre-wrap; overflow-wrap: anywhere; }
.media-preview__unavailable { display: grid; justify-items: center; gap: 1rem; text-align: center; }
</style>
