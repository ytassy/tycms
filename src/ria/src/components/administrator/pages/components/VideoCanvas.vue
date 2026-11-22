<template>
<PageCanvasVideo :options="item.props" :space-id="store.current.space_id" />
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
