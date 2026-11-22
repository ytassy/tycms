<template>
	<h1>Médias</h1>
	<div v-if="directory" class="media-manager">
		<div v-if="error" class="alert alert-warning">{{ error }}</div>
		<div v-if="copyFallbackUrl" class="alert alert-info d-flex align-items-center gap-2 flex-wrap">
			<label for="media-copy-url" class="mb-0">Copie automatique indisponible. Sélectionnez l’URL :</label>
			<input id="media-copy-url" ref="copyFallbackInput" class="form-control flex-grow-1" type="text" :value="copyFallbackUrl" readonly @focus="$event.target.select()" @click="$event.target.select()">
			<button type="button" class="btn-close esc" aria-label="Fermer" @click="copyFallbackUrl = ''" />
		</div>

		<div class="media-manager__toolbar card card-body mb-3">
			<div class="d-flex align-items-center justify-content-start">
				<label class="d-flex align-items-center gap-2 mb-0">
					<select v-model.number="selectedSpaceId" class="form-select" :disabled="processing" @change="changeSpace">
						<option v-for="space in directory.spaces" :key="space.id" :value="space.id">{{ space.title }}</option>
					</select>
				</label>
				<span v-if="canCrud" class="media-manager__actions">
					<button type="button" class="btn btn-outline-primary btn-sm" :disabled="processing" @click="createFolder"><i class="fa-solid fa-folder-plus" /> Nouveau dossier</button>
					<button v-for="extension in directory.editableExtensions" :key="extension" type="button" class="btn btn-outline-primary btn-sm" :disabled="processing" @click="createTextFile(extension)"><i class="fa-solid fa-file-circle-plus" /> Fichier {{ extension.toUpperCase() }}</button>
					<button type="button" class="btn btn-outline-primary btn-sm" :disabled="processing" @click="createDiagram('png')"><i class="fa-solid fa-diagram-project" /> Diagramme PNG</button>
					<button type="button" class="btn btn-outline-primary btn-sm" :disabled="processing" @click="createDiagram('svg')"><i class="fa-solid fa-diagram-project" /> Diagramme SVG</button>
					<button type="button" class="btn btn-primary btn-sm" :disabled="processing" @click="$refs.fileDnd?.openPicker()"><i class="fa-solid fa-upload" /> Téléverser</button>
				</span>
			</div>
		</div>

		<div v-if="listing" class="media-manager__content">
			<aside class="media-manager__sidebar card">
				<div class="card-header">Dossiers<span v-if="canCrud" class="d-block small text-muted fw-normal fz-70">Glissez un élément ici pour le déplacer.</span></div>
				<div class="media-manager__tree p-2">
					<button type="button" class="media-manager__root" :class="{ 'is-current': listing.path === '', 'is-target': dragPath && validMoveTarget('') && rootOver }" :disabled="processing" @click="navigate('')" @dragenter.prevent="rootOver = true" @dragleave="rootOver = false" @dragover="onRootDragOver" @drop.prevent.stop="dropOnRoot"><i class="fa-solid fa-hard-drive me-2" /> Racine</button>
					<MediaTreeNode v-for="entry in rootFolders" :key="entry.path" :entry="entry" :current-path="listing.path" :expanded-paths="expandedPaths" :drag-path="dragPath" :processing="processing" @navigate="navigate" @toggle="toggleFolder" @move="moveTo" />
				</div>
			</aside>

			<file_dnd ref="fileDnd" tag="section" class="media-manager__browser card" multiple :accepted-extensions="directory.allowedExtensions" :max-size="directory.maxUploadSize" :disabled="!canCrud || processing" :internal-dragging="Boolean(dragPath)" :uploading="uploadProgress !== null" :progress="uploadProgress" @files="uploadFiles">
				<div class="card-header d-flex align-items-center gap-2 flex-wrap">
					<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="listing.parentPath === null || processing" @click="navigate(listing.parentPath)"><i class="fa-solid fa-arrow-left" /><span class="visually-hidden">Retour</span></button>
					<nav class="media-manager__breadcrumb" aria-label="Chemin courant"><button type="button" class="btn btn-link p-0" @click="navigate('')">Médias</button><template v-for="part in breadcrumbs" :key="part.path"><span class="mx-1">/</span><button type="button" class="btn btn-link p-0" @click="navigate(part.path)">{{ part.name }}</button></template></nav>
						<span class="ms-auto text-muted small">{{ listing.entries.length }} élément{{ listing.entries.length > 1 ? 's' : '' }}</span>
						<div class="btn-group btn-group-sm" role="group" aria-label="Mode d’affichage">
							<button type="button" class="btn" :class="viewMode === 'list' ? 'btn-primary' : 'btn-outline-secondary'" :aria-pressed="viewMode === 'list'" title="Liste" @click="viewMode = 'list'"><i class="fa-solid fa-list" /><span class="visually-hidden"> Liste</span></button>
							<button type="button" class="btn" :class="viewMode === 'grid' ? 'btn-primary' : 'btn-outline-secondary'" :aria-pressed="viewMode === 'grid'" title="Carré" @click="viewMode = 'grid'"><i class="fa-solid fa-grip" /><span class="visually-hidden"> Carré</span></button>
						</div>
						<select v-if="viewMode === 'grid'" v-model="gridSize" class="form-select form-select-sm media-manager__size" aria-label="Taille des vignettes"><option value="small">Petites</option><option value="medium">Moyennes</option><option value="large">Grandes</option></select>
				</div>

				<div v-if="listing.entries.length === 0" class="media-manager__empty text-muted"><i class="fa-regular fa-folder-open fa-2x" /><span>Ce dossier est vide.</span></div>
				<div v-else class="media-manager__entries p-3" :class="viewMode === 'list' ? 'media-manager__entries--list' : `media-manager__entries--${gridSize}`">
					<article v-for="entry in listing.entries" :key="entry.path" class="media-card" :class="{ 'media-card--list': viewMode === 'list' }" :draggable="canCrud && !processing" @dragstart="startDrag($event, entry)" @dragend="endDrag" @dblclick="open(entry)">
						<button type="button" class="media-card__main" :title="entry.name" @click="open(entry)">
							<img v-if="isImage(entry)" :src="mediaUrl(entry)" :alt="entry.name" loading="lazy">
							<i v-else-if="entry.type === 'directory'" class="fa-solid fa-folder fa-3x text-warning" />
							<i v-else :class="fileIcon(entry)" class="fa-3x text-secondary" />
						</button>
						<div class="media-card__details"><div class="media-card__name" :title="entry.name">{{ entry.name }}</div><span class="media-card__type small text-muted">{{ entry.type === 'directory' ? 'Dossier' : (entry.extension?.toUpperCase() || 'Fichier') }}</span><span class="media-card__size small text-muted">{{ entry.type === 'file' ? formatSize(entry.size) : '—' }}</span></div>
						<b-dropdown class="media-card__actions" variant="outline-secondary" no-caret>
							<template #button-content><i class="fa-solid fa-ellipsis-vertical" /><span class="visually-hidden">Actions</span></template>
								<li v-if="entry.type === 'file'"><button type="button" class="dropdown-item" @click="preview(entry)">{{ canEditTextFile(entry) ? 'Prévisualiser' : 'Ouvrir' }}</button></li>
								<li v-if="entry.type === 'file'"><text_copy class="d-block" :text-to-copy="entry.url" button-class="dropdown-item" title="Copier l’URL" always-visible @copied="copyFallbackUrl = ''" @copy-failed="showCopyFallback"><template #button>Copier l’URL</template></text_copy></li>
								<li v-if="canEditDiagram(entry)"><button type="button" class="dropdown-item" @click="editDiagram(entry)">Modifier avec diagram.net</button></li>
								<li v-if="canEditTextFile(entry)"><button type="button" class="dropdown-item" @click="editTextFile(entry)">Modifier le fichier</button></li>
								<li v-if="canCrud"><hr class="dropdown-divider"></li>
								<li v-if="canCrud"><button type="button" class="dropdown-item" @click="rename(entry)">Renommer</button></li>
								<li v-if="canCrud"><button type="button" class="dropdown-item text-danger" @click="deleteEntry(entry)">Supprimer</button></li>
						</b-dropdown>
					</article>
				</div>
			</file_dnd>
		</div>

		<MediaPreview v-if="previewTarget" :key="previewTarget.path" :entry="{ ...previewTarget, url: mediaUrl(previewTarget) }" @close="previewTarget = null" />
		<DiagramEditor v-if="diagramTarget" :visible="diagramVisible" :entry="diagramTarget.entry" :name="diagramTarget.name" :format="diagramTarget.format" :saving="savingDiagram" :save-error="diagramSaveError" @close="closeDiagram" @save="saveDiagram" />
		<TextFileEditor v-if="textFileTarget" :key="`${selectedSpaceId}/${textFileTarget.path ?? textFileTarget.name}`" :entry="textFileTarget.entry" :name="textFileTarget.name" :space-id="textFileTarget.spaceId" :folder-path="textFileTarget.folderPath" :max-bytes="directory.maxEditableSize" @close="textFileTarget = null" @saved="onTextFileSaved" />
	</div>
	<div v-else class="py-5 text-center"><spinner /></div>
