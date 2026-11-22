<template>
	<div class="page-ai-panel">
		<div class="page-ai-intro">
			<h2 class="h6 mb-1">Assistance IA (Beta)</h2>
			<p class="small text-body-secondary mb-0">Décrivez une modification. Chaque action proposée est vérifiée avant de changer le brouillon ; enregistrez ensuite la page.</p>
		</div>
		<ai_chat v-if="pageTools.length" :key="`${store.editorSession}:${spaceId}`" :space-id="spaceId"
			use-case="design" :custom-tools="pageTools" :system-prompt="systemPrompt"
			:initial-draft="initialDraft" :max-tool-rounds="15" :max-tool-calls="40" embedded />
	</div>
</template>

<script>
import { createPageAiTools } from './pageAiTools.js'

const systemPrompt = `Tu aides à modifier le brouillon d'une page visuelle Tycms. La structure est un arbre : la racine contient des lignes, chaque ligne contient des colonnes, et chaque colonne contient des composants ou des lignes imbriquées. Utilise get_page_outline pour trouver les ID et les positions, get_component pour lire les paramètres, et get_component_types/get_component_type pour découvrir les types, y compris ceux des extensions. Si un contenu est tronqué, lis-le par segments avec get_component_content et modifie-le avec replace_content_fragment. Lis le brouillon avant toute modification. Appelle de petits Tools ciblés, un changement à la fois. Chaque changement doit être confirmé par l'utilisateur. N'invente pas d'ID ni de champs. Après une modification, le brouillon n'est pas encore enregistré : invite l'utilisateur à cliquer sur Enregistrer quand il a terminé.`

export default {
	data: () => ({ pageTools: [], systemPrompt, initialDraft: '' }),
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		spaceId() { return this.store.current?.space_id ?? 0 },
	},
	watch: {
		spaceId() { this.resetTools() },
	},
	created() { this.resetTools() },
	methods: {
		resetTools() {
			this.pageTools = this.store.current?.type === 2 ? createPageAiTools({ store: this.store }) : []
		},
	},
}
</script>

<style scoped>
.page-ai-panel { display: flex; flex-direction: column; height: 100%; min-height: 0; padding: .75rem; }
.page-ai-intro { flex: none; margin-bottom: .75rem; }
.page-ai-panel :deep(.ai-chat) { flex: 1 1 auto; min-height: 0; max-height: none; }
.page-ai-panel :deep(.ai-chat-history) { min-height: 0; }
</style>
