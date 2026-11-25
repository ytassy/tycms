<template>
	<section class="page-workspace">
		<header class="page-workspace-header">
			<div class="d-flex align-items-center gap-2 min-width-0">
				<button type="button" class="btn btn-outline-secondary btn-sm" @click="$emit('close')">← Liste</button>
				<div class="min-width-0"><h1 class="h5 mb-0 text-truncate">{{ store.current.name || 'Nouvelle page' }}</h1><small class="text-muted">{{ store.isDirty ? 'Modifications non enregistrées' : 'Enregistrée' }}</small></div>
			</div>
			<div class="d-flex gap-2 align-items-center">
				<div v-if="store.current.type === 2 && sizeRatio > 50" class="page-size-gauge" :class="{ 'text-danger': sizeRatio >= 100 }"
					:title="sizeRatio > 100 ? 'La page dépasse la limite autorisée.' : 'Poids du JSON de la page'">
					<small>{{ sizeLabel }} / {{ limitLabel }} · {{ Math.round(sizeRatio) }} %</small>
					<div class="progress" role="progressbar" aria-label="Poids du JSON de la page"
						:aria-valuenow="Math.round(sizeRatio)" aria-valuemin="0" :aria-valuemax="Math.max(100, Math.round(sizeRatio))"
						:aria-valuetext="`${sizeLabel} sur ${limitLabel}`">
						<div class="progress-bar" :class="sizeRatio >= 100 ? 'bg-danger' : sizeRatio >= 80 ? 'bg-warning' : 'bg-info'"
							:style="{ width: `${Math.min(100, sizeRatio)}%` }" />
					</div>
				</div>
				<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!store.current.id" @click="showHistory = true">Historique</button>
				<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="store.previewing || !store.current.canEdit" @click="showPreview = true">Rendu fidèle</button>
				<button type="button" class="btn btn-primary btn-sm ctrl-s" :disabled="!store.current.canEdit || !store.isDirty" @click="$emit('save')">{{ store.saving ? 'Enregistrement…' : 'Enregistrer' }}</button>
			</div>
		</header>
		<div ref="workspaceBody" class="page-workspace-body" :class="{ 'page-workspace-body--resizing': resizingSidebar }" :style="{ '--page-sidebar-width': `${sidebarWidth}px` }">
			<PageSidebar />
			<div class="page-workspace-resizer" role="separator" tabindex="0" aria-label="Largeur du panneau de gauche" title="Glisser pour agrandir le panneau"
				aria-orientation="vertical" :aria-valuenow="sidebarWidth" aria-valuemin="320" :aria-valuemax="sidebarMax"
				@pointerdown="startSidebarResize" @pointermove="moveSidebarResize" @pointerup="stopSidebarResize" @pointercancel="stopSidebarResize" @keydown="resizeSidebarWithKeyboard" />
			<main class="page-workspace-main" aria-label="Prévisualisation de la page">
				<div class="page-workspace-toolbar">
					<strong>Aperçu interactif</strong>
					<div v-if="store.currentSpaceLanguages.length > 1" class="d-flex flex-wrap gap-1 ms-2" role="group" aria-label="Langue de l’aperçu">
						<button v-for="locale in store.currentSpaceLanguages" :key="locale" type="button"
							class="btn btn-sm rounded-pill px-2 py-0" :class="store.previewLocale === locale ? 'btn-primary' : 'btn-outline-secondary'"
							:aria-pressed="store.previewLocale === locale" @click="store.previewLocale = locale">{{ locale.toUpperCase() }}</button>
					</div>
					<div v-if="store.current.type === 2" class="btn-group btn-group-sm page-viewport-options ms-auto" role="group" aria-label="Largeur de l’aperçu">
						<button v-for="option in viewports" :key="option.id" type="button" class="btn"
							:class="viewport === option.id ? 'btn-secondary' : 'btn-outline-secondary'"
							:aria-pressed="viewport === option.id" :title="`${option.width} px`" @click="viewport = option.id">{{ option.label }}</button>
					</div>
				</div>
				<div v-if="store.current.type === 2" class="page-canvas-stage">
					<div class="page-canvas-viewport" :style="{ width: `${selectedViewport.width}px` }">
						<div class="page-canvas-container" :style="{ maxWidth: store.current.data.container.fluid || !selectedViewport.containerWidth ? 'none' : `${selectedViewport.containerWidth}px` }">
							<PageCanvasItems :items="store.current.data.items" parent-id="page" :viewport="viewport" />
						</div>
					</div>
				</div>
				<div v-else class="page-canvas-stage"><p class="text-muted text-center p-4">Modifiez le HTML brut dans l’onglet Page, puis ouvrez le rendu fidèle.</p></div>
			</main>
		</div>
		<PageCanvasContextMenu ref="contextMenu" />
		<PagePreview v-if="showPreview" @close="showPreview = false" />
		<PageHistory
			:visible="showHistory"
			:page-id="store.current.id"
			:page-name="store.current.name"
			@close="showHistory = false"
			@load="loadHistoryVersion"
		/>
	</section>
