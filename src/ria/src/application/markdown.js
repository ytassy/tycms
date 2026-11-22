import { marked } from 'marked'
import DOMPurify from 'dompurify'

export function renderMarkdown(content) {
	const html = marked.parse(content, { gfm: true, breaks: true })
	return DOMPurify.sanitize(html, {
		ALLOWED_TAGS: [
			'a', 'blockquote', 'br', 'code', 'del', 'em', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
			'hr', 'li', 'ol', 'p', 'pre', 'strong', 'table', 'tbody', 'td', 'th', 'thead', 'tr', 'ul',
		],
		ALLOWED_ATTR: ['href', 'title', 'start', 'align'],
		ALLOW_DATA_ATTR: false,
	})
}
