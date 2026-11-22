import { createApp } from 'vue'
import Mermaid from '../Mermaid.vue'

const mounted = new WeakMap()
export function mountMermaidComponents(root = document) {
	const elements = [...root.querySelectorAll('[data-shortcode-diagram]')]
	if (root instanceof Element && root.matches('[data-shortcode-diagram]')) elements.unshift(root)
	for (const element of elements) {
		if (mounted.has(element)) continue
		const source = element.querySelector('.tytools-mermaid-source')?.textContent ?? ''
		const options = { width: element.style.width, align: element.style.marginLeft === '0px' ? 'left'
			: element.style.marginRight === '0px' ? 'right' : 'center',
			look: element.dataset.mermaidLook, theme: element.dataset.mermaidTheme,
			sketch: element.dataset.mermaidSketch, wrapwidth: element.dataset.mermaidWrapWidth }
		// La largeur est portée par le composant, sans appliquer deux fois un pourcentage.
		element.removeAttribute('style')
		const app = createApp(Mermaid, { diagram: source, options })
		mounted.set(element, app)
		app.mount(element)
	}
}
export function unmountMermaidComponents(root) {
	for (const element of root.querySelectorAll('[data-shortcode-diagram]')) {
		mounted.get(element)?.unmount()
		mounted.delete(element)
	}
}
