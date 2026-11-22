<template>
<div class="page-field">
				<label for="page-component-image">Image</label>
				<MediaFileInput id="page-component-image" v-model="node.props.src" :space-id="store.current.space_id"
					media-type="image" manual-input-mode="any" size="sm" />
			</div>
			<label class="page-field">Au clic sur l’image
				<select :value="node.props.click_action ?? 'none'" class="form-select form-select-sm" @change="node.props.click_action = $event.target.value">
					<option value="none">Aucune action</option><option value="enlarge">Agrandir</option><option value="link">Ouvrir un lien</option>
				</select>
			</label>
			<PageLinkFields v-if="node.props.click_action === 'link'" :node="node" :space-id="store.current.space_id" :locale="store.previewLocale" :disabled="!store.current.canEdit" />
			<LocalizedTextField v-model="node.props.alt" label="Texte alternatif" :locales="locales" :locale="store.previewLocale"
				:space-id="store.current.space_id" :disabled="!store.current.canEdit" />
			<label v-for="dimension in imageDimensions" :key="dimension.id" class="page-field">{{ dimension.label }}
				<input v-model="node.props[dimension.id]" class="form-control form-control-sm" placeholder="300px ou 50%">
			</label>
		
</template>
<script>
import pageComponentFormMixin from '../pageComponentFormMixin.js'
export default { mixins: [pageComponentFormMixin] }
</script>
