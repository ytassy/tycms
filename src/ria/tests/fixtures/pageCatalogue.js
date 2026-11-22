import { readFileSync } from 'node:fs'
import { setPageComponentDefinitions } from '../../src/components/administrator/pages/pageEditorModel.js'

// Métadonnées exportées du catalogue PHP ; les tests n'inventent pas de valeurs par défaut.
export const pageCatalogue = JSON.parse(readFileSync(new URL('./pageCatalogue.json', import.meta.url), 'utf8'))
setPageComponentDefinitions(pageCatalogue)
