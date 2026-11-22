<template>
<span :class="buttonClasses(item.props)" :style="buttonStyle(item.props)">{{ text(item.props.text) || 'Bouton' }}</span>
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
