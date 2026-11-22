<template>
	<section class="border rounded p-3 mb-3" aria-label="Configuration WAF héritée">
		<h3 class="h5">Configuration WAF de l’espace Root — lecture seule</h3>
		<p class="text-body-secondary">Ces valeurs s’appliqueront après enregistrement. Vos réglages locaux sont conservés.</p>
		<template v-if="configuration">
			<dl>
				<dt>Mode</dt><dd>{{ modeLabel }}</dd>
				<dt>Exemption des utilisateurs ROOT</dt><dd>{{ configuration.waf_skip_root ? 'Oui' : 'Non' }}</dd>
				<dt>Message de refus</dt><dd>[WAF] {{ configuration.waf_message }}</dd>
			</dl>
			<label class="form-label fw-semibold">Motifs hérités</label>
			<CodeEditor :model-value="configuration.waf_patterns" syntax="json" :read-only="true" />
		</template>
		<p v-else class="text-body-secondary mb-0">La configuration du Space Root n’est pas disponible dans ce compte. Le serveur appliquera ses réglages sans les copier dans ce Space.</p>
	</section>
</template>
<script>
import CodeEditor from '../../common/CodeEditor.vue'

export default {
	components: { CodeEditor },
	props: { configuration: { type: Object, default: null } },
	computed: {
		modeLabel() { return { off: 'Désactivé', observe: 'Observation', block: 'Blocage' }[this.configuration?.waf_mode] ?? '' },
	},
}
</script>
