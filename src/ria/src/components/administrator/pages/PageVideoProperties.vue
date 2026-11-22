<template>
	<label class="page-field">Source vidéo
		<select :value="node.props.source ?? 'media'" class="form-select form-select-sm" @change="setSource($event.target.value)">
			<option value="media">Média du Space</option><option value="url">URL YouTube / Vimeo</option><option value="bunny">Bunny</option><option value="hls">HLS (.m3u8)</option>
		</select>
	</label>
	<div v-if="(node.props.source ?? 'media') === 'media'" class="page-field">
		<label for="page-video-media">Fichier vidéo</label>
		<MediaFileInput id="page-video-media" v-model="node.props.src" :space-id="store.current.space_id" media-type="video"
			:allow-manual-input="false" :disabled="disabled || !canReadMedia" size="sm" />
		<small v-if="!canReadMedia" class="text-muted">La lecture des médias est nécessaire pour choisir une vidéo.</small>
	</div>
	<label v-else-if="node.props.source === 'url'" class="page-field">URL de la vidéo
		<input v-model.trim="node.props.src" type="url" maxlength="2048" class="form-control form-control-sm" placeholder="https://www.youtube.com/watch?v=… ou https://vimeo.com/…">
	</label>
	<div v-else-if="node.props.source === 'hls'" class="page-field">
		<label for="page-video-hls">Playlist HLS (.m3u8)</label>
		<MediaFileInput id="page-video-hls" v-model="node.props.src" :space-id="store.current.space_id" media-type="hls" manual-input-mode="any"
			:disabled="disabled" :picker-disabled="!canReadMedia" size="sm" />
		<small class="text-muted">Choisissez une playlist dans un dossier média, ou saisissez une référence de bibliothèque comme <code>bib1:kenyoku/playlist.m3u8</code>.</small>
		<small v-if="!canReadMedia" class="text-muted d-block">La consultation des médias est nécessaire pour ouvrir le sélecteur ; une bibliothèque externe reste saisissable.</small>
	</div>
	<template v-else>
		<label class="page-field">Identifiant de bibliothèque Bunny<input v-model.trim="node.props.library_id" inputmode="numeric" maxlength="15" class="form-control form-control-sm"></label>
		<label class="page-field">Identifiant de vidéo Bunny<input v-model.trim="node.props.video_id" maxlength="100" class="form-control form-control-sm"></label>
		<p class="small text-muted">Les clés de cette bibliothèque sont celles de la configuration Core du Space. Le lecteur apparaît dans le rendu fidèle et sur le site public.</p>
	</template>
</template>
<script>
import MediaFileInput from '../../common/MediaFileInput.vue'

export default {
	components: { MediaFileInput },
	props: { selected: Object, context: Object, node: { type: Object, required: true } },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
		canReadMedia() { return this.acl.hasGlobalPermission('MEDIA_READ', this.store.current.space_id) || this.acl.hasGlobalPermission('MEDIA_CRUD', this.store.current.space_id) },
	},
	methods: {
		setSource(source) {
			if (this.disabled) return
			this.node.props.source = source
			this.node.props.src = ''
		},
	},
}
</script>