</template>

<script>
import DiagramEditor from '../../common/DiagramEditor.vue'
import MediaTreeNode from './MediaTreeNode.vue'
import MediaPreview from './MediaPreview.vue'
import TextFileEditor from './TextFileEditor.vue'

const imageExtensions = new Set(['apng', 'avif', 'bmp', 'gif', 'ico', 'jfif', 'jpeg', 'jpg', 'png', 'svg', 'webp'])
const displayPreferencesKey = 'tycms.admin.medias.display'

function loadDisplayPreferences() {
	try {
		const saved = JSON.parse(window.localStorage.getItem(displayPreferencesKey))
		return {
			viewMode: saved?.viewMode === 'list' ? 'list' : 'grid',
			gridSize: ['small', 'medium', 'large'].includes(saved?.gridSize) ? saved.gridSize : 'medium',
		}
	} catch (_) {
		return { viewMode: 'grid', gridSize: 'medium' }
	}
}

function blobFromDataUrl(dataUrl) {
	const separator = dataUrl.indexOf(',')
	if (!dataUrl.startsWith('data:') || separator < 0) throw new Error('Image de diagramme invalide')
	const header = dataUrl.slice(5, separator)
	const payload = dataUrl.slice(separator + 1)
	const mime = header.split(';')[0] || 'application/octet-stream'
	const bytes = header.endsWith(';base64')
		? Uint8Array.from(atob(payload), character => character.charCodeAt(0))
		: new TextEncoder().encode(decodeURIComponent(payload))
	return new Blob([bytes], { type: mime })
}

