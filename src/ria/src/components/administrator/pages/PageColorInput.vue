<template>
	<ColorInput v-bind="$attrs" :model-value="modelValue" :template-colors="templateColors" @update:model-value="$emit('update:modelValue', $event)" />
</template>
<script>
import ColorInput from '../../common/ColorInput.vue'

export default {
	components: { ColorInput },
	inheritAttrs: false,
	props: { modelValue: { type: String, default: '' } },
	emits: ['update:modelValue'],
	computed: {
		templateColors() { return this.stores.useAdminPagesStore().editContext.plg_core_template_colors ?? [] },
	},
}
</script>
