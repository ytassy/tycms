import { createApp } from 'vue'
import ImageLightbox from './ImageLightbox.vue'
import { safePageImageUrl } from '../../common/safePageImageUrl'

let installed = false
let active = false

/** Un seul écouteur pour les images rendues en PHP, y compris dans le Footer. */
export function installPageImageLightbox() {
	if (installed) return
	installed = true
	document.addEventListener('click', event => {
		const trigger = event.target.closest?.('button[data-page-image-lightbox]')
		if (!trigger || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return
		const src = safePageImageUrl(trigger.getAttribute('data-page-image-src'))
		if (!src || typeof HTMLDialogElement === 'undefined' || !HTMLDialogElement.prototype.showModal) return
		event.preventDefault()
		if (active) return
		active = true

		// Vue construit des attributs DOM ; aucune source ou légende n’est interpolée en HTML.
		const host = document.createElement('div')
		document.body.append(host)
		const app = createApp(ImageLightbox, {
			src, alt: trigger.querySelector('img')?.alt ?? '',
			onClose() { app.unmount(); host.remove(); active = false },
		})
		app.mount(host)
	})
}
