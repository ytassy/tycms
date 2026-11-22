<template>
	<div class="font-table-preview">
		<span v-if="loading" class="text-body-secondary small" role="status">Chargement de l’aperçu…</span>
		<span v-else-if="error" class="text-body-secondary small" role="status">{{ error }}</span>
		<span v-else :style="previewStyle">{{ sample }}</span>
	</div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { FONT_PREVIEW_SAMPLE, loadFontPreview } from '../../common/forms/fields/fontPreview.js'
import { createCatalogPreview } from './catalogPreview.js'

const props = defineProps({
	font: { type: Object, required: true },
	local: { type: Boolean, default: false },
})
const sample = FONT_PREVIEW_SAMPLE
const loading = ref(true)
const error = ref('')
const previewStyle = ref({})
let request = null
let closed = false

onMounted(async () => {
	try {
		if (props.local) {
			// Une police installée utilise sa feuille locale, y compris lorsqu'elle est désactivée.
			previewStyle.value = { fontFamily: JSON.stringify(props.font.family), fontSize: '20px', lineHeight: 1.5 }
			await loadFontPreview({ data: { fontName: props.font.family, fontCssUrl: props.font.cssUrl } }, sample)
		} else {
			request = createCatalogPreview(props.font)
			const { family, weight, style } = request.info
			previewStyle.value = { fontFamily: family, fontWeight: weight, fontStyle: style, fontSize: '20px', lineHeight: 1.5 }
			await request.ready
		}
	} catch (exception) {
		if (!closed) error.value = exception.message || 'L’aperçu est indisponible.'
	} finally {
		if (!closed) loading.value = false
	}
})

onBeforeUnmount(() => {
	closed = true
	request?.dispose()
})
</script>
