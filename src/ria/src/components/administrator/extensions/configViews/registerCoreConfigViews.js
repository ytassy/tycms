import { defineAsyncComponent } from 'vue'
import { registerExtensionConfigView } from '../../../../application/configViewRegistry.js'

// Les vues des extensions Core suivent le même registre que celles des
// extensions installées ; elles vivent seulement dans le bundle du Core.
registerExtensionConfigView('security/config', defineAsyncComponent(() => import('./security/SecurityConfig.vue')))
registerExtensionConfigView('main-template/config', defineAsyncComponent(() => import('./main-template/MainTemplateConfig.vue')))
