<template>
    <div class="updater-shell mx-auto">
        <header class="mb-4">
            <p class="text-uppercase text-secondary fw-semibold small mb-2">Administration système</p>
            <h1 class="h2 mb-2">Mise à jour Tycms</h1>
            <p class="text-secondary mb-0">Chaque étape est lancée séparément.</p>
        </header>

        <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
        <div v-if="loading" class="alert alert-light border" role="status">
            <div class="d-flex flex-column align-items-center gap-3 p-4">
                <div class="spinner-border text-primary"
                    style="width: 3rem; height: 3rem;"
                    role="status">
                    <span class="visually-hidden">Chargement...</span>
                </div>
            </div>
            {{ loadingMessage }}
        </div>
        <div v-if="status && isClosed && status.phase === 'cancelled'" class="alert alert-success" role="status">
            La mise à jour est annulée. Le lien est désactivé.
        </div>
        <div v-if="status && isClosed && status.phase === 'rolled_back'" class="alert alert-success" role="status">
            La release source est rétablie. Le lien est désactivé.
        </div>
        <div v-if="status && isClosed && status.phase === 'completed'" class="alert alert-success" role="status">
            Mise à jour terminée. {{ status.source_offline ? 'Le site reste en maintenance comme avant la mise à jour.' : 'Le site est remis en ligne.' }} Le lien est désactivé. Le nettoyage est disponible dans l’administration.
        </div>

        <section v-if="status && !isClosed" class="card shadow-sm mb-4" aria-labelledby="operation-title">
            <div class="card-body p-4">
                <h2 id="operation-title" class="h5">Mise à jour vers la version {{ status.version }}</h2>
                <p class="text-secondary mb-0">État : {{ phaseLabel }}</p>
            </div>
        </section>

        <section v-if="status && !isClosed" class="card shadow-sm mb-4" aria-labelledby="steps-title">
            <div class="card-body p-4">
                <h2 id="steps-title" class="h5 mb-3">Étapes de la mise à jour</h2>
                <ol class="updater-steps">
                    <li v-for="step in steps" :key="step.label">
                        {{ step.label }}
                        <span v-if="step.done" class="text-success fw-bold ms-1" aria-hidden="true">✓</span>
                        <span v-if="step.done" class="visually-hidden"> — terminée</span>
                        <span v-else-if="step.skipped" class="text-secondary small"> — omise</span>
                        <span v-else-if="step.partial" class="text-warning-emphasis small"> — partielle</span>
                    </li>
                </ol>
            </div>
        </section>

        <div v-if="status && status.phase === 'offline'" class="alert alert-warning" role="status">
            Le site est en maintenance. Attendez la fin des requêtes déjà engagées et des tâches qui écrivent en base avant de poursuivre.
        </div>
        <div v-if="status && status.phase === 'maintenance_running'" class="alert alert-warning" role="status">
            L’activation de la maintenance a été interrompue ou n’a pas pu être confirmée. Actualisez l’état avant de réessayer ou d’annuler.
        </div>
        <div v-if="status && canChooseBackup" class="alert alert-warning" role="status">
            Une sauvegarde est vivement conseillée avant les migrations. Vous pouvez utiliser celle de l’updater ou assumer la responsabilité de votre propre sauvegarde.
        </div>
        <div v-if="status && status.phase === 'backed_up' && status.backup === 'completed'" class="alert alert-success" role="status">
            La sauvegarde privée de la base est terminée. Son intégrité sera vérifiée avant les migrations.
        </div>
        <div v-if="status && status.backup === 'partial'" class="alert alert-warning" role="alert">
            La sauvegarde est partielle : certains objets peuvent manquer. La restauration automatique est désactivée.
            Prévoyez une sauvegarde externe complète et vérifiez que vous pourrez la restaurer avant les migrations.
            <p v-if="partialOmissions.length" class="mb-0 mt-2">Catégories omises, si elles existent : {{ partialOmissions.join(', ') }}.</p>
            <p v-if="status.backup_missing_privileges && status.backup_missing_privileges.length" class="mb-0 mt-2">Droits manquants : {{ status.backup_missing_privileges.join(', ') }}.</p>
        </div>
        <div v-if="status && ['completed', 'partial'].includes(status.backup) && status.backup_paths" class="alert alert-light border">
            <p class="fw-semibold mb-2">Emplacement des sauvegardes sur le serveur</p>
            <p v-if="status.backup_paths.sql" class="mb-1">{{ status.backup === 'partial' ? 'Export SQL partiel' : 'Restauration manuelle' }} : <code>{{ status.backup_paths.sql }}</code></p>
            <p class="mb-0">Sauvegarde de l’updater : <code>{{ status.backup_paths.jsonl }}</code></p>
        </div>
        <div v-if="status && status.phase === 'backup_skipped'" class="alert alert-warning" role="status">
            Vous avez choisi de continuer sans sauvegarde de l’updater. Vérifiez que vous pouvez restaurer votre propre sauvegarde.
        </div>
        <div v-if="status && ['migrating', 'migration_failed', 'activation_failed', 'restore_running', 'restore_failed'].includes(status.phase)" class="alert alert-danger" role="alert">
            Une étape a échoué ou a été interrompue. Le site reste en maintenance. Une requête peut encore être en cours : actualisez l’état avant toute action de récupération.
        </div>
        <div v-if="status && status.phase === 'restored'" class="alert alert-warning" role="status">La base est restaurée. Terminez le retour à la release source.</div>
        <div v-if="status && !isClosed" class="d-flex flex-wrap gap-2">
            <button v-if="canPrepare" type="button" class="btn btn-primary" :disabled="loading" @click="run('prepare')">
                Préparer les fichiers
            </button>
            <button v-if="canMaintain" type="button" class="btn btn-primary" :disabled="loading" @click="confirmMaintenance">
                Activer la maintenance
            </button>
            <button v-if="canChooseBackup" type="button" class="btn btn-primary" :disabled="loading" @click="run('backup')">
                Sauvegarder la base
            </button>
            <button v-if="canChooseBackup" type="button" class="btn btn-outline-danger" :disabled="loading" @click="confirmSkipBackup">
                Continuer sans sauvegarde
            </button>
            <button v-if="canMigrate" type="button" class="btn btn-primary" :disabled="loading" @click="confirmMigration">
                Lancer les migrations
            </button>
            <button v-if="canActivate" type="button" class="btn btn-primary" :disabled="loading" @click="run('activate')">Vérifier et activer la nouvelle version</button>
            <button v-if="canOnline" type="button" class="btn btn-primary" :disabled="loading" @click="run('online')">Terminer et rétablir l’état du site</button>
            <button v-if="canRestore && status.backup === 'completed'" type="button" class="btn btn-outline-danger" :disabled="loading" @click="confirmRestore">Restaurer la sauvegarde de l’updater</button>
            <button v-if="canRestore && status.phase !== 'restore_running'" type="button" class="btn btn-outline-danger" :disabled="loading" @click="confirmExternalRestore">J’ai restauré ma sauvegarde externe</button>
            <button v-if="['restored', 'rolling_back', 'rolled_back'].includes(status.phase)" type="button" class="btn btn-outline-danger" :disabled="loading" @click="run('rollback')">Terminer le retour à la source</button>
            <button type="button" class="btn btn-outline-secondary" :disabled="loading" @click="refresh">
                Actualiser l’état
            </button>
            <button v-if="canCancel" type="button" class="btn btn-outline-danger" :disabled="loading" @click="confirmCancel">
                Annuler la mise à jour
            </button>
        </div>
    </div>
