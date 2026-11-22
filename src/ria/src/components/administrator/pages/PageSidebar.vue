<template>
	<aside class="page-editor-sidebar" aria-label="Outils de l’éditeur">
		<div class="page-editor-rail" role="tablist" aria-label="Panneaux de l’éditeur" aria-orientation="vertical">
			<button v-for="tab in tabs" :key="tab.id" type="button" role="tab" class="page-editor-tab"
				:class="{ 'page-editor-tab--active': store.activeTab === tab.id }" :aria-selected="store.activeTab === tab.id"
				:aria-controls="`page-panel-${tab.id}`" :title="tab.label" :disabled="store.current.type !== 2 && tab.id !== 'page'" @click="store.activeTab = tab.id">
				<i :class="tab.icon" aria-hidden="true"></i><span>{{ tab.label }}</span>
			</button>
		</div>
		<div class="page-editor-panel" role="tabpanel" :id="`page-panel-${store.activeTab}`">
			<PageAi v-if="canUseAi" v-show="store.activeTab === 'ai'" :key="store.editorSession" />
			<template v-if="store.activeTab !== 'ai' || !canUseAi">
				<PageCatalogue v-if="store.activeTab === 'components' && store.current.type === 2" />
				<PageProperties v-else-if="store.activeTab === 'properties' && store.current.type === 2" />
				<PageTree v-else-if="store.activeTab === 'tree' && store.current.type === 2" />
				<PageSettings v-else />
			</template>
		</div>
	</aside>
</template>

<script>
import PageCatalogue from './PageCatalogue.vue'
import PageProperties from './PageProperties.vue'
import PageSettings from './PageSettings.vue'
import PageTree from './PageTree.vue'
import PageAi from './ai/PageAi.vue'

export default {
	components: { PageCatalogue, PageProperties, PageSettings, PageTree, PageAi },
	data: () => ({ baseTabs: [
		{ id: 'components', label: 'Composants', icon: 'fa-solid fa-cubes' },
		{ id: 'properties', label: 'Propriétés', icon: 'fa-solid fa-sliders' },
		{ id: 'page', label: 'Page', icon: 'fa-solid fa-file-lines' },
		{ id: 'tree', label: 'Structure', icon: 'fa-solid fa-sitemap' },
	] }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		canUseAi() {
			return Boolean(this.store.current?.type === 2 && this.store.current.canEdit
				&& this.acl.hasGlobalPermission('AI_USE', this.store.current.space_id))
		},
		tabs() { return this.canUseAi
			? [...this.baseTabs, { id: 'ai', label: 'IA', icon: 'fa-solid fa-wand-magic-sparkles' }]
			: this.baseTabs },
	},
}
</script>
