<template>
	<div class="data-table">
		<DataTableToolbar
			:selection-enabled="selectionEnabled"
			:count-selected="table.countSelected.value"
			:all-items-are-selected="table.allItemsAreSelected.value"
			:all-filtered-items-are-selected="table.allFilteredItemsAreSelected.value"
			:items-filtered="table.itemsFiltered.value"
			:items-selected="table.selectedItems.value"
			:export-csv-enabled="exportCsvEnabled"
			:pagination-enabled="paginationEnabled"
			:current-page="table.currentPage.value"
			:total-rows="table.sortedItems.value.length"
			:initial-per-page="initialPerPage"
			:per-page-options="perPageOptions"
			@toggle-selection="table.toggleAllSelection"
			@export-csv="table.exportToCsv"
			@pagination-change="table.changePage"
		>
			<template #buttons="slotProps"><slot name="buttons" v-bind="slotProps" /></template>
			<template #after_export><slot name="after_export" /></template>
			<template #before_pagination><slot name="before_pagination" /></template>
			<template #after_pagination><slot name="after_pagination" /></template>
		</DataTableToolbar>

		<div class="data-table__content" :class="{ 'overflow-auto': autoScrollEnabled }">
			<table :class="['table mt-2 mb-0', tableClass]">
				<thead>
					<tr>
						<th v-if="selectionEnabled" :class="headClass()" data-field="selection"></th>
						<th
							v-for="field in fields"
							:key="field.key"
							:class="headClass(field)"
							:style="field.head_style || null"
							:data-field="field.key"
							@click="table.sortBy(field)"
						>
							<div class="d-flex align-items-center">
								<div class="flex-grow-1">
									<slot :name="'head_' + field.key" :field="field"><span v-html="field.label"></span></slot>
								</div>
								<button
									v-if="!filtersOpened && table.canFilter(field)"
									type="button"
									class="btn btn-link btn-sm p-0 ms-1"
									aria-label="Filtrer"
									@click.stop="openFilters(field)"
								>
									<i class="fa fa-search"></i>
								</button>
								<i v-if="field.sortable" :class="sortIcon(field)"></i>
								<button
									v-if="table.hasFilterValue(field)"
									type="button"
									class="btn btn-link btn-sm text-danger p-0 ms-1"
									aria-label="Réinitialiser le filtre"
									@click.stop="resetFilter(field)"
								>
									<i class="fa fa-filter"></i>
								</button>
							</div>
						</th>
					</tr>
					<tr v-if="hasSubHead">
						<th v-if="selectionEnabled" data-field="selection"></th>
						<th v-for="field in fields" :key="field.key" :data-field="field.key">
							<slot :name="'sub_head_' + field.key" :field="field">
								<span v-if="field.sub_label !== undefined" v-html="field.sub_label"></span>
							</slot>
						</th>
					</tr>
					<tr v-if="filtersOpened" class="data-table__filters">
						<th v-if="selectionEnabled" data-field="selection"></th>
						<th v-for="field in fields" :key="field.key" :data-field="field.key">
							<template v-if="table.canFilter(field)">
								<div class="input-group input-group-sm">
									<button type="button" class="btn btn-outline-secondary" @click="table.toggleFilterType(field)">
										{{ table.filterTypes.value[field.key] === 'ne' ? '≠' : '=' }}
									</button>
									<input
										v-if="field.filter === 'search'"
										:ref="(element) => setFilterRef(field.key, element)"
										:value="table.filters.value[field.key]"
										type="search"
										class="form-control"
										@input="table.filters.value[field.key] = $event.target.value"
									/>
									<checkboxes_dropdown
										v-else-if="field.filter === 'select'"
										:model-value="table.filters.value[field.key]"
										:options="table.selectOptions(field)"
										@update:model-value="table.filters.value[field.key] = $event"
									/>
									<slot
										v-else-if="field.filter === 'custom'"
										:name="'filter_' + field.key"
										:field="field"
										:filters="table.filters.value"
									/>
								</div>
							</template>
						</th>
					</tr>
				</thead>

				<tbody>
					<template v-for="(item, index) in table.pagedItems.value" :key="itemKey(item, index)">
						<tr :class="rowClass(item)" :data-id="item.id" :data-name="item.name" @click="clickRow(item)">
							<td
								v-if="selectionEnabled"
								class="text-center align-middle cursor-pointer"
								data-field="selection"
								@click.stop="table.toggleSelection(item)"
							>
								<i :class="table.isSelected(item) ? 'fa fa-check-square text-primary' : 'fa fa-square-o text-primary'"></i>
							</td>
							<component
								:is="field.th ? 'th' : 'td'"
								v-for="(field, fieldIndex) in fields"
								:key="field.key"
								:class="cellClass(field, fieldIndex, item)"
								:style="field.style || null"
								v-propagate-to-input="field.propagate_click_inside === true"
								@click="clickCell(field, item, $event)"
							>
								<slot :name="'cell_' + field.key" :item="item" :index="index">
									<span>{{ formatCell(field, item) }}</span>
								</slot>
							</component>
						</tr>
						<tr v-if="table.isOpened(item)">
							<td :colspan="fields.length + (selectionEnabled ? 1 : 0)" class="data-table__nested">
								<slot name="nested_row" :item="item" />
							</td>
						</tr>
					</template>
					<tr v-if="table.pagedItems.value.length === 0">
						<td :colspan="fields.length + (selectionEnabled ? 1 : 0)" class="text-center text-muted py-3">
							<slot name="empty">Aucun résultat.</slot>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div v-if="counterEnabled" class="text-end small mt-1">
			{{ rangeStart }}–{{ rangeEnd }} / {{ table.itemsFiltered.value.length }}
			<template v-if="table.itemsFiltered.value.length !== items.length"> ({{ items.length }} au total)</template>
		</div>
	</div>
