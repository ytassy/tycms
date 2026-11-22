export function safePageImageUrl(value) {
	return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//')
		&& !value.includes('..') && !/[\x00-\x20\\]/.test(value) ? value : ''
}

