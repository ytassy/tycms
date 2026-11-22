<template>
	<modal :visible="visible" width="800px" :large-body="true" :closable="true" :z-index="1400" @close="$emit('close')">
		<template #header>Choisir {{ directoryOnly ? 'un dossier' : mediaType === 'image' ? 'une image' : mediaType === 'video' ? 'une vidéo' : mediaType === 'audio' ? 'un fichier audio' : mediaType === 'hls' ? 'une playlist HLS' : 'un média' }}</template>
		<template #body>
			<div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
			<div v-if="loading" class="py-4 text-center"><spinner /></div>
			<template v-else-if="!error && listing">
				<nav class="d-flex align-items-center flex-wrap gap-1 mb-3" aria-label="Dossier média">
					<button type="button" class="btn btn-link btn-sm" @click="navigate('')">Médias</button>
					<template v-for="part in breadcrumbs" :key="part.path">
						<span>/</span><button type="button" class="btn btn-link btn-sm" @click="navigate(part.path)">{{ part.name }}</button>
					</template>
				</nav>
				<div class="media-picker__grid">
					<template v-for="entry in listing.entries" :key="entry.path">
						<button v-if="entry.type === 'directory'" type="button" class="media-picker__item" @click="navigate(entry.path)">
							<i class="fa-solid fa-folder fa-2x text-warning" aria-hidden="true" />
							<span :title="entry.name">{{ entry.name }}</span>
						</button>
						<button v-else-if="isSelectable(entry)" type="button" class="media-picker__item" :title="entry.name" @click="choose(entry)">
							<img v-if="entry.mime?.startsWith('image/')" :src="entry.url" :alt="entry.name" loading="lazy" />
							<i v-else class="fa-solid fa-file fa-2x text-secondary" aria-hidden="true" />
							<span class="media-picker__filename fz-80">{{ entry.name }}</span>
						</button>
					</template>
				</div>
				<p v-if="!listing.entries.some(entry => entry.type === 'directory' || isSelectable(entry))" class="text-body-secondary mb-0">{{ directoryOnly ? 'Aucun sous-dossier. Vous pouvez choisir ce dossier.' : 'Aucun fichier correspondant dans ce dossier.' }}</p>
			</template>
		</template>
		<template #footer>
			<button v-if="directoryOnly" type="button" class="btn btn-primary me-2" :disabled="loading || Boolean(error) || !listing" @click="choose({ path: listing.path, type: 'directory' })">Choisir ce dossier</button>
			<button type="button" class="btn btn-outline-secondary" @click="$emit('close')">Fermer</button>
		</template>
	</modal>
</template>

<script>

export default {
	props: {
		visible: { type: Boolean, default: false },
		directoryOnly: { type: Boolean, default: false },
		spaceId: { type: [Number, String], default: null },
		mediaType: { type: String, default: 'all' },
	},
	emits: ['select', 'close'],
	data() { return { loading: false, error: '' } },
	computed: {
		mediaStore() { return this.stores.useAdminMediasStore() },
		listing() { return this.visible ? this.mediaStore.listing : null },
		breadcrumbs() {
			let path = ''
			return (this.listing?.path ?? '').split('/').filter(Boolean).map(name => {
				path = path ? `${path}/${name}` : name
				return { name, path }
			})
		},
	},
	watch: {
		visible(value) { if (value) this.open() },
		spaceId() { if (this.visible) this.open() },
	},
	methods: {
		async open() {
			this.loading = true
			this.error = ''
			this.mediaStore.listing = null
			try {
				if (!this.spaceId) {
					this.error = 'Sélectionnez d’abord un espace.'
					return
				}
				await this.mediaStore.loadDirectory()
				if (!this.mediaStore.directory?.spaces?.some(space => Number(space.id) === Number(this.spaceId))) {
					this.error = 'Vous ne disposez pas des droits pour consulter les médias de cet espace.'
					return
				}
				await this.mediaStore.list(this.spaceId, '')
			} catch (_) {
				this.error = 'Impossible de charger les médias de cet espace.'
			} finally {
				this.loading = false
			}
		},
		async navigate(path) {
			if (this.loading) return
			this.loading = true
			this.error = ''
			try { await this.mediaStore.list(this.spaceId, path) }
			catch (_) { this.error = 'Impossible de charger ce dossier.' }
			finally { this.loading = false }
		},
		isSelectable(entry) {
			if (this.directoryOnly || entry.type !== 'file') return false
			// Les playlists ne sont pas toujours reconnues comme video/* par le serveur.
			if (this.mediaType === 'hls') return Boolean(this.listing?.path)
				&& /^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,149}\.m3u8$/.test(entry.name)
			return this.mediaType === 'all' || ['image', 'video', 'audio'].includes(this.mediaType) && entry.mime?.startsWith(`${this.mediaType}/`)
		},
		choose(entry) {
			this.$emit('select', entry)
			this.$emit('close')
		},
	},
}
</script>

<style scoped>
.media-picker__grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(125px, 1fr)); gap: .75rem; }
.media-picker__item { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .5rem; min-height: 110px; padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .375rem; background: var(--bs-body-bg); color: var(--bs-body-color); }
.media-picker__item:hover, .media-picker__item:focus-visible { border-color: var(--bs-primary); background: var(--bs-secondary-bg); }
.media-picker__item img { max-width: 100%; max-height: 100px; object-fit: contain; }
.media-picker__item span { max-width: 100%; white-space: normal; overflow-wrap: anywhere; word-break: break-all; }
.media-picker__filename { width: 100%; text-align: left; }
</style>
