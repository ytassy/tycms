<template>
	<div v-if="store.dragPayload && store.canDrop(target)" class="page-drop-zone" :class="{ 'page-drop-zone--compact': compact }"
		role="presentation" @dragenter.prevent.stop @dragover.prevent.stop="dragOver" @drop.prevent.stop="store.drop(target)">

	</div>
</template>

<script>
export default {
	props: {
		target: { type: Object, required: true },
		compact: { type: Boolean, default: false },
	},
	computed: { store() { return this.stores.useAdminPagesStore() } },
	methods: {
		dragOver(event) { event.dataTransfer.dropEffect = this.store.dragPayload?.kind === 'catalog' ? 'copy' : 'move' },
	},
}
</script>
