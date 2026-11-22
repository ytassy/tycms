<template>
	<div ref="root" class="color-input">
		<div class="color-input__main d-flex align-items-center gap-2">
			<div v-if="isVariable" class="color-input__control d-flex align-items-center gap-2">
				<span class="color-input__variable-swatch" :style="{ backgroundColor: modelValue }" aria-hidden="true"></span>
				<select :id="inputId" :value="variableName" class="form-select" :class="{ 'form-select-sm': size === 'sm', 'is-invalid': invalid || !availableVariable }"
					:disabled="isDisabled" aria-label="Couleur de la template" @change="selectTemplateVariable($event.target.value)">
					<option v-if="!availableVariable" :value="variableName" disabled>Variable indisponible : {{ variableName }}</option>
					<option v-for="entry in templateColors" :key="entry.variable" :value="entry.variable">{{ entry.label }}</option>
				</select>
			</div>
			<div v-if="!isVariable" class="color-input__control d-flex align-items-center gap-2">
				<input :id="inputId" type="color" :value="baseColor" :disabled="isDisabled"
					:class="['form-control', 'form-control-color', 'color-input__picker', { 'is-invalid': invalid }]"
					@input="updateBaseColor($event.target.value)">
				<input type="text" :value="modelValue ?? ''" :maxlength="allowAlpha ? 9 : 7" :pattern="colorPattern"
					:placeholder="colorPlaceholder"
					:readonly="readonly" :disabled="disabled" :spellcheck="false"
					:class="['form-control', 'color-input__value', { 'form-control-sm': size === 'sm', 'is-invalid': invalid }]"
					aria-label="Valeur de la couleur" @input="updateTextColor($event.target.value)"
					@blur="normalizeTextColor($event.target.value)">
				<b-dropdown v-if="palette.length > 0 || templateColors?.length" ref="paletteDropdown" variant="outline-secondary" no-caret tag="div"
					:disabled="isDisabled" class="color-input__palette-dropdown" @show="refreshTemplatePalette">
					<template #button-content>
						<i class="fa-solid fa-palette" aria-hidden="true"></i>
						<span class="visually-hidden">Palette de couleurs</span>
					</template>
					<div class="color-input__palette" role="group" aria-label="Palette de couleurs">
						<template v-if="templatePalette.length">
							<strong class="color-input__palette-heading">Couleurs de la template</strong>
							<button v-for="entry in templatePalette" :key="entry.variable" type="button"
								:class="['color-input__swatch', { 'color-input__swatch--selected': baseColor === getBaseColor(entry.color) }]"
								:style="{ backgroundColor: entry.color }" :disabled="isDisabled" :title="`${entry.label} — ${entry.color}`"
								:aria-label="`Choisir ${entry.label} : ${entry.color}`" @click="selectPaletteColor(entry.color)" />
							<strong v-if="palette.length" class="color-input__palette-heading">Autres couleurs</strong>
						</template>
						<button v-for="color in palette" :key="color" type="button"
							:class="['color-input__swatch', { 'color-input__swatch--selected': baseColor === getBaseColor(color) }]"
							:style="{ backgroundColor: getBaseColor(color) }" :disabled="isDisabled"
							:title="color" :aria-label="`Choisir ${color}`" @click="selectPaletteColor(color)" />
					</div>
				</b-dropdown>
			</div>
			<div v-if="templateColors !== null && (templateColors.length || isVariable)" class="btn-group btn-group-sm color-input__mode" role="group" aria-label="Source de la couleur">
				<button type="button" class="btn" :class="isVariable ? 'btn-outline-secondary' : 'btn-secondary'"
					:disabled="isDisabled" :aria-pressed="!isVariable" aria-label="Couleur personnalisée" title="Couleur personnalisée" @click="setColorMode('custom')">
					<i class="fa-solid fa-eye-dropper" aria-hidden="true"></i>
				</button>
				<button type="button" class="btn" :class="isVariable ? 'btn-secondary' : 'btn-outline-secondary'"
					:disabled="isDisabled || !templateColors.length" :aria-pressed="isVariable" aria-label="Couleur de la template" title="Couleur de la template" @click="setColorMode('template')">
					<i class="fa-solid fa-swatchbook" aria-hidden="true"></i>
				</button>
			</div>
		</div>
		<small v-if="isVariable" class="d-block text-body-secondary mt-1">{{ modelValue }}</small>
		<small v-if="isVariable && !availableVariable" class="d-block text-danger mt-1" role="status">Cette variable n’est plus proposée par la template. Choisissez une autre couleur avant d’enregistrer.</small>
		<div v-if="allowAlpha && !isVariable" class="d-flex align-items-center gap-2 mt-2">
			<label :for="`${inputId}-alpha`" class="form-label mb-0">Opacité</label>
			<input :id="`${inputId}-alpha`" type="range" min="0" max="255" step="1" :value="alpha"
				:disabled="isDisabled" class="form-range color-input__alpha" @input="updateAlpha($event.target.value)">
			<output :for="`${inputId}-alpha`" class="text-body-secondary text-nowrap">{{ alphaPercent }} %</output>
		</div>
	</div>
