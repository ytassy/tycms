<template>
	<CeItem
		:modelValue="modelValue"
		:schema="schema"
		:currentRef="schema"
		:validations="validations"
		@update:modelValue="updateValue"
		@modified="modified"
		:local_pointer="''"
	>
	</CeItem>
</template>

<script>

		export default
		{
		provide() {
			return {
				all_schemas: this.schemas
			}
		},

		props: {
			modelValue: { },
			schema: { type: Object },
			schemas: { type: Object, default: () => ({}) },
		},

		emits: ['update:modelValue', 'change', 'modified' ],

		mounted() {
		},

		data() {
			return {
				validations: []
			}
		},

		methods:{
			updateValue(value) {
				this.$emit('update:modelValue', value);
				this.$emit('change', value);
			},
			modified() { this.$emit('modified'); }
		},

				computed:
		{
			
		},
		}
</script>
