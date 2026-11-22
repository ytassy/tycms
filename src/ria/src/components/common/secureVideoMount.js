import { createApp, defineAsyncComponent } from 'vue'
import { readSecureVideoHtmlProps } from './secureVideoHtmlProps.js'

// Charger le lecteur seulement si une balise valide doit être montée.
const SecureVideo = defineAsyncComponent(() => import('./SecureVideo.vue'))
const mounted = new WeakMap()

/** Seule sec_video est autorisée ici ; le HTML n'est jamais compilé par Vue. */
export function mountSecureVideos(root = document) {
	const elements = [...root.querySelectorAll('sec_video')]
	if (root instanceof Element && root.matches('sec_video')) elements.unshift(root)
	for (const element of elements) {
		if (!element.isConnected || mounted.has(element)) continue
		const props = readSecureVideoHtmlProps(element)
		if (!props) continue

		const app = createApp(SecureVideo, props)
		element.style.display = 'block'
		app.mount(element)
		mounted.set(element, app)
	}
}

export function unmountSecureVideos(root) {
	const elements = [...root.querySelectorAll('sec_video')]
	if (root instanceof Element && root.matches('sec_video')) elements.unshift(root)
	for (const element of elements) {
		mounted.get(element)?.unmount()
		mounted.delete(element)
	}
}