</template>

<script setup>
import { computed, ref, useId, watch } from 'vue'
import { templateColorName } from './pageColors.js'
import { resolveTemplatePalette } from './templateColorPalette.js'

const DEFAULT_PALETTE = [
	'#000000', '#212529', '#495057', '#6C757D', '#CED4DA', '#FFFFFF',
	'#842029', '#DC3545', '#EA868F', '#FD7E14', '#FFC107', '#FFF3CD',
	'#7A2E1B', '#A84426', '#D65A31', '#F07B53', '#F8B094', '#FCE1D3',
	'#6B3A00', '#A65A00', '#D97706', '#F59E0B', '#FBBF24', '#FEF3C7',
	'#123524', '#198754', '#00C853', '#79D353', '#B0D6A2', '#F0F8E7',
	'#4A4A12', '#6B8E23', '#A3B82C', '#C6E327', '#DDF279', '#F7F9D2',
	'#084298', '#0D6EFD', '#6EA8FE', '#0DCAF0', '#9EEAF9', '#CFF4FC',
	'#134E4A', '#0F766E', '#20C997', '#5EEAD4', '#99F6E4', '#CCFBF1',
	'#3D0A91', '#6610F2', '#6F42C1', '#A370F7', '#D63384', '#F7C6D9',
	'#4A2C2A', '#795548', '#A47148', '#C69C6D', '#E6CCB2', '#F3E5D8',
]

const props = defineProps({
	id: { type: String, default: null },
	modelValue: { type: String, default: '#000000' },
	allowAlpha: { type: Boolean, default: true },
	allowShortHex: { type: Boolean, default: false },
	palette: { type: Array, default: null },
	templateColors: { type: Array, default: null },
	readonly: { type: Boolean, default: false },
	disabled: { type: Boolean, default: false },
	invalid: { type: Boolean, default: false },
	size: { type: String, default: 'md' },
})
const emit = defineEmits(['update:modelValue'])
const paletteDropdown = ref(null)
const root = ref(null)
const templatePalette = ref([])
const generatedId = useId()
const inputId = computed(() => props.id ?? generatedId)
const variableName = computed(() => templateColorName(props.modelValue))
const isVariable = computed(() => props.templateColors !== null && variableName.value !== null)
const availableVariable = computed(() => props.templateColors?.some(entry => entry.variable === variableName.value))
const previousCustomColor = ref('#000000')
watch(() => props.modelValue, value => { if (parseColor(value).isValid) previousCustomColor.value = value }, { immediate: true })
const parsedValue = computed(() => parseColor(props.modelValue))
const baseColor = computed(() => parsedValue.value.baseColor)
const alpha = computed(() => parsedValue.value.alpha)
const alphaPercent = computed(() => Math.round((alpha.value / 255) * 100))
const isDisabled = computed(() => props.disabled || props.readonly)
const palette = computed(() => Array.isArray(props.palette) ? props.palette : DEFAULT_PALETTE)
const colorPlaceholder = computed(() => [props.allowShortHex ? '#RGB' : null, '#RRGGBB', props.allowAlpha ? '#RRGGBBAA' : null]
	.filter(Boolean).join(' ou '))
