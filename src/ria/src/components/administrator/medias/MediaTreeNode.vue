<template>
	<div class="media-tree-node">
		<div class="media-tree-node__row" :class="{ 'is-current': currentPath === entry.path, 'is-target': dragPath && validTarget && over }" @dragenter.prevent="onDragEnter" @dragleave="onDragLeave" @dragover="onDragOver" @drop.prevent.stop="onDrop">
			<button v-if="hasChildren" type="button" class="media-tree-node__toggle" :aria-label="expanded ? 'Replier' : 'Déplier'" :aria-expanded="expanded" :disabled="processing" @click="$emit('toggle', entry.path)"><i :class="expanded ? 'fa-solid fa-chevron-down' : 'fa-solid fa-chevron-right'" /></button>
			<span v-else class="media-tree-node__toggle" aria-hidden="true" />
			<button type="button" class="media-tree-node__name" :title="entry.path" :disabled="processing" @click="$emit('navigate', entry.path)"><i class="fa-solid fa-folder text-warning me-2" />{{ entry.name }}</button>
		</div>
		<div v-if="hasChildren && expanded" class="media-tree-node__children">
			<MediaTreeNode v-for="child in entry.children" :key="child.path" :entry="child" :current-path="currentPath" :expanded-paths="expandedPaths" :drag-path="dragPath" :processing="processing" @navigate="$emit('navigate', $event)" @toggle="$emit('toggle', $event)" @move="$emit('move', $event)" />
		</div>
	</div>
</template>

<script>
export default {
	name: 'MediaTreeNode',
	props: {
		entry: { type: Object, required: true },
		currentPath: { type: String, required: true },
		expandedPaths: { type: Object, required: true },
		dragPath: { type: String, default: null },
		processing: { type: Boolean, default: false },
	},
	emits: ['navigate', 'toggle', 'move'],
	data() { return { over: false } },
	computed: {
		hasChildren() { return this.entry.children?.length > 0 },
		expanded() { return this.expandedPaths.has(this.entry.path) },
		validTarget() { return this.dragPath !== this.entry.path && !this.entry.path.startsWith(`${this.dragPath}/`) && this.dragPath?.split('/').slice(0, -1).join('/') !== this.entry.path },
	},
	methods: {
		onDragEnter() { if (this.dragPath && this.validTarget) this.over = true },
		onDragLeave(event) { if (!event.currentTarget.contains(event.relatedTarget)) this.over = false },
		onDragOver(event) { if (this.dragPath && this.validTarget && !this.processing) event.preventDefault() },
		onDrop() { this.over = false; if (this.dragPath && this.validTarget && !this.processing) this.$emit('move', this.entry.path) },
	},
}
</script>

<style scoped>
.media-tree-node__row { display: flex; align-items: stretch; min-width: 0; border-radius: .3rem; }
.media-tree-node__row:hover { background: #f1f3f5; }
.media-tree-node__row.is-current { background: #e7f1ff; color: #084298; }
.media-tree-node__row.is-target { outline: 2px solid #0d6efd; background: #dbeafe; }
.media-tree-node__toggle, .media-tree-node__name { border: 0; background: transparent; color: inherit; }
.media-tree-node__toggle { flex: 0 0 1.8rem; padding: .35rem; font-size: .7rem; }
.media-tree-node__name { min-width: 0; flex: 1; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; padding: .35rem .25rem; }
.media-tree-node__children { margin-left: .85rem; padding-left: .45rem; border-left: 1px solid #dee2e6; }
</style>
