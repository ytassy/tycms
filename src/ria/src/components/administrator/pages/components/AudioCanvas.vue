<template>
	<AudioPlayer :src="item.props.src" :audio_style="item.props.audio_style" />
	<small v-if="!item.props.src" class="page-canvas-placeholder">Audio : choisissez un fichier dans les médias du Space.</small>
</template>
<script>
import AudioPlayer from '../../../page/AudioPlayer.vue'

export default {
	components: { AudioPlayer },
	props: { item: { type: Object, required: true }, node: Object, context: Object, viewport: String, components: Array },
}
</script>
