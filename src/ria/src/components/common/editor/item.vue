<template>
	<div class="ce-helper-item" v-if="schema !== null">
		<div v-if="displayed == false">
			<div class="tbl-100">
				<div class="td vam p-1">
					<button @click="lazyLoadClicked = true" class="btn btn-outline-tertiary white-space-nowrap pt-3 pb-3">
						Ouvrir l'objet
					</button>
				</div>

				<div class="td-100 ps-3">
					<div class="tbl-100">
						<div v-for="(attr) in schema._ui_lazy_preview" class="tr">
							<div class="td pb-1 pe-2">
								<b>{{ attr }}</b>
							</div>

							<div class="td-100 pb-1">
								<template v-if="isString(dataModel[attr])">
									{{ dataModel[attr] }}
								</template>

								<template v-else-if="isLangObject(dataModel[attr])">
									{{ dataModel[attr].fr }}
									<br />
									{{ dataModel[attr].en }}
								</template>

								<template v-else>
									{{ dataModel[attr] }}
								</template>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>

		<div v-else-if="type == 'null'">
			Null
			<p v-if="schema._ui_text != undefined">
				<b>{{ schema._ui_text }}</b>
			</p>
		</div>

		<div v-else-if="_const !== null">
			<ItemConst
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemConst>
		</div>

		<div v-else-if="allOf !== null">
			<ItemAllOf
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemAllOf>
		</div>
		<div v-else-if="anyOf !== null">
			<ItemAnyOf
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemAnyOf>
		</div>

		<div v-else-if="ref !== null">
			<ItemRef
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemRef>
		</div>

		<div v-else-if="type == 'string' || type == 'number' || type == 'integer' || type == 'boolean' || type == 'null'">
			<ItemScalar
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemScalar>
		</div>

		<div v-else-if="type == 'object'">
			<ItemObject
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemObject>
		</div>

		<div v-else-if="type == 'array'">
			<ItemArray
				v-model="dataModel"
				:schema="schema"
				:currentRef="currentRef"
				:required="required"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:validations="validations"
				:context="mappedContext"
				@modified="modified"
			>
			</ItemArray>
		</div>
	</div>
</template>

<script>

	import editorBase from './base.js';
	import ItemAllOf from './items/item_all_of.vue';
	import ItemAnyOf from './items/item_any_of.vue';
	import ItemArray from './items/item_array.vue';
	import ItemObject from './items/item_object.vue';
	import ItemRef from './items/item_ref.vue';
	import ItemScalar from './items/item_scalar.vue';
	import ItemConst from './items/item_const.vue';
	
		export default
		{
		extends: editorBase,
		name: "CeHelperItem",

		components:
		{
			ItemAllOf,
			ItemAnyOf,
			ItemArray,
			ItemObject,
			ItemRef,
			ItemScalar,
			ItemConst,
		},

		props: {
			modelValue: { },
			schema: { type: Object },
			currentRef: { type: Object },
			required: { default: false, type: Boolean },
			pointer: { default: "", type: String },
			local_pointer: { default: "", type: String },
			validations: { default: null, type: Object },
			context: { default: null, type: Object },
		},

		emits: ['update:modelValue', 'change', 'modified' ],

		data() {
			return {
				lazyLoadClicked: false,
			}
		},

		methods:{

			isString(val) { return typeof val == "string" },
			isLangObject(val) {
				return typeof val == "object"
					&& typeof val['fr'] == "string"
					&& typeof val['en'] == "string";
			},

			modified() { this.$emit('modified'); }
		},

				computed: Object.assign(
		{
			type() { return this.schema.type == undefined ? null : this.schema.type; },
			allOf() { return this.schema.allOf == undefined ? null : this.schema.allOf; },
			anyOf() { return this.schema.anyOf == undefined ? null : this.schema.anyOf; },
			_const() { return this.schema.const == undefined ? null : this.schema.const; },
			ref() { return this.schema['$ref'] == undefined ? null : this.schema['$ref']; },

			displayed() {
				if (this.lazyLoadClicked) return true;
				if (this.schema._ui_lazy === undefined || this.schema._ui_lazy === false) return true;

				// On affiche quand même si valeurs simples
				let display = false;

				if (this.modelValue === null) display = true;
				if (Array.isArray(this.modelValue) && this.modelValue.length == 0) display = true;
				if (JSON.stringify(this.modelValue) == "{}") display = true;

				// Et on sauvegarde le fait que ça a été affiché. Car sinon s'il y a modification de l'utilisateur, ça va se masquer.
				if (display) this.lazyLoadClicked = true

				return display;
			},

			dataModel: {
				get() {
					return this.modelValue;
				},
				set(value) {
					this.$emit('update:modelValue', value);
				}
			},

			mappedContext()
			{
				let context = { ...(this.context === null ? {} : this.context) }; // Clone object

				if (this.schema._ui_contextMapping != undefined) // Permet de gérer le contexte que l'on souhaite mapper (en cas de doublon)
				{
					for (let from in this.schema._ui_contextMapping)
					{
						if (context[from] != undefined)
						{
							let to = this.schema._ui_contextMapping[from];
							context[to] = context[from];
						}
					}
				}

				return context;
			},
		}),
		}
</script>
