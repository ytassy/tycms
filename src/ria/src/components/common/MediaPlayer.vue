<template>
	<div class="d-print-none" :style="customStyle">
		<video ref="video" class="video-js vjs-default-skin vjs-big-play-centered vjs-fluid" controls preload="auto">
			<source :src="mediaUrl" type="application/x-mpegURL">
			Votre navigateur ne prend pas en charge la lecture vidéo.
		</video>
	</div>
</template>

<script>
export default {
	props: {
		mediaUrl: { type: String, required: true },
		audioOnly: { type: Boolean, default: false },
		customSize: { type: Number, default: null },
	},

	data() {
		return { player: null, xhrConfigured: false }
	},

	computed: {
		customStyle() {
			return this.customSize !== null && this.customSize >= 10
				? { '--tyh': `${Math.round(this.customSize / 3.2)}px` }
				: {}
		},
	},

	mounted() {
		if (this.$refs.video === null) return
		const videojs = window.videojs
		if (typeof videojs !== 'function') return

		this.player = videojs(this.$refs.video, {
			fluid: true,
			responsive: true,
			audioOnlyMode: this.audioOnly,
			html5: {
				vhs: { overrideNative: true },
				nativeVideoTracks: false,
				nativeAudioTracks: false,
				nativeTextTracks: false,
			},
		})

		this.player.ready(() => {
			this.configureXhrHeaders()
			this.player.one('loadstart', this.configureXhrHeaders)
		})
	},

	watch: {
		mediaUrl(mediaUrl) {
			this.player?.src({ src: mediaUrl, type: 'application/x-mpegURL' })
		},
	},

	beforeUnmount() {
		this.player?.dispose()
	},

	methods: {
		configureXhrHeaders() {
			if (this.xhrConfigured || this.player === null) return

			const vhs = this.player.tech(true)?.vhs
			if (vhs?.xhr === undefined) return

			vhs.xhr.onRequest((options) => {
				options.headers ??= {}
				if (options.uri !== undefined) options.headers['X-TYLMS'] = options.uri
				return options
			})

			vhs.xhr.onResponse((xhr) => {
				try {
					if (xhr.status !== 200 || !xhr.url?.includes('task=video.get') || !xhr.url.includes('video.key')) return
					const rawData = xhr.body ?? xhr.response
					if (rawData === undefined) return

					let base64 = String.fromCharCode(...new Uint8Array(rawData)).replace(/[^A-Za-z0-9+/=]/g, '').trim()
					if (!base64.startsWith('==B64')) return

					base64 = base64.slice(6)
					while (base64.length % 4 !== 0) base64 += '='
					const binary = atob(base64)
					const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0))
					Object.defineProperty(xhr, 'response', { get: () => bytes.buffer })
				} catch {
					// A malformed or unencrypted key must remain handled by Video.js.
				}
			})

			this.xhrConfigured = true
		},
	},
}
</script>