</template>

<script>
export default {
    props: { csrfToken: { type: String, required: true } },

    data() {
        return { status: null, loading: false, error: '', loadingMessage: '' };
    },

    computed: {
        isClosed() { return this.status.closed === true; },
        partialOmissions() {
            const missing = this.status?.backup_missing_privileges || [];
            const categories = [];
            if (missing.includes('SHOW VIEW')) categories.push('vues');
            if (missing.includes('TRIGGER')) categories.push('déclencheurs');
            if (missing.includes('EVENT')) categories.push('événements');
            if (missing.includes('SHOW CREATE ROUTINE ou SELECT sur mysql.proc')) categories.push('fonctions et procédures');
            return categories;
        },
        steps() {
            const phase = this.status.phase;
            const afterMigration = ['migrated', 'activating', 'activation_failed', 'activated', 'online_running', 'completed', 'restore_running', 'restore_failed', 'restored', 'rolling_back'];
            const filesDone = ['ready', 'maintenance_running', 'offline', 'backup_running', 'backup_failed',
                'backed_up', 'backup_skipped', 'migrating', 'migration_failed', ...afterMigration].includes(phase);
            const maintenanceDone = ['offline', 'backup_running', 'backup_failed', 'backed_up', 'backup_skipped',
                'migrating', 'migration_failed', ...afterMigration].includes(phase);

            return [
                { label: 'Préparation des fichiers', done: filesDone },
                { label: 'Activation de la maintenance', done: maintenanceDone },
                { label: 'Sauvegarde de la base de données', done: this.status.backup === 'completed', skipped: this.status.backup === 'skipped', partial: this.status.backup === 'partial' },
                { label: 'Exécution des migrations', done: this.status.migrations_completed === true },
                { label: 'Activation de la nouvelle version', done: ['activated', 'online_running', 'completed'].includes(phase) },
                { label: 'Remise en ligne', done: phase === 'completed' },
            ];
        },
        phaseLabel() {
            const labels = {
                new: 'Fichiers à préparer', copying: 'Préparation des fichiers interrompue ou en cours',
                publishing: 'Publication des fichiers interrompue ou en cours', ready: 'Fichiers préparés',
                maintenance_running: 'Activation de la maintenance à confirmer', offline: 'Site en maintenance',
                cancelling: 'Annulation interrompue ou en cours',
                backup_running: 'Sauvegarde en cours ou interrompue', backup_failed: 'Sauvegarde échouée',
                backed_up: 'Sauvegarde terminée', backup_skipped: 'Sauvegarde omise',
                migrating: 'Migrations en cours ou interrompues', migration_failed: 'Échec des migrations',
                migrated: 'Migrations terminées',
                activating: 'Contrôle de démarrage ou activation en cours ou interrompu', activation_failed: 'Échec de l’activation',
                activated: 'Nouvelle version activée en maintenance', online_running: 'Finalisation en cours ou interrompue', completed: 'Finalisation à confirmer',
                restore_running: 'Restauration en cours ou interrompue', restore_failed: 'Échec de la restauration', restored: 'Base restaurée', rolling_back: 'Retour à la source en cours ou interrompu', rolled_back: 'Retour à la source à confirmer',
            };
            return labels[this.status.phase] || 'État non reconnu';
        },
        canPrepare() {
            return ['new', 'copying', 'publishing'].includes(this.status.phase);
        },
        canMaintain() {
            return ['ready', 'maintenance_running'].includes(this.status.phase);
        },
        canChooseBackup() {
            return ['offline', 'backup_running', 'backup_failed'].includes(this.status.phase);
        },
        canMigrate() {
            return ['backed_up', 'backup_skipped'].includes(this.status.phase);
        },
        canActivate() { return ['migrated', 'activating', 'activation_failed'].includes(this.status.phase); },
        canOnline() { return ['activated', 'online_running', 'completed'].includes(this.status.phase); },
        canRestore() { return ['migrating', 'migration_failed', 'migrated', 'activating', 'activation_failed', 'activated', 'restore_running', 'restore_failed'].includes(this.status.phase); },
        canCancel() {
            return ['new', 'copying', 'publishing', 'ready', 'maintenance_running', 'offline',
                'backup_running', 'backup_failed', 'backed_up', 'backup_skipped', 'cancelling', 'cancelled'].includes(this.status.phase);
        },
    },

    mounted() {
        this.refresh();
    },

    methods: {
        refresh() {
            return this.run('status');
        },
        confirmMaintenance() {
            if (window.confirm('Le site sera mis en maintenance. Voulez-vous continuer ?')) this.run('maintenance');
        },
        confirmCancel() {
            if (window.confirm('Annuler la mise à jour et rétablir l’état initial de maintenance ?')) this.run('cancel');
        },
        confirmSkipBackup() {
            if (window.confirm('Continuer sans sauvegarde de l’updater ? Vous assumez la responsabilité de pouvoir restaurer la base après une erreur.')) this.run('skip_backup');
        },
        confirmMigration() {
            if (this.status.backup === 'partial' && !window.confirm('La sauvegarde est partielle et ne permet pas de restauration automatique. Avez-vous une sauvegarde externe complète que vous pourrez restaurer ?')) return;
            if (window.confirm('Lancer les migrations ? Le site restera en maintenance jusqu’à la finalisation ou la récupération.')) this.run('migrate');
        },
        confirmRestore() {
            if (window.confirm('Restaurer la base avec la sauvegarde de l’updater ? Les données actuelles de cette base seront remplacées.')) this.run('restore');
        },
        confirmExternalRestore() {
            if (this.status.phase === 'restore_running') { this.error = 'Une restauration interne est peut-être encore en cours. Actualisez l’état et attendez la fin de la requête.'; return; }
            if (window.confirm('Confirmez-vous avoir restauré vous-même la base correspondant à la release source ? Cette confirmation ne vérifie pas la restauration. Le site reviendra à son état initial.')) this.run('external_restore');
        },
        async run(action) {
            this.loading = true;
            this.error = '';
            this.loadingMessage = action === 'status' ? 'Lecture de l’état en cours…' : 'Opération en cours…';

            try {
                // Toutes les actions utilisent le même lien secret et le jeton CSRF.
                const response = await fetch(location.pathname, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { 'X-CSRF-Token': this.csrfToken }, body: new URLSearchParams({ action }),
                });
                const data = await response.json();
                if (!response.ok) {
                    this.error = typeof data.error === 'string' ? data.error : 'Cette étape a échoué.';
                    return;
                }
                if (typeof data.version !== 'string' || typeof data.phase !== 'string') throw new Error('Réponse invalide.');
                this.status = data;
            } catch (error) {
                this.error = 'La réponse n’a pas pu être reçue. Le traitement peut encore être en cours : actualisez l’état avant toute nouvelle action.';
            } finally {
                this.loading = false;
            }
        },
    },
};
</script>
