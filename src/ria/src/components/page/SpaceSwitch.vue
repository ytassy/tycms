<template>
    <common_app />
    <div class="space-switch">
        <p v-if="loading" class="text-body-secondary" role="status">Chargement des espaces…</p>
        <div v-else-if="error" class="alert alert-warning" role="alert">
            {{ error }} <button type="button" class="btn btn-link btn-sm" @click="load">Réessayer</button>
        </div>
        <template v-else>
            <p v-if="!spaces.length" class="text-body-secondary">Aucun espace disponible.</p>
            <div class="space-switch__list">
                <article v-for="space in spaces" :key="space.id" class="card h-100">
                    <div class="card-body d-flex flex-column align-items-start gap-2">
                        <img v-if="show_logo && space.logo" :src="space.logo" alt=""
                            :class="['space-switch__logo', logoAlignmentClass]" :style="logoStyle" loading="lazy" />
                        <h3 v-if="show_name" class="h5 mb-0">{{ space.title }}</h3>
                        <p v-if="show_short_description && space.short_description" class="mb-0">{{ space.short_description }}</p>
                        <!-- Le serveur purifie aussi les anciennes descriptions avant de les transmettre. -->
                        <div v-if="show_description && space.description" class="space-switch__description" v-html="space.description" />
                        <button type="button" class="btn btn-primary mt-auto" :disabled="!space.url || selecting"
                            :aria-label="buttonText(space) + ' : ' + space.title" @click="select(space)">
                            {{ buttonText(space) }}
                        </button>
                    </div>
                </article>
            </div>
        </template>
    </div>
</template>

<script>
export default {
    props: {
        configToken: { type: String, required: true },
        show_logo: { type: Boolean, default: true },
        logo_alignment: { type: String, default: 'left' },
        logo_max_width: { type: Number, default: 0 },
        show_name: { type: Boolean, default: true },
        show_short_description: { type: Boolean, default: true },
        show_description: { type: Boolean, default: false },
        closed_text: { type: String, default: 'Inscription fermée' },
    },
    data: () => ({ spaces: [], loading: true, selecting: false, error: '' }),
    computed: {
        logoMaxWidth() {
            // Les anciens documents peuvent omettre la valeur ; 0 garde la pleine largeur.
            const width = Number(this.logo_max_width)
            return Number.isFinite(width) && width > 0 ? Math.min(1000, width) : 0
        },
        logoStyle() { return this.logoMaxWidth > 0 ? { maxWidth: `${this.logoMaxWidth}px` } : {} },
        logoAlignmentClass() {
            const alignment = ['left', 'center', 'right'].includes(this.logo_alignment) ? this.logo_alignment : 'left'
            return `space-switch__logo--${alignment}`
        },
    },
    mounted() { this.load() },
    methods: {
        async load() {
            this.loading = true
            this.error = ''
            try {
                const result = await this.stores.useSpaceSwitchStore().load(this.configToken)
                this.spaces = result.spaces
            } catch (error) {
                this.error = error?.message ?? 'Impossible de charger les espaces.'
            } finally {
                this.loading = false
            }
        },
        buttonText(space) { return space.member ? 'Entrer' : space.registration_enabled ? 'S’inscrire' : this.closed_text },
        async select(space) {
            if (this.selecting || !space.url) return
            this.selecting = true
            this.showModaleWait()
            try {
                // Seule la réponse autorisée du POST peut déclencher la navigation finale.
                const result = await this.stores.useSpaceSwitchStore().select(this.configToken, space.id)
                if (typeof result.url !== 'string' || !result.url.startsWith('/') || result.url.startsWith('//')
                    || /[\\\r\n]/.test(result.url)) {
                    this.showModaleError('Destination d’espace invalide.')
                    return
                }
                window.location.assign(result.url)
            } catch (_) {
                // Le client API présente déjà le refus du serveur dans la modale commune.
            } finally {
                this.selecting = false
                this.hideModaleWait()
            }
        },
    },
}
</script>

<style scoped>
.space-switch__list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 16rem), 1fr)); gap: 1rem; }
.space-switch__logo { width: auto; max-width: 100%; height: 5rem; object-fit: contain; }
.space-switch__logo--center { align-self: center; }
.space-switch__logo--right { align-self: flex-end; }
.space-switch__description { width: 100%; overflow-wrap: anywhere; }
.space-switch__description :deep(img) { max-width: 100%; height: auto; }
</style>
