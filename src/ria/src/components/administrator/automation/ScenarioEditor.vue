<template>
    <div class="automation-editor">
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <button type="button" class="btn btn-outline-secondary btn-sm esc" :disabled="saving" @click="requestClose">
                <i class="fa-solid fa-arrow-left me-1" aria-hidden="true" />Retour
            </button>
            <div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <h2 class="h4 mb-0">{{ isNew ? 'Nouveau scénario' : detail?.name }}</h2>
                    <code v-if="!isNew" class="small text-body-secondary">{{ detail?.key }}</code>
                    <span v-if="!isNew && detail?.published" class="badge text-bg-success">
                        Publié v{{ detail.published.version_number }}
                    </span>
                    <span v-else-if="!isNew" class="badge text-bg-secondary">Brouillon</span>
                    <span v-if="!isNew && detail?.is_dirty" class="badge text-bg-warning">Modifications non publiées</span>
                </div>
                <p class="text-body-secondary mb-0 small">
                    {{ isNew ? 'Créez un brouillon, vérifiez-le puis publiez-le pour l’activer.' : `Espace : ${detail?.space_title || '—'}` }}
                </p>
            </div>
        </div>

        <div v-if="error" class="alert alert-warning" role="alert">{{ error }}</div>
        <div v-if="notice" class="alert alert-success" role="status">{{ notice }}</div>

        <div v-if="loading" class="py-5 text-center"><spinner /></div>

        <div v-else-if="detail || isNew" class="row g-3">
            <div class="col-lg-4 order-2">
                <section class="card h-100">
                    <div class="card-header"><h3 class="h5 mb-0">Paramètres</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="automation-space">Espace</label>
                            <select v-if="isNew" id="automation-space" v-model.number="form.space_id"
                                class="form-select" :disabled="!canSave || saving">
                                <option v-for="space in creatableSpaces" :key="space.id" :value="space.id">
                                    {{ space.title }}
                                </option>
                            </select>
                            <input v-else id="automation-space" type="text" class="form-control"
                                :value="detail?.space_title" disabled />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-key">Clé technique</label>
                            <div v-if="isNew" class="input-group">
                                <input id="automation-key" v-model.trim="form.key" type="text" class="form-control"
                                    maxlength="64" placeholder="ex. bienvenue-inscription"
                                    :disabled="!canSave || saving" @input="keyTouched = true" />
                                <button type="button" class="btn btn-outline-secondary" :disabled="!canSave || saving"
                                    @click="generateKey">Générer</button>
                            </div>
                            <input v-else id="automation-key" type="text" class="form-control"
                                :value="detail?.key" disabled />
                            <div class="form-text">Unique dans l’espace, minuscules, chiffres, tirets et underscores.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-name">Nom</label>
                            <input id="automation-name" v-model.trim="form.name" type="text" class="form-control"
                                maxlength="150" required :disabled="!canSave || saving" @blur="suggestKeyFromName" />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-description">Description</label>
                            <textarea id="automation-description" v-model.trim="form.description" class="form-control"
                                rows="2" maxlength="2000" :disabled="!canSave || saving" />
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-principal-mode">Identité d’exécution</label>
                            <select id="automation-principal-mode" v-model="form.principal_mode" class="form-select"
                                :disabled="!triggerConfigEditable || requiresAuthor || saving">
                                <option value="trigger" :disabled="requiresAuthor">Utilisateur qui déclenche</option>
                                <option value="author">Auteur du scénario</option>
                            </select>
                            <div class="form-text">{{ modeDescription }}</div>
                            <div v-if="form.principal_mode === 'author' && detail?.execution_user" class="form-text">
                                Auteur publié : {{ detail.execution_user.label }}
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-trigger">Déclencheur</label>
                            <select id="automation-trigger" v-model="form.trigger_id" class="form-select"
                                :disabled="!triggerConfigEditable || saving" @change="triggerChanged">
                                <option v-if="!triggerOptions.length" value="">Aucun déclencheur disponible</option>
                                <option v-for="trigger in triggerOptions" :key="trigger.id" :value="trigger.id">
                                    {{ trigger.label }}
                                </option>
                            </select>
                            <div v-if="selectedTrigger?.description" class="form-text">{{ selectedTrigger.description }}</div>
                        </div>

                        <div v-if="isWebhookTrigger" class="border-top pt-3 mt-3">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <h4 class="h6 mb-0">Webhook</h4>
                                <span :class="['badge', secretConfigured ? 'text-bg-success' : 'text-bg-secondary']">
                                    {{ secretConfigured ? 'Jeton configuré' : 'Jeton absent' }}
                                </span>
                            </div>

                            <input_switch v-model="triggerEnabled" :disabled="!triggerConfigEditable">
                                Déclencheur actif
                            </input_switch>

                            <div class="mt-3 mb-3">
                                <label class="form-label" for="automation-webhook-mode">Authentification</label>
                                <select id="automation-webhook-mode" v-model="triggerConfig.auth_mode" class="form-select"
                                    :disabled="!triggerConfigEditable">
                                    <option value="bearer">Bearer (POST)</option>
                                    <option value="query">Jeton en query (GET ou POST)</option>
                                    <option value="hmac">Signature HMAC (POST)</option>
                                </select>
                                <div class="form-text">{{ webhookModeDescription }}</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="automation-webhook-response">Réponse</label>
                                <select id="automation-webhook-response" v-model="triggerConfig.response_mode"
                                    class="form-select" :disabled="!triggerConfigEditable">
                                    <option value="accepted">Réponse générique acceptée</option>
                                    <option value="result">Renvoyer le résultat du scénario</option>
                                </select>
                                <div class="form-text">{{ webhookResponseDescription }}</div>
                                <pre class="small bg-body-tertiary border rounded p-2 mt-2 mb-0">{{ webhookResponseExample }}</pre>
                                <div v-if="triggerConfig.response_mode === 'result'" class="form-text text-warning mt-2">
                                    Le résultat peut contenir des données du scénario. Ne l’activez que pour un appelant de confiance.
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-label">URL</div>
                                <div class="input-group input-group-sm">
                                    <input :value="webhookUrl" type="text" class="form-control font-monospace" readonly
                                        aria-label="URL du webhook" />
                                    <text_copy :text-to-copy="webhookUrl" button-class="btn btn-outline-secondary"
                                        title="Copier l’URL" />
                                </div>
                                <div class="form-text">{{ webhookMethodHint }}</div>
                            </div>

                            <div class="mb-3">
                                <div class="form-label">Exemple de charge utile</div>
                                <pre class="small bg-body-tertiary border rounded p-2 mb-0">{{ webhookPayloadExample }}</pre>
                            </div>

                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <button type="button" class="btn btn-outline-primary btn-sm"
                                    :disabled="!canGenerateSecret" @click="generateWebhookSecret">
                                    <i class="fa-solid fa-key me-1" aria-hidden="true" />
                                    {{ secretConfigured ? 'Régénérer' : 'Générer' }} le secret
                                </button>
                                <span class="small text-body-secondary">{{ webhookSecretHint }}</span>
                            </div>

                            <div v-if="detail?.published && !detail?.can_publish" class="form-text text-warning mt-2">
                                La modification du déclencheur d’un scénario publié requiert la permission PUBLISH.
                            </div>
                        </div>

                        <div v-else-if="isScheduleTrigger" class="border-top pt-3 mt-3">
                            <h4 class="h6 mb-2">Planification</h4>

                            <input_switch v-model="triggerEnabled" :disabled="!triggerConfigEditable">
                                Déclencheur actif
                            </input_switch>

                            <div class="mt-3 mb-3">
                                <label class="form-label" for="automation-schedule-preset">Fréquence</label>
                                <select id="automation-schedule-preset" v-model="triggerConfig.preset" class="form-select"
                                    :disabled="!triggerConfigEditable">
                                    <option value="every_15_minutes">Toutes les 15 minutes</option>
                                    <option value="hourly">Toutes les heures</option>
                                    <option value="daily">Tous les jours</option>
                                    <option value="weekly">Toutes les semaines</option>
                                </select>
                            </div>

                            <div v-if="showScheduleTime" class="mb-3">
                                <label class="form-label" for="automation-schedule-time">Heure</label>
                                <input id="automation-schedule-time" v-model="triggerConfig.time" type="time"
                                    class="form-control" :disabled="!triggerConfigEditable" />
                            </div>

                            <div v-if="triggerConfig.preset === 'weekly'" class="mb-3">
                                <label class="form-label" for="automation-schedule-weekday">Jour</label>
                                <select id="automation-schedule-weekday" v-model.number="triggerConfig.weekday"
                                    class="form-select" :disabled="!triggerConfigEditable">
                                    <option v-for="day in scheduleWeekdays" :key="day.value" :value="day.value">
                                        {{ day.label }}
                                    </option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label" for="automation-schedule-timezone">Fuseau du Space</label>
                                <input id="automation-schedule-timezone" :value="scheduleTimezone" type="text"
                                    class="form-control" readonly />
                            </div>

                            <div class="mb-0">
                                <div class="form-label">Prochaine exécution</div>
                                <div class="small">{{ scheduleNextRunLabel }}</div>
                            </div>

                            <div v-if="detail?.published && !detail?.can_publish" class="form-text text-warning mt-2">
                                La modification du déclencheur d’un scénario publié requiert la permission PUBLISH.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label" for="automation-input">Entrée de test (<code>$input</code>)</label>
                            <textarea id="automation-input" v-model="inputText" class="form-control font-monospace"
                                rows="5" spellcheck="false" :disabled="saving || (!canSave && !detail?.can_run)" />
                            <div v-if="inputError" class="form-text text-danger">{{ inputError }}</div>
                            <div class="form-text">Objet JSON transmis aux vérifications et aux exécutions manuelles.</div>
                        </div>

                    </div>
                </section>
            </div>

            <div class="col-lg-8 order-1">
                <section class="card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h3 class="h5 mb-0">Source</h3>
                        <span class="small text-body-secondary">{{ sourceBytes }} / 65536 octets</span>
                    </div>
                    <div class="card-body">
                        <code_editor v-model="form.source" syntax="javascript" :max-bytes="65536"
                            :read-only="!canSave || saving" input-id="automation-source"
                            aria-label="Source du scénario" />
                        <p class="form-text mb-0">
                            Langage maison : <code>function main()</code> obligatoire, <code>$input</code>,
                            <code>$principal</code>, <code>$space</code> et <code>$run</code> en lecture seule.
                        </p>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button type="button" class="btn btn-primary ctrl-s" :disabled="!canPersist || saving" @click="save">
                                <i class="fa-solid fa-floppy-disk me-1" aria-hidden="true" />Enregistrer
                            </button>
                            <button type="button" class="btn btn-success" :disabled="!canPublish || (isNew && !canPersist) || saving" @click="publish">
                                <i class="fa-solid fa-upload me-1" aria-hidden="true" />Publier
                            </button>
                            <button type="button" class="btn btn-outline-primary"
                                :disabled="!canSave || saving || dryRunning" title="Exécute la source courante en lecture seule ; les écritures et envois sont refusés."
                                @click="dryRun">
                                <i class="fa-solid fa-flask me-1" aria-hidden="true" />Vérifier
                            </button>
                            <button type="button" class="btn btn-outline-success"
                                :disabled="!canRun || saving || running" :title="runHint" @click="run">
                                <i class="fa-solid fa-play me-1" aria-hidden="true" />Exécuter
                            </button>
                            <button v-if="detail?.can_archive" type="button" class="btn btn-outline-danger ms-auto"
                                :disabled="saving" @click="archive">
                                <i class="fa-solid fa-box-archive me-1" aria-hidden="true" />Archiver
                            </button>
                        </div>

                        <div v-if="shownDiagnostics.length" class="mt-3">
                            <div class="small fw-semibold mb-2">
                                Diagnostics
                                <span v-if="isDirty" class="text-body-secondary fw-normal">(du dernier enregistrement)</span>
                            </div>
                            <Diagnostics :items="shownDiagnostics" />
                        </div>

                        <details v-if="actions.length" class="automation-reference mt-3">
                            <summary class="small fw-semibold">Actions disponibles ({{ actions.length }})</summary>
                            <div class="automation-reference-body mt-2">
                                <details v-for="action in actions" :key="action.id" class="automation-action">
                                    <summary>
                                        <span class="d-flex flex-wrap align-items-center gap-2">
                                            <text_copy :text-to-copy="action.id" title="Copier le nom de l’action" @click.stop>
                                                <code class="small fw-semibold">{{ action.id }}</code>
                                            </text_copy>
                                            <span :class="['badge', effectClass(action.effect)]">{{ effectLabel(action.effect) }}</span>
                                            <span class="small text-body-secondary">retourne <code>{{ action.return_type }}</code></span>
                                            <span class="small text-body-secondary ms-auto">{{ action.label }}</span>
                                        </span>
                                    </summary>
                                    <div class="automation-action-body">
                                        <div class="small text-body-secondary mb-1">{{ action.description }}</div>
                                        <div v-if="action.parameters.length" class="table-responsive">
                                            <table class="table table-sm table-borderless automation-params mb-1">
                                                <thead>
                                                    <tr><th>Paramètre</th><th>Type</th><th>Requis</th><th>Description</th></tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="parameter in action.parameters" :key="parameter.name">
                                                        <td>
                                                            <code class="small">{{ parameter.name }}</code>
                                                            <div v-if="parameter.label" class="small text-body-secondary">{{ parameter.label }}</div>
                                                        </td>
                                                        <td><code class="small">{{ parameter.type }}</code></td>
                                                        <td>
                                                            <span :class="['badge', parameter.required ? 'text-bg-warning' : 'text-bg-secondary']">
                                                                {{ parameter.required ? 'Oui' : 'Non' }}
                                                            </span>
                                                        </td>
                                                        <td class="small">
                                                            {{ parameter.description || '—' }}
                                                            <div v-if="parameter.default !== null && parameter.default !== undefined"
                                                                class="text-body-secondary">
                                                                Défaut : <code>{{ formatDefault(parameter.default) }}</code>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </table>
                                        </div>
                                        <div v-else class="small text-body-secondary">Aucun paramètre.</div>
                                        <div v-if="action.required_permissions.length" class="small text-body-secondary">
                                            Permissions :
                                            <code v-for="permission in action.required_permissions" :key="permission"
                                                class="me-2">{{ permission }}</code>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </details>

                        <details v-if="builtins.length || statements.length" class="automation-reference mt-3">
                            <summary class="small fw-semibold">
                                Fonctions standard et contrôle du flux ({{ builtins.length + statements.length }})
                            </summary>
                            <div class="automation-reference-body mt-2">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                    <span class="small text-body-secondary">Signatures et descriptions des instructions et fonctions intégrées.</span>
                                    <a class="btn btn-outline-secondary btn-sm" :href="referenceUrl" download>
                                        <i class="fa-solid fa-download me-1" aria-hidden="true" />Télécharger la référence
                                    </a>
                                </div>
                                <div v-if="statements.length" class="mb-2">
                                    <div class="small fw-semibold mb-1">Contrôle du flux</div>
                                    <ul class="list-unstyled mb-0">
                                        <li v-for="statement in statements" :key="statement.id" class="mb-1">
                                            <text_copy :text-to-copy="statement.id" title="Copier le nom de l’instruction">
                                                <code class="small">{{ statement.signature }}</code>
                                            </text_copy>
                                            <span class="small text-body-secondary"> — {{ statement.description }}</span>
                                        </li>
                                    </ul>
                                </div>
                                <div v-for="group in builtinGroups" :key="group.namespace" class="mb-2">
                                    <div class="small fw-semibold mb-1">{{ group.namespace }}</div>
                                    <ul class="list-unstyled mb-0">
                                        <li v-for="functionReference in group.functions" :key="functionReference.id" class="mb-1">
                                            <text_copy :text-to-copy="functionReference.id" title="Copier le nom de la fonction">
                                                <code class="small">{{ functionReference.signature }}</code>
                                            </text_copy>
                                            <span class="small text-body-secondary"> — {{ functionReference.description }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </details>

                        <details class="automation-reference mt-3">
                            <summary class="small fw-semibold">Exemples de code et variables de contexte</summary>
                            <div class="automation-reference-body mt-2">
                                <p class="small text-body-secondary">
                                    Les variables <code>$input</code>, <code>$principal</code>, <code>$space</code> et
                                    <code>$run</code> sont en lecture seule. <code>$input</code> est non fiable ; les
                                    autres sont fournies par le moteur.
                                </p>

                                <div v-for="example in examples" :key="example.title" class="mb-3">
                                    <div class="small fw-semibold">{{ example.title }}</div>
                                    <pre class="automation-json">{{ example.code }}</pre>
                                </div>

                                <div v-for="context in contextVariables" :key="context.name" class="mb-3">
                                    <div class="d-flex flex-wrap align-items-center gap-2">
                                        <div class="small fw-semibold"><code>{{ context.name }}</code></div>
                                        <span v-if="context.readonly" class="badge text-bg-secondary">Lecture seule</span>
                                    </div>
                                    <div class="small text-body-secondary">{{ context.description }}</div>
                                    <div v-if="context.fields?.length" class="table-responsive mt-1">
                                        <table class="table table-sm table-borderless automation-params mb-1">
                                            <thead>
                                                <tr><th>Champ</th><th>Type</th><th>Requis</th><th>Description</th></tr>
                                            </thead>
                                            <tbody>
                                                <tr v-for="field in context.fields" :key="field.name">
                                                    <td><code class="small">{{ context.name }}->{{ field.name }}</code></td>
                                                    <td><code class="small">{{ field.type }}</code></td>
                                                    <td>
                                                        <span :class="['badge', field.required ? 'text-bg-warning' : 'text-bg-secondary']">
                                                            {{ field.required ? 'Oui' : 'Non' }}
                                                        </span>
                                                    </td>
                                                    <td class="small">{{ field.description || '—' }}</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                    <pre v-if="context.example" class="automation-json mt-1">{{ prettyJson(context.example) }}</pre>
                                </div>

                                <p class="small text-body-secondary mb-0">
                                    Le schéma de <code>$input</code> suit le déclencheur sélectionné. Les dates, comme
                                    <code>$run->startedAt</code>, sont des valeurs <code>date</code> à formater avec
                                    <code>date::format</code>.
                                </p>
                            </div>
                        </details>
                    </div>
                </section>

                <section v-if="result" class="card mt-3">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
                        <h3 class="h5 mb-0">{{ result.kind === 'dry' ? 'Simulation' : `Run #${result.run_id}` }}</h3>
                        <span :class="['badge', statusClass(result.status)]">{{ statusLabel(result.status) }}</span>
                    </div>
                    <div class="card-body">
                        <p v-if="result.message" class="mb-2">{{ result.message }}</p>
                        <Diagnostics :items="result.diagnostics ?? []" />
                        <div class="row g-2 small text-body-secondary mt-2">
                            <div class="col-md-4">Étapes : {{ result.steps ?? 0 }}</div>
                            <div class="col-md-4">Actions : {{ result.action_calls ?? 0 }}</div>
                            <div class="col-md-4">Durée : {{ result.duration_ms ?? 0 }} ms</div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label small fw-semibold">Sortie</label>
                            <pre class="automation-json">{{ prettyJson(result.output) || '—' }}</pre>
                        </div>
                        <p v-if="result.kind === 'dry'" class="form-text mb-0">
                            La simulation n’enregistre ni run ni étape. Les actions d’écriture et les envois externes sont refusés.
                        </p>
                    </div>
                </section>
            </div>

            <div v-if="currentId" class="col-12 order-3">
                <RunHistory ref="history" :scenario-id="currentId"
                    :can-manage-runs="!!detail?.can_manage_runs" />
            </div>
        </div>

        <modal :visible="secretModal.open" width="640px" closable @close="closeSecretModal">
            <template #header>{{ secretModal.title }}</template>
            <template #body>
                <p class="mb-3">{{ secretModal.description }}</p>
                <div class="input-group">
                    <input :value="secretModal.value" type="text" class="form-control font-monospace" readonly
                        aria-label="Secret généré" />
                    <text_copy :text-to-copy="secretModal.value" button-class="btn btn-outline-secondary"
                        title="Copier le secret" />
                </div>
                <p v-if="secretModal.hint" class="form-text mt-2">{{ secretModal.hint }}</p>
                <p class="form-text text-warning mb-0">
                    Ce secret ne sera plus affiché après la fermeture. Conservez-le dans votre gestionnaire de secrets.
                </p>
            </template>
            <template #footer>
                <button type="button" class="btn btn-primary esc" @click="closeSecretModal">J’ai conservé le secret</button>
            </template>
        </modal>
    </div>
</template>

<script>
import Diagnostics from './Diagnostics.vue'
import RunHistory from './RunHistory.vue'
import {
    effectClass,
    effectLabel,
    formatDate,
    prettyJson,
    slugify,
    statusClass,
    statusLabel,
} from './format.js'

export default {
    components: { Diagnostics, RunHistory },

    props: {
        scenarioId: { type: Number, default: null },
        directory: { type: Object, default: null },
    },

    emits: ['close', 'saved'],

    data() {
        return {
            currentId: this.scenarioId ? Number(this.scenarioId) : null,
            detail: null,
            loading: false,
            saving: false,
            dryRunning: false,
            running: false,
            error: '',
            notice: '',
            form: {
                space_id: 0,
                key: '',
                name: '',
                description: '',
                principal_mode: 'trigger',
                trigger_id: '',
                source: '',
            },
            triggerConfig: { auth_mode: 'bearer', preset: 'daily', time: '09:00', weekday: 1 },
            triggerEnabled: true,
            secretGenerating: false,
            secretModal: {
                open: false,
                title: '',
                description: '',
                value: '',
                hint: '',
            },
            scheduleWeekdays: [
                { value: 1, label: 'Lundi' },
                { value: 2, label: 'Mardi' },
                { value: 3, label: 'Mercredi' },
                { value: 4, label: 'Jeudi' },
                { value: 5, label: 'Vendredi' },
                { value: 6, label: 'Samedi' },
                { value: 7, label: 'Dimanche' },
            ],
            savedSnapshot: '',
            keyTouched: false,
            inputText: '{}',
            inputError: '',
            result: null,
            publishDiagnostics: [],
            referenceUrl: '/administrator/api/automation/reference',
            examples: [
                {
                    title: 'Exemple complet de syntaxe et grammaire',
                    code: [
                        '// Exemple complet de syntaxe. Les commentaires // et /* */ sont acceptés.',
                        '/* Les fonctions sont déclarées à la racine et hoistées. */',
                        '',
                        'function main() {',
                        '    // Types simples : null, bool, int, float et string.',
                        '    $enabled = $input?->enabled ?? true;',
                        '    $blocked = $input?->blocked == true;',
                        '    $count = 0;',
                        '    $ratio = 2.5;',
                        '    $label = "démo";',
                        '',
                        '    // Tableaux : liste et map.',
                        '    $numbers = [1, 2, 3, 4, 5];',
                        '    $settings = [',
                        '        "retries" => 2,',
                        '        "mode" => "auto"',
                        '    ];',
                        '',
                        '    // Accès par index, par clé et navigation sécurisée.',
                        '    $first = $numbers[0];',
                        '    $mode = $settings["mode"];',
                        '    $retries = $settings->retries;',
                        '    $missing = $settings?->missing ?? "valeur par défaut";',
                        '',
                        '    // Expressions : calcul, concaténation, comparaison et logique.',
                        '    $count = arr::length($numbers);',
                        '    $sum = $first + $retries + $ratio;',
                        '    $text = $label . " : " . $sum;',
                        '    $isReady = $enabled and not $blocked;',
                        '    $hasFallback = ($retries > 0) or ($missing != "");',
                        '',
                        '    // Conditions, elseif et ternaire.',
                        '    $modeLabel = "inconnu";',
                        '    if ($mode == "auto") {',
                        '        $modeLabel = "automatique";',
                        '    } elseif ($mode == "manual") {',
                        '        $modeLabel = "manuel";',
                        '    } else {',
                        '        $modeLabel = "inconnu";',
                        '    }',
                        '',
                        '    $plural = $count > 1 ? "valeurs" : "valeur";',
                        '',
                        '    // foreach sur une liste avec continue et break.',
                        '    $evenTotal = 0;',
                        '    foreach ($numbers as $number) {',
                        '        if ($number % 2 != 0) {',
                        '            continue;',
                        '        }',
                        '        if ($number > 4) {',
                        '            break;',
                        '        }',
                        '        $evenTotal = $evenTotal + $number;',
                        '    }',
                        '',
                        '    // foreach sur une map avec clé et valeur.',
                        '    $modeFromMap = $mode;',
                        '    foreach ($settings as $key => $value) {',
                        '        if ($key == "mode") {',
                        '            $modeFromMap = $value;',
                        '        }',
                        '    }',
                        '',
                        '    // Boucle for avec compteur.',
                        '    $forTotal = 0;',
                        '    for ($i = 0; $i < 3; $i = $i + 1) {',
                        '        $forTotal = $forTotal + $i;',
                        '    }',
                        '',
                        '    // Boucle while avec arrêt explicite.',
                        '    $attempts = 0;',
                        '    while (true) {',
                        '        $attempts = $attempts + 1;',
                        '        if ($attempts >= 2) {',
                        '            break;',
                        '        }',
                        '    }',
                        '',
                        '    // Appartenance, fonctions standard et fonctions utilisateur.',
                        '    $found = $evenTotal in [0, 2, 6];',
                        '',
                        '    // Opérateurs logiques : and, or, not.',
                        '    // &&, || et ! ne sont pas acceptés ; le langage exige les mots-clés.',
                        '    $logicLabel = "non";',
                        '    if (($found and not $blocked) or $enabled) {',
                        '        $logicLabel = "oui";',
                        '    }',
                        '',
                        '    $clamped = math::clamp($forTotal, 0, 100);',
                        '    $line = formatLine($modeLabel, $clamped);',
                        '    $optional = optionalLabel($input?->label);',
                        '',
                        '    // Les variables de contexte sont en lecture seule.',
                        '    $spaceName = $space->name;',
                        '    $principalName = $principal?->firstName ?? "visiteur";',
                        '',
                        '    // Arrêts explicites, seulement sur un cas volontairement choisi.',
                        '    if ($input?->mode == "stop") {',
                        '        stop("Arrêt demandé.");',
                        '    }',
                        '    if ($input?->mode == "fail") {',
                        '        fail("Échec demandé.");',
                        '    }',
                        '',
                        '    // Une réponse res::* peut être affichée par un bouton de page.',
                        '    if ($input?->mode == "respond") {',
                        '        return res::confirm("Traitement terminé : " . $evenTotal . ".");',
                        '    }',
                        '',
                        '    // Retour final sous forme de map.',
                        '    return [',
                        '        "text" => $text,',
                        '        "mode" => $modeLabel,',
                        '        "plural" => $plural,',
                        '        "sum" => $sum,',
                        '        "evenTotal" => $evenTotal,',
                        '        "forTotal" => $forTotal,',
                        '        "attempts" => $attempts,',
                        '        "found" => $found,',
                        '        "logic" => $logicLabel,',
                        '        "ready" => $isReady,',
                        '        "hasFallback" => $hasFallback,',
                        '        "missing" => $missing,',
                        '        "modeFromMap" => $modeFromMap,',
                        '        "line" => $line,',
                        '        "optional" => $optional ?? "aucune valeur",',
                        '        "space" => $spaceName,',
                        '        "principal" => $principalName',
                        '    ];',
                        '}',
                        '',
                        'function formatLine($label, $value) {',
                        '    return str::upper($label) . " : " . $value;',
                        '}',
                        '',
                        'function optionalLabel($value) {',
                        '    if ($value == null) {',
                        '        return;',
                        '    }',
                        '',
                        '    return "Libellé : " . $value;',
                        '}',
                    ].join('\n'),
                },
                {
                    title: 'Lire l’entrée et appeler une action',
                    code: [
                        'function fullName($user) {',
                        '    return $user->firstName . " " . $user->lastName;',
                        '}',
                        '',
                        'function main() {',
                        '    $user = core::getUser($input->user->id);',
                        '    util::log("Compte reçu : " . fullName($user));',
                        '',
                        '    if ($principal->type == "user") {',
                        '        core::sendMailTemplate("welcome", $user->email, [',
                        '            "firstName" => $user->firstName',
                        '        ]);',
                        '    }',
                        '',
                        '    return res::confirm("Le compte de " . fullName($user) . " a été traité.");',
                        '}',
                    ].join('\n'),
                },
                {
                    title: 'Boucle, condition et arrêt',
                    code: [
                        'function main() {',
                        '    if ($input->items == null) {',
                        '        stop("Rien à traiter.");',
                        '    }',
                        '',
                        '    $total = 0;',
                        '    foreach ($input->items as $item) {',
                        '        $total = $total + $item->amount;',
                        '    }',
                        '',
                        '    return ["total" => $total, "email" => $principal->email];',
                        '}',
                    ].join('\n'),
                },
                {
                    title: 'Utiliser la date, le Space et le run',
                    code: [
                        'function main() {',
                        '    $now = date::now();',
                        '',
                        '    return [',
                        '        "space" => $space->name,',
                        '        "today" => date::format($now, "Y-m-d"),',
                        '        "trigger" => $run->trigger',
                        '    ];',
                        '}',
                    ].join('\n'),
                },
                {
                    title: 'Répondre au demandeur avec res::*',
                    code: [
                        'function main() {',
                        '    if ($input->user == null) {',
                        '        return res::error("Le compte demandé est introuvable.");',
                        '    }',
                        '',
                        '    $user = core::getUser($input->user->id);',
                        '',
                        '    if ($user == null) {',
                        '        return res::warning("Ce compte n’existe plus.");',
                        '    }',
                        '',
                        '    // Affiché par le bouton de page en mode « Résultat du scénario ».',
                        '    // Hors de ce mode, la réponse reste dans l’historique du run.',
                        '    return res::confirm("Le compte de " . $user->firstName . " est à jour.");',
                        '}',
                    ].join('\n'),
                },
            ],
        }
    },

    computed: {
        isNew() {
            return this.currentId === null
        },
        spaces() {
            return this.directory?.spaces ?? []
        },
        creatableSpaces() {
            return this.spaces.filter(space => space.can_create)
        },
        selectedSpace() {
            return this.spaces.find(space => space.id === this.form.space_id) ?? null
        },
        triggers() {
            return this.directory?.triggers ?? []
        },
        triggerOptions() {
            const options = [...this.triggers]
            if (this.form.trigger_id && !options.some(trigger => trigger.id === this.form.trigger_id)) {
                options.unshift({
                    id: this.form.trigger_id,
                    label: `${this.form.trigger_id} (indisponible)`,
                    description: 'Le déclencheur n’est plus fourni par une extension active.',
                })
            }

            return options
        },
        selectedTrigger() {
            return this.triggerOptions.find(trigger => trigger.id === this.form.trigger_id) ?? null
        },
        isWebhookTrigger() {
            return this.form.trigger_id === 'automation.webhook.received'
        },
        isScheduleTrigger() {
            return this.form.trigger_id === 'automation.schedule.tick'
        },
        requiresAuthor() {
            return this.isWebhookTrigger || this.isScheduleTrigger
        },
        triggerConfigEditable() {
            return this.canSave && (!this.detail?.published || !!this.detail?.can_publish)
        },
        secretConfigured() {
            return !!this.detail?.secret_configured
                && this.webhookMode === (this.detail?.auth_mode ?? 'bearer')
        },
        webhookMode() {
            return this.triggerConfig.auth_mode ?? 'bearer'
        },
        webhookModeDescription() {
            return {
                bearer: 'Le demandeur envoie Authorization: Bearer <jeton>. Corps JSON ou formulaire en POST.',
                query: 'Le jeton est placé dans tycms_token, en query ou dans le corps. GET et POST acceptés ; le jeton est retiré de $input.',
                hmac: 'Le demandeur signe timestamp . "." . corps brut avec X-Tycms-Timestamp et X-Tycms-Signature: sha256=… en POST.',
            }[this.webhookMode] ?? ''
        },
        webhookMethodHint() {
            return {
                bearer: 'Méthode POST · Content-Type application/json ou formulaire.',
                query: 'Méthodes GET et POST · jeton tycms_token.',
                hmac: 'Méthode POST · en-têtes X-Tycms-Timestamp et X-Tycms-Signature.',
            }[this.webhookMode] ?? ''
        },
        webhookResponseDescription() {
            return this.triggerConfig.response_mode === 'result'
                ? 'Le webhook attend la fin du run et renvoie son statut et sa sortie.'
                : 'Le webhook répond immédiatement que l’événement est accepté, sans la sortie du run.'
        },
        webhookResponseExample() {
            if (this.triggerConfig.response_mode === 'result') {
                return prettyJson({
                    accepted: true,
                    run_id: 49,
                    status: 'success',
                    output: { message_level: 'WARNING', message: 'coucou' },
                    message: null,
                })
            }

            return prettyJson({ accepted: true })
        },
        webhookUrl() {
            return this.detail?.webhook_url
                ?? (this.currentId ? `/automation/webhook/${this.currentId}` : '/automation/webhook/{scenarioId}')
        },
        webhookPayloadExample() {
            return prettyJson(this.selectedTrigger?.payload_example ?? {}) || '{}'
        },
        webhookSecretHint() {
            if (!this.detail) return 'Enregistrez le scénario avant de générer un secret.'
            if (!this.detail.can_publish) return 'La génération requiert la permission PUBLISH.'
            return this.secretConfigured
                ? 'Le secret est conservé. Une régénération invalide immédiatement l’ancien.'
                : 'Aucun secret actif : les appels seront refusés.'
        },
        canGenerateSecret() {
            return !!this.detail
                && !!this.detail.can_publish
                && this.triggerConfigEditable
                && !this.secretGenerating
                && !this.saving
        },
        showScheduleTime() {
            return this.triggerConfig.preset === 'daily' || this.triggerConfig.preset === 'weekly'
        },
        scheduleTimezone() {
            return this.detail?.space_timezone ?? this.selectedSpace?.timezone ?? '—'
        },
        scheduleNextRunLabel() {
            if (!this.detail) return 'Calculée après le premier enregistrement.'
            if (!this.triggerEnabled) return 'Déclencheur suspendu.'
            if (!this.detail.schedule_next_run) return '—'

            return formatDate(this.detail.schedule_next_run)
        },
        actions() {
            return (this.directory?.actions ?? []).map(action => ({
                ...action,
                return_type: action.return_type ?? 'mixed',
                parameters: Array.isArray(action.parameters) ? action.parameters : [],
                required_permissions: Array.isArray(action.required_permissions)
                    ? action.required_permissions
                    : [],
            }))
        },
        builtins() {
            return this.directory?.builtins ?? []
        },
        statements() {
            return this.directory?.statements ?? []
        },
        builtinGroups() {
            const groups = new Map()

            for (const builtin of this.builtins) {
                if (!groups.has(builtin.namespace)) groups.set(builtin.namespace, [])
                groups.get(builtin.namespace).push(builtin)
            }

            return [...groups].map(([namespace, functions]) => ({ namespace, functions }))
        },
        baseContext() {
            return this.directory?.context ?? []
        },
        inputContext() {
            const trigger = this.selectedTrigger
            const fields = Array.isArray(trigger?.payload_fields) ? trigger.payload_fields : []

            return {
                name: '$input',
                description: fields.length
                    ? 'Charge utile du déclencheur sélectionné, non fiable et toujours une map.'
                    : 'Charge utile du déclencheur. Le schéma dépend du déclencheur sélectionné ; l’entrée manuelle ou de simulation est libre.',
                readonly: true,
                fields,
                example: trigger?.payload_example ?? null,
            }
        },
        contextVariables() {
            return [this.inputContext, ...this.baseContext]
        },
        canSave() {
            return this.isNew ? this.creatableSpaces.length > 0 : !!this.detail?.can_edit
        },
        canPersist() {
            return this.canSave && this.form.trigger_id !== ''
        },
        canPublish() {
            return this.isNew ? !!this.selectedSpace?.can_publish : !!this.detail?.can_publish
        },
        canRun() {
            return !!this.detail?.can_run
        },
        isDirty() {
            return this.snapshot() !== this.savedSnapshot
        },
        draftDiagnostics() {
            return this.detail?.draft?.diagnostics ?? []
        },
        shownDiagnostics() {
            return this.publishDiagnostics.length ? this.publishDiagnostics : this.draftDiagnostics
        },
        sourceBytes() {
            return new TextEncoder().encode(this.form.source ?? '').length
        },
        modeDescription() {
            if (this.requiresAuthor) {
                return 'Ce déclencheur n’a pas d’utilisateur connecté : le scénario s’exécute avec l’auteur publié. La permission RUN_AS_AUTHOR est requise à la publication.'
            }

            return this.form.principal_mode === 'author'
                ? 'Le scénario s’exécute avec les droits du publieur. La permission RUN_AS_AUTHOR est requise à la publication.'
                : 'Le scénario s’exécute avec l’identité de l’utilisateur qui déclenche. Un déclenchement système sans utilisateur est ignoré.'
        },
        runHint() {
            if (!this.detail?.published) return 'Publiez une version avant de lancer le scénario.'
            if (this.detail.is_dirty || this.isDirty) {
                return `La version publiée v${this.detail.published.version_number} sera exécutée ; les modifications non publiées sont ignorées.`
            }
            return 'Exécute la version publiée avec l’entrée de test.'
        },
    },

    created() {
        if (this.currentId) {
            this.load()
        } else {
            this.initializeNew()
        }
    },

    methods: {
        initializeNew() {
            const space = this.creatableSpaces[0] ?? null
            const trigger = this.triggers[0] ?? null
            this.form = {
                space_id: space?.id ?? 0,
                key: '',
                name: '',
                description: '',
                principal_mode: trigger?.default_principal_mode ?? 'trigger',
                trigger_id: trigger?.id ?? '',
                source: this.directory?.template ?? 'function main() {\n    return null;\n}\n',
            }
            this.triggerConfig = this.defaultTriggerConfig(this.form.trigger_id)
            this.triggerEnabled = true
            if (this.requiresAuthor) this.form.principal_mode = 'author'
            this.savedSnapshot = this.snapshot()
            this.keyTouched = false

            if (!space) {
                this.error = 'Aucun espace ne vous autorise à créer un scénario.'
            }
        },

        async load() {
            this.loading = true
            this.error = ''

            try {
                const response = await this.api.get('/administrator/api/automation/read', {
                    scenarioId: this.currentId,
                })
                this.detail = response.scenario
                this.currentId = this.detail.id
                this.syncForm()
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger ce scénario.'
            } finally {
                this.loading = false
            }
        },

        syncForm() {
            this.form = {
                space_id: this.detail.space_id,
                key: this.detail.key,
                name: this.detail.name,
                description: this.detail.description,
                principal_mode: this.detail.principal_mode,
                trigger_id: this.detail.trigger_id ?? '',
                source: this.detail.source,
            }
            this.triggerConfig = this.defaultTriggerConfig(this.form.trigger_id, this.detail.trigger_config ?? {})
            this.triggerEnabled = this.detail.trigger_enabled ?? true
            this.savedSnapshot = this.snapshot()
            this.publishDiagnostics = []
        },

        defaultTriggerConfig(triggerId, source = {}) {
            if (triggerId === 'automation.schedule.tick') {
                const weekday = Number(source.weekday)

                return {
                    preset: source.preset ?? 'daily',
                    time: source.time ?? '09:00',
                    weekday: Number.isInteger(weekday) && weekday >= 1 && weekday <= 7 ? weekday : 1,
                }
            }

            if (triggerId === 'automation.webhook.received') {
                return {
                    auth_mode: source.auth_mode ?? 'bearer',
                    response_mode: source.response_mode ?? 'accepted',
                }
            }

            return {}
        },

        snapshot() {
            return JSON.stringify({
                form: this.form,
                trigger_config: this.triggerConfig,
                trigger_enabled: this.triggerEnabled,
            })
        },

        suggestKeyFromName() {
            if (this.isNew && !this.keyTouched) this.form.key = slugify(this.form.name)
        },

        generateKey() {
            this.keyTouched = true
            this.form.key = slugify(this.form.name)
        },

        triggerChanged() {
            this.triggerConfig = this.defaultTriggerConfig(this.form.trigger_id)

            // Les déclencheurs système n'ont pas d'utilisateur connecté : le
            // mode Auteur est le seul disponible et reste forcé côté serveur.
            if (this.requiresAuthor) {
                this.form.principal_mode = 'author'
                return
            }

            if (!this.isNew) return

            const trigger = this.triggers.find(item => item.id === this.form.trigger_id)
            if (trigger?.default_principal_mode) this.form.principal_mode = trigger.default_principal_mode
        },

        async persist(silent) {
            this.error = ''
            this.notice = ''
            this.publishDiagnostics = []

            try {
                const data = {
                    name: this.form.name,
                    description: this.form.description,
                    principal_mode: this.form.principal_mode,
                    trigger_id: this.form.trigger_id,
                    trigger_config: this.triggerConfig,
                    trigger_enabled: this.triggerEnabled,
                    source: this.form.source,
                }
                const response = this.isNew
                    ? await this.api.post('/administrator/api/automation/create', {
                        data: {
                            ...data,
                            space_id: this.form.space_id,
                            key: this.form.key,
                        },
                    })
                    : await this.api.post('/administrator/api/automation/save', {
                        scenarioId: this.currentId,
                        data,
                    })

                this.detail = response.scenario
                this.currentId = this.detail.id
                this.syncForm()

                if (!silent) {
                    this.notice = this.detail.draft?.compile_status === 'ok'
                        ? 'Brouillon enregistré.'
                        : 'Brouillon enregistré avec des erreurs de compilation.'
                }

                this.$emit('saved', this.detail)
                return true
            } catch (error) {
                this.error = error?.message ?? 'Impossible d’enregistrer le scénario.'
                return false
            }
        },

        async generateWebhookSecret() {
            if (!this.canGenerateSecret) return

            if (this.secretConfigured
                && !await this.askYesNoAsync('Régénérer le secret ? L’ancien sera immédiatement refusé.')) {
                return
            }

            this.secretGenerating = true
            this.error = ''

            try {
                const response = await this.api.post('/administrator/api/automation/webhook-secret', {
                    scenarioId: this.currentId,
                    mode: this.webhookMode,
                })

                this.triggerConfig.auth_mode = response.mode
                this.detail.trigger_config = {
                    ...(this.detail.trigger_config ?? {}),
                    auth_mode: response.mode,
                    response_mode: this.triggerConfig.response_mode,
                }
                this.detail.auth_mode = response.mode
                this.detail.secret_configured = true

                this.secretModal = this.buildSecretModal(response)
            } catch (error) {
                this.error = error?.message ?? 'Impossible de générer le secret du webhook.'
            } finally {
                this.secretGenerating = false
            }
        },

        buildSecretModal(response) {
            if (response.mode === 'query') {
                return {
                    open: true,
                    title: 'URL signée générée',
                    description: 'Utilisez cette URL complète pour appeler le webhook. Le jeton est retiré de $input.',
                    value: new URL(response.path, window.location.origin).toString(),
                    hint: 'Méthodes GET ou POST acceptées.',
                }
            }

            if (response.mode === 'hmac') {
                return {
                    open: true,
                    title: 'Secret HMAC généré',
                    description: 'Signez timestamp . "." . corps brut avec ce secret.',
                    value: response.secret,
                    hint: 'En-têtes : X-Tycms-Timestamp et X-Tycms-Signature: sha256=<signature>.',
                }
            }

            return {
                open: true,
                title: 'Jeton Bearer généré',
                description: 'Envoyez ce jeton dans l’en-tête Authorization à chaque appel POST.',
                value: response.secret,
                hint: 'En-tête : Authorization: Bearer <jeton>.',
            }
        },

        closeSecretModal() {
            // Le secret n'est conservé que le temps de l'affichage.
            this.secretModal = {
                open: false,
                title: '',
                description: '',
                value: '',
                hint: '',
            }
        },

        async save() {
            if (this.saving || !this.canPersist) return false

            this.saving = true
            try {
                return await this.persist(false)
            } finally {
                this.saving = false
            }
        },

        async publish() {
            if (this.saving || !this.canPublish || (this.isNew && !this.canPersist)) return
            if (!await this.askYesNoAsync('Publier la version courante ? Elle deviendra exécutable par le déclencheur.')) return

            this.saving = true
            this.error = ''
            this.notice = ''

            try {
                if (this.isDirty) {
                    const saved = await this.persist(true)
                    if (!saved) return
                }

                const response = await this.api.post('/administrator/api/automation/publish', {
                    scenarioId: this.currentId,
                })

                if (!response.published) {
                    this.publishDiagnostics = response.diagnostics ?? []
                    this.notice = ''
                    return
                }

                this.detail = response.scenario
                this.syncForm()
                this.publishDiagnostics = []
                this.notice = `Version ${this.detail.published?.version_number ?? ''} publiée.`
                this.$emit('saved', this.detail)
            } catch (error) {
                this.error = error?.message ?? 'Impossible de publier ce scénario.'
            } finally {
                this.saving = false
            }
        },

        async dryRun() {
            if (this.saving || this.dryRunning || !this.canSave) return
            const input = this.parseInput()
            if (input === null) return

            this.dryRunning = true
            this.error = ''
            this.result = null

            try {
                const response = await this.api.post('/administrator/api/automation/dry-run', {
                    data: {
                        scenario_id: this.currentId ?? 0,
                        space_id: this.form.space_id ?? 0,
                        source: this.form.source,
                        input,
                    },
                })
                this.result = { kind: 'dry', ...response }
            } catch (error) {
                this.error = error?.message ?? 'La simulation a échoué.'
            } finally {
                this.dryRunning = false
            }
        },

        async run() {
            if (this.saving || this.running || !this.canRun) return
            const input = this.parseInput()
            if (input === null) return

            this.running = true
            this.error = ''
            this.result = null

            try {
                const response = await this.api.post('/administrator/api/automation/run', {
                    scenarioId: this.currentId,
                    input,
                })
                this.result = { kind: 'run', ...response }
                this.$refs.history?.reload()

                // Confirmer le succès sans masquer le détail : le panneau du run
                // reste affiché plus bas dans la page.
                if (response.status === 'success') {
                    const runLabel = response.run_id ? `Run #${response.run_id}` : 'Le run'
                    this.showModaleInfo(`${runLabel} exécuté avec succès. Le détail s’affiche plus bas.`)
                }
            } catch (error) {
                this.error = error?.message ?? 'L’exécution a échoué.'
            } finally {
                this.running = false
            }
        },

        async archive() {
            if (this.saving || !this.detail?.can_archive) return
            if (!await this.askYesNoAsync(`Archiver « ${this.detail.name} » ? Les runs existants sont conservés.`)) return

            this.saving = true
            this.error = ''

            try {
                await this.api.post('/administrator/api/automation/archive', { scenarioId: this.currentId })
                this.$emit('close')
            } catch (error) {
                this.error = error?.message ?? 'Impossible d’archiver ce scénario.'
            } finally {
                this.saving = false
            }
        },

        async requestClose() {
            if (this.saving) return
            if (this.isDirty && !await this.askYesNoAsync('Fermer sans enregistrer les modifications ?')) return
            this.$emit('close')
        },

        parseInput() {
            this.inputError = ''
            const raw = (this.inputText ?? '').trim()
            if (raw === '') return {}

            try {
                const value = JSON.parse(raw)
                if (value === null) return {}
                if (typeof value !== 'object' || Array.isArray(value)) {
                    this.inputError = 'L’entrée doit être un objet JSON.'
                    return null
                }

                return value
            } catch {
                this.inputError = 'Le JSON de l’entrée est invalide.'
                return null
            }
        },

        formatDefault(value) {
            if (value === null || value === undefined) return ''
            if (typeof value === 'string') return value === '' ? '""' : value

            return JSON.stringify(value)
        },

        statusLabel,
        statusClass,
        effectLabel,
        effectClass,
        prettyJson,
    },
}
</script>

<style scoped>
.automation-editor {
    width: 100%;
    min-width: 0;
}

.automation-json {
    max-height: 320px;
    overflow: auto;
    background: var(--bs-tertiary-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: var(--bs-border-radius);
    padding: 0.75rem;
    font-size: 0.8rem;
    white-space: pre-wrap;
    word-break: break-word;
    margin-bottom: 0;
}

.automation-reference summary {
    cursor: pointer;
}

.automation-action + .automation-action {
    border-top: 1px solid var(--bs-border-color);
    margin-top: 0.75rem;
    padding-top: 0.75rem;
}

.automation-action > summary {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
    list-style: none;
    padding: 0.35rem 0;
}

.automation-action > summary::-webkit-details-marker {
    display: none;
}

.automation-action > summary::before {
    content: '';
    flex-shrink: 0;
    width: 0;
    height: 0;
    border-top: 0.3rem solid transparent;
    border-bottom: 0.3rem solid transparent;
    border-left: 0.42rem solid var(--bs-secondary);
    transition: transform 0.15s ease;
}

.automation-action[open] > summary::before {
    transform: rotate(90deg);
}

.automation-action > summary > span {
    flex: 1 1 auto;
    min-width: 0;
}

.automation-action-body {
    padding: 0.25rem 0 0.5rem 1rem;
}

.automation-params th {
    font-size: 0.75rem;
    font-weight: 600;
}

.automation-params td,
.automation-params th {
    padding-top: 0.25rem;
    padding-bottom: 0.25rem;
}
</style>