</template>

<script>
import PageCanvasContextMenu from './PageCanvasContextMenu.vue'
import PageCanvasItems from './PageCanvasItems.vue'
import PageHistory from './PageHistory.vue'
import PagePreview from './PagePreview.vue'
import PageSidebar from './PageSidebar.vue'
import { pagePreviewViewports } from './pagePreviewViewports'
import { formatPageBytes } from './pageSize.js'
import './pageEditor.scss'

const sidebarWidthStorageKey = 'tycms.admin.pages.sidebarWidth'

function loadSidebarWidth() {
	try {
		const width = Number(window.localStorage.getItem(sidebarWidthStorageKey))
		return Number.isInteger(width) && width >= 320 && width <= 900 ? width : 360
	} catch (_) {
		return 360
	}
}

export default {
	components: { PageCanvasContextMenu, PageCanvasItems, PageHistory, PagePreview, PageSidebar },
	emits: ['close', 'save'],
	provide() { return { openPageContextMenu: (event, id) => this.$refs.contextMenu?.open(event, id) } },
	data() {
		const sidebarWidth = loadSidebarWidth()
		return { viewport: 'lg', viewports: pagePreviewViewports, showPreview: false, showHistory: false, sidebarWidth, preferredSidebarWidth: sidebarWidth, sidebarMax: 900, resizingSidebar: false, resizeOffset: 0 }
	},
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		sizeRatio() { return this.store.pageSize / this.store.maxPageSize * 100 },
		sizeLabel() { return formatPageBytes(this.store.pageSize) },
		limitLabel() { return formatPageBytes(this.store.maxPageSize) },
		selectedViewport() { return this.viewports.find(option => option.id === this.viewport) ?? this.viewports[0] },
	},
	mounted() {
		document.documentElement.classList.add('page-editor-fullscreen-open')
		window.addEventListener('beforeunload', this.beforeUnload)
		window.addEventListener('resize', this.updateSidebarBounds)
		this.updateSidebarBounds()
	},
	beforeUnmount() {
		document.documentElement.classList.remove('page-editor-fullscreen-open')
		window.removeEventListener('beforeunload', this.beforeUnload)
		window.removeEventListener('resize', this.updateSidebarBounds)
	},
	methods: {
		async loadHistoryVersion(version) {
			if (this.store.isDirty
				&& !await this.askYesNoAsync('Remplacer les modifications en cours par cette version de l’historique ?')) return
			if (!this.store.applyHistoryVersion(version)) return

			this.showHistory = false
			this.showModaleSuccess('Version chargée. Enregistrez la page pour la restaurer.')
		},

		updateSidebarBounds() {
			const width = this.$refs.workspaceBody?.clientWidth ?? 0
			if (width <= 900) return
			this.sidebarMax = Math.max(320, Math.min(900, width - 332))
			this.sidebarWidth = Math.min(this.preferredSidebarWidth, this.sidebarMax)
		},
		saveSidebarWidth() {
		try { window.localStorage.setItem(sidebarWidthStorageKey, String(this.preferredSidebarWidth)) }
		catch (_) { /* La largeur reste utilisable pendant cette session. */ }
		},
		startSidebarResize(event) {
			if (event.button !== 0) return
			this.updateSidebarBounds()
			this.resizeOffset = event.clientX - this.$refs.workspaceBody.getBoundingClientRect().left - this.sidebarWidth
			this.resizingSidebar = true
			event.currentTarget.setPointerCapture(event.pointerId)
			event.preventDefault()
		},
		moveSidebarResize(event) {
			if (!this.resizingSidebar) return
			const width = event.clientX - this.$refs.workspaceBody.getBoundingClientRect().left - this.resizeOffset
			this.sidebarWidth = Math.max(320, Math.min(this.sidebarMax, Math.round(width)))
			this.preferredSidebarWidth = this.sidebarWidth
		},
		stopSidebarResize(event) {
			if (this.resizingSidebar) this.saveSidebarWidth()
			this.resizingSidebar = false
			if (event.currentTarget.hasPointerCapture(event.pointerId)) event.currentTarget.releasePointerCapture(event.pointerId)
		},
		resizeSidebarWithKeyboard(event) {
			const steps = { ArrowLeft: -20, ArrowRight: 20 }
			if (!(event.key in steps) && event.key !== 'Home' && event.key !== 'End') return
			event.preventDefault()
			this.updateSidebarBounds()
			const width = event.key === 'Home' ? 320 : event.key === 'End' ? this.sidebarMax : this.sidebarWidth + steps[event.key]
			this.sidebarWidth = Math.max(320, Math.min(this.sidebarMax, width))
			this.preferredSidebarWidth = this.sidebarWidth
			this.saveSidebarWidth()
		},
		beforeUnload(event) {
			if (!this.store.isDirty) return
			event.preventDefault()
			event.returnValue = ''
		},
	},
}
</script>
