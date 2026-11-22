<template>
	<div class="icon-picker d-inline-flex align-items-center gap-1">
		<button
			ref="trigger"
			type="button"
			:id="id"
			class="btn btn-outline-secondary d-inline-flex align-items-center gap-2"
			:aria-expanded="open"
			:disabled="disabled"
			@click="open = !open"
		>
			<i :class="modelValue || 'fa-solid fa-icons'" aria-hidden="true"></i>
			<span class="text-truncate">{{ modelValue || placeholder }}</span>
		</button>
		<button
			v-if="modelValue"
			type="button"
			class="btn btn-outline-secondary"
			title="Retirer l’icône"
			aria-label="Retirer l’icône"
			:disabled="disabled"
			@click="select('')"
		>
			<i class="fa-solid fa-xmark" aria-hidden="true"></i>
		</button>

		<b-popover v-model:show="open" :target="$refs.trigger" placement="bottom-start" popover-class="icon-picker-popover">
			<div class="icon-picker-content">
				<div class="input-group mb-2">
					<span class="input-group-text"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i></span>
					<input ref="searchInput" v-model="search" type="search" class="form-control" placeholder="Rechercher une icône" aria-label="Rechercher une icône">
				</div>

				<div class="icon-picker-grid" role="listbox" aria-label="Icônes Font Awesome">
					<button
						v-for="icon in filteredIcons"
						:key="icon"
						type="button"
						:class="['btn btn-outline-secondary icon-picker-icon', { active: modelValue === iconClass(icon) }]"
						:title="iconClass(icon)"
						:aria-label="icon"
						@click="select(iconClass(icon))"
					>
						<i :class="iconClass(icon)" aria-hidden="true"></i>
					</button>
				</div>

				<div v-if="filteredIcons.length === 0" class="text-muted text-center py-4">Aucune icône trouvée.</div>
				<div class="d-flex justify-content-between align-items-center mt-2 pt-2 border-top small text-muted">
					<span>{{ filteredIcons.length }} icône{{ filteredIcons.length > 1 ? 's' : '' }}</span>
					<button type="button" class="btn btn-outline-secondary btn-sm" @click="open = false">Fermer</button>
				</div>
			</div>
		</b-popover>
	</div>
</template>

<script>

const fallbackIcons = [
	'address-book', 'anchor', 'arrow-down', 'arrow-left', 'arrow-right', 'arrow-up', 'arrows-up-down-left-right',
	'award', 'bars', 'bell', 'book', 'bookmark', 'briefcase', 'building', 'calendar', 'camera', 'cart-shopping',
	'chart-bar', 'check', 'check-circle', 'chevron-down', 'chevron-left', 'chevron-right', 'chevron-up', 'circle',
	'cloud', 'code', 'cog', 'comment', 'compass', 'copy', 'download', 'ellipsis', 'envelope', 'eye', 'file',
	'filter', 'flag', 'folder', 'gear', 'globe', 'graduation-cap', 'heart', 'house', 'image', 'info-circle', 'key',
	'link', 'list', 'lock', 'magnifying-glass', 'map-marker', 'menu', 'minus', 'mobile-screen', 'music', 'paperclip',
	'pen', 'phone', 'play', 'plus', 'power-off', 'print', 'question-circle', 'save', 'search', 'server', 'share',
	'shield', 'shopping-cart', 'sliders', 'star', 'tag', 'thumbtack', 'trash', 'triangle-exclamation', 'truck', 'upload',
	'user', 'user-group', 'users', 'video', 'wifi', 'wrench', 'xmark',
]

export default {
	props: {
		id: { type: String, default: null },
		modelValue: { type: String, default: '' },
		placeholder: { type: String, default: 'Choisir une icône' },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue', 'change'],

	data() {
		return {
			open: false,
			search: '',
			icons: fallbackIcons,
		}
	},

	computed: {
		filteredIcons() {
			const search = this.search.trim().toLowerCase().replace(/^fa-/, '')
			if (search === '') return this.icons

			return this.icons.filter(icon => icon.includes(search))
		},
	},

	watch: {
		open(visible) {
			if (visible) this.$nextTick(() => this.$refs.searchInput?.focus())
		},
	},

	mounted() {
		const icons = this.getLoadedFontAwesomeIcons()
		if (icons.length > 0) this.icons = icons
	},

	methods: {
		iconClass(icon) {
			return `fa-solid fa-${icon}`
		},

		select(icon) {
			this.$emit('update:modelValue', icon)
			this.$emit('change', icon)
			this.open = false
		},

		getLoadedFontAwesomeIcons() {
			const namesByGlyph = new Map()

			for (const styleSheet of document.styleSheets) {
				if (!styleSheet.href?.includes('/fontawesome')) continue

				let rules
				try {
					rules = styleSheet.cssRules
				} catch (_) {
					continue
				}

				for (const rule of rules) {
					const glyph = rule.style?.getPropertyValue('--fa')
					if (!glyph || !rule.selectorText) continue

					for (const match of rule.selectorText.matchAll(/\.fa-([a-z0-9-]+)/g)) {
						if (!namesByGlyph.has(glyph)) namesByGlyph.set(glyph, match[1])
					}
				}
			}

			return [...namesByGlyph.values()].sort((first, second) => first.localeCompare(second))
		},
	},
}
</script>

<style>
.icon-picker > .btn:first-child {
	max-width: 100%;
}

.icon-picker-popover {
	width: min(40rem, calc(100vw - 1rem));
	max-width: min(40rem, calc(100vw - 1rem));
}

.icon-picker-content {
	width: 100%;
}

.icon-picker-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(2.5rem, 1fr));
	gap: .35rem;
	max-height: 250px;
	overflow-y: auto;
	padding: .1rem;
}

.icon-picker-icon {
	display: grid;
	place-items: center;
	aspect-ratio: 1;
	padding: .35rem;
}
</style>
