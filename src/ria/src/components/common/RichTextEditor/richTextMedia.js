const escapeHtml = value => String(value).replace(/[&<>"']/g, character => ({
	'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
})[character])

export function mediaEntryHtml(entry) {
	if (entry?.type !== 'file' || typeof entry.url !== 'string' || !entry.url.startsWith('/medias/') || entry.url.startsWith('//')) return null

	const url = escapeHtml(entry.url)
	const name = escapeHtml(entry.name ?? 'Média')
	if (entry.mime?.startsWith('image/')) return `<img src="${url}" alt="${name}">`
	if (entry.mime?.startsWith('video/')) return `<video controls="controls" src="${url}"></video>`
	return `<a href="${url}">${name}</a>`
}
