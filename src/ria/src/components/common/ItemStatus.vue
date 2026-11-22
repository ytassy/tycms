<template>
	<span class="badge" :class="badgeClass">{{ label }}</span>
</template>

<script>
export default {
	props: {
		status: {
			type: [Number, String],
			required: true,
		},
	},

	computed: {
		value() {
			return Number(this.status)
		},

		label() {
			return {
				1: 'Publié',
				0: 'Dépublié',
				[-1]: 'Archivé',
				[-2]: 'Corbeille',
			}[this.value] ?? 'Inconnu'
		},

		badgeClass() {
			return {
				1: 'text-bg-success',
				0: 'text-bg-secondary',
				[-1]: 'text-bg-warning',
				[-2]: 'text-bg-danger',
			}[this.value] ?? 'text-bg-dark'
		},
	},
}
</script>
