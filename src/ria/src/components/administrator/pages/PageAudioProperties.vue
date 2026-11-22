<template>
	<div class="page-field">
		<label for="page-audio-media">Fichier audio</label>
		<MediaFileInput id="page-audio-media" v-model="node.props.src" :space-id="store.current.space_id" media-type="audio"
			:allow-manual-input="false" :disabled="!store.current.canEdit || !canReadMedia" size="sm" />
		<small v-if="!canReadMedia" class="text-muted">La lecture des médias est nécessaire pour choisir un fichier audio.</small>
		<small class="text-muted d-block">MP3, WAV, OGG, M4A… La lecture dépend des formats pris en charge par le navigateur.</small>
	</div>
</template>
<script>
import MediaFileInput from '../../common/MediaFileInput.vue'

export default {
	components: { MediaFileInput },
	props: { selected: Object, context: Object, node: { type: Object, required: true } },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canReadMedia() { return this.acl.hasGlobalPermission('MEDIA_READ', this.store.current.space_id) || this.acl.hasGlobalPermission('MEDIA_CRUD', this.store.current.space_id) },
	},
}
</script>
