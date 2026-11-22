<template>
	<input v-model="localValue" type="datetime-local" class="form-control">
</template>

<script>
import moment from 'moment'

export default {
	props: {
		modelValue: { type: String, default: null },
	},
	emits: ['update:modelValue'],
	computed: {
		localValue: {
			get() {
				return this.modelValue && moment(this.modelValue).isValid()
					? moment(this.modelValue).local().format('YYYY-MM-DDTHH:mm')
					: ''
			},
			set(value) {
				if (moment(value).isValid()) this.$emit('update:modelValue', moment(value).utc().format('YYYY-MM-DDTHH:mm:ss[Z]'))
			},
		},
	},
}
</script>
