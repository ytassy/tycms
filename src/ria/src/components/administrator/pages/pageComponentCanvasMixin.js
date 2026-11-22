import PageCanvasFileList from './PageCanvasFileList.vue'
import PageCanvasVideo from './PageCanvasVideo.vue'
import Mermaid from '../../common/Mermaid.vue'
import { mermaidDefaultsForPage } from './pageMermaidOptions.js'
import { nodeLabel } from './pageEditorModel'
import { buttonClasses, buttonStyle } from './pageButtonStyle'
import { selectLocalizedText } from '../../common/localizedText'
import DOMPurify from 'dompurify'
import ImageLightbox from '../../features/imageLightbox/ImageLightbox.vue'
import { safePageImageUrl } from '../../common/safePageImageUrl'

export default {
	components: { ImageLightbox, PageCanvasFileList, PageCanvasVideo, Mermaid },
	data: () => ({ lightboxSrc: '' }),
	props: { node: Object, context: Object, viewport: String, item: { type: Object, required: true }, components: { type: Array, default: () => [] } },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		// Afficher le HTML nettoyé du brouillon en conservant les shortcodes littéraux.
		safeHtml() { return DOMPurify.sanitize(this.text(this.item.props.content)) },
		mermaidOptions() { return { ...mermaidDefaultsForPage(this.store), ...(this.item.props.options ?? {}) } },
		label() { return nodeLabel(this.item, this.components) },
		headingTag() { return `h${[1, 2, 3, 4].includes(Number(this.item.props.level)) ? Number(this.item.props.level) : 2}` },
		safeImageSrc() {
			const src = this.item.props.src ?? ''
			return safePageImageUrl(src)
		},
		imageStyle() {
			const style = {}
			for (const [field, cssName] of [['width', 'width'], ['max_width', 'maxWidth'], ['max_height', 'maxHeight']]) {
				const value = this.item.props[field]
				if (typeof value === 'string' && /^(?:[1-9][0-9]{0,3})(?:px|%)$/.test(value)) style[cssName] = value
			}
			return style
		},
	},
	methods: {
		openLightbox() {
			this.store.selectNode(this.item.id)
			this.lightboxSrc = this.safeImageSrc
		},
		buttonClasses,
		buttonStyle,
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
	},
}
