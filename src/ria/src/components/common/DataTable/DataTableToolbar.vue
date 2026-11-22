<template>
	<div class="d-flex flex-wrap align-items-center gap-2">
		<button v-if="selectionEnabled" type="button" class="btn btn-sm btn-outline-primary" @click="$emit('toggle-selection')">
			<i :class="allFilteredItemsAreSelected ? 'fa fa-check-square' : countSelected ? 'fa fa-minus-square' : 'fa fa-square-o'"></i>
			<span v-if="countSelected" class="ms-1">{{ countSelected }}</span>
		</button>
		<slot
			name="buttons"
			:items-filtered="itemsFiltered"
			:items-selected="itemsSelected"
			:count-selected="countSelected"
			:all-items-are-selected="allItemsAreSelected"
			:all-filtered-items-are-selected="allFilteredItemsAreSelected"
		/>
		<button v-if="exportCsvEnabled" type="button" class="btn btn-sm btn-outline-secondary" @click="$emit('export-csv')">
			<i class="fa fa-download"></i><span class="ms-1">Télécharger</span>
		</button>
		<slot name="after_export" />
		<slot name="before_pagination" />
		<pagination
			v-if="paginationEnabled"
			class="ms-auto"
			:model-value="currentPage"
			:total-rows="totalRows"
			:initial-per-page="initialPerPage"
			:per-page-options="perPageOptions"
			@change="$emit('pagination-change', $event)"
		/>
		<slot name="after_pagination" />
	</div>
</template>

<script>

export default {
	props: {
		selectionEnabled: Boolean,
		countSelected: { type: Number, required: true },
		allItemsAreSelected: Boolean,
		allFilteredItemsAreSelected: Boolean,
		itemsFiltered: { type: Array, required: true },
		itemsSelected: { type: Array, required: true },
		exportCsvEnabled: Boolean,
		paginationEnabled: Boolean,
		currentPage: { type: Number, required: true },
		totalRows: { type: Number, required: true },
		initialPerPage: { type: Number, required: true },
		perPageOptions: { type: [Array, Object], required: true },
	},
	emits: ['toggle-selection', 'export-csv', 'pagination-change'],
}
</script>
