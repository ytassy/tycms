<template>
	<fieldset :disabled="!store.current.canEdit">
		<component :is="vueForm" v-if="vueForm" :selected="selected" :node="selected.node" :context="formContext" />
		<form_renderer v-else-if="form" condensed :definition="form" :model-value="values" :context="formContext" @update:model-value="updateValues" />
		<template v-else-if="selected.kind !== 'item'">
			<LocalizedTextField v-model="selected.node.label" label="Libellé de la section" :locales="store.currentSpaceLanguages"
				:locale="store.previewLocale" :space-id="store.current.space_id" :disabled="!store.current.canEdit" />
			<icon_picker v-model="selected.node.icon" :disabled="!store.current.canEdit" />
		</template>
		<p v-else-if="definition?.formVue" class="alert alert-warning small">Le formulaire Vue n’est pas disponible. Reconstruisez le frontend avec le plugin.</p>
		<p v-else-if="definition" class="text-muted small">Ce composant n’a pas de paramètre général.</p>
		<p v-else class="alert alert-warning small">Composant indisponible. Ses données et ses descendants sont conservés.</p>
		<!-- Les commandes structurelles appartiennent au moteur, pour tous les plugins. -->
		<template v-if="selected.kind === 'item'">
			<div v-for="(zone, name) in definition?.zones ?? {}" :key="name" class="mt-3">
				<button type="button" class="btn btn-outline-primary btn-sm" :disabled="(selected.node.zones[name]?.length ?? 0) >= (zone.max ?? MAX_ZONE_BODIES)"
					@click="store.addZone(selected.node.id, name)">+ Ajouter : {{ zone.label ?? name }}</button>
			</div>
		</template>
	</fieldset>
</template>

<script>
import LocalizedTextField from '../../common/LocalizedTextField.vue'
import { resolvePageForm } from './componentRegistry.js'
import { MAX_ZONE_BODIES, findNode } from './pageEditorModel.js'

export default {
	components: { LocalizedTextField },
	props: { selected: { type: Object, required: true } },
	computed: {
		MAX_ZONE_BODIES() { return MAX_ZONE_BODIES },
		store() { return this.stores.useAdminPagesStore() },
		owner() { return this.selected.kind === 'item' ? this.selected.node : findNode(this.store.current.data, this.selected.parentId)?.node },
		definition() { return this.store.components.find(entry => entry.id === this.owner?.type) },
		vueForm() {
			const name = this.selected.kind === 'item' ? this.definition?.formVue : this.definition?.zones?.[this.selected.zoneName]?.formVue
			return resolvePageForm(name)
		},
		form() { return this.selected.kind === 'item' ? this.store.forms[this.selected.node.type] : null },
		values() {
			const values = {}
			for (const section of this.form?.sections ?? []) {
				for (const field of section.fields) if (field.name) values[field.name] = this.selected.node.props[field.name] ?? this.form.initialValues?.[field.name]
			}
			return values
		},
		formContext() {
			return { ...this.store.editContext, spaceId: this.store.current.space_id, locale: this.store.previewLocale,
				locales: this.store.currentSpaceLanguages, readonly: !this.store.current.canEdit }
		},
	},
	methods: {
		updateValues(values) {
			if (this.store.current.canEdit) Object.assign(this.selected.node.props, values)
		},
	},
}
</script>
