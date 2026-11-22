<template>
	<div v-if="options.source === 'bunny'" class="page-canvas-placeholder border rounded p-4 text-center">
		<i class="fa-solid fa-circle-play fs-2 d-block mb-2" aria-hidden="true"></i>
		<strong>Vidéo Bunny</strong>
		<div class="small">{{ options.library_id || 'Bibliothèque à définir' }} / {{ options.video_id || 'Vidéo à définir' }}</div>
		<small>Lecteur disponible dans le rendu fidèle</small>
	</div>
	<SecureVideo v-else-if="options.source === 'hls' && options.src && spaceId" :src="options.src" :space-id="spaceId" />
	<video v-else-if="mediaUrl" :src="mediaUrl" controls playsinline preload="metadata" style="display:block;width:100%;max-width:100%"></video>
	<div v-else-if="embedUrl" class="ratio ratio-16x9">
		<iframe :src="embedUrl" title="Aperçu de la vidéo" loading="lazy" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" allow="fullscreen; picture-in-picture; encrypted-media"></iframe>
	</div>
	<div v-else class="page-canvas-placeholder">Vidéo : {{ options.source === 'url' ? 'saisissez une URL YouTube ou Vimeo valide' : options.source === 'hls' ? 'choisissez une playlist HLS ou saisissez un alias' : 'choisissez un fichier dans les médias du Space' }}.</div>
</template>
<script>
import { videoEmbedUrl, videoMediaUrl } from '../../features/video/videoSources.js'
import SecureVideo from '../../common/SecureVideo.vue'

export default {
	components: { SecureVideo },
	props: { options: { type: Object, required: true }, spaceId: { type: [Number, String], default: null } },
	computed: {
		mediaUrl() { return (this.options.source ?? 'media') === 'media' ? videoMediaUrl(this.options.src) : '' },
		embedUrl() { return this.options.source === 'url' ? videoEmbedUrl(this.options.src) : '' },
	},
}
</script>
