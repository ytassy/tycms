<template>
<PageCanvasFileList :options="item.props" />
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
