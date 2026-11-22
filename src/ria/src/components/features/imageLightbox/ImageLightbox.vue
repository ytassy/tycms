<template>
	<Teleport to="body">
		<dialog ref="dialog" class="page-image-lightbox" aria-label="Image agrandie" @cancel.prevent="close" @close="close" @click="backdropClick" @keydown.stop>
			<button type="button" class="page-image-lightbox-close" autofocus aria-label="Fermer l’image agrandie" @click="close">× <span>Fermer</span></button>
			<img :src="src" :alt="alt" draggable="false">
		</dialog>
	</Teleport>
</template>
<script>
export default {
	props: { src: { type: String, required: true }, alt: { type: String, default: '' } },
	emits: ['close'],
	data: () => ({ previousFocus: null, previousOverflow: '', closed: false }),
	mounted() {
		this.previousFocus = document.activeElement
		this.previousOverflow = document.body.style.overflow
		document.body.style.overflow = 'hidden'
		this.$refs.dialog.showModal()
	},
	beforeUnmount() {
		this.$refs.dialog?.close()
		document.body.style.overflow = this.previousOverflow
		if (this.previousFocus?.isConnected) this.previousFocus.focus({ preventScroll: true })
	},
	methods: {
		close() {
			if (this.closed) return
			this.closed = true
			this.$emit('close')
		},
		backdropClick(event) {
			if (event.target === this.$refs.dialog) this.close()
		},
	},
}
</script>
<style lang="scss">
.page-image-enlarge { cursor: zoom-in; }
button.page-image-enlarge { padding: 0; border: 0; background: transparent; max-width: 100%; line-height: 0; vertical-align: middle; }
.page-image-lightbox {
	position: fixed;
	inset: 0;
	width: 100%;
	height: 100%;
	max-width: none;
	max-height: none;
	margin: 0;
	padding: 4.5rem 1rem 1rem;
	border: 0;
	background: transparent;
	overflow: hidden;

	&[open] { display: flex; justify-content: center; align-items: center; }
	&::backdrop { background: rgb(0 0 0 / 82%); }
	> img { display: block; max-width: 100%; max-height: 100%; object-fit: contain; }
}
.page-image-lightbox-close {
	position: absolute;
	top: 1rem;
	right: 1rem;
	padding: .5rem 1rem;
	border: 1px solid #fff;
	border-radius: .3rem;
	background: #111;
	color: #fff;
	font-size: 1.25rem;
	cursor: pointer;
	&:focus-visible { outline: 3px solid #fff; outline-offset: 3px; }
}
</style>
