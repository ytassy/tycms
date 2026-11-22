<template>
	<div :class="['rich-text-editor', { saving, saved }]">
		<button v-if="expandable" type="button" class="btn btn-outline-secondary btn-sm mb-2" :disabled="!editor" @click="toggleFullscreen">
			<i class="fa-solid fa-expand" aria-hidden="true"></i> Plein écran
		</button>
		<button v-if="canUseAi" type="button" class="btn btn-outline-primary btn-sm mb-2 ms-2" :disabled="!editor || disabled" @click="openAiChat">
			<i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Corriger avec l’IA
		</button>
		<textarea ref="textarea" />
		<MediaPicker :visible="mediaPickerVisible" :space-id="mediaSpaceId" @select="insertSelectedMedia" @close="closeMediaPicker" />
		<modal :visible="aiChatVisible" width="1000px" max-height="calc(100vh - 8rem)" :large-body="true" :closable="true" :z-index="1100" @close="closeAiChat">
			<template #header>Correction avec l’IA</template>
			<template #body>
				<ai_chat :space-id="mediaSpaceId" use-case="proofreading" :custom-tools="aiChatTools"
					:system-prompt="aiSystemPrompt" :initial-draft="aiInitialDraft" embedded />
			</template>
			<template #footer>
				<div class="tal"><button class="btn btn-primary" @click="closeAiChat">Fermer</button></div>
			</template>
		</modal>
	</div>
</template>

<script>
import tinymce from 'tinymce/tinymce'
import './langs/fr-FR.js'
import 'tinymce/icons/default'
import 'tinymce/models/dom'
import 'tinymce/themes/silver'
import 'tinymce/skins/ui/oxide/skin.css'
import oxideContentCss from 'tinymce/skins/ui/oxide/content.css?inline'
import defaultContentCss from 'tinymce/skins/content/default/content.css?inline'
import 'tinymce/plugins/advlist'
import 'tinymce/plugins/anchor'
import 'tinymce/plugins/autolink'
import 'tinymce/plugins/autoresize'
import 'tinymce/plugins/charmap'
import 'tinymce/plugins/code'
import 'tinymce/plugins/codesample'
import 'tinymce/plugins/directionality'
import 'tinymce/plugins/emoticons'
import 'tinymce/plugins/emoticons/js/emojis'
import 'tinymce/plugins/fullscreen'
import 'tinymce/plugins/image'
import 'tinymce/plugins/importcss'
import 'tinymce/plugins/insertdatetime'
import 'tinymce/plugins/link'
import 'tinymce/plugins/lists'
import 'tinymce/plugins/media'
import 'tinymce/plugins/nonbreaking'
import 'tinymce/plugins/preview'
import 'tinymce/plugins/quickbars'
import 'tinymce/plugins/searchreplace'
import 'tinymce/plugins/table'
import 'tinymce/plugins/visualblocks'
import 'tinymce/plugins/visualchars'
import 'tinymce/plugins/wordcount'
import MediaPicker from '../MediaPicker.vue'
import { mediaEntryHtml } from './richTextMedia.js'
import { richTextColorOptions } from './richTextColors.js'
import { resolveTemplatePalette } from '../templateColorPalette.js'
import mediaButtonIcon from './media-button.png?inline'
import { createHtmlContentTools } from '../ai/aiTools.js'

const imageAlignmentCss = '.float-none{float:none!important}.float-start{float:left!important}.float-end{float:right!important}.mx-auto{margin-right:auto!important;margin-left:auto!important}.d-block{display:block!important}'
const insertMenuItems = 'siteMedia | image video link media addcomment pageembed inserttemplate codesample inserttable accordion math | charmap emoticons hr | pagebreak nonbreaking anchor tableofcontents footnotes | mergetags | insertdatetime'
const aiSystemPrompt = 'Tu aides à corriger le contenu HTML d’un éditeur. Utilise get_content pour lire la version actuelle avant de proposer une modification. Pour appliquer une correction, appelle replace_content avec le HTML complet corrigé.'
const aiInitialDraft = 'Corrige les fautes d\'orthographe et de grammaire.'

