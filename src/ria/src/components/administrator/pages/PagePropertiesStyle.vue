<template>
	<fieldset :disabled="!store.current.canEdit">
		<PageAudioStyle v-if="node.type === 'core.audio'" :node="node" />
		<h3 class="page-panel-heading">Alignement</h3>
		<label class="page-field">Alignement du texte
			<select :value="textAlign" class="form-select form-select-sm" @change="setTextAlign($event.target.value)">
				<option value="">Hérité</option><option value="left">Gauche</option>
				<option value="center">Centre</option><option value="right">Droite</option>
			</select>
		</label>
		<h3 class="page-panel-heading">Espacements</h3>
		<p class="small text-muted mb-2">Exemples : 0, 12px ou -1rem pour une marge.</p>
		<div v-for="group in spacingGroups" :key="group.id" class="mb-3">
			<strong class="page-style-group-label">{{ group.label }}</strong>
			<div class="page-style-grid">
				<label v-for="side in sides" :key="side.id" class="page-field">{{ side.label }}
					<input :value="style[`${group.id}_${side.id}`] ?? ''" class="form-control form-control-sm" placeholder="0px"
						@input="setStyle(`${group.id}_${side.id}`, $event.target.value)">
				</label>
			</div>
		</div>
		<h3 class="page-panel-heading">Bordure</h3>
		<strong class="page-style-group-label">Épaisseur par côté</strong>
		<div class="page-style-grid">
			<label v-for="side in sides" :key="side.id" class="page-field">{{ side.label }}
				<input :value="style[`border_${side.id}`] ?? ''" class="form-control form-control-sm" placeholder="1px"
					@input="setStyle(`border_${side.id}`, $event.target.value)">
			</label>
		</div>
		<label class="page-field">Type
			<select :value="style.border_style ?? ''" class="form-select form-select-sm" @change="setStyle('border_style', $event.target.value)">
				<option value="">Par défaut</option><option value="solid">Continue</option><option value="dashed">Tirets</option>
				<option value="dotted">Points</option><option value="double">Double</option>
			</select>
		</label>
		<div class="page-field">
			<label for="page-border-color">Couleur de bordure</label>
			<color_input id="page-border-color" :model-value="style.border_color ?? ''" size="sm" allow-short-hex
				@update:model-value="setStyle('border_color', $event)" />
		</div>
		<label class="page-field">Rayon des angles
			<input :value="style.border_radius ?? ''" class="form-control form-control-sm" placeholder="8px" @input="setStyle('border_radius', $event.target.value)">
		</label>
		<h3 class="page-panel-heading">Couleurs et fond</h3>
		<div class="page-field">
			<label for="page-text-color">Couleur du texte</label>
			<color_input id="page-text-color" :model-value="style.color ?? ''" size="sm" allow-short-hex
				@update:model-value="setStyle('color', $event)" />
		</div>
		<div class="page-field">
			<label for="page-link-color">Couleur des liens</label>
			<color_input id="page-link-color" :model-value="style.link_color ?? ''" size="sm" allow-short-hex
				@update:model-value="setStyle('link_color', $event)" />
			<small>Liens de texte, y compris au survol. Les boutons gardent leurs couleurs.</small>
		</div>
		<div class="page-field">
			<label for="page-background-color">Couleur de fond</label>
			<color_input id="page-background-color" :model-value="style.background_color ?? ''" size="sm" allow-short-hex
				@update:model-value="setStyle('background_color', $event)" />
		</div>
		<div class="page-field">
			<label for="page-background-image">Image de fond</label>
			<MediaFileInput id="page-background-image" :model-value="style.background_image ?? ''" size="sm"
				:space-id="store.current.space_id" media-type="image" manual-input-mode="any"
				@update:model-value="setStyle('background_image', $event)" />
			<small>Chemin local commençant par /.</small>
		</div>
		<label class="page-field">Placement de l’image
			<select :value="backgroundPositionAxes ? 'custom' : (style.background_position ?? '')" class="form-select form-select-sm" @change="setBackgroundPosition($event.target.value)">
				<option value="">Par défaut</option><option value="center">Centre</option><option value="top">Haut</option>
				<option value="bottom">Bas</option><option value="left">Gauche</option><option value="right">Droite</option>
				<option value="custom">Personnalisé (X / Y)</option>
			</select>
		</label>
		<div v-if="backgroundPositionAxes" class="page-style-grid">
			<label v-for="axis in ['x', 'y']" :key="axis" class="page-field">{{ axis.toUpperCase() }} (%)
				<input type="number" min="0" max="100" step="1" :value="backgroundPositionAxes[axis]" class="form-control form-control-sm"
					@input="setBackgroundAxis(axis, $event)" @blur="$event.target.value = backgroundPositionAxes[axis]">
			</label>
		</div>
		<label class="page-field">Taille de l’image
			<select :value="style.background_size ?? ''" class="form-select form-select-sm" @change="setStyle('background_size', $event.target.value)">
				<option value="">Par défaut</option><option value="auto">Originale</option><option value="cover">Couvrir</option><option value="contain">Contenir</option>
			</select>
		</label>
		<div class="page-field">
			<label for="page-background-opacity">Opacité de l’image de fond</label>
			<div class="d-flex align-items-center gap-2">
				<input id="page-background-opacity" type="range" min="0" max="100" step="1"
					:value="style.background_image_opacity ?? '100'" class="form-range flex-grow-1 min-width-0 mb-0"
					@input="setPercentStyle('background_image_opacity', $event, '100')">
				<output for="page-background-opacity" class="text-body-secondary text-nowrap">{{ style.background_image_opacity ?? '100' }} %</output>
			</div>
		</div>
		<button v-if="!style.background_image_tint" type="button" class="btn btn-outline-secondary btn-sm mb-3"
			@click="setStyle('background_image_tint', '#FFFFFF4D')">Ajouter un filigrane</button>
		<div v-else>
			<div class="page-field">
				<label for="page-background-tint">Filigrane : couleur et opacité</label>
				<color_input id="page-background-tint" :model-value="style.background_image_tint" size="sm"
					@update:model-value="setStyle('background_image_tint', $event)" />
			</div>
			<button type="button" class="btn btn-outline-secondary btn-sm mb-3" @click="setStyle('background_image_tint', '')">Retirer le filigrane</button>
		</div>
		<h3 class="page-panel-heading">Positionnement</h3>
		<label class="page-field">Position
			<select :value="style.position ?? ''" class="form-select form-select-sm" @change="setStyle('position', $event.target.value)">
				<option value="">Par défaut</option><option value="static">Statique</option><option value="relative">Relative</option>
				<option value="absolute">Absolue</option><option value="sticky">Collante</option>
			</select>
		</label>
		<div class="page-style-grid">
			<label v-for="side in sides" :key="side.id" class="page-field">{{ side.label }}
				<input :value="style[`offset_${side.id}`] ?? ''" class="form-control form-control-sm" placeholder="0px"
					@input="setStyle(`offset_${side.id}`, $event.target.value)">
			</label>
		</div>
	</fieldset>
