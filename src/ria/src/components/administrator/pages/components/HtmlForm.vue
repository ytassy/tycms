<template>
<LocalizedTextField v-model="node.props.content" label="Contenu HTML" mode="html" :locales="locales" :locale="store.previewLocale"
				:space-id="store.current.space_id" :disabled="!store.current.canEdit" />
		
</template>
<script>
import pageComponentFormMixin from '../pageComponentFormMixin.js'
export default { mixins: [pageComponentFormMixin] }
</script>
