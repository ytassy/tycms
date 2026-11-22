// Ce script de confiance est le seul pont de mesure des aperçus isolés.
const token = document.documentElement.dataset.pageCanvasToken
const parentOrigin = new URL(document.currentScript.src).origin
let previousHeight = 0
let scheduled = false

function measure() {
	scheduled = false
	const body = document.body
	if (!body || !token) return
	const style = getComputedStyle(body)
	const height = Math.min(100000, Math.max(24, Math.ceil(body.getBoundingClientRect().height
		+ (parseFloat(style.marginTop) || 0) + (parseFloat(style.marginBottom) || 0))))
	if (height === previousHeight) return
	previousHeight = height
	window.parent.postMessage({ type: 'tycms:page-canvas-size', token, height }, parentOrigin)
}

function schedule() {
	if (!scheduled) { scheduled = true; requestAnimationFrame(measure) }
}

const observer = new ResizeObserver(schedule)
observer.observe(document.body)
window.addEventListener('load', schedule)
document.fonts?.ready.then(schedule)
schedule()
