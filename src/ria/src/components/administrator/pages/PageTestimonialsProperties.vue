<template>
	<label class="page-field">Présentation
		<select v-model="node.props.mode" class="form-select form-select-sm"><option value="carousel">Carrousel</option><option value="grid">Grille</option></select>
	</label>
	<div class="page-style-grid">
		<label v-for="screen in screens" :key="screen.key" class="page-field">{{ screen.label }}
			<select v-model="node.props[`columns_${screen.key}`]" class="form-select form-select-sm"><option v-for="count in 6" :key="count" :value="count">{{ count }} carte(s)</option></select>
		</label>
	</div>
	<label class="page-field">Alignement
		<select v-model="node.props.align" class="form-select form-select-sm"><option value="center">Centré</option><option value="left">À gauche</option></select>
	</label>
	<label class="page-field">Auteur
		<select v-model="node.props.author_position" class="form-select form-select-sm"><option value="outside">Sous la carte</option><option value="inside">Dans la carte</option></select>
	</label>
	<label class="page-field">Portrait
		<select v-model="node.props.portrait_position" class="form-select form-select-sm"><option value="author">Près du nom</option><option value="top">En haut de la carte</option></select>
	</label>
	<label class="page-field">Forme du portrait
		<select v-model="node.props.portrait_shape" class="form-select form-select-sm"><option value="circle">Rond</option><option value="rounded">Arrondi</option><option value="square">Carré</option></select>
	</label>
	<label class="page-field"><input v-model="node.props.equal_height" type="checkbox" class="form-check-input me-2">Uniformiser la hauteur des cartes</label>
	<label class="page-field"><input v-model="node.props.show_quotes" type="checkbox" class="form-check-input me-2">Guillemets décoratifs</label>
	<details v-if="node.props.mode === 'carousel'" class="mb-3">
		<summary class="page-panel-heading">Navigation du carrousel</summary>
		<label class="page-field"><input v-model="node.props.show_arrows" type="checkbox" class="form-check-input me-2">Flèches</label>
		<label class="page-field"><input v-model="node.props.show_dots" type="checkbox" class="form-check-input me-2">Points de navigation</label>
		<label class="page-field"><input v-model="node.props.autoplay" type="checkbox" class="form-check-input me-2">Lecture automatique</label>
		<label v-if="node.props.autoplay" class="page-field">Délai entre les défilements (secondes)
			<input type="number" min="2" max="60" step="1" :value="node.props.interval / 1000" class="form-control form-control-sm" @change="setIntervalValue($event)">
		</label>
		<p class="small text-muted">Pause au survol ou au focus. La préférence de réduction des animations désactive le départ automatique.</p>
	</details>

	<h3 class="page-panel-heading">Témoignages · {{ entries.length }} / 100</h3>
	<label class="page-field">Témoignage à modifier
		<select v-model="activeIndex" class="form-select form-select-sm"><option v-for="(entry, index) in entries" :key="index" :value="index">{{ index + 1 }} — {{ text(entry.author) || 'Sans nom' }}</option></select>
	</label>
	<div class="d-flex flex-wrap gap-1 mb-3">
		<button type="button" class="btn btn-outline-primary btn-sm" :disabled="disabled || entries.length >= 100" @click="addEntry">+ Ajouter</button>
		<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="disabled || entries.length >= 100" @click="duplicateEntry">Dupliquer</button>
		<button type="button" class="btn btn-outline-secondary btn-sm" title="Monter" :disabled="disabled || activeIndex === 0" @click="moveEntry(-1)">↑</button>
		<button type="button" class="btn btn-outline-secondary btn-sm" title="Descendre" :disabled="disabled || activeIndex === entries.length - 1" @click="moveEntry(1)">↓</button>
		<button type="button" class="btn btn-outline-danger btn-sm" :disabled="disabled || entries.length <= 1" @click="removeEntry">Supprimer</button>
	</div>
	<template v-if="activeEntry">
		<LocalizedTextField :key="`text-${node.id}-${activeIndex}`" v-model="activeEntry.text" label="Témoignage" mode="rich" :max-length="20000" :locales="locales" :locale="store.previewLocale" :space-id="store.current.space_id" :disabled="disabled" />
		<LocalizedTextField v-model="activeEntry.author" label="Auteur" :max-length="300" :locales="locales" :locale="store.previewLocale" :space-id="store.current.space_id" :disabled="disabled" />
		<LocalizedTextField v-model="activeEntry.role" label="Fonction / entreprise (facultatif)" :locales="locales" :locale="store.previewLocale" :space-id="store.current.space_id" :disabled="disabled" />
		<div class="page-field"><label>Portrait facultatif</label><MediaFileInput v-model="activeEntry.portrait" :space-id="store.current.space_id" media-type="image" :disabled="disabled" /></div>
		<label class="page-field">Note
			<select v-model="activeEntry.rating" class="form-select form-select-sm"><option :value="null">Ne pas afficher de note</option><option v-for="rating in [0, 1, 2, 3, 4, 5]" :key="rating" :value="rating">{{ rating }} / 5</option></select>
		</label>
	</template>
	<PageCollectionStyleFields v-model="node.props.testimonials_style" :fields="testimonialStyleFields" :disabled="disabled" />
