import { test } from 'node:test';
import assert from 'node:assert/strict';
import { registerRiaPage, resolveRiaPage, registerExtensionStore, createExtensionStores } from '../src/application/extensionRegistry.js';

test('les pages conservent leur composant et refusent les collisions', () => {
    const component = { name: 'LmsHome' };
    registerRiaPage('lms/home', component);
    assert.equal(resolveRiaPage('lms/home'), component);
    assert.throws(() => registerRiaPage('lms/home', {}));
    assert.throws(() => registerRiaPage('core/home', {}));
});

test('les factories reçoivent le contexte de leur racine et ne remplacent pas Core', () => {
    const app = {};
    const application = { stores: {} };
    registerExtensionStore('useLmsStore', { get: (vueApp, context) => ({ vueApp, context }) });
    const result = createExtensionStores(app, application, []);
    assert.equal(result.useLmsStore.vueApp, app);
    assert.equal(result.useLmsStore.context, application);
    assert.throws(() => createExtensionStores(app, application, ['useLmsStore']));
    assert.throws(() => registerExtensionStore('useLmsStore', { get() {} }));
});
