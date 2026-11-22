<template>
	<div class="ce-helper-item-object">

		<div v-if="isSet == false">
			<a @click="setDefaultValue">
				Définir
			</a>
		</div>

		<template v-else>
			<ModalValue
				ref="mv"
				v-if="isPatternProperties"
				:pointer="pointer"
				:local_pointer="local_pointer"
				:context="context"
			></ModalValue>

			<div v-if="schema.properties === undefined && schema.patternProperties === undefined">
				<pre>{{ JSON.stringify(dataModel, null, 4) }}</pre>
			</div>
			<!--<div class="text-danger">{{ local_pointer }}</div>-->

			<div class="tbl-100 cursor-pointer hover-gray-600">
				<div class="td-100">
					<div v-if="schema._ui_text != undefined">
						<strong>{{ schema._ui_text }}</strong>
					</div>

					<div v-if="schema._ui_description != undefined">
						<small><span v-html="schema._ui_description"></span></small>
					</div>
				</div>

				
			</div>



			<div>
				<template v-for="(props, groupName) in propertiesByGroups" :key="groupName">
					<div>
						<div
							v-if="groupName != 'default'"
							class="tbl-100 border-bottom border-primary cursor-pointer hover-primary"
							@click="toogleGroup(groupName)"
						>
							<div class="td-100 p-2">
								<strong>{{ groupName }}</strong>
							</div>

							<div class="td p-2">
								<i class="fa-solid fa-angle-up" v-if="moreGroupOpened[groupName] !== true"></i>
								<i class="fa-solid fa-angle-down" v-if="moreGroupOpened[groupName] === true"></i>
							</div>
						</div>

						<div class="tbl-100" v-if="moreGroupOpened[groupName] === true">
							<template v-for="(prop, name) in props" :key="name">
								<div v-if="displayProperty(name)" class="tr">
									<div class="td pe-2 white-space-nowrap hover-handler">
										<div
											:class="'td-100 vam ' + (dataModel[name] !== undefined ? '' : 'text-gray-550')"
											
											data-bs-toggle="tooltip"
											data-bs-placement="right"
										>
											<span>{{ docGetPropertyText(prop, name) }}</span>
										</div>

										<div class="td hover-only ps-2 vam" v-if="canDelete(name)">
											<delete_btn
												@click="removeProperty(name)"
												:spinner="false"
											>
											</delete_btn>
										</div>
									</div>

									<div class="td-100 vam">
										<CeHelperItem
											v-model="dataModel[name]"
											:schema="prop"
											:currentRef="currentRef"
											:required="propertyIsRequired(name)"
											:pointer="pointer + (pointer == '' ? '' : '.') + name"
											:local_pointer="local_pointer + (local_pointer == '' ? '' : '.') + ( isPatternProperties ? '*' : name)"
											:validations="validations"
											:context="newContext(name)"
											@change="$emit('update:modelValue', dataModel)"
											@modified="modified"
										>
										</CeHelperItem>

										<div
											v-if="prop._ui_property_description != undefined"
											class="fz-80"
											v-html="prop._ui_property_description"
										>
										</div>
									</div>
								</div>
							</template>
						</div>
					</div>
				</template>
			</div>

			<div v-if="isPatternProperties">
				<a @click="add">
					<i class="fa-solid fa-plus-square"></i>
				</a>
			</div>
		</template>
	</div>
</template>

