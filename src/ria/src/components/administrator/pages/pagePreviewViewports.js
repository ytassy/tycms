export const pagePreviewViewports = [
	{ id: 'xs', label: 'Mobile', width: 390, containerWidth: null },
	{ id: 'sm', label: 'Petit écran', width: 640, containerWidth: 540 },
	{ id: 'md', label: 'Tablette', width: 820, containerWidth: 720 },
	{ id: 'lg', label: 'Bureau', width: 1024, containerWidth: 960 },
	{ id: 'xl', label: 'Grand écran', width: 1280, containerWidth: 1140 },
	{ id: 'xxl', label: 'Très grand écran', width: 1440, containerWidth: 1320 },
]

export function columnSizeAtViewport(sizes, viewport) {
	const index = pagePreviewViewports.findIndex(option => option.id === viewport)
	for (let i = index < 0 ? 0 : index; i >= 0; i--) {
		const size = sizes?.[pagePreviewViewports[i].id]
		if (Number.isInteger(size) && size >= 1 && size <= 12) return size
	}
	return 12
}
