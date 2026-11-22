<template>
	<LocalizedTextField v-model="node.props.text" label="Libellé" :locales="locales" :locale="store.previewLocale"
				:space-id="store.current.space_id" :disabled="!store.current.canEdit" />
	<label class="page-field">Action du bouton
		<select :value="node.props.button_action ?? 'link'" class="form-select form-select-sm"
			:disabled="!store.current.canEdit" @change="setButtonAction($event.target.value)">
			<option value="link">Lien</option>
			<option v-if="automationAvailable || node.props.button_action === 'automation'" value="automation">
				Scénario d’automatisation
			</option>
		</select>
	</label>

	<PageLinkFields v-if="(node.props.button_action ?? 'link') === 'link'" :node="node"
		:space-id="store.current.space_id" :locale="store.previewLocale" :disabled="!store.current.canEdit" />

	<template v-else>
		<label class="page-field">Scénario
			<select :value="node.props.automation_scenario_key ?? ''" class="form-select form-select-sm"
				:disabled="!store.current.canEdit || automationLoading" @change="setScenarioKey($event.target.value)">
				<option value="">Choisir un scénario publié</option>
				<option v-if="missingScenario" :value="node.props.automation_scenario_key">
					Scénario indisponible ({{ node.props.automation_scenario_key }})
				</option>
				<option v-for="scenario in automationScenarios" :key="scenario.key" :value="scenario.key">
					{{ scenario.name }} — {{ principalModeLabel(scenario.principal_mode) }}{{ scenario.principal_mode === 'author' && !scenario.execution_user_id ? ' (auteur non publié)' : '' }}
				</option>
			</select>
		</label>
		<p v-if="automationLoading" class="small text-muted">Chargement des scénarios…</p>
		<p v-else-if="automationError" class="small text-danger" role="alert">{{ automationError }}
			<button type="button" class="btn btn-link btn-sm p-0" :disabled="!store.current.canEdit"
				@click="loadScenarios">Réessayer</button>
		</p>
		<p v-else-if="!automationAvailable" class="small text-warning">
			Vous n’avez pas accès à la liste des automatisations de cet espace.
		</p>
		<p v-else-if="!automationScenarios.length" class="small text-muted">
			Aucun scénario publié avec le déclencheur « Clic sur un bouton de page » dans cet espace.
		</p>
		<p v-else class="small text-muted">
			Le scénario s’exécute après le clic, selon son mode d’identité (utilisateur connecté ou auteur).
		</p>
		<p v-if="authorMissing" class="small text-warning" role="status">
			Ce scénario est en mode auteur mais n’a pas encore d’auteur publié. Ouvrez-le, choisissez
			« Auteur du scénario », puis publiez-le.
		</p>
		<label class="page-field">Message après le clic
			<select :value="successMessageMode" class="form-select form-select-sm"
				:disabled="!store.current.canEdit" @change="setSuccessMessageMode($event.target.value)">
				<option value="default">Message par défaut</option>
				<option value="custom">Message personnalisé</option>
				<option value="result">Résultat du scénario</option>
				<option value="none">Aucun message</option>
			</select>
		</label>
		<LocalizedTextField v-if="successMessageMode === 'custom'" v-model="node.props.success_message"
			label="Message personnalisé" :locales="locales" :locale="store.previewLocale"
			:space-id="store.current.space_id" :max-length="300" :disabled="!store.current.canEdit" />
		<p v-if="successMessageMode === 'default'" class="small text-muted">
			« Votre demande a été prise en compte. » sera affiché après un clic réussi.
		</p>
		<p v-else-if="successMessageMode === 'result'" class="small text-muted">
			Une chaîne retournée par le scénario devient une confirmation ; une réponse
			<code>res::*</code> choisit la modale et son niveau. Le clic attend la fin de
			l’exécution du scénario.
		</p>
		<p v-else-if="successMessageMode === 'none'" class="small text-muted">
			Le visiteur revient sur la page sans message de confirmation.
		</p>
		<label class="form-check mt-2">
			<input class="form-check-input" type="checkbox" :checked="node.props.reload_on_close === true"
				:disabled="!store.current.canEdit" @change="setReloadOnClose($event.target.checked)">
			<span class="form-check-label">Recharger la page après la confirmation</span>
		</label>
		<p class="small text-muted">
			En mode « Aucun message », le rechargement est immédiat lorsque cette option est activée.
		</p>
	</template>

	<label class="page-field">Variante Bootstrap
		<select :value="node.props.variant ?? 'primary'" class="form-select form-select-sm" @change="setButtonOption('variant', $event.target.value, 'primary')">
			<option v-for="variant in buttonVariants" :key="variant.value" :value="variant.value">{{ variant.label }}</option>
		</select>
	</label>
	<label class="page-field">Taille
		<select :value="node.props.size ?? ''" class="form-select form-select-sm" @change="setButtonOption('size', $event.target.value, '')">
			<option value="">Normale</option><option value="sm">Petite</option><option value="lg">Grande</option>
		</select>
	</label>
	<h3 class="page-panel-heading">Apparence du bouton</h3>
	<p class="small text-muted mb-2">Les champs vides conservent les valeurs de la variante Bootstrap.</p>
	<div v-for="group in buttonColorGroups" :key="group.label">
		<strong class="page-style-group-label">{{ group.label }}</strong>
		<div v-for="field in group.fields" :key="field.key" class="page-field">
			<label :for="`page-button-${field.key}`">{{ field.label }}</label>
			<color_input :id="`page-button-${field.key}`" :model-value="buttonStyle[field.key] ?? ''" size="sm" allow-short-hex
				@update:model-value="setButtonStyle(field.key, $event)" />
		</div>
	</div>
	<div class="page-style-grid">
		<label class="page-field">Épaisseur de bordure
			<input :value="buttonStyle.border_width ?? ''" class="form-control form-control-sm" placeholder="1px"
				@input="setButtonStyle('border_width', $event.target.value)">
		</label>
		<label class="page-field">Rayon de bordure
			<input :value="buttonStyle.border_radius ?? ''" class="form-control form-control-sm" placeholder="8px"
				@input="setButtonStyle('border_radius', $event.target.value)">
		</label>
	</div>