const colorPattern = computed(() => {
	const lengths = [props.allowShortHex ? '[0-9A-Fa-f]{3}|' : '', '[0-9A-Fa-f]{6}', props.allowAlpha ? '|[0-9A-Fa-f]{8}' : ''].join('')
	return `^#(?:${lengths})$`
})

function updateBaseColor(color) { emitColor(color, alpha.value) }
function refreshTemplatePalette() { templatePalette.value = resolveTemplatePalette(root.value, props.templateColors) }
// Une sélection de thème conserve sa référence ; elle n’est jamais convertie en hexadécimal.
function selectTemplateVariable(name) {
	if (props.templateColors?.some(entry => entry.variable === name)) emit('update:modelValue', `var(${name})`)
}
function setColorMode(mode) {
	// Un clic sur le mode déjà actif conserve la couleur et la variable sélectionnées.
	if ((mode === 'template') === isVariable.value) return
	if (mode === 'custom') emit('update:modelValue', previousCustomColor.value)
	else if (props.templateColors?.length) selectTemplateVariable(props.templateColors[0].variable)
}
function updateAlpha(value) { emitColor(baseColor.value, Number(value)) }
function updateTextColor(value) { emit('update:modelValue', value) }
function normalizeTextColor(value) {
	const color = parseColor(value)
	if (color.isValid && (props.allowShortHex || !color.isShort) && (props.allowAlpha || !color.hasAlpha)) {
		emitColor(color.baseColor, props.allowAlpha ? color.alpha : 255)
	}
}
function selectPaletteColor(color) {
	const selected = parseColor(color)
	emitColor(selected.baseColor, props.allowAlpha ? selected.alpha : 255)
	paletteDropdown.value?.hide()
}
function emitColor(color, opacity) { emit('update:modelValue', toColorValue(color, props.allowAlpha ? opacity : 255)) }
function getBaseColor(color) { return parseColor(color).baseColor }
function parseColor(value) {
	const match = typeof value === 'string' ? /^#(?:([0-9a-f]{3})|([0-9a-f]{6})([0-9a-f]{2})?)$/i.exec(value) : null
	if (!match) return { isValid: false, hasAlpha: false, baseColor: '#000000', alpha: 255 }
	const base = match[1] ? [...match[1]].map(character => character + character).join('') : match[2]
	return {
		isValid: true, isShort: Boolean(match[1]), hasAlpha: Boolean(match[3]), baseColor: `#${base.toUpperCase()}`,
		alpha: match[3] ? Number.parseInt(match[3], 16) : 255,
	}
}
function toColorValue(color, opacity) {
	const base = parseColor(color).baseColor
	const alphaValue = Math.max(0, Math.min(255, Math.round(Number(opacity))))
	return alphaValue === 255 ? base : `${base}${alphaValue.toString(16).padStart(2, '0').toUpperCase()}`
}
</script>

<style scoped>
.color-input__control { flex: 1; min-width: 0; }
.color-input__mode { flex: none; margin-left: auto; }
.color-input__mode > button { padding: .25rem .4rem; }
.color-input__picker { flex: 0 0 3rem; width: 3rem; }
.color-input__variable-swatch { flex: 0 0 2rem; height: 2rem; border: 1px solid var(--bs-border-color); border-radius: .25rem; }
.color-input__alpha { max-width: 16rem; }
.color-input__value { min-width: 0; max-width: 10em; }
.color-input__palette-dropdown { flex: none; }
.color-input__palette-dropdown :deep(> button) { padding: .25rem .5rem; }
.color-input__palette { display: grid; grid-template-columns: repeat(6, 1.65rem); gap: .5rem; max-height: min(19rem, 55vh); overflow-y: auto; padding: .65rem; }
.color-input__palette-heading { grid-column: 1 / -1; font-size: .75rem; font-weight: 600; }
.color-input__swatch { border: 1px solid var(--bs-border-color); border-radius: .25rem; box-shadow: inset 0 0 0 1px rgb(0 0 0 / 8%); height: 1.65rem; padding: 0; width: 1.65rem; }
.color-input__swatch--selected { outline: 2px solid var(--bs-primary); outline-offset: 2px; }
</style>
