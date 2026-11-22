import { defineAsyncComponent } from 'vue'

const forms = new Map()
const canvases = new Map()

export function registerPageForm(id, component) {
    if (forms.has(id)) throw new Error(`Formulaire de page déjà enregistré : ${id}`)
    forms.set(id, component)
}
export function registerPageCanvas(id, component) {
    if (canvases.has(id)) throw new Error(`Canevas de page déjà enregistré : ${id}`)
    canvases.set(id, component)
}
export const resolvePageForm = id => forms.get(id) ?? null
export const resolvePageCanvas = id => canvases.get(id) ?? null

registerPageForm('core/row', defineAsyncComponent(() => import('./components/RowForm.vue')))
registerPageForm('core/html', defineAsyncComponent(() => import('./components/HtmlForm.vue')))
registerPageForm('core/rich-text', defineAsyncComponent(() => import('./components/RichTextForm.vue')))
registerPageForm('core/image', defineAsyncComponent(() => import('./components/ImageForm.vue')))
registerPageForm('core/button', defineAsyncComponent(() => import('./components/ButtonForm.vue')))
registerPageForm('core/space-switch', defineAsyncComponent(() => import('./components/SpaceSwitchForm.vue')))
registerPageForm('core/my-account', defineAsyncComponent(() => import('./components/MyAccountForm.vue')))
registerPageForm('core/column', defineAsyncComponent(() => import('./components/ColumnForm.vue')))
registerPageForm('core/video', defineAsyncComponent(() => import('./PageVideoProperties.vue')))
registerPageForm('core/audio', defineAsyncComponent(() => import('./PageAudioProperties.vue')))
registerPageForm('core/diagram', defineAsyncComponent(() => import('./PageDiagramProperties.vue')))
registerPageForm('core/mermaid', defineAsyncComponent(() => import('./PageMermaidProperties.vue')))
registerPageForm('core/file-list', defineAsyncComponent(() => import('./PageFileListProperties.vue')))
registerPageForm('core/tabs', defineAsyncComponent(() => import('./PageTabsProperties.vue')))
registerPageForm('core/accordion', defineAsyncComponent(() => import('./PageAccordionProperties.vue')))
registerPageForm('core/timeline', defineAsyncComponent(() => import('./PageTimelineProperties.vue')))
registerPageForm('core/testimonials', defineAsyncComponent(() => import('./PageTestimonialsProperties.vue')))
registerPageCanvas('core/timeline', defineAsyncComponent(() => import('./components/TimelineCanvas.vue')))
registerPageCanvas('core/testimonials', defineAsyncComponent(() => import('./components/TestimonialsCanvas.vue')))
registerPageCanvas('core/heading', defineAsyncComponent(() => import('./components/HeadingCanvas.vue')))
registerPageCanvas('core/image', defineAsyncComponent(() => import('./components/ImageCanvas.vue')))
registerPageCanvas('core/button', defineAsyncComponent(() => import('./components/ButtonCanvas.vue')))
registerPageCanvas('core/hr', defineAsyncComponent(() => import('./components/SeparatorCanvas.vue')))
registerPageCanvas('core/html', defineAsyncComponent(() => import('./components/HtmlCanvas.vue')))
registerPageCanvas('core/rich-text', defineAsyncComponent(() => import('./components/HtmlCanvas.vue')))
registerPageCanvas('core/video', defineAsyncComponent(() => import('./components/VideoCanvas.vue')))
registerPageCanvas('core/iframe', defineAsyncComponent(() => import('./components/IframeCanvas.vue')))
registerPageCanvas('core/audio', defineAsyncComponent(() => import('./components/AudioCanvas.vue')))
registerPageCanvas('core/diagram', defineAsyncComponent(() => import('./components/DiagramCanvas.vue')))
registerPageCanvas('core/mermaid', defineAsyncComponent(() => import('./components/MermaidCanvas.vue')))
registerPageCanvas('core/file-list', defineAsyncComponent(() => import('./components/FileListCanvas.vue')))
registerPageCanvas('core/row', defineAsyncComponent(() => import('./components/RowCanvas.vue')))
registerPageCanvas('core/tabs', defineAsyncComponent(() => import('./components/TabsCanvas.vue')))
registerPageCanvas('core/accordion', defineAsyncComponent(() => import('./components/AccordionCanvas.vue')))
