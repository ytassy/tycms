import { computed, ref, toRef, watch } from 'vue'

export function useDataTable(props, emit) {
	const fields = toRef(props, 'fields')
	const items = toRef(props, 'items')
	const filters = ref({})
	const filterTypes = ref({})
	const sortColumn = ref(props.defaultSortKey)
	const sortDescending = ref(props.defaultSortKeyDesc)
	const currentPage = ref(0)
	const perPage = ref(props.initialPerPage)
	const selectedItems = ref([...props.selection])
	const openedItems = ref([...props.openedRows])

	const canFilter = (field) => field.filter !== undefined && field.filter !== 'none'
	const initializeFilters = () => {
		filters.value = Object.fromEntries(fields.value.map((field) => [field.key, field.filter === 'select' ? [] : '']))
		filterTypes.value = Object.fromEntries(fields.value.map((field) => [field.key, 'eq']))
	}
	const hasFilterValue = (field) => {
		const value = filters.value[field.key]
		return Array.isArray(value) ? value.length > 0 : String(value ?? '').trim() !== ''
	}
	const selectOptions = (field) => Object.fromEntries(
		[...new Set(items.value
			.map((item) => item[field.filterKey || field.key])
			.filter((value) => value !== null && value !== undefined))]
			.map((value) => [String(value), value]),
	)

	initializeFilters()
	watch(fields, initializeFilters, { deep: true })

	const itemsFiltered = computed(() => items.value.filter((item) => fields.value.every((field) => {
		if (!canFilter(field) || !hasFilterValue(field)) return true
		const filter = filters.value[field.key]
		const formattedValue = field.formatter ? field.formatter(item) : item[field.filterKey || field.key]
		const value = String(formattedValue ?? '').toLocaleLowerCase()
		const matches = Array.isArray(filter)
			? filter.map(String).includes(String(item[field.filterKey || field.key] ?? ''))
			: value.includes(String(filter).trim().toLocaleLowerCase())
		return filterTypes.value[field.key] === 'ne' ? !matches : matches
	})))
	const sortedItems = computed(() => {
		const field = fields.value.find((candidate) => candidate.key === sortColumn.value)
		if (!field) return itemsFiltered.value
		const key = field.sortKey || field.key
		const compare = field.sortCallback || ((left, right) => String(left[key] ?? '').localeCompare(String(right[key] ?? ''), undefined, { numeric: true }))
		return [...itemsFiltered.value].sort((left, right) => compare(left, right) * (sortDescending.value ? -1 : 1))
	})
	const pageCount = computed(() => Math.max(1, Math.ceil(sortedItems.value.length / perPage.value)))
	const pagedItems = computed(() => {
		if (!props.paginationEnabled) return sortedItems.value
		const start = currentPage.value * perPage.value
		return sortedItems.value.slice(start, start + perPage.value)
	})

	watch([sortedItems, perPage], () => {
		if (currentPage.value >= pageCount.value) currentPage.value = pageCount.value - 1
	})
	watch(() => props.selection, (value) => { selectedItems.value = [...value] }, { deep: true })
	watch(() => props.openedRows, (value) => { openedItems.value = [...value] }, { deep: true })
	watch(items, (value) => {
		selectedItems.value = selectedItems.value.filter((item) => value.includes(item))
		openedItems.value = openedItems.value.filter((item) => value.includes(item))
	})
	watch(selectedItems, (value) => emit('update:selection', value), { deep: true })
	watch(openedItems, (value) => {
		emit('update:openedRows', value)
		emit('update:row-opened', value)
	}, { deep: true })

	const isSelected = (item) => selectedItems.value.includes(item)
	const countSelected = computed(() => selectedItems.value.length)
	const allItemsAreSelected = computed(() => items.value.length > 0 && items.value.every(isSelected))
	const allFilteredItemsAreSelected = computed(() => itemsFiltered.value.length > 0 && itemsFiltered.value.every(isSelected))
	const toggleSelection = (item) => {
		const index = selectedItems.value.indexOf(item)
		if (index === -1) selectedItems.value.push(item)
		else selectedItems.value.splice(index, 1)
	}
	const toggleAllSelection = () => {
		selectedItems.value = allFilteredItemsAreSelected.value || countSelected.value ? [] : [...itemsFiltered.value]
	}
	const isOpened = (item) => openedItems.value.includes(item)
	const toggleOpened = (item) => {
		const index = openedItems.value.indexOf(item)
		if (index === -1) {
			if (props.singleRowOpened) openedItems.value = [item]
			else openedItems.value.push(item)
		} else {
			openedItems.value.splice(index, 1)
		}
	}
	const closeAll = () => { openedItems.value = [] }
	const sortBy = (field) => {
		if (!field.sortable) return
		if (sortColumn.value === field.key) sortDescending.value = !sortDescending.value
		else {
			sortColumn.value = field.key
			sortDescending.value = true
		}
		emit('datatableSort', { key: field.key, sortDesc: sortDescending.value })
	}
	const changePage = ({ currentPage: page, perPage: size }) => {
		currentPage.value = page
		perPage.value = size
	}
	const resetFilter = (field) => {
		filters.value[field.key] = field.filter === 'select' ? [] : ''
		filterTypes.value[field.key] = 'eq'
	}
	const toggleFilterType = (field) => {
		filterTypes.value[field.key] = filterTypes.value[field.key] === 'eq' ? 'ne' : 'eq'
	}
	const exportToCsv = () => {
		const columns = fields.value.filter((field) => field.export !== false)
		const escape = (value) => {
			let text = String(value ?? '').replace(/<[^>]*>/g, '')
			if (/^[=+\-@]/.test(text.trim())) text = "'" + text
			return '"' + text.replace(/"/g, '""') + '"'
		}
		const rows = [
			columns.map((field) => escape(field.label)).join(','),
			...itemsFiltered.value.map((item) => columns.map((field) => {
				const value = field.exportFormatter
					? field.exportFormatter(item)
					: field.formatter ? field.formatter(item) : item[field.exportKey || field.key]
				return escape(Array.isArray(value) ? value.join('; ') : value)
			}).join(',')),
		]
		const url = URL.createObjectURL(new Blob([rows.join('\r\n')], { type: 'text/csv;charset=utf-8' }))
		const link = document.createElement('a')
		link.href = url
		link.download = props.exportCsvFileName
		link.click()
		URL.revokeObjectURL(url)
	}

	return {
		filters, filterTypes, itemsFiltered, sortedItems, pagedItems, currentPage, perPage, pageCount,
		sortColumn, sortDescending, selectedItems, openedItems, countSelected, allItemsAreSelected, allFilteredItemsAreSelected,
		canFilter, hasFilterValue, selectOptions, isSelected, toggleSelection, toggleAllSelection,
		isOpened, toggleOpened, closeAll, sortBy, changePage, resetFilter, toggleFilterType, exportToCsv,
	}
}
