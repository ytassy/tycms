import { defineAsyncComponent } from 'vue'

/**
 * Components available in every Vue app under the registered names below.
 * Templates use these names without importing the component locally.
 */
export function registerGlobalComponents(app) {
	app.component('common_app', defineAsyncComponent(() => import('../components/common/CommonApp.vue')))
	app.component('calendar', defineAsyncComponent(() => import('../components/common/Calendar.vue')))
	app.component('rich_text_editor', defineAsyncComponent(() => import('../components/common/RichTextEditor/RichTextEditor.vue')))
	app.component('ai_chat', defineAsyncComponent(() => import('../components/common/ai/ai_chat.vue')))
	app.component('saving_status', defineAsyncComponent(() => import('../components/common/SavingStatus.vue')))
	app.component('item_status', defineAsyncComponent(() => import('../components/common/ItemStatus.vue')))
	app.component('json_editor', defineAsyncComponent(() => import('../components/common/JsonEditor.vue')))
	app.component('form_renderer', defineAsyncComponent(() => import('../components/common/forms/FormRenderer.vue')))
	app.component('CeHelperItem', defineAsyncComponent(() => import('../components/common/editor/item.vue')))
	app.component('CeItem', defineAsyncComponent(() => import('../components/common/editor/item.vue')))
	app.component('code_editor', defineAsyncComponent(() => import('../components/common/CodeEditor.vue')))
	app.component('color_input', defineAsyncComponent(() => import('../components/common/ColorInput.vue')))
	app.component('b-dropdown', defineAsyncComponent(() => import('../components/common/BDropdown.vue')))
	app.component('dropdown_list', defineAsyncComponent(() => import('../components/common/DropdownList.vue')))
	app.component('input_switch', defineAsyncComponent(() => import('../components/common/InputSwitch.vue')))
	app.component('icon_picker', defineAsyncComponent(() => import('../components/common/IconPicker.vue')))
	app.component('modal', defineAsyncComponent(() => import('../components/common/Modal.vue')))
	app.component('b-popover', defineAsyncComponent(() => import('../components/common/BPopover.vue')))
	app.component('delete_btn', defineAsyncComponent(() => import('../components/common/DeleteButton.vue')))
	app.component('spinner', defineAsyncComponent(() => import('../components/common/Spinner.vue')))
	app.component('text_copy', defineAsyncComponent(() => import('../components/common/TextCopy.vue')))
	app.component('file_dnd', defineAsyncComponent(() => import('../components/common/FileDnd.vue')))
	app.component('mediaplayer', defineAsyncComponent(() => import('../components/common/MediaPlayer.vue')))
	app.component('sec_video', defineAsyncComponent(() => import('../components/common/SecureVideo.vue')))
	app.component('tab', defineAsyncComponent(() => import('../components/common/Tab.vue')))
	app.component('tabs', defineAsyncComponent(() => import('../components/common/Tabs.vue')))
	app.component('data-table', defineAsyncComponent(() => import('../components/common/DataTable/DataTable.vue')))
	app.component('pagination', defineAsyncComponent(() => import('../components/common/Pagination.vue')))
	app.component('checkboxes_dropdown', defineAsyncComponent(() => import('../components/common/CheckboxesDropdown.vue')))
}
