import RichTextField from './fields/RichTextField.vue'
import LocalizedTextField from './fields/LocalizedTextField.vue'
import ColorField from './fields/ColorField.vue'
import CodeField from './fields/CodeField.vue'
import ComputedField from './fields/ComputedField.vue'
import HiddenField from './fields/HiddenField.vue'
import FontFamilyField from './fields/FontFamilyField.vue'
import IconPickerField from './fields/IconPickerField.vue'
import JsonField from './fields/JsonField.vue'
import MediaFileField from './fields/MediaFileField.vue'
import NoteField from './fields/NoteField.vue'
import NumberField from './fields/NumberField.vue'
import RadioField from './fields/RadioField.vue'
import SelectField from './fields/SelectField.vue'
import TextareaField from './fields/TextareaField.vue'
import TextField from './fields/TextField.vue'
import SwitchField from './fields/SwitchField.vue'

const fieldComponents = new Map([
	['forms/localized-text', LocalizedTextField],
	['forms/rich-text', RichTextField],
	['forms/text', TextField],
	['forms/textarea', TextareaField],
	['forms/code', CodeField],
	['forms/computed', ComputedField],
	['forms/color', ColorField],
	['forms/number', NumberField],
	['forms/hidden', HiddenField],
	['forms/switch', SwitchField],
	['forms/icon-picker', IconPickerField],
	['forms/select', SelectField],
	['forms/font-family', FontFamilyField],
	['forms/radio', RadioField],
	['forms/note', NoteField],
	['forms/json', JsonField],
	['forms/media-file', MediaFileField],
])

/**
 * An application bundle registers an extension field component explicitly.
 * PHP only sends this logical id; it never controls an import path.
 */
export function registerFormFieldComponent(id, component) {
	if (fieldComponents.has(id)) {
		throw new Error(`Le composant de champ « ${id} » est déjà enregistré.`)
	}

	fieldComponents.set(id, component)
}

export function resolveFormFieldComponent(id) {
	return fieldComponents.get(id) ?? null
}