</template>

<script>
import pageComponentFormMixin from '../pageComponentFormMixin.js'

export default {
	mixins: [pageComponentFormMixin],
	data: () => ({
		automationScenarios: [],
		automationLoading: false,
		automationError: '',
		automationRequest: 0,
	}),
	computed: {
		spaceId() {
			return this.store.current.space_id
		},
		automationAvailable() {
			return this.conf.getExtensions().includes('plg_automation')
				&& this.acl.hasGlobalPermission('plg_automation__VIEW', this.spaceId)
		},
		missingScenario() {
			const key = this.node.props.automation_scenario_key

			return typeof key === 'string' && key !== ''
				&& !this.automationScenarios.some(scenario => scenario.key === key)
		},
		selectedScenario() {
			return this.automationScenarios.find(
				scenario => scenario.key === this.node.props.automation_scenario_key,
			) ?? null
		},
		authorMissing() {
			return this.selectedScenario?.principal_mode === 'author'
				&& !this.selectedScenario?.execution_user_id
		},
		successMessageMode() {
			const mode = this.node.props.success_message_mode

			return ['default', 'custom', 'result', 'none'].includes(mode) ? mode : 'default'
		},
	},
	watch: {
		spaceId() {
			if ((this.node.props.button_action ?? 'link') === 'automation') this.loadScenarios()
		},
		'node.props.button_action'(value) {
			if (value === 'automation') this.loadScenarios()
		},
	},
	mounted() {
		if ((this.node.props.button_action ?? 'link') === 'automation') this.loadScenarios()
	},
	beforeUnmount() {
		this.automationRequest++
	},
	methods: {
		setButtonAction(value) {
			if (!this.store.current.canEdit) return

			if (value === 'automation') {
				this.node.props.button_action = 'automation'
				this.node.props.success_message_mode ??= 'default'
				delete this.node.props.href
				delete this.node.props.link_type
				delete this.node.props.menu_item_id
				delete this.node.props.link_new_tab
				this.loadScenarios()
				return
			}

			delete this.node.props.button_action
			delete this.node.props.automation_scenario_key
			delete this.node.props.success_message_mode
			delete this.node.props.success_message
			delete this.node.props.reload_on_close
		},
		setScenarioKey(value) {
			if (!this.store.current.canEdit) return

			if (value === '') delete this.node.props.automation_scenario_key
			else this.node.props.automation_scenario_key = value
		},
		setSuccessMessageMode(value) {
			if (!this.store.current.canEdit) return
			if (!['default', 'custom', 'result', 'none'].includes(value)) return

			this.node.props.success_message_mode = value

			// Seul le mode personnalisé conserve un texte propre au bouton.
			if (value !== 'custom') delete this.node.props.success_message
		},
		setReloadOnClose(value) {
			if (!this.store.current.canEdit) return

			if (value) this.node.props.reload_on_close = true
			else delete this.node.props.reload_on_close
		},
		loadScenarios() {
			if (!this.automationAvailable || (this.node.props.button_action ?? 'link') !== 'automation') {
				this.automationRequest++
				this.automationScenarios = []
				this.automationLoading = false
				this.automationError = ''
				return
			}

			const request = ++this.automationRequest
			this.automationLoading = true
			this.automationError = ''

			this.api.get('/administrator/api/automation/scenario-choices', {
				spaceId: this.spaceId,
				triggerId: 'core.page.button.clicked',
			}).then(response => {
				if (request !== this.automationRequest) return
				this.automationScenarios = response.scenarios ?? []
			}).catch(error => {
				if (request !== this.automationRequest) return
				this.automationError = error?.message ?? 'Impossible de charger les scénarios.'
			}).finally(() => {
				if (request === this.automationRequest) this.automationLoading = false
			})
		},
		principalModeLabel(mode) {
			return {
				trigger: 'Utilisateur connecté',
				author: 'Auteur',
			}[mode] ?? mode
		},
	},
}
</script>
