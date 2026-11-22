<template>
	<div :class="plain ? 'mb-3' : 'alert alert-info mb-3'" role="note">
		<div v-if="field.label" class="fw-semibold">{{ field.label }}</div>
		<div v-if="field.description">{{ field.description }}</div>
	</div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
	field: { type: Object, required: true },
})

// La variante « plain » sert aux formulaires denses : le repère reste lisible sans encadré coloré.
const plain = computed(() => props.field.ui?.variant === 'plain')
</script>
