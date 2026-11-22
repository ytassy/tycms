<template>
	<div class="page-field">
		<label for="page-files-folder">Dossier des médias de cet espace</label>
		<div class="d-flex gap-2">
			<input id="page-files-folder" :value="folderLabel" class="form-control form-control-sm" placeholder="Aucun dossier sélectionné" :disabled="!canReadMedia" @change="setFolder($event.target.value)">
			<button type="button" class="btn btn-outline-primary btn-sm" :disabled="disabled || !canReadMedia" @click="pickerVisible = true">Choisir</button>
			<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="disabled || node.props.folder == null" @click="node.props.folder = null">Effacer</button>
		</div>
		<small class="text-muted">Chemin relatif aux médias. / désigne la racine. Les sous-dossiers sont exclus.</small>
		<p v-if="!canReadMedia" class="small text-muted">La lecture des médias est nécessaire pour choisir le dossier.</p>
	</div>
	<label v-for="option in flags" :key="option.key" class="page-field d-flex gap-2 align-items-center">
		<input type="checkbox" class="form-check-input" :checked="node.props[option.key] ?? option.defaultValue" @change="node.props[option.key] = $event.target.checked"> {{ option.label }}
	</label>
	<template v-for="index in 2" :key="index">
		<h3 class="page-panel-heading">Tri {{ index }}{{ index === 2 ? ' : départage le premier' : ' : prioritaire' }}</h3>
		<label class="page-field">Critère
			<select :value="node.props[`sort_${index}`] ?? 'name'" class="form-select form-select-sm" @change="node.props[`sort_${index}`] = $event.target.value">
				<option value="name">Nom du fichier</option><option value="extension">Extension</option><option value="modified">Date de modification</option><option value="size">Taille</option>
			</select>
		</label>
		<label class="page-field">De manière
			<select :value="node.props[`direction_${index}`] ?? 'asc'" class="form-select form-select-sm" @change="node.props[`direction_${index}`] = $event.target.value">
				<option value="asc">Ascendante</option><option value="desc">Descendante</option>
			</select>
		</label>
		<label class="page-field">Premiers éléments
			<input v-model="node.props[`first_${index}`]" maxlength="500" class="form-control form-control-sm" placeholder="pdf,png,jpeg,svg">
			<small class="text-muted">Sous-chaînes séparées par des virgules, dans l’ordre souhaité, sans distinction de casse. Les autres fichiers viennent après.</small>
		</label>
	</template>
	<MediaPicker :visible="pickerVisible" :space-id="store.current.space_id" directory-only @select="chooseFolder" @close="pickerVisible = false" />
</template>
<script>
import MediaPicker from '../../common/MediaPicker.vue'

export default {
	components: { MediaPicker },
	props: { selected: Object, context: Object, node: { type: Object, required: true } },
	data: () => ({ pickerVisible: false, flags: [
		{ key: 'compact', label: 'Mode compact : une ligne et petites icônes', defaultValue: false },
		{ key: 'show_date', label: 'Afficher la date de modification', defaultValue: true },
		{ key: 'show_size', label: 'Afficher la taille', defaultValue: true },
		{ key: 'show_thumbnails', label: 'Prévisualiser les images en tout petit', defaultValue: false },
		{ key: 'show_open', label: 'Afficher Ouvrir dans un nouvel onglet', defaultValue: true },
		{ key: 'show_download', label: 'Afficher Télécharger', defaultValue: true },
	] }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
		canReadMedia() { return this.acl.hasGlobalPermission('MEDIA_READ', this.store.current.space_id) || this.acl.hasGlobalPermission('MEDIA_CRUD', this.store.current.space_id) },
		folderLabel() { return this.node.props.folder == null ? '' : this.node.props.folder === '' ? '/' : this.node.props.folder },
	},
	watch: {
		disabled(value) { if (value) this.pickerVisible = false },
	},
	methods: {
		chooseFolder(entry) {
			if (!this.disabled && this.canReadMedia) this.node.props.folder = entry.path
		},
		setFolder(value) { this.node.props.folder = value.trim() === '/' ? '' : value.trim() || null },
	},
}
</script>