</template>

<script>
import { computed, nextTick, ref } from 'vue'
import DataTableToolbar from './DataTableToolbar.vue'
import { useDataTable } from './useDataTable'

export default {
	components: { DataTableToolbar },
	props: {
		fields: { type: Array, default: () => [] },
		items: { type: Array, default: () => [] },
		selection: { type: Array, default: () => [] },
		openedRows: { type: Array, default: () => [] },
		trClass: { type: [String, Array, Object], default: '' },
		tableClass: { type: [String, Array, Object], default: '' },
		initialPerPage: { type: Number, default: 20 },
		perPageOptions: { type: [Array, Object], default: () => ({ 10: 10, 20: 20, 50: 50, 100: 100, All: Number.MAX_SAFE_INTEGER }) },
		selectionEnabled: { type: Boolean, default: false },
		stickyHeader: { type: Boolean, default: true },
		autoScrollEnabled: { type: Boolean, default: false },
		paginationEnabled: { type: Boolean, default: false },
		clickableRow: { type: Boolean, default: false },
		rowHover: { type: Boolean, default: false },
		onClickOpenRow: { type: Boolean, default: false },
		singleRowOpened: { type: Boolean, default: true },
		exportCsvEnabled: { type: Boolean, default: false },
		exportCsvFileName: { type: String, default: 'export.csv' },
		counterEnabled: { type: Boolean, default: true },
		filtersDefaultOpened: { type: Boolean, default: false },
		itemKeyPropertyName: { type: String, default: 'id' },
		itemGetKeyCallback: { type: Function, default: null },
		defaultSortKey: { type: String, default: null },
		defaultSortKeyDesc: { type: Boolean, default: false },
	},
	emits: ['click:row', 'datatableSort', 'update:selection', 'update:openedRows', 'update:row-opened'],

	setup(props, { emit, expose }) {
		const table = useDataTable(props, emit)
		const filtersOpened = ref(props.filtersDefaultOpened)
		const filterRefs = {}
		const hasSubHead = computed(() => props.fields.some((field) => field.sub_label !== undefined))
		const rangeStart = computed(() => table.pagedItems.value.length ? table.currentPage.value * table.perPage.value + 1 : 0)
		const rangeEnd = computed(() => rangeStart.value + Math.max(0, table.pagedItems.value.length - 1))

		const setFilterRef = (key, element) => { filterRefs[key] = element }
		const openFilters = async (field) => {
			filtersOpened.value = true
			await nextTick()
			filterRefs[field.key]?.focus()
		}
		const resetFilter = (field) => {
			table.resetFilter(field)
			if (!props.fields.some(table.hasFilterValue)) filtersOpened.value = false
		}
		const sortIcon = (field) => {
			if (table.sortColumn.value !== field.key) return 'fa fa-sort ms-1'
			return table.sortDescending.value ? 'fa fa-sort-desc ms-1' : 'fa fa-sort-asc ms-1'
		}
		const formatCell = (field, item) => field.formatter ? field.formatter(item) : item[field.key]
		const itemKey = (item, index) => props.itemGetKeyCallback ? props.itemGetKeyCallback(item) : item[props.itemKeyPropertyName] ?? index
		const headClass = (field = null) => ['align-middle', { 'data-table__sticky-header': props.stickyHeader, 'cursor-pointer': field?.sortable }, field?.head_class]
		const rowClass = (item) => [props.trClass, {
			'table-primary': table.isSelected(item),
			'cursor-pointer': props.clickableRow,
			'data-table__row-hover': props.rowHover,
		}]
		const cellClass = (field, index, item) => [field.class, { 'data-table__nested-opened': index === 0 && !props.selectionEnabled && table.isOpened(item), 'data-table__hover': field.hover }]
		const clickCell = (field, item, event) => {
			if (!field.on_click) return
			field.on_click(item, event)
			event.stopPropagation()
		}
		const clickRow = (item) => {
			if (!props.clickableRow) return
			emit('click:row', item)
			if (props.onClickOpenRow) table.toggleOpened(item)
		}

		expose({ closeAll: table.closeAll, itemsFiltered: table.itemsFiltered, selectedItems: table.selectedItems, openedItems: table.openedItems })
		return {
			table, filtersOpened, hasSubHead, rangeStart, rangeEnd, setFilterRef, openFilters, resetFilter,
			sortIcon, formatCell, itemKey, headClass, rowClass, cellClass, clickCell, clickRow,
		}
	},
}
</script>

<style scoped>
.data-table__sticky-header {
	position: sticky;
	top: 0;
	z-index: 1;
	background: var(--bs-light);
}

.data-table__filters th {
	min-width: 8rem;
}

.data-table__nested {
	background-color: var(--bs-tertiary-bg);
}

.data-table__nested-opened {
	border-left: 3px solid var(--bs-primary);
}

.data-table__hover:hover,
.data-table__row-hover:hover > td,
.data-table__row-hover:hover > th {
	background-color: var(--bs-tertiary-bg);
}
</style>
