<template>
	<div class="ce-helper-item-scalar">
		
		<a v-if="isSet == false" @click="setDefaultValue">
			Définir
		</a>

		<template v-else>
			<div v-if="schema.enum != undefined" class="m-1">
				<!--<select v-model="dataModel" class="form-control d-inline-block" style="width: auto;">
					<option v-for="(value, index) in effectiveEnum" :value="value" :key="index">
						<template v-if="schema._ui_texts != undefined ">{{ schema._ui_texts[value] }}</template>
						<template v-else-if="contextValues != null ">{{ contextValues[value] }}</template>
						<template v-else>{{ value }}</template>
					</option>
				</select>-->

				<dropdown_list
					:items="effectiveEnumForDpL"
					variant="outline-primary"
					field="value"
					:searchOnFields="['name', 'value']"
					@select="dataModel = $event.name"
				>
					<template #default>
						<span v-if="dataModel == null">
							- Choisir -
						</span>

						<span v-else>
							<template v-if="schema._ui_texts != undefined && schema._ui_texts[dataModel] !== undefined">
								{{ schema._ui_texts[dataModel] }}
							</template>

							<template v-else-if="contextValues != null">
								{{ contextValues[dataModel] }}
							</template>

							<template v-else>
								{{ dataModel }}
							</template>
						</span>
					</template>

					<template #item="{item}">
						<div class="fz-90">
							<b>{{ item.value }}</b>
						</div>

						<div class="fz-70 text-gray-700">
							{{ item.name }}
						</div>
					</template>
				</dropdown_list>

				<!--<span v-if="schema._ui_texts != undefined || schema._ui_contextValues != undefined">
					<small>{{ dataModel }}</small>
				</span>-->
			</div>

			<div v-else-if="type == 'string'" class="m-1">
				<div>
					
					<textarea
						v-if="schema._ui_text_editor == 'textarea'"
						v-model="dataModel"
						class="form-control"
						rows="4"
					>
					</textarea>

					<input v-else v-model="dataModel" class="form-control"/>
				</div>

				<div>

					<template v-if="schema._ui_examples != undefined && schema._ui_examples.length > 0">
						<small>Exemples: </small>
						<template v-for="(exemple, index) in schema._ui_examples" :key="index">
							<span class="badge badge-secondary mr-1">
								{{ exemple }}
							</span>
						</template>
					</template>
				</div>
			</div>

			<div v-else-if="type == 'boolean'">
				<input_switch v-model="dataModel"/>
			</div>

			<div v-else-if="type == 'integer'" class="m-1">
				<input
					type="number"
					v-model="dataModel"
					class="form-control"
					style="max-width: 10rem"
					:min="schema.minimum"
					:max="schema.maximum"/>
			</div>

			<div v-else-if="type == 'number'" class="m-1">
				<input
					type="number"
					v-model="dataModel"
					class="form-control"
					style="max-width: 10rem"
					:min="schema.minimum"
					:max="schema.maximum"
				/>
			</div>

			<div v-else>
				Scalar type not implemented :{{ type }}
			</div>

			<div v-if="schema._ui_description != undefined" class="fz-80" v-html="schema._ui_description"></div>
		</template>
	</div>
</template>

<script>
	import editorBase from '../base.js';

		export default
		{
		extends: editorBase,

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

		mounted()
		{
			this.checkDefault();
		},

		watch: { modelValue() { this.checkDefault() } },

		methods:{
			checkDefault() { if (this.required && this.isSet == false) this.setDefaultValue(); },

			setDefaultValue() {
				if (this.type == 'string')
				{
					if (this.schema.enum != undefined) this.dataModel = this.schema.enum[0];
					else
					{
						if (this.schema._ui_example != undefined) this.dataModel = this.schema._ui_example;
						this.dataModel = "";
					}
				}

				if (this.type == 'boolean')
				{
					this.dataModel = false;
				}

				if (this.type == 'integer')
				{
					if (this.schema.minimum != undefined) this.dataModel = this.schema.minimum;
					else this.dataModel = 0;
				}

				if (this.type == 'number')
				{
					if (this.schema.minimum != undefined) this.dataModel = this.schema.minimum;
					else this.dataModel = 0;
				}
			},
		},

				computed:
		{
			
			isSet() { return this.modelValue !== null && this.modelValue !== undefined; },

			type() { return this.schema.type; },
			dataModel: {
				get() {
					return this.modelValue;
				},
				set(value) {
					if (this.type == "integer")
					{
						value = parseInt(value);
					}
					if (this.type == "number")
					{
						value = parseFloat(value);
					}

					this.$emit('update:modelValue', value);
					this.$emit('modified', true);
				}
			},

			contextValues()
			{
				if (this.schema._ui_contextValues != undefined)
				{
					for (let contextName in this.schema._ui_contextValues)
					{
						if (this.context[contextName] != undefined)
						{
							const val = this.context[contextName];

							if (this.schema._ui_contextValues[contextName][val] != undefined)
							{
								return this.schema._ui_contextValues[contextName][val];
							}
						}
					}
				}

				return null;
			},

			effectiveEnum()
			{
				const contextValues = this.contextValues;

				if (contextValues != null) return Object.keys(contextValues);

				return this.schema.enum;
			},

			effectiveEnumForDpL()
			{
				let res = [];
				const effectiveEnum = this.effectiveEnum;

				for (let i in effectiveEnum)
				{
					const name = effectiveEnum[i];

					let value = name;
					if (this.schema._ui_texts != undefined && this.schema._ui_texts[name] !== undefined) value = this.schema._ui_texts[name]
					
					else if (this.contextValues != null) value = this.contextValues[name]

					res.push({
						name: name,
						value: value
					})
				}

				return res;
			}
		}
		}


</script>
