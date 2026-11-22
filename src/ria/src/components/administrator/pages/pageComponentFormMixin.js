import PageFileListProperties from './PageFileListProperties.vue'
import PageLinkFields from './PageLinkFields.vue'
import PageVideoProperties from './PageVideoProperties.vue'
import PageMermaidProperties from './PageMermaidProperties.vue'
import PageAccordionProperties from './PageAccordionProperties.vue'
import PageTabsProperties from './PageTabsProperties.vue'
import MediaFileInput from '../../common/MediaFileInput.vue'
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import { buttonVariants } from './pageButtonStyle'
import { pagePreviewViewports } from './pagePreviewViewports'

export default {
	components: { PageLinkFields, PageVideoProperties, PageMermaidProperties, PageFileListProperties, PageAccordionProperties, PageTabsProperties, MediaFileInput, LocalizedTextField },
	props: { node: Object, context: Object, selected: { type: Object, required: true } },
	data: () => ({
		breakpoints: pagePreviewViewports,
		buttonVariants,
		buttonColorGroups: [
			{ label: 'État normal', fields: [
				{ key: 'color', label: 'Texte' }, { key: 'background_color', label: 'Fond' },
				{ key: 'border_color', label: 'Bordure' },
			] },
			{ label: 'Au survol', fields: [
				{ key: 'hover_color', label: 'Texte' }, { key: 'hover_background_color', label: 'Fond' },
				{ key: 'hover_border_color', label: 'Bordure' },
			] },
		],
		imageDimensions: [
			{ id: 'width', label: 'Largeur' }, { id: 'max_width', label: 'Largeur maximale' }, { id: 'max_height', label: 'Hauteur maximale' },
		],
	}),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		buttonStyle() { return this.node.props.button_style ?? {} },
		definition() { return this.store.components.find(component => component.id === this.node.type) ?? null },
		locales() { return this.store.currentSpaceLanguages },
	},
	methods: {
		setButtonOption(key, value, defaultValue) {
			if (value === defaultValue) delete this.node.props[key]
			else this.node.props[key] = value
		},
		setButtonStyle(key, value) {
			if (!this.node.props.button_style) this.node.props.button_style = {}
			if (value.trim() === '') delete this.node.props.button_style[key]
			else this.node.props.button_style[key] = value.trim()
			if (Object.keys(this.node.props.button_style).length === 0) delete this.node.props.button_style
		},
	},
}

