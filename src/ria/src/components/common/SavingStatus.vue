<template>
	<span>
		<span v-if="saved" class="text-success">{{ savedText }} <span aria-hidden="true">✓</span></span>
		<span v-else-if="saving" class="text-primary">{{ savingText }} <spinner size="sm" /></span>
	</span>
</template>

<script>

export default {
	props: {
		saving: { type: Boolean, default: false },
		saved: { type: Boolean, default: false },
		savingText: { type: String, default: 'Enregistrement' },
		savedText: { type: String, default: 'Enregistré' },
	},
}
</script>
