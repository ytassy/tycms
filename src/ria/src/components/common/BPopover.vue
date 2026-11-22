<template>
	<Teleport to="body">
		<div
			v-if="show"
			ref="popover"
			:class="['popover bs-popover-auto show', popoverClass]"
			:style="{ zIndex: zindex ?? 1080 }"
			role="dialog"
			@click.stop
		>
			<div class="popover-arrow"></div>
			<div class="popover-body"><slot></slot></div>
		</div>
	</Teleport>
</template>

<script>
import { createPopper } from '@popperjs/core'

export default {
	props: {
		show: { type: Boolean, default: false },
		target: { type: [String, Object], default: null },
		placement: { type: String, default: 'bottom' },
		zindex: { type: Number, default: null },
		popoverClass: { type: String, default: null },
	},
	emits: ['update:show'],
	data() {
		return { popper: null }
	},
	watch: {
		show(visible) {
			if (visible) this.$nextTick(this.createPositioner)
			else this.destroyPositioner()
		},
		target() {
			if (this.show) this.$nextTick(this.createPositioner)
		},
		placement() {
			if (this.show) this.$nextTick(this.createPositioner)
		},
	},
	mounted() {
		document.addEventListener('pointerdown', this.onDocumentPointerDown)
		document.addEventListener('keydown', this.onKeydown)
		if (this.show) this.$nextTick(this.createPositioner)
	},
	beforeUnmount() {
		document.removeEventListener('pointerdown', this.onDocumentPointerDown)
		document.removeEventListener('keydown', this.onKeydown)
		this.destroyPositioner()
	},
	methods: {
		setShow(show) {
			this.$emit('update:show', show)
		},
		resolveTarget() {
			if (typeof this.target === 'string') return document.getElementById(this.target) ?? document.querySelector(this.target)
			return this.target instanceof Element ? this.target : null
		},
		createPositioner() {
			this.destroyPositioner()
			const target = this.resolveTarget()
			const popover = this.$refs.popover
			if (target === null || popover === undefined) return

			this.popper = createPopper(target, popover, {
				placement: this.placement,
				modifiers: [
					{ name: 'offset', options: { offset: [0, 8] } },
					{ name: 'preventOverflow', options: { padding: 8 } },
				],
			})
		},
		destroyPositioner() {
			this.popper?.destroy()
			this.popper = null
		},
		onDocumentPointerDown(event) {
			if (!this.show) return
			const target = this.resolveTarget()
			const popover = this.$refs.popover
			if (target?.contains(event.target) || popover?.contains(event.target)) return
			this.setShow(false)
		},
		onKeydown(event) {
			if (this.show && event.key === 'Escape') this.setShow(false)
		},
	},
}
</script>
