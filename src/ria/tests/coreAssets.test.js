import assert from 'node:assert/strict'
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { test } from 'node:test'
import { readCoreAssets } from '../../tools/core-assets.mjs'
import { getCoreAssetsBase } from '../src/application/coreAssets.js'

function readFixture(source) {
    const directory = mkdtempSync(join(tmpdir(), 'ty-core-version-'))
    try {
        const file = join(directory, 'version.php')
        writeFileSync(file, source)
        return readCoreAssets(file)
    } finally {
        rmSync(directory, { recursive: true, force: true })
    }
}

test('le lecteur construit les chemins DEV et de release sans exécuter PHP', () => {
    for (const version of ['dev', '1_2_3', '1_2_3-beta']) {
        const assets = readFixture(`<?php\r\ndefine('APP_VERSION_STRING', '${version}'); // Version cible\r\n`)
        assert.equal(assets.version, version)
        assert.equal(assets.publicPath, `/assets_core_v${version}`)
        assert.equal(assets.base, `/assets_core_v${version}/plg_core/`)
        assert.ok(assets.directory.endsWith(`/public/assets_core_v${version}/plg_core`))
    }
})

test('les commentaires ne remplacent pas une définition effective', () => {
    const assets = readFixture(`<?php
// define('APP_VERSION_STRING', 'ancienne');
/*
define('APP_VERSION_STRING', 'commentee');
*/
define('APP_VERSION_STRING', '1_2_3');
`)
    assert.equal(assets.version, '1_2_3')
    assert.throws(() => readFixture("<?php\n// define('APP_VERSION_STRING', 'dev');"), /APP_VERSION_STRING/)
})

test('une version absente, ambiguë, calculée ou dangereuse interrompt la compilation', () => {
    for (const source of [
        '<?php',
        "define('APP_VERSION_STRING', 'dev');\ndefine('APP_VERSION_STRING', '1_2_3');",
        "define('APP_VERSION_STRING', 'dev');\ndefine(\"APP_VERSION_STRING\", 'autre');",
        "define('APP_VERSION_STRING', '1_' . '2_3');",
        "define('APP_VERSION_STRING',\n'dev');",
        "define('APP_VERSION_STRING', \"dev\");",
        "define('APP_VERSION_STRING', '');",
        "define('APP_VERSION_STRING', '../../outside');",
        "define('APP_VERSION_STRING', '1.2.3');",
        "define('APP_VERSION_STRING', 'dev/../../outside');",
    ]) {
        assert.throws(() => readFixture(source), /APP_VERSION_STRING/)
    }
    assert.throws(() => readCoreAssets(join(tmpdir(), 'ty-core-version-absente', 'version.php')), /ENOENT/)
})

test('les URL utilisées par le frontend restent dans une base Core versionnée', () => {
    assert.equal(getCoreAssetsBase('/assets_core_v1_2_3/plg_core/'), '/assets_core_v1_2_3/plg_core/')
    for (const base of [undefined, '', '//evil.test/', 'https://evil.test/',
        '/assets_core_v../plg_core/', '/assets_core_vdev/plg_core/../',
        '/assets_core_vdev/plg_core/;script-src https://evil.test/', '/assets_core_vdev/plg_core/\n']) {
        assert.throws(() => getCoreAssetsBase(base), /absente ou invalide/)
    }
})
