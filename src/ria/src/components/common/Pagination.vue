<template>
	<div class="d-inline-flex flex-wrap align-items-center gap-2">
		<nav v-if="totalRows > perPage" aria-label="Pagination">
			<ul class="pagination pagination-sm mb-0">
				<li class="page-item" :class="{ disabled: currentPage === 0 }">
					<button type="button" class="page-link" aria-label="Première page" @click="goTo(0)">«</button>
				</li>
				<li class="page-item" :class="{ disabled: currentPage === 0 }">
					<button type="button" class="page-link" aria-label="Page précédente" @click="goTo(currentPage - 1)">‹</button>
				</li>
				<li v-if="pages[0] > 0" class="page-item disabled"><span class="page-link">…</span></li>
				<li v-for="page in pages" :key="page" class="page-item" :class="{ active: page === currentPage }">
					<button type="button" class="page-link" @click="goTo(page)">{{ page + 1 }}</button>
				</li>
				<li v-if="pages.at(-1) < lastPage" class="page-item disabled"><span class="page-link">…</span></li>
				<li class="page-item" :class="{ disabled: currentPage === lastPage }">
					<button type="button" class="page-link" aria-label="Page suivante" @click="goTo(currentPage + 1)">›</button>
				</li>
				<li class="page-item" :class="{ disabled: currentPage === lastPage }">
					<button type="button" class="page-link" aria-label="Dernière page" @click="goTo(lastPage)">»</button>
				</li>
			</ul>
		</nav>

		<div class="btn-group btn-group-sm" role="group" aria-label="Lignes par page">
			<button
				v-for="option in visiblePerPageOptions"
				:key="option.value"
				type="button"
				class="btn btn-outline-secondary"
				:class="{ active: option.value === perPage }"
				@click="setPerPage(option.value)"
			>
				{{ option.label }}
			</button>
		</div>
	</div>
</template>

<script>
export default {
	props: {
		modelValue: { type: Number, default: 0 },
		totalRows: { type: Number, default: 0 },
		initialPerPage: { type: Number, default: 20 },
		perPageOptions: {
			type: [Array, Object],
			default: () => ({ 10: 10, 20: 20, 50: 50, 100: 100, All: Number.MAX_SAFE_INTEGER }),
		},
		buttons: { type: Number, default: 5 },
	},
	emits: ['update:modelValue', 'change'],

	data() {
		return { perPage: this.initialPerPage }
	},

	computed: {
		options() {
			return Array.isArray(this.perPageOptions)
				? this.perPageOptions.map((value) => ({ label: String(value), value }))
				: Object.entries(this.perPageOptions).map(([label, value]) => ({ label, value }))
		},
		lastPage() {
			return Math.max(0, Math.ceil(this.totalRows / this.perPage) - 1)
		},
		currentPage() {
			return Math.min(Math.max(0, this.modelValue), this.lastPage)
		},
		pages() {
			const size = Math.max(1, this.buttons)
			const half = Math.floor(size / 2)
			const start = Math.max(0, Math.min(this.currentPage - half, this.lastPage - size + 1))
			const end = Math.min(this.lastPage, start + size - 1)
			return Array.from({ length: end - start + 1 }, (_, index) => start + index)
		},
		visiblePerPageOptions() {
			return this.options.filter((option) => option.label === 'All' || this.totalRows > option.value || option.value <= this.initialPerPage)
		},
	},

	watch: {
		totalRows() {
			if (this.currentPage !== this.modelValue) this.goTo(this.currentPage)
		},
	},

	methods: {
		goTo(page) {
			const value = Math.min(Math.max(0, page), this.lastPage)
			this.$emit('update:modelValue', value)
			this.$emit('change', {
				currentPage: value,
				perPage: this.perPage,
				minIndex: value * this.perPage,
				maxIndex: Math.min((value + 1) * this.perPage - 1, this.totalRows - 1),
			})
		},
		setPerPage(perPage) {
			this.perPage = perPage
			this.goTo(Math.min(this.currentPage, this.lastPage))
		},
	},
}
</script>
