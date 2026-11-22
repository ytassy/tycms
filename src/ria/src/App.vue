<template>
	<AppDialogs />
	<component :is="pageComponent" v-bind="pageProps"></component>
	<slot></slot> 
</template>

<script>
import AppDialogs from './components/common/AppDialogs.vue'

export default {
	components: { AppDialogs },
	props: {
		pageComponent: {
			type: [Object, Function],
			required: true,
		},
		pageProps: {
			type: Object,
			default: () => ({}),
		},
	},
}
</script>
