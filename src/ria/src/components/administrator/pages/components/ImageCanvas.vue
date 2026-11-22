<template>
<div class="page-canvas-image">
			<button v-if="safeImageSrc && item.props.click_action === 'enlarge'" type="button" class="page-image-enlarge" :style="imageStyle" aria-label="Agrandir l’image" @click.stop="openLightbox">
				<img :src="safeImageSrc" :alt="text(item.props.alt)" class="img-fluid" :style="{ width: '100%', maxHeight: imageStyle.maxHeight }">
			</button>
			<img v-else-if="safeImageSrc" :src="safeImageSrc" :alt="text(item.props.alt)" class="img-fluid" :style="imageStyle">
			<span v-else class="page-canvas-placeholder">Image : ajoutez une URL locale valide</span>
		</div>
<ImageLightbox v-if="lightboxSrc" :src="lightboxSrc" :alt="text(item.props.alt)" @close="lightboxSrc = ''" />
</template>
<script>
import pageComponentCanvasMixin from '../pageComponentCanvasMixin.js'
export default { mixins: [pageComponentCanvasMixin] }
</script>
