<template>
	<div class="ce-helper-item tbl-100">
		<a v-if="isSet == false" @click="setDefaultValue">
			Définir
		</a>

		<template v-else>
			<span v-if="schema._ui_text != undefined" class="me-3">
				<strong>{{ schema._ui_text }}</strong>

				<div class="fz-80" v-if="schema._ui_description != undefined" v-html="schema._ui_description"></div>
			</span>

			<span class="text-danger" v-if="modelValue != _const">
				<i class="fa-solid fa-exclamation-triangle"></i> {{ modelValue }}
			</span>
			{{ _const }}
		</template>
	</div>
</template>

<script>
	import editorBase from '../base.js';

		export default
		{
		extends: editorBase,

		props: {
			modelValue: { },
			schema: { type: Object },
			required: { default: false, type: Boolean },
			pointer: { default: "", type: String },
			local_pointer: { default: "", type: String },
			validations: { default: null, type: Object },
			context: { default: null, type: Object },
		},

		emits: ['update:modelValue', 'change', 'modified' ],

		mounted() { this.checkDefault(); },
		watch: { modelValue() { this.checkDefault() } },

		methods:{
			setDefaultValue() { this.$emit('update:modelValue', this._const); },
			checkDefault(){ if (this.required && this.isSet == false) this.setDefaultValue(); },
		},

				computed:
		{
			isSet() { return this.modelValue !== null && this.modelValue !== undefined; },

			_const() { return this.schema.const == undefined ? null : this.schema.const; },

			dataModel: {
				get() {
					return this.modelValue;
				},
				set(value) {
					this.$emit('update:modelValue', value);
				}
			}
		},
		}
</script>
