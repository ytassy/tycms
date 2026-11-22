import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const sourceRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Lire uniquement le contrat littéral de version.php, sans exécuter de PHP. */
export function readCoreAssets(versionFile = resolve(sourceRoot, 'releases/vdev/version.php')) {
    const source = readFileSync(versionFile, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '');

    // Compter aussi les déclarations mal formées pour refuser une définition ambiguë.
    const declarations = [...source.matchAll(/^[\t ]*define[\t ]*\(\s*(['"])APP_VERSION_STRING\1/gm)];
    const definitions = [...source.matchAll(/^[\t ]*define[\t ]*\([\t ]*'APP_VERSION_STRING'[\t ]*,[\t ]*'([A-Za-z0-9_-]+)'[\t ]*\)[\t ]*;[\t ]*(?:\/\/[^\r\n]*)?\r?$/gm)];

    if (declarations.length !== 1 || definitions.length !== 1) {
        throw new Error('version.php doit définir une seule fois APP_VERSION_STRING sur une ligne, avec une chaîne littérale entre apostrophes composée de lettres, chiffres, tirets ou underscores.');
    }

    // La valeur validée sert à la fois aux URL publiques et aux chemins de sortie.
    const version = definitions[0][1];
    const publicPath = `/assets_core_v${version}`;

    return {
        version,
        publicPath,
        base: `${publicPath}/plg_core/`,
        directory: resolve(sourceRoot, 'public', `assets_core_v${version}`, 'plg_core'),
    };
}
