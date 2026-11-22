<template>
	<div class="secure-video">
		<video ref="video" class="video-js vjs-default-skin vjs-big-play-centered" controls playsinline preload="metadata">
			Votre navigateur ne peut pas lire cette vidéo.
		</video>
		<p v-if="error" class="small text-danger mt-2" role="alert">{{ error }}</p>
	</div>
</template>

<script>
export default {
	name: 'SecureVideo',
	props: {
		src: { type: String, required: true },
		spaceId: { type: [Number, String], required: true },
	},
	data: () => ({ player: null, error: '', generation: 0 }),
	watch: {
		src() { this.load() },
		spaceId() { this.load() },
	},
	mounted() { this.load() },
	beforeUnmount() {
		this.generation++
		this.player?.dispose()
		this.player = null
	},
	methods: {
		load() {
			if (!this.$refs.video) return
			const generation = ++this.generation
			this.error = ''
			if (!this.src || !/^\d+$/.test(String(this.spaceId))) {
				this.player?.reset()
				return
			}

			try {
				if (!this.player) {
					const videojs = window.videojs
					if (typeof videojs !== 'function') throw new Error('Video.js indisponible')
					if (generation !== this.generation || !this.$refs.video?.isConnected) return

					// Le hook rétablit la clé AES-128 depuis le transport historique ==B64.
					this.player = videojs(this.$refs.video, {
						fluid: true, responsive: true,
						html5: { vhs: { overrideNative: true }, nativeVideoTracks: false,
							nativeAudioTracks: false, nativeTextTracks: false },
					})
					this.installKeyDecoder(this.player)
					this.player.on('segmenttransmuxingcomplete', () => {
						// Ce service accepte uniquement HLS AES-128, sans DRM EME. Si VHS
						// laisse son initialisation ou sa file en attente, l'ajout se bloque.
						const player = this.player
						const vhs = player?.tech(true)?.vhs
						window.setTimeout(() => {
							if (this.player !== player || player?.tech(true)?.vhs !== vhs) return
							const controller = vhs?.playlistController_
							const updater = controller?.sourceUpdater_
							const loader = controller?.mainSegmentLoader_
							if (updater?.hasCreatedSourceBuffers() && !updater.hasInitializedAnyEme()) {
								updater.initializedEme()
							}
							if (loader?.hasEnoughInfoToAppend_() && loader.callQueue_?.length) {
								loader.processCallQueue_()
							}
						}, 0)
					})
					this.player.on('playing', () => { this.error = '' })
					this.player.on('error', () => { this.error = 'La lecture de cette vidéo a échoué.' })
				}
				const query = new URLSearchParams({ spaceId: String(this.spaceId), source: this.src, file: 'playlist.m3u8' })
				const player = this.player
				player.ready(() => {
					if (generation === this.generation && this.player === player) {
						player.src({ src: `/secure-video/stream?${query}`, type: 'application/x-mpegURL' })
					}
				})
			} catch {
				if (generation === this.generation) this.error = 'Le lecteur vidéo est indisponible.'
			}
		},
		installKeyDecoder(player) {
			// Chaque changement de source recrée VHS et son objet XHR.
			player.on('xhr-hooks-ready', () => {
				// Video.js accepte un argument pour signaler que l'accès interne est volontaire.
				const xhr = player.tech(true)?.vhs?.xhr
				if (!xhr) return

				xhr.onResponse((request, error) => {
					if (error || !(request?.uri || request?.url)) return
					let url
					try { url = new URL(request.uri || request.url, window.location.href) } catch { return }
					if (url.origin !== window.location.origin || url.pathname !== '/secure-video/stream'
						|| url.searchParams.get('file') !== 'video.key') return

					const raw = request.response
					if (!(raw instanceof ArrayBuffer)) {
						this.error = 'La réponse de la clé HLS est illisible.'
						return
					}
					const encoded = String.fromCharCode(...new Uint8Array(raw))
					if (!/^==B64.[A-Za-z0-9+/]+={0,2}$/.test(encoded)) {
						this.error = 'Le format de la clé HLS est invalide.'
						return
					}
					const decoded = atob(encoded.slice(6))
					if (decoded.length !== 16) {
						this.error = 'La longueur de la clé HLS est invalide.'
						return
					}

					const key = Uint8Array.from(decoded, char => char.charCodeAt(0))
					Object.defineProperty(request, 'response', { configurable: true, get: () => key.buffer, set: () => {} })
				})
			})
		},
	},
}
</script>

<style scoped>
.secure-video { width: 100%; max-width: 100%; }
.secure-video :deep(.video-js) { width: 100%; max-width: 100%; }
</style>