<script>
	import editorBase from '../base.js';
	import ModalValue from './modal_value.vue';

	export default
	{
		extends: editorBase,

		components:
		{
			ModalValue,

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

		data(){
			return {
				moreGroupOpened: {
					default: true
				},
				pointerInEdition: null
			};
		},

		mounted() { this.checkDefault(); },
		watch: { modelValue() { this.checkDefault() } },

		methods:{
			
			modified() { this.$emit('modified'); },
		
			setDefaultValue() { this.$emit('update:modelValue', {}); },
			checkDefault(){ if (this.required && this.isSet == false) this.setDefaultValue(); },

			propertyIsRequired(name) { return this.schema.required != undefined && this.schema.required.indexOf(name) >= 0; },

			displayProperty(name)
			{
				return this.schema._ui_hiddenProperties == undefined || this.schema._ui_hiddenProperties.indexOf(name) == -1;
			},

			toogleGroup(groupName)
			{
				if (this.moreGroupOpened[groupName] === undefined) this.moreGroupOpened[groupName] = false; // 				if (this.moreGroupOpened[groupName] === undefined) this.$set(this.moreGroupOpened, groupName, false);
				this.moreGroupOpened[groupName] = ! this.moreGroupOpened[groupName];
			},

			removeProperty(name)
			{
				delete this.modelValue[name];
				this.$emit('update:modelValue', this.modelValue);
			},

			canDelete(name)
			{
				return this.propertyIsRequired(name) == false
					&& this.dataModel[name] !== undefined
					&& (this.schema.minProperties === undefined || this.schema.minProperties < Object.keys(this.dataModel).length)
			},

			add()
			{
				let self = this;
				let schemas = [ this.propertyNamesShema ];

				this.$refs.mv.open(schemas, (value, schema, index) => {
					let valueSchema = self.getSchemaOfPatternValue(value);
					let objects = self.getDefaultObjectsFor(valueSchema, self.currentRef);

					let data = self.dataModel;

					// 					if (data[value] === undefined) Vue.set(data, value, objects.length > 0 ? objects[0] : null);
					if (data[value] === undefined)
					{
						data[value] = objects.length > 0 ? objects[0] : null;
					}
					self.dataModel = data;
				});
			},

			getSchemaOfPatternValue(name)
			{
				for (let pattern in this.schema.patternProperties)
				{
					const regex = new RegExp(pattern, 'ig');
					const propSchema = this.schema.patternProperties[pattern];

					if (regex.test(name) == false)
					{
						continue;
					}

					return propSchema;
				}
				return null;
			},

			newContext(propertyName)
			{
				let context = { ...(this.context === null ? {} : this.context) }; // Clone object

				if (this.schema._ui_contextProperties != undefined)
				{
					for (let name in this.schema._ui_contextProperties)
					{
						let prop = this.schema._ui_contextProperties[name];

						// Ca doit toujours être un array car on concatène après la value
						if (typeof prop === "string") prop = [ prop ];

						let value = '';

						// On concatène avec "__"
						for (let i in prop)
						{
							if (value != "") value += "__";

							let item = prop[i];
							if (item == "*") value += propertyName;
							else if ( item[0] == "$" )
							{
								let contextName = item.substring(1)
								value += context[contextName] == undefined ? '' : context[contextName];
							}
							else value += this.dataModel[prop] == undefined ? null : this.dataModel[prop];
						}
						context[name] = value;
					}
				}

				return context;
			},

			docGetPropertyText(prop, name)
			{
				if (prop._ui_text != undefined) return prop._ui_text;
				return name;
			},
		},

				computed:
		{

			isSet() { return this.modelValue !== null && this.modelValue !== undefined; },

			dataModel: {
				get() {
					return this.modelValue;
				},
				set(value) {
					this.$emit('update:modelValue', value);
				}
			},

			isPatternProperties() { return this.schema.patternProperties !== undefined; },
			propertyNamesShema() { return this.schema.propertyNames === undefined ? null : this.schema.propertyNames; },

			propertiesByGroups()
			{
				let res = {
					default: {}
				};

				if (this.isPatternProperties)
				{
					if (this.isSet == false) return { };

					for (let pattern in this.schema.patternProperties)
					{
						const regex = new RegExp(pattern);
						const propSchema = this.schema.patternProperties[pattern];

						for (let name in this.dataModel)
						{
							if (regex.test(name) == false) continue;
							res.default[name] = propSchema;
						}
					}
				}
				else // Simple Properties
				{
					for (let name in this.schema.properties)
					{
						const prop = this.schema.properties[name];

						if (prop._ui_more === undefined || (this.isSet && this.modelValue[name] !== undefined)) res.default[name] = prop;

						if (prop._ui_more !== undefined)
						{
							if (res[prop._ui_more] == undefined) res[prop._ui_more] = {};
							res[prop._ui_more][name] = prop;
						}
					}
				}

				return Object.keys(res).sort((a,b) => {
					if (a == 'default' && b != 'default') return -1;
					if (b == 'default' && a != 'default') return 1;

					if (a < b) return -1;
					if (a > b) return 1;
					return 0;
				}).reduce(
					(obj, key) => {
						obj[key] = res[key];
						return obj;
					},
					{}
				);
			}

			
		},
		}
</script>
