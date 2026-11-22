<template>
	<img v-if="src" :src="src" :alt="text(item.props.alt)" class="img-fluid">
	<span v-else class="page-canvas-placeholder">Diagramme : créez le dessin dans les propriétés</span>
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
import { diagramImageUrl } from '../pageDiagram.js'

export default {
	mixins: [pageComponentCanvasMixin],
	computed: { src() { return diagramImageUrl(this.item.props.svg) } },
}
</script>
