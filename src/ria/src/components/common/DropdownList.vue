<template>
	<b-dropdown ref="dropdown" :variant="variant" :lazy="true" :no-caret="noCaret" tag="div" @show="onShow">
		<template #button-content><slot /></template>

		<div class="p-3">
			<div class="pb-2">
				<input ref="searchInput" v-model="search" class="form-control form-control-sm m-0" placeholder="Rechercher">
			</div>

			<slot name="description" />

			<div class="overflow-auto p-1" style="max-height: 30rem; width: 30rem; max-width: 30rem">
				<div class="list-group">
					<button
						v-for="([key, item], index) in filteredItems"
						:key="String(key)"
						type="button"
						class="list-group-item list-group-item-action p-2"
						@click="select(key, item)"
					>
						<slot name="item" :item="item" :index="index">{{ displayItem(item) }}</slot>
					</button>
				</div>
			</div>
		</div>
	</b-dropdown>
</template>

<script>

export default {
	props: {
		items: { type: [Array, Object], required: true },
		field: { type: String, default: null },
		searchOnFields: { type: Array, default: null },
		variant: { type: String, default: 'primary' },
		noCaret: { type: Boolean, default: false },
	},
	emits: ['select', 'show'],

	data() {
		return { search: '' }
	},

	computed: {
		entries() {
			return Object.entries(this.items)
		},

		filteredItems() {
			const term = this.search.trim().toLocaleLowerCase()
			if (term === '') return this.entries

			return this.entries.filter(([, item]) => {
				if (typeof item === 'string') return item.toLocaleLowerCase().includes(term)
				const fields = this.searchOnFields ?? (this.field === null ? [] : [this.field])
				return fields.some((field) => String(item[field] ?? '').toLocaleLowerCase().includes(term))
			})
		},
	},

	methods: {
		displayItem(item) {
			if (typeof item === 'string') return item
			return this.field === null ? JSON.stringify(item) : String(item[this.field] ?? '')
		},

		select(key, item) {
			this.$refs.dropdown?.hide()
			this.$emit('select', Array.isArray(this.items) ? item : key)
		},

		onShow() {
			this.$emit('show')
			this.$nextTick(() => this.$refs.searchInput?.focus())
		},
	},
}
</script>
