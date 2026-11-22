<template>
	<span
		:class="['spinner-border', size === null ? null : `spinner-border-${size}`]"
		role="status"
		aria-label="Chargement"
	/>
</template>

<script>
export default {
	props: {
		size: {
			type: String,
			default: null,
		},
	},
}
</script>
