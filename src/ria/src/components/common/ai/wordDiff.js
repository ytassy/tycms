// Keep spaces as tokens so the displayed proposal remains readable and exact.
const tokens = text => text.match(/\s+|[\p{L}\p{M}\p{N}_]+(?:['’\-][\p{L}\p{M}\p{N}_]+)*|[^\s]/gu) ?? []

export function wordDiff(before, after) {
	const left = tokens(before)
	const right = tokens(after)
	let start = 0
	while (start < left.length && start < right.length && left[start] === right[start]) start++
	let endLeft = left.length
	let endRight = right.length
	while (endLeft > start && endRight > start && left[endLeft - 1] === right[endRight - 1]) {
		endLeft--
		endRight--
	}
	const a = left.slice(start, endLeft)
	const b = right.slice(start, endRight)
	const result = []
	const add = (type, value) => {
		if (!value) return
		if (result.at(-1)?.type === type) result.at(-1).text += value
		else result.push({ type, text: value })
	}
	add('same', left.slice(0, start).join(''))
	if (a.length * b.length > 2_000_000) {
		add('removed', a.join(''))
		add('added', b.join(''))
	} else {
		const rows = Array.from({ length: a.length + 1 }, () => new Uint32Array(b.length + 1))
		for (let i = a.length - 1; i >= 0; i--) {
			for (let j = b.length - 1; j >= 0; j--) {
				rows[i][j] = a[i] === b[j] ? rows[i + 1][j + 1] + 1 : Math.max(rows[i + 1][j], rows[i][j + 1])
			}
		}
		let i = 0, j = 0
		while (i < a.length || j < b.length) {
			if (i < a.length && j < b.length && a[i] === b[j]) { add('same', a[i++]); j++ }
			else if (j < b.length && (i === a.length || rows[i][j + 1] > rows[i + 1][j])) add('added', b[j++])
			else add('removed', a[i++])
		}
	}
	add('same', left.slice(endLeft).join(''))
	return result
}

export function htmlToText(html) {
	const template = document.createElement('template')
	template.innerHTML = html
	const blocks = new Set(['P', 'DIV', 'SECTION', 'ARTICLE', 'LI', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'BR', 'TR'])
	let text = ''
	const visit = node => {
		if (node.nodeType === 3) { text += node.textContent; return }
		if (node.nodeType !== 1 && node.nodeType !== 11) return
		if (['SCRIPT', 'STYLE', 'TEMPLATE'].includes(node.nodeName)) return
		if (blocks.has(node.nodeName) && text && !text.endsWith('\n')) text += '\n'
		for (const child of node.childNodes) visit(child)
	}
	visit(template.content)
	return text.trim()
}
