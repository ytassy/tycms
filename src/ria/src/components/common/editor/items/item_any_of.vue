<template>
	<div class="ce-helper-item-any-of" >
		<ModalObjectChoice ref="moc"></ModalObjectChoice>

		<a v-if="isSet == false" @click="add">Définir</a>

		<template v-else>
			<template v-if="validSchema != null">
				<div class="tbl-100">
					<div
						class="td ps-1 pe-1 pb-1 bg-gray-150 hover-primary cursor-pointer"
						@click="changeSchema"
						v-if="schema._ui_anyOfAllowChange === undefined || schema._ui_anyOfAllowChange === true"
					>
						<i class="fa-solid fa-retweet"></i>
					</div>

					<div class="td-100 ps-1">
						<CeHelperItem
							v-model="dataModel"
							:schema="validSchema"
							:currentRef="currentRef"
							:required="required"
							:pointer="pointer"
							:local_pointer="local_pointer + (keyConstValue == null ? '' : '.' + keyConstValue)"
							:validations="validations"
							:context="context"
							@modified="modified"
						>
						</CeHelperItem>
					</div>
				</div>
			</template>

			<span v-else class="text-alert">
				Schema de données non trouvé.
			</span>
		</template>
	</div>
</template>

<script>
	import editorBase from '../base.js';
	import ModalObjectChoice from './modal_object_choice.vue';

		export default
		{
		extends: editorBase,

		components:
		{
			ModalObjectChoice,
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

		methods:{
			add()
			{
				let self = this;
				self.createItemObjects().then((obj) => {
					self.dataModel = obj;
				});
			},

			changeSchema()
			{
				let self = this;
				self.createItemObjects().then((obj) => {
					self.dataModel = obj;
				});
			},

			createItemObjects()
			{
				let self = this;
				return new Promise((resolve) => {
					let uiProps = [];
					let objects = self.getDefaultObjectsFor(self.schema, self.currentRef, uiProps);

					if (objects.length == 0) return;
					else if (objects.length == 1) resolve(objects[0]);
					else self.$refs.moc.open(objects, uiProps, (obj) => {
						resolve(obj);
					});
				});
			},

			modified() { this.$emit('modified'); }
		
		},

				computed:
		{
			isSet() { return this.modelValue !== undefined; },

			keyConstIndex() {
				if (this.schema._ui_anyOfConstProperty != undefined)
				{
					const selectorValue = this.modelValue[this.schema._ui_anyOfConstProperty];
					for (let i in this.schema.anyOf)
					{
						let currentSchema = this.schema.anyOf[i];
						currentSchema = this.flatSchemas(currentSchema, this.currentRef)[0];

						currentSchema = currentSchema.properties[this.schema._ui_anyOfConstProperty];

						if (currentSchema.const == selectorValue) return i;
					}
				}

				return null;
			},

			keyConstValue()
			{
				if (this.keyConstIndex !== null)
				{
					return this.modelValue[this.schema._ui_anyOfConstProperty]
				}

				return null;
			},

			schemaIndex()
			{
				const self = this;

				if (this.schema._ui_anyOfConstProperty != undefined)
				{
					return this.keyConstIndex;
				}
				else
				{
					// Try to identify Schema
					let possibleSchemas = [];

					for (let i in this.schema.anyOf)
					{
						let currentSchemas = this.flatSchemas(this.schema.anyOf[i], this.currentRef);

						for (let isc in currentSchemas)
						{
							const currentSchema = currentSchemas[isc];

							let add = false;
							if (currentSchema.const != undefined)
							{
								if (this.modelValue == currentSchema.const) add = true;
							}
							else if (currentSchema.type != undefined)
							{
								if (currentSchema.type == "null" && this.modelValue === null) add = true;
								else if (currentSchema.type == "string" && typeof this.modelValue === "string")
								{
									if (currentSchema.enum == undefined) add = true;
									else
									{
										add = currentSchema.enum.indexOf(this.modelValue) >= 0;
									}
								}
								else if (currentSchema.type == "integer" && typeof this.modelValue === "number" && Number.isInteger(this.modelValue)) add = true;
								else if (currentSchema.type == "number" && typeof this.modelValue === "number") add = true;
								else if (currentSchema.type == "boolean" && typeof this.modelValue === "boolean") add = true;
								else if (currentSchema.type == "array" && Array.isArray(this.modelValue)) add = true;
								else if (currentSchema.type == "object" && typeof this.modelValue === "object" && this.modelValue !== null && Array.isArray(this.modelValue) == false) add = true;
							}
							else if (currentSchema.$ref !== undefined) { /* Nothing to add */}
							else
							{
								add = true;
								if (this.isDebug) console.log("else", currentSchema);
							}

							if (add)
							{
								possibleSchemas.push(i);
								continue;
							}

						}
					}

					// Pas de Schema => null
					if (possibleSchemas.length == 0) return null;

					// Si 1 Schema c'est ok
					if (possibleSchemas.length == 1) return possibleSchemas[0];

					// Si plusieurs schemas on vérifie si ce sont les mêmes.
					let isSameSchema = true;
					for (let i = 1; i < possibleSchemas.length; i++)
					{
						if (isSameSchema == false) break;
						isSameSchema = isSameSchema && (JSON.stringify(possibleSchemas[i-1]) == JSON.stringify(possibleSchemas[i]))
					}

					// Si c'est tous les même alors on retourne
					if (isSameSchema) return possibleSchemas[0];

					// Si c'est pas les mêmes on cherche le bon grâce à Ajv. => Solution très lente à l'execution
					if (this.isDebug)
					{
						console.log("Possible Schemas:");
						for (let i in possibleSchemas) console.log(this.schema.anyOf[possibleSchemas[i]]);
					}

					/*for (let i in this.schema.anyOf)
					{
						if (CeHelperTools.validate(this.modelValue, this.schema.anyOf[i], this.schemas)) return i;
					}*/
				}

				return null;
			},

			validSchema()
			{
				const i = this.schemaIndex;
				if (i === null) return null;
				return this.schema.anyOf[i];
			},
			//firstSchema() { return this.schema.anyOf[0]; },

			dataModel: {
				get() {
					return this.modelValue;
				},
				set(value) {
					this.$emit('update:modelValue', value);
				}
			}
		},
		}
</script>
