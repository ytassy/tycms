<template>
<Mermaid :diagram="item.props.diagram ?? ''" :options="mermaidOptions" />
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
