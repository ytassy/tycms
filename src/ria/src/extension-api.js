// Surface publique des extensions : ces registres et symboles restent ceux du Core.
export { useApplication } from './application/context.js';
export { registerRiaPage, registerExtensionStore } from './application/extensionRegistry.js';
export { registerPageForm, registerPageCanvas } from './components/administrator/pages/componentRegistry.js';
export { registerFormFieldComponent } from './components/common/forms/fieldRegistry.js';
export { registerPublicPageComponent } from './application/publicPageRegistry.js';
