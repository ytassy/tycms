<template>
	<Teleport to="body">
		<div v-if="targetId && found" ref="menu" class="page-canvas-context-menu" role="menu" aria-label="Actions du composant"
			:style="{ left: `${left}px`, top: `${top}px` }" @contextmenu.prevent.stop @keydown="menuKeydown">
			<button type="button" role="menuitem" :disabled="!store.canDuplicateNode(targetId)" @click="duplicate">
				<i class="fa-solid fa-copy" aria-hidden="true"></i> Dupliquer
			</button>
			<div class="page-canvas-context-menu-separator" role="separator"></div>

			<button type="button" role="menuitem" :disabled="!store.canUseClipboard(targetId) || !clipboardWritable" @click="copy">
				<i class="fa-solid fa-copy" aria-hidden="true"></i> Copier
			</button>
			<button v-for="action in pasteActions" :key="action.mode" type="button" role="menuitem"
				:disabled="!store.canUseClipboard(targetId) || !clipboardReadable" @click="paste(action.mode)">
				<i class="fa-solid fa-paste" aria-hidden="true"></i> {{ action.label }}
			</button>
			
			<div class="page-canvas-context-menu-separator" role="separator"></div>
			<button type="button" role="menuitem" :disabled="!canEdit || found.index === 0" @click="move(-1)">
				<i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Monter
			</button>
			<button type="button" role="menuitem" :disabled="!canEdit || found.index === found.siblings.length - 1" @click="move(1)">
				<i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Descendre
			</button>
			<div class="page-canvas-context-menu-separator" role="separator"></div>
			<button type="button" role="menuitem" :disabled="!canEdit" @click="toggleVisibility">
				<i :class="isNodeHidden(found.node) ? 'fa-solid fa-eye' : 'fa-solid fa-eye-slash'" aria-hidden="true"></i>
				{{ isNodeHidden(found.node) ? 'Afficher' : 'Masquer' }}
			</button>
			<div class="page-canvas-context-menu-separator" role="separator"></div>
			<button type="button" role="menuitem" class="text-danger" :disabled="!canRemove" @click="remove">
				<i class="fa-solid fa-trash" aria-hidden="true"></i> Supprimer
			</button>
		</div>
	</Teleport>
</template>
<script>
import { findNode } from './pageEditorModel'
import { isNodeHidden } from './pageVisibility'

export default {
	data: () => ({ targetId: null, left: 0, top: 0, previousFocus: null,
		pasteActions: [{ mode: 'replace', label: 'Coller (remplacer)' }, { mode: 'before', label: 'Coller avant' }, { mode: 'after', label: 'Coller après' }],
	}),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		found() { return findNode(this.store.current?.data, this.targetId) },
		canEdit() { return Boolean(this.store.current?.canEdit && this.store.current.type === 2) },
		clipboardWritable() { return Boolean(globalThis.navigator?.clipboard?.writeText) },
		clipboardReadable() { return Boolean(globalThis.navigator?.clipboard?.readText) },
		canRemove() { return this.store.canRemoveNode(this.targetId) },
	},
	watch: {
		canEdit(value) { if (!value) this.close() },
		'store.editorSession'() { this.close() },
		found(value) { if (!value) this.close() },
	},
	mounted() {
		document.addEventListener('pointerdown', this.outsideClick, true)
		document.addEventListener('keydown', this.escapeKey, true)
		window.addEventListener('scroll', this.scrolled, true)
		window.addEventListener('resize', this.scrolled)
		document.addEventListener('dragstart', this.scrolled, true)
	},
	beforeUnmount() {
		document.removeEventListener('pointerdown', this.outsideClick, true)
		document.removeEventListener('keydown', this.escapeKey, true)
		window.removeEventListener('scroll', this.scrolled, true)
		window.removeEventListener('resize', this.scrolled)
		document.removeEventListener('dragstart', this.scrolled, true)
	},
	methods: {
		isNodeHidden,
		async open(event, id) {
			if (!this.canEdit || !findNode(this.store.current.data, id)) return
			this.previousFocus = document.activeElement
			this.targetId = id
			this.left = event.clientX
			this.top = event.clientY
			this.store.selectNode(id, { showProperties: false })
			await this.$nextTick()
			if (this.targetId !== id || !this.$refs.menu) return

			// Le menu est téléporté pour échapper au défilement et aux zones estompées.
			const bounds = this.$refs.menu.getBoundingClientRect()
			this.left = Math.max(8, Math.min(this.left, window.innerWidth - bounds.width - 8))
			this.top = Math.max(8, Math.min(this.top, window.innerHeight - bounds.height - 8))
			this.$refs.menu.querySelector('button:not(:disabled)')?.focus({ preventScroll: true })
		},
		close(restoreFocus = false) {
			this.targetId = null
			if (restoreFocus && this.previousFocus?.isConnected) this.previousFocus.focus({ preventScroll: true })
		},
		outsideClick(event) { if (this.targetId && !this.$refs.menu?.contains(event.target)) this.close() },
		scrolled() { this.close() },
		escapeKey(event) {
			if (!this.targetId || event.key !== 'Escape') return
			event.preventDefault()
			event.stopPropagation()
			this.close(true)
		},
		menuKeydown(event) {
			if (event.key === 'Tab') { this.close(); return }
			if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return
			event.preventDefault()
			const buttons = [...this.$refs.menu.querySelectorAll('button:not(:disabled)')]
			const index = buttons.indexOf(document.activeElement)
			const next = event.key === 'Home' ? 0 : event.key === 'End' ? buttons.length - 1
				: (index + (event.key === 'ArrowUp' ? -1 : 1) + buttons.length) % buttons.length
			buttons[next]?.focus({ preventScroll: true })
		},
		move(delta) {
			const id = this.targetId
			this.close(true)
			if (this.canEdit) this.store.moveSibling(id, delta)
		},
		toggleVisibility() {
			if (!this.canEdit || !this.found) return
			const id = this.targetId
			const visible = isNodeHidden(this.found.node)
			this.close(true)
			this.store.setNodeVisibility(id, visible)
		},
		async copy() {
			const id = this.targetId
			this.close(true)
			try { await this.store.copyNode(id) }
			catch (error) { this.showModaleError(error.message) }
		},
		async paste(mode) {
			const id = this.targetId
			this.close(true)
			try { await this.store.pasteNode(id, mode, message => this.askYesNoAsync(message)) }
			catch (error) { this.showModaleError(error.message) }
		},
		duplicate() {
			const id = this.targetId
			this.close(true)
			return this.store.duplicateNode(id)
		},
		remove() {
			const id = this.targetId
			this.close(true)
			return this.store.requestRemoveNode(id, message => this.askYesNoAsync(message))
		},
	},
}
</script>
