<template>
<div class="page-canvas-content page-canvas-html">
			<div v-if="text(item.props.content)" v-html="safeHtml"></div><span v-else class="page-canvas-placeholder">Contenu vide</span>
		</div>
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
