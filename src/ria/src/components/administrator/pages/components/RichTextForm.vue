<template>
<LocalizedTextField v-model="node.props.content" label="Contenu" mode="rich" :locales="locales" :locale="store.previewLocale"
				:space-id="store.current.space_id" :disabled="!store.current.canEdit" />
		
</template>
<script>
import pageComponentFormMixin from '../pageComponentFormMixin.js'
export default { mixins: [pageComponentFormMixin] }
</script>
