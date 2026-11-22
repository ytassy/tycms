// Les URL saisies ne sont jamais utilisées telles quelles pour un lecteur distant.
export function videoEmbedUrl(value) {
	if (typeof value !== 'string' || /[\x00-\x20\x7f"'<>\\]/.test(value)) return ''
	let url
	try { url = new URL(value) } catch { return '' }
	if (url.protocol !== 'https:') return ''
	let id = ''
	if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'].includes(url.hostname)) {
		id = url.pathname.match(/^\/(?:embed|shorts)\/([A-Za-z0-9_-]{11})$/)?.[1] ?? ''
		if (url.pathname === '/watch' && ![...url.searchParams.keys()].some(key => key.startsWith('v['))) id = url.searchParams.getAll('v').at(-1) ?? ''
	} else if (url.hostname === 'youtu.be') {
		id = url.pathname.match(/^\/([A-Za-z0-9_-]{11})$/)?.[1] ?? ''
	}
	if (/^[A-Za-z0-9_-]{11}$/.test(id)) return `https://www.youtube.com/embed/${id}`
	if (url.hostname === 'player.vimeo.com') id = url.pathname.match(/^\/video\/([1-9][0-9]{0,19})$/)?.[1] ?? ''
	else if (['vimeo.com', 'www.vimeo.com'].includes(url.hostname)) id = url.pathname.match(/^\/([1-9][0-9]{0,19})$/)?.[1] ?? ''
	else return ''
	return id ? `https://player.vimeo.com/video/${id}` : ''
}

export function videoMediaUrl(value) {
	if (typeof value !== 'string' || !value.startsWith('/medias/') || /[\x00-\x20\x7f"'<>\\?#]/.test(value)) return ''
	try {
		const decoded = decodeURIComponent(value)
		if (/[\x00-\x1f\x7f\\]/.test(decoded) || decoded.split('/').some(part => part.startsWith('.'))) return ''
	} catch { return '' }
	return value
}