export default {
	components: { DiagramEditor, MediaTreeNode, MediaPreview, TextFileEditor },
	data() {
		return { selectedSpaceId: null, error: '', copyFallbackUrl: '', processing: false, uploadProgress: null, diagramVisible: false, diagramTarget: null, textFileTarget: null, previewTarget: null, savingDiagram: false, diagramSaveError: '', mediaVersions: {}, ...loadDisplayPreferences(), expandedPaths: new Set(), dragPath: null, rootOver: false }
	},
	watch: {
		viewMode() { this.saveDisplayPreferences() },
		gridSize() { this.saveDisplayPreferences() },
	},
	computed: {
		adminMediasStore() { return this.stores.useAdminMediasStore() },
		directory() { return this.adminMediasStore.directory },
		listing() { return this.adminMediasStore.listing },
		selectedSpace() { return this.directory?.spaces?.find(space => space.id === this.selectedSpaceId) ?? null },
		canCrud() { return this.selectedSpace?.canCrud === true },
		rootFolders() { return this.listing?.tree ?? [] },
		breadcrumbs() {
			let path = ''
			return (this.listing?.path?.split('/') ?? []).filter(Boolean).map(name => {
				path = path ? `${path}/${name}` : name
				return { name, path }
			})
		},
	},
	async mounted() {
		try {
			await this.adminMediasStore.loadDirectory()
			this.selectedSpaceId = this.directory.spaces?.[0]?.id ?? null
			if (this.selectedSpaceId !== null) await this.loadListing()
			else this.error = 'Vous ne disposez d’aucun accès aux médias.'
		} catch (_) { this.error = 'Impossible de charger le gestionnaire de médias.' }
	},
	methods: {
		saveDisplayPreferences() {
			try { window.localStorage.setItem(displayPreferencesKey, JSON.stringify({ viewMode: this.viewMode, gridSize: this.gridSize })) }
			catch (_) { /* La préférence reste utilisable pendant cette session. */ }
		},
		async loadListing(path = this.listing?.path ?? '') {
			if (this.selectedSpaceId === null) return
			this.processing = true; this.error = ''
			try {
				await this.adminMediasStore.list(this.selectedSpaceId, path ?? '')
				this.expandAncestors(path ?? '')
			}
			catch (_) { this.error = 'Impossible de charger ce dossier.' }
			finally { this.processing = false }
		},
		changeSpace() { this.expandedPaths.clear(); this.loadListing('') },
		navigate(path) { if (!this.processing && path !== null) this.loadListing(path) },
		expandAncestors(path) {
			let parent = ''
			for (const part of path.split('/').filter(Boolean)) {
				parent = parent ? `${parent}/${part}` : part
				this.expandedPaths.add(parent)
			}
		},
		toggleFolder(path) {
			if (this.expandedPaths.has(path)) { this.expandedPaths.delete(path); return }
			this.expandedPaths.add(path)
		},
		forgetExpandedPath(path) {
			for (const expanded of this.expandedPaths) {
				if (expanded === path || expanded.startsWith(`${path}/`)) this.expandedPaths.delete(expanded)
			}
		},
		validMoveTarget(destination) {
			if (!this.dragPath) return false
			if (this.dragPath.split('/').slice(0, -1).join('/') === destination) return false
			return destination !== this.dragPath && !destination.startsWith(`${this.dragPath}/`)
		},
		startDrag(event, entry) {
			if (!this.canCrud || this.processing) { event.preventDefault(); return }
			this.dragPath = entry.path
			event.dataTransfer.effectAllowed = 'move'
			event.dataTransfer.setData('text/plain', entry.path)
		},
		endDrag() { this.dragPath = null; this.rootOver = false },
		onRootDragOver(event) { if (this.validMoveTarget('') && !this.processing) event.preventDefault() },
		dropOnRoot() { this.rootOver = false; this.moveTo('') },
		async moveTo(destinationPath) {
			if (!this.canCrud || this.processing || !this.validMoveTarget(destinationPath)) return
			const path = this.dragPath
			this.endDrag()
			await this.runAction(async () => { await this.adminMediasStore.move(this.selectedSpaceId, path, destinationPath); this.forgetExpandedPath(path); await this.loadListing() })
		},
		open(entry) {
			if (entry.type === 'directory') this.navigate(entry.path)
			else if (this.canEditTextFile(entry)) this.editTextFile(entry)
			else this.preview(entry)
		},
		preview(entry) { this.previewTarget = entry },
		mediaUrl(entry) {
			if (!entry?.url) return null
			const version = `${entry.modified ?? ''}-${entry.size ?? ''}-${this.mediaVersions[`${this.selectedSpaceId}/${entry.path}`] ?? ''}`
			return `${entry.url}${entry.url.includes('?') ? '&' : '?'}v=${encodeURIComponent(version)}`
		},
		async createFolder() {
			const name = await this.askInput('Nouveau dossier', 'Nom du dossier')
			if (!name) return
			await this.runAction(async () => { await this.adminMediasStore.createFolder(this.selectedSpaceId, this.listing.path, name); await this.loadListing() })
		},
		async createTextFile(extension) {
			let name = await this.askInput(`Nouveau fichier ${extension.toUpperCase()}`, 'Nom du fichier', `nouveau.${extension}`)
			if (!name) return
			if (!name.toLowerCase().endsWith(`.${extension}`)) name += `.${extension}`
			this.textFileTarget = { entry: null, name, path: null, spaceId: this.selectedSpaceId, folderPath: this.listing.path }
		},
		canEditTextFile(entry) { return this.canCrud && entry.type === 'file' && this.directory.editableExtensions.includes(entry.extension) },
		editTextFile(entry) { this.textFileTarget = { entry, name: entry.name, path: entry.path, spaceId: this.selectedSpaceId, folderPath: this.listing.path } },
		async onTextFileSaved({ path, spaceId, folderPath }) {
			this.mediaVersions[`${spaceId}/${path}`] = Date.now()
			if (this.selectedSpaceId === spaceId && this.listing?.path === folderPath) await this.loadListing(folderPath)
		},
		async uploadFiles(files) {
			if (!this.canCrud || this.processing || !files?.length) return
			const totalBytes = files.reduce((sum, file) => sum + file.size, 0)
			const spaceId = this.selectedSpaceId
			const path = this.listing.path
			let uploadedBytes = 0
			this.processing = true
			this.uploadProgress = 0
			this.error = ''
			try {
				for (const file of files) {
					await this.adminMediasStore.upload(spaceId, path, file, false, event => {
						const ratio = event.total ? event.loaded / event.total : event.progress ?? 0
						this.uploadProgress = Math.min(99, Math.round((uploadedBytes + file.size * ratio) / totalBytes * 100))
					})
					uploadedBytes += file.size
					this.uploadProgress = Math.round(uploadedBytes / totalBytes * 100)
				}
				await this.adminMediasStore.list(spaceId, path)
			} catch (_) {
				this.error = 'Le téléversement des fichiers a échoué.'
				if (uploadedBytes > 0) await this.adminMediasStore.list(spaceId, path).catch(() => {})
			} finally {
				this.uploadProgress = null
				this.processing = false
			}
		},
		async rename(entry) {
			const name = await this.askInput('Renommer', 'Nouveau nom', entry.name)
			if (!name || name === entry.name) return
			await this.runAction(async () => { await this.adminMediasStore.rename(this.selectedSpaceId, entry.path, name); this.forgetExpandedPath(entry.path); await this.loadListing() })
		},
		async deleteEntry(entry) {
			if (!await this.stores.useAppStore().askYesNo(`Supprimer « ${entry.name} » ?`)) return
			await this.runAction(async () => { await this.adminMediasStore.delete(this.selectedSpaceId, entry.path); this.forgetExpandedPath(entry.path); await this.loadListing() })
		},
		async showCopyFallback(url) {
			this.copyFallbackUrl = url
			await this.$nextTick()
			this.$refs.copyFallbackInput?.focus()
		},
		canEditDiagram(entry) { return this.canCrud && entry.type === 'file' && ['png', 'svg'].includes(entry.extension) },
		async createDiagram(extension) {
			let name = await this.askInput(`Diagramme ${extension.toUpperCase()}`, 'Nom du diagramme', `diagramme.${extension}`)
			if (!name) return
			if (!name.toLowerCase().endsWith(`.${extension}`)) name += `.${extension}`
			this.diagramSaveError = ''
			this.diagramTarget = { entry: null, name, format: `xml${extension}`, overwrite: false }; this.diagramVisible = true
		},
		editDiagram(entry) { this.diagramSaveError = ''; this.diagramTarget = { entry: { ...entry, url: this.mediaUrl(entry) }, name: entry.name, format: `xml${entry.extension}`, overwrite: true }; this.diagramVisible = true },
		closeDiagram() { if (!this.savingDiagram) { this.diagramVisible = false; this.diagramTarget = null } },
		async saveDiagram({ dataUrl, name, closeAfterSave }) {
			this.savingDiagram = true
			this.diagramSaveError = ''
			try {
				const blob = blobFromDataUrl(dataUrl)
				await this.adminMediasStore.upload(this.selectedSpaceId, this.listing.path, new File([blob], name, { type: blob.type }), this.diagramTarget.overwrite)
				const path = this.listing.path ? `${this.listing.path}/${name}` : name
				this.mediaVersions[`${this.selectedSpaceId}/${path}`] = Date.now()
				this.diagramTarget.overwrite = true
				await this.loadListing()
				if (closeAfterSave) { this.diagramVisible = false; this.diagramTarget = null }
				else this.diagramTarget.entry = this.listing.entries.find(entry => entry.path === path) ?? this.diagramTarget.entry
			} catch (_) { this.diagramSaveError = 'Impossible d’enregistrer le diagramme.' }
			finally { this.savingDiagram = false }
		},
		async runAction(action) {
			if (this.processing) return
			this.processing = true; this.error = ''
			try { await action() } catch (_) { this.error = 'L’opération sur les médias a échoué.' } finally { this.processing = false }
		},
		isImage(entry) { return entry.type === 'file' && imageExtensions.has(entry.extension) },
		fileIcon(entry) {
			if (entry.extension === 'pdf') return 'fa-solid fa-file-pdf'
			if (['aac', 'aif', 'aiff', 'flac', 'm4a', 'mp3', 'oga', 'ogg', 'opus', 'wav'].includes(entry.extension)) return 'fa-solid fa-file-audio'
			if (['m4v', 'mov', 'mp4', 'ogv', 'webm'].includes(entry.extension)) return 'fa-solid fa-file-video'
			if (entry.extension === 'zip') return 'fa-solid fa-file-zipper'
			return 'fa-solid fa-file'
		},
		formatSize(size) {
			if (!Number.isFinite(size)) return '—'
			const units = ['o', 'Ko', 'Mo', 'Go']; let value = size; let unit = 0
			while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++ }
			return `${value.toLocaleString('fr-FR', { maximumFractionDigits: 1 })} ${units[unit]}`
		},
	},
}
</script>

