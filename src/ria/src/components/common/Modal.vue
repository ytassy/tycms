<template>
	<Teleport to="body">
		<div v-if="visible" class="modal-backdrop" :style="{ zIndex: zIndex + zIndexAdd }" @click.self="onBackgroundClick">
			<section
				:class="['modal-window', 'card', customClass]"
				:style="{
					maxWidth: 'calc(100vw - 5rem)',
					width: fullScreen ? 'calc(100vw - 3rem)' : width
				}"
				role="dialog"
				aria-modal="true"
				@click.stop
			>
				<header v-if="showHeader" class="card-header d-flex align-items-center gap-2">
					<div class="flex-grow-1"><slot name="header" /></div>
					<button v-if="closable" type="button" class="btn-close esc" aria-label="Fermer" @click="close" />
				</header>

				<div :class="['card-body', { 'overflow-auto': fullScreen || largeBody }]" :style="bodyStyle">
					<slot name="body" />
				</div>

				<footer v-if="$slots.footer" class="card-footer tar">
					<slot name="footer" />
				</footer>
			</section>
		</div>
	</Teleport>
</template>

<script>
export default {
	props: {
		visible: { type: Boolean, default: false },
		width: { type: String, default: '300px' },
		maxHeight: { type: String, default: null },
		showHeader: { type: Boolean, default: true },
		zIndex: { type: Number, default: 1060 },
		zIndexAdd: { type: Number, default: 0 },
		fullScreen: { type: Boolean, default: false },
		largeBody: { type: Boolean, default: false },
		closable: { type: Boolean, default: false },
		closeOnBg: { type: Boolean, default: false },
		customClass: { type: String, default: null },
	},
	emits: ['close', 'clickBg'],

	computed: {
		bodyStyle() {
			return {
				maxHeight: this.maxHeight ?? (this.fullScreen ? 'calc(100vh - 10rem)' : 'calc(100vh - 14rem)'),
				overflowY: "auto"
			}
		},
	},

	methods: {
		close() {
			this.$emit('close')
		},

		onBackgroundClick() {
			this.$emit('clickBg')
			if (this.closeOnBg) this.close()
		},
	},
}
</script>

<style scoped>
.modal-backdrop {
	position: fixed;
	inset: 0;
	display: grid;
	place-items: center;
	padding: 1.5rem;
	background: rgb(0 0 0 / 45%);
}


</style>