</template>

<script>
import PageColorInput from './PageColorInput.vue'
import { backgroundPositionAxes, styleSides } from './pageCommonStyle'
import MediaFileInput from '../../common/MediaFileInput.vue'
import PageAudioStyle from './PageAudioStyle.vue'

export default {
	components: { color_input: PageColorInput, MediaFileInput, PageAudioStyle },
	props: { node: { type: Object, required: true } },
	data: () => ({
		sides: styleSides,
		spacingGroups: [{ id: 'margin', label: 'Marge extérieure' }, { id: 'padding', label: 'Marge intérieure' }],
	}),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		style() { return this.node.props.common?.style ?? {} },
		textAlign() { return this.style.text_align ?? (!this.node.type ? this.node.props.align ?? '' : '') },
		backgroundPositionAxes() { return backgroundPositionAxes(this.style.background_position) },
	},
	methods: {
		setTextAlign(value) {
			if (!this.node.type) delete this.node.props.align
			this.setStyle('text_align', value)
		},
		setBackgroundPosition(value) { this.setStyle('background_position', value === 'custom' ? '50% 50%' : value) },
		setBackgroundAxis(axis, event) {
			const value = event.target.value
			if (!/^(?:100|[1-9]?[0-9])$/.test(value)) return
			const axes = this.backgroundPositionAxes ?? { x: 50, y: 50 }
			this.setStyle('background_position', `${axis === 'x' ? value : axes.x}% ${axis === 'y' ? value : axes.y}%`)
		},
		setPercentStyle(key, event, defaultValue) {
			const value = event.target.value
			if (/^(?:100|[1-9]?[0-9])$/.test(value)) this.setStyle(key, value === defaultValue ? '' : value)
		},
		setStyle(key, value) {
			if (!this.node.props.common) this.node.props.common = {}
			if (!this.node.props.common.style) this.node.props.common.style = {}
			if (value === '') delete this.node.props.common.style[key]
			else this.node.props.common.style[key] = value.trim()
		},
	},
}
</script>