<style scoped>
.media-manager__toolbar { display: flex; align-items: left; gap: .5rem; }
.media-manager__toolbar select { min-width: 220px; }
.media-manager__actions { margin-left: .5rem; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
@media (max-width: 1100px) { .media-manager__actions { width: 100%; margin-left: 0; } }
.media-manager__content { display: grid; grid-template-columns: minmax(200px, 260px) minmax(0, 1fr); gap: 1rem; }
.media-manager__sidebar { align-self: start; max-height: calc(100vh - 15rem); overflow: auto; }
.media-manager__tree { min-width: 0; }
.media-manager__root { display: block; width: 100%; padding: .4rem .5rem; border: 0; border-radius: .3rem; background: transparent; text-align: left; }
.media-manager__root:hover { background: #f1f3f5; }
.media-manager__root.is-current { background: #e7f1ff; color: #084298; }
.media-manager__root.is-target { outline: 2px solid #0d6efd; background: #dbeafe; }
.media-manager__browser { min-height: 420px; }
.media-manager__breadcrumb { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-manager__size { width: auto; }
.media-manager__empty { min-height: 330px; display: grid; place-content: center; justify-items: center; gap: .5rem; }
.media-manager__entries { display: grid; gap: .75rem; align-content: start; }
.media-manager__entries--small { grid-template-columns: repeat(auto-fill, minmax(110px, 1fr)); }
.media-manager__entries--medium { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
.media-manager__entries--large { grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); }
.media-manager__entries--list { display: flex; flex-direction: column; gap: .35rem; }
.media-card { position: relative; min-width: 0; border: 1px solid rgba(0,0,0,.13); border-radius: .4rem; background: #fff; }
.media-card[draggable="true"] { cursor: grab; }
.media-card[draggable="true"]:active { cursor: grabbing; }
.media-card__main { width: 100%; height: 120px; display: grid; place-items: center; border: 0; background: #f8f9fa; overflow: hidden; }
.media-manager__entries--small .media-card__main { height: 90px; }
.media-manager__entries--large .media-card__main { height: 170px; }
.media-card__main:hover { background: #e9ecef; }
.media-card__main img { width: 100%; height: 100%; object-fit: contain; }
.media-card__details { min-width: 0; padding: .5rem; padding-right: 2.7rem; }
.media-card__name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.media-card__type::after { content: ' · '; }
.media-card__actions { position: absolute; right: .45rem; bottom: .45rem; }
.media-card__actions :deep(> button) { padding: .25rem .45rem; }
.media-card--list { display: flex; align-items: center; min-height: 64px; }
.media-card--list .media-card__main { flex: 0 0 64px; width: 64px; height: 62px; }
.media-card--list .media-card__main :is(i, img) { max-width: 100%; max-height: 100%; }
.media-card--list .media-card__details { flex: 1; display: grid; grid-template-columns: minmax(0, 1fr) 6rem 6rem; align-items: center; gap: .5rem; padding-right: 3rem; }
.media-card--list .media-card__type::after { content: none; }
.media-card--list .media-card__actions { bottom: auto; top: 50%; transform: translateY(-50%); }
@media (max-width: 767px) { .media-manager__content { grid-template-columns: 1fr; } .media-manager__sidebar { max-height: 180px; } .media-card--list .media-card__details { grid-template-columns: minmax(0, 1fr) 3.5rem 4rem; gap: .25rem; } }
</style>
