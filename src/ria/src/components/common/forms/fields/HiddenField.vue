<template>
	<input type="hidden" :value="modelValue ?? ''">
</template>

<script setup>
defineProps({
	field: { type: Object, required: true },
	modelValue: { default: '' },
})
</script>
