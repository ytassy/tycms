<template>
	<div class="ce-helper-item-ref">
		<!--<icon_picker
			v-model="dataModel"
			:material="true"
			v-if="ref == '/ma_icon' || ref == '/ma_icon_empty'"
		/>

		<icon_picker
			v-model="dataModel"
			:material="false"
			v-else-if="ref == '/fa_icon' || ref == '/fa_icon_empty'"
		/>

		<color_picker
			v-model="dataModel"
			:material="true"
			v-else-if="ref == '/color' || ref == '/color_empty'"
		/>-->


		<CeHelperItem
			
			v-model="dataModel"
			:schema="effectiveSchema"
			:currentRef="effectiveSchema"
			:required="required"
			:pointer="pointer"
			:local_pointer="ref"
			:validations="validations"
			:context="context"
			@modified="modified"
		>
		</CeHelperItem>
	</div>
</template>

<script>
	import editorBase from '../base.js';
	
	export default
	{
		extends: editorBase,

		components: {
		},

		props: {
			modelValue: { },
			schema: { type: Object },
			currentRef: { type: Object },
			required: { default: false, type: Boolean },
			pointer: { default: "", type: String },
			local_pointer: { default: "", type: String },
			validations: { default: null, type: Object },
			context: { default: null, type: Object },
		},

		emits: ['update:modelValue', 'change', 'modified'],

		methods: {
			modified() { this.$emit('modified'); }
		},

		computed:
		{
			ref() { return this.schema['$ref']; },

			effectiveSchema() { return this.getSchemaOfRef(this.ref, this.currentRef); },

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
