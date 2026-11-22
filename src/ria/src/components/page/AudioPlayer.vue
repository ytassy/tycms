<template>
	<div class="page-audio" :style="playerStyle" role="group" aria-label="Lecteur audio">
		<audio v-if="safeSrc" :key="safeSrc" ref="audio" :src="safeSrc" preload="metadata"
			@loadedmetadata="syncTime" @durationchange="syncTime" @timeupdate="syncTime"
			@play="playing = true" @pause="playing = false" @ended="playing = false"
			@waiting="waiting = true" @playing="waiting = false" @canplay="waiting = false"
			@volumechange="syncVolume" @error="fail" />
		<div class="page-audio__controls">
			<button type="button" class="page-audio__button page-audio__play" :disabled="!safeSrc || !!error || pending"
				:aria-label="playing ? 'Mettre en pause' : 'Lire le fichier audio'" :title="playing ? 'Pause' : 'Lecture'" @click="togglePlay">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path :d="playing ? 'M6 4h4v16H6zM14 4h4v16h-4z' : 'M7 3v18l16-9z'" /></svg>
			</button>
			<input type="range" class="page-audio__progress" min="0" :max="duration || 1" step="0.1" :value="currentTime"
				:style="{ '--page-audio-progress': `${duration ? currentTime / duration * 100 : 0}%` }"
				:disabled="!duration || !!error" aria-label="Position dans le fichier audio"
				:aria-valuetext="`${audioTime(currentTime)} sur ${audioTime(duration)}`" @input="seek">
			<span class="page-audio__time" aria-hidden="true">{{ audioTime(currentTime) }} / {{ audioTime(duration) }}</span>
			<button type="button" class="page-audio__button" :disabled="!safeSrc" :aria-label="silent ? 'Rétablir le son' : 'Couper le son'"
				:aria-pressed="silent" :title="silent ? 'Rétablir le son' : 'Couper le son'" @click="toggleMute">
				<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
					<path d="M2 9v6h4l6 5V4L6 9z" />
					<path v-if="silent" d="m16 9 6 6m0-6-6 6" class="page-audio__sound" />
					<path v-else d="M16 8c3 2 3 6 0 8m3-11c5 4 5 10 0 14" class="page-audio__sound" />
				</svg>
			</button>
			<input type="range" class="page-audio__volume" min="0" max="1" step="0.01" :value="silent ? 0 : volume"
				:style="{ '--page-audio-progress': `${(silent ? 0 : volume) * 100}%` }"
				:disabled="!safeSrc" aria-label="Volume" :aria-valuetext="`${Math.round((silent ? 0 : volume) * 100)} %`" @input="setVolume">
		</div>
		<p v-if="error || pending || waiting" class="page-audio__status" role="status">{{ error || 'Chargement du fichier audio…' }}</p>
	</div>
</template>

<script>
import { audioMediaUrl, audioPlayerStyle, audioTime } from '../features/audio/audioPlayer.js'

