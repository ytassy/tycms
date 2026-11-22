<template>
	<div class="ce-helper-item-all-of">
		<CeHelperItem
			v-model="dataModel"
			:schema="firstSchema"
			:currentRef="currentRef"
			:required="required"
			:pointer="pointer"
			:local_pointer="local_pointer"
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

		emits: ['update:modelValue', 'change', 'modified' ],

		methods: {
			modified() { this.$emit('modified'); }
		},

				computed:
		{
			firstSchema() { return this.schema.allOf[0]; },
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
