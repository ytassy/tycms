import moment from 'moment'

export function initAndGetTools(_application) {
	moment.locale(navigator.language)

	const entityMap = {
		'&': '&amp;',
		'<': '&lt;',
		'>': '&gt;',
		'"': '&quot;',
		"'": '&#39;',
		'/': '&#x2F;',
		'`': '&#x60;',
		'=': '&#x3D;',
	}

	return {
		getUrlParameters(parameter, staticUrl = null, decode = false) {
			const url = new URL(staticUrl ?? window.location.href, window.location.origin)
			const value = url.searchParams.get(parameter)
			return value === null ? false : (decode ? decodeURIComponent(value) : value)
		},

		escapeHtml(value) {
			return String(value).replace(/[&<>"'`=/]/g, (character) => entityMap[character])
		},

		html2text(html) {
			const container = document.createElement('div')
			container.innerHTML = html
			return container.textContent ?? ''
		},

		uuid() {
			return crypto.randomUUID()
		},

		setLocalStorageItem(itemName, item) {
			window.localStorage.setItem(itemName, item)
		},

		getLocalStorageItem(itemName, defaultValue) {
			return window.localStorage.getItem(itemName) ?? defaultValue
		},

		getLocalStorageItemBoolean(itemName, defaultValue) {
			const value = window.localStorage.getItem(itemName)
			return value === null ? defaultValue : value === 'true'
		},

		quickPrintHtml(title, html, style = '') {
			const printWindow = window.open('', '_blank')
			if (printWindow === null) return

			const headItems = [...document.querySelectorAll('head > style, head > link')]
				.map((item) => item.outerHTML)
				.join('\n')
			printWindow.document.write(`<!doctype html><html><head><title>${title}</title><style>${style}</style>${headItems}</head><body>${html}</body></html>`)
			printWindow.document.close()
			printWindow.focus()
			printWindow.print()
		},

		downloadFileFromContent(data, fileName, contentType = 'application/octet-stream') {
			const url = window.URL.createObjectURL(new Blob([data], { type: contentType }))
			const link = document.createElement('a')
			link.href = url
			link.download = fileName
			document.body.append(link)
			link.click()
			link.remove()
			window.URL.revokeObjectURL(url)
		},
	}
}

export default {
	initAndGet: initAndGetTools,
}