export default {
	components: { MediaPicker },
	props: {
		modelValue: { type: String, default: '' },
		saving: { type: Boolean, default: false },
		saved: { type: Boolean, default: false },
		disabled: { type: Boolean, default: false },
		expandable: { type: Boolean, default: false },
		mediaSpaceId: { type: [Number, String], default: null },
		showAiButton: { type: Boolean, default: true },
	},
	emits: ['update:modelValue'],

	data() {
		return { editor: null, unmounted: false, mediaPickerVisible: false, mediaBookmark: null,
			aiChatVisible: false, aiChatTools: [], aiSystemPrompt, aiInitialDraft }
	},
	computed: {
		canSelectMedia() {
			return Boolean(this.mediaSpaceId && (this.acl.hasGlobalPermission('MEDIA_READ', this.mediaSpaceId)
				|| this.acl.hasGlobalPermission('MEDIA_CRUD', this.mediaSpaceId)))
		},
		canUseAi() {
			return Boolean(this.showAiButton && this.mediaSpaceId && this.acl.hasGlobalPermission('AI_USE', this.mediaSpaceId))
		},
	},

	async mounted() {
		if (this.$refs.textarea === null) return

		// Même palette pour toutes les instances, à partir du bootstrap de la template courante.
		const templateColors = this.conf.getTemplateColors()
		const colorSettings = richTextColorOptions(templateColors, resolveTemplatePalette(this.$el, templateColors))
		const [instance] = await tinymce.init({
			target: this.$refs.textarea,
			license_key: 'gpl',
			language: 'fr-FR',
			language_load: false,
			skin: false,
			content_css: false,
			content_style: `${oxideContentCss}\n${defaultContentCss}\n${imageAlignmentCss}\n${colorSettings.contentCss}`,
			...colorSettings.options,
		promotion: false,
		branding: false,
		menubar: 'edit insert view format table tools help',
		...(this.canSelectMedia ? { menu: { insert: { title: 'Insert', items: insertMenuItems } } } : {}),
		removed_menuitems: 'fontfamily',
		plugins: [
			'advlist', 'anchor', 'autolink', 'autoresize', 'charmap', 'code', 'codesample',
			'directionality', 'emoticons', 'fullscreen', 'image', 'importcss', 'insertdatetime',
			'link', 'lists', 'media', 'nonbreaking', 'preview', 'quickbars', 'searchreplace',
			'table', 'visualblocks', 'visualchars', 'wordcount',
		],
		toolbar: `bold italic underline strikethrough | alignleft aligncenter alignright alignjustify | lineheight styles | blocks fontsize | searchreplace | bullist numlist | outdent indent | undo redo | link unlink anchor image ${this.canSelectMedia ? 'siteMedia ' : ''}| code | forecolor backcolor | fullscreen | table | subscript superscript | charmap emoticons media hr ltr rtl | cut copy paste pastetext | visualchars visualblocks nonbreaking blockquote | print preview codesample insertdatetime removeformat`,
		font_size_formats: '50% 60% 70% 80% 90% 110% 120% 130% 140% 150% 160% 0.7em 0.8em 0.9em 1em 1.1em 1.2em 1.3em 1.4em 1.5em 1.6em 1.7em',
		line_height_formats: '1 1.1 1.2 1.3 1.4 1.5 2',
		style_formats: [{ title: 'Conteneur', items: [
			{ title: 'Article', block: 'article', wrapper: true, merge_siblings: false },
			{ title: 'Aparté', block: 'aside', wrapper: true, merge_siblings: false },
			{ title: 'Section', block: 'section', wrapper: true, merge_siblings: false },
		] }],
		style_formats_merge: true,
		importcss_append: true,
		quickbars_image_toolbar: false,
		quickbars_insert_toolbar: false,
		quickbars_selection_toolbar: 'bold italic underline | h2 h3 | link blockquote',
		browser_spellcheck: true,
		entity_encoding: 'raw',
		convert_urls: false,
		paste_data_images: false,
		image_caption: true,
		image_advtab: true,
		image_title: true,
		image_class_list: [
			{ title: 'Aucune', value: '' },
			{ title: 'Sans flottement', value: 'float-none' },
			{ title: 'À gauche', value: 'float-start' },
			{ title: 'À droite', value: 'float-end' },
			{ title: 'Centrée', value: 'mx-auto d-block' },
		],
		toolbar_sticky: true,
		toolbar_mode: 'sliding',
		resize: true,
			readonly: this.disabled,
			setup: (instance) => {
				if (this.canSelectMedia) {
					instance.ui.registry.addIcon('site-media', `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24"><image href="${mediaButtonIcon}" width="24" height="24" preserveAspectRatio="xMidYMid meet" /></svg>`)
					instance.ui.registry.addButton('siteMedia', {
						icon: 'site-media',
						tooltip: 'Insérer un média de cet espace',
						onAction: () => this.openMediaPicker(instance),
					})
					instance.ui.registry.addMenuItem('siteMedia', {
						text: 'Médias du site',
						icon: 'site-media',
						onAction: () => this.openMediaPicker(instance),
					})
				}
				instance.on('change input undo redo', () => {
					if (!this.disabled) this.$emit('update:modelValue', instance.getContent())
				})
				instance.on('keydown', (event) => {
					if (!event.ctrlKey || event.key?.toLowerCase() !== 's') return
					event.preventDefault()
					event.stopPropagation()
					document.querySelector('.ctrl-s:not(:disabled)')?.click()
				})
		},
	})

		if (!instance) return
		if (this.unmounted) { instance.remove(); return }
		this.editor = instance
		this.editor.setContent(this.modelValue ?? '')
		this.editor.mode.set(this.disabled ? 'readonly' : 'design')
	},

	watch: {
		modelValue(value) {
			if (this.editor !== null && value !== this.editor.getContent()) this.editor.setContent(value ?? '')
		},
		disabled(value) {
			this.editor?.mode.set(value ? 'readonly' : 'design')
		},
	},

	methods: {
		openAiChat() {
			if (!this.canUseAi || this.disabled || !this.editor) return
			this.aiChatTools = createHtmlContentTools({
				read: () => this.editor?.getContent() ?? '',
				write: html => {
					this.editor.undoManager.transact(() => this.editor.setContent(html))
					this.$emit('update:modelValue', this.editor.getContent())
				},
				canWrite: () => Boolean(this.editor && !this.disabled),
				mode: 'review',
			})
			this.aiChatVisible = true
		},
		closeAiChat() { this.aiChatVisible = false },
		openMediaPicker(instance) {
			if (this.disabled || !this.canSelectMedia) return
			this.mediaBookmark = instance.selection.getBookmark(2, true)
			this.mediaPickerVisible = true
		},
		insertSelectedMedia(entry) {
			if (this.disabled || !this.canSelectMedia || !this.editor) return
			const html = mediaEntryHtml(entry)
			if (!html) return
			this.editor.focus()
			if (this.mediaBookmark) this.editor.selection.moveToBookmark(this.mediaBookmark)
			this.editor.undoManager.transact(() => this.editor.insertContent(html))
			this.$emit('update:modelValue', this.editor.getContent())
			this.closeMediaPicker()
		},
		closeMediaPicker() {
			this.mediaPickerVisible = false
			this.mediaBookmark = null
		},
		toggleFullscreen() {
			this.editor?.execCommand('mceFullScreen')
		},
	},

	beforeUnmount() {
		this.unmounted = true
		this.editor?.remove()
	},
}
</script>

<style scoped>
.rich-text-editor {
	border: 2px solid transparent;
	border-radius: var(--bs-border-radius);
}

.rich-text-editor.saving { border-color: var(--bs-primary); }
.rich-text-editor.saved { border-color: var(--bs-success); }
</style>