export default {
	props: { src: { type: String, default: '' }, audio_style: { type: Object, default: () => ({}) } },
	data: () => ({ playing: false, pending: false, waiting: false, error: '', currentTime: 0, duration: 0, volume: 1, muted: false, generation: 0 }),
	computed: {
		safeSrc() { return audioMediaUrl(this.src) },
		playerStyle() { return audioPlayerStyle(this.audio_style) },
		silent() { return this.muted || this.volume === 0 },
	},
	watch: {
		safeSrc() {
			// Une ancienne promesse de lecture ne doit pas modifier le nouveau fichier.
			this.generation++
			this.$refs.audio?.pause()
			Object.assign(this, { playing: false, pending: false, waiting: false, error: '', currentTime: 0, duration: 0, volume: 1, muted: false })
		},
	},
	beforeUnmount() {
		this.generation++
		const audio = this.$refs.audio
		if (!audio) return
		audio.pause()
		audio.removeAttribute('src')
		audio.load()
	},
	methods: {
		audioTime,
		async togglePlay() {
			const audio = this.$refs.audio
			if (!audio || this.pending) return
			if (!audio.paused) { audio.pause(); return }

			const generation = this.generation
			this.pending = true
			try { await audio.play() }
			catch (error) {
				if (generation === this.generation && error.name !== 'AbortError') this.fail()
			}
			finally { if (generation === this.generation) this.pending = false }
		},
		syncTime() {
			const audio = this.$refs.audio
			if (!audio) return
			this.duration = Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0
			this.currentTime = Math.min(this.duration, Math.max(0, audio.currentTime || 0))
		},
		seek(event) {
			if (this.duration && this.$refs.audio) this.$refs.audio.currentTime = Math.min(this.duration, Math.max(0, Number(event.target.value)))
		},
		syncVolume() {
			if (!this.$refs.audio) return
			this.volume = this.$refs.audio.volume
			this.muted = this.$refs.audio.muted
		},
		toggleMute() {
			const audio = this.$refs.audio
			if (!audio) return
			if (this.silent) { audio.muted = false; if (audio.volume === 0) audio.volume = 1 }
			else audio.muted = true
			this.syncVolume()
		},
		setVolume(event) {
			if (!this.$refs.audio) return
			this.$refs.audio.volume = Math.min(1, Math.max(0, Number(event.target.value)))
			this.$refs.audio.muted = false
			this.syncVolume()
		},
		fail() {
			this.error = 'Impossible de lire ce fichier audio. Vérifiez le fichier et sa compatibilité avec votre navigateur.'
			this.playing = this.pending = this.waiting = false
		},
	},
}
</script>

<style scoped>
.page-audio { width: 100%; min-width: 0; container-type: inline-size; color: var(--page-audio-color, #212529); background: var(--page-audio-background-color, #f1f3f5); border-radius: var(--page-audio-border-radius, 8px); }
.page-audio__controls { display: flex; align-items: center; gap: 10px; min-height: var(--page-audio-height, 56px); padding: 6px 12px; box-sizing: border-box; }
.page-audio__button { display: inline-flex; flex: 0 0 32px; align-items: center; justify-content: center; width: 32px; height: 32px; padding: 5px; border: 0; border-radius: 50%; background: transparent; color: inherit; cursor: pointer; }
.page-audio__play { color: var(--page-audio-accent-color, #0d6efd); }
.page-audio__button svg { width: 22px; height: 22px; fill: currentColor; }
.page-audio__sound { fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; }
.page-audio__button:disabled { opacity: .5; cursor: default; }
.page-audio__button:focus-visible, .page-audio input:focus-visible { outline: 2px solid currentColor; outline-offset: 3px; }
.page-audio input[type="range"] { appearance: none; height: 24px; margin: 0; border: 0; background: linear-gradient(to right, var(--page-audio-accent-color, #0d6efd) var(--page-audio-progress, 0%), var(--page-audio-track-color, #ced4da) var(--page-audio-progress, 0%)) center / 100% 6px no-repeat; cursor: pointer; }
.page-audio input[type="range"]::-webkit-slider-thumb { appearance: none; width: 14px; height: 14px; border: 0; border-radius: 50%; background: var(--page-audio-accent-color, #0d6efd); }
.page-audio input[type="range"]::-moz-range-thumb { width: 14px; height: 14px; border: 0; border-radius: 50%; background: var(--page-audio-accent-color, #0d6efd); }
.page-audio input:disabled { opacity: .5; cursor: default; }
.page-audio__progress { flex: 1 1 60px; min-width: 0; width: 100%; }
.page-audio__volume { flex: 0 0 64px; width: 64px; }
.page-audio__time { flex: 0 0 auto; font-size: 12px; font-variant-numeric: tabular-nums; white-space: nowrap; }
.page-audio__status { margin: 0; padding: 0 12px 10px; font-size: .8rem; overflow-wrap: anywhere; }
@container (max-width: 380px) { .page-audio__volume { display: none; } .page-audio__controls { gap: 6px; padding-inline: 8px; } }
@container (max-width: 240px) { .page-audio__time { display: none; } }
</style>
