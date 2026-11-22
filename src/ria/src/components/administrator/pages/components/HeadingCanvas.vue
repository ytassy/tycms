<template>
<component :is="headingTag" class="mb-0">{{ text(item.props.text) || 'Titre sans texte' }}</component>
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