</template>
<script>
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import MediaFileInput from '../../common/MediaFileInput.vue'
import { selectLocalizedText } from '../../common/localizedText.js'
import PageCollectionStyleFields from './PageCollectionStyleFields.vue'
import { testimonialStyleFields } from '../../common/pageCollectionStyle.js'

export default {
	components: { LocalizedTextField, MediaFileInput, PageCollectionStyleFields },
	props: { node: Object, selected: Object, context: Object },
	data: () => ({ activeIndex: 0, testimonialStyleFields, screens: [
		{ key: 'desktop', label: 'Ordinateur' }, { key: 'tablet', label: 'Tablette' }, { key: 'mobile', label: 'Mobile' },
	] }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		disabled() { return !this.store.current.canEdit },
		locales() { return this.store.currentSpaceLanguages },
		entries() { return this.node.props.testimonials },
		activeEntry() { return this.entries[this.activeIndex] },
	},
	watch: { 'node.id'() { this.activeIndex = 0 }, 'entries.length'(length) { this.activeIndex = Math.min(this.activeIndex, length - 1) } },
	methods: {
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		setIntervalValue(event) {
			const value = Number(event.target.value)
			if (Number.isInteger(value) && value >= 2 && value <= 60) this.node.props.interval = value * 1000
			else event.target.value = this.node.props.interval / 1000
		},
		addEntry() {
			if (this.disabled || this.entries.length >= 100) return
			this.entries.push({ text: '', author: '', role: '', portrait: '', rating: 5 })
			this.activeIndex = this.entries.length - 1
		},
		duplicateEntry() {
			if (this.disabled || this.entries.length >= 100) return
			this.entries.splice(this.activeIndex + 1, 0, JSON.parse(JSON.stringify(this.activeEntry)))
			this.activeIndex++
		},
		moveEntry(delta) {
			const target = this.activeIndex + delta
			if (this.disabled || target < 0 || target >= this.entries.length) return
			const [entry] = this.entries.splice(this.activeIndex, 1)
			this.entries.splice(target, 0, entry)
			this.activeIndex = target
		},
		async removeEntry() {
			if (this.disabled || this.entries.length <= 1) return
			const node = this.node
			const entry = this.activeEntry
			if (!await this.askYesNoAsync('Supprimer ce témoignage ?')) return

			// Une confirmation tardive ne doit jamais supprimer un autre témoignage.
			if (this.disabled || this.node !== node || this.entries.length <= 1) return
			const index = this.entries.indexOf(entry)
			if (index >= 0) this.entries.splice(index, 1)
		},
	},
}
</script>
