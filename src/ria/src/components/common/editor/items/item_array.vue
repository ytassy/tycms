<template>
	<div class="ce-helper-item-array">
		<ModalObjectChoice ref="moc"></ModalObjectChoice>

		<a v-if="isSet == false" @click="setDefaultValue">Définir</a>

		<template v-else>
			<div class="tbl-100">
				<div class="tr" v-for="(item, index) in modelValue" :key="index">
					<div class="td p-1 tar white-space-nowrap">
						<div>
							<a @click="insertBefore(index)" title="Insérer avant">
								<i class="fa-solid fa-long-arrow-right"></i>
							</a>

							<delete_btn @click="remove(index)" :key="getUniqueId(index)"></delete_btn>
						</div>

						<div>
							<a @click="itemUp(index)" v-visible="index != 0" title="Monter">
								<i class="fa-solid fa-arrow-up"></i>
							</a>

							<a @click="itemDown(index)" v-visible="index != modelValue.length -1" title="Descendre">
								<i class="fa-solid fa-arrow-down"></i>
							</a>
						</div>
					</div>
					<div class="td-100">
						<div class="border p-2 mb-2" >
							<CeHelperItem
								v-model="dataModel[index]"
								:schema="schema.items"
								:currentRef="currentRef"
								:pointer="pointer + (pointer == '' ? '' : '.') + index"
								:local_pointer="local_pointer + (local_pointer == '' ? '' : '.') + '*'"
								:validations="validations"
								:context="context"
								@change="$emit('update:modelValue', dataModel)"
								@modified="modified"
							>
							</CeHelperItem>
						</div>
					</div>
				</div>
			</div>

			<a @click="add">
				<i class="fa-solid fa-plus-circle" aria-hidden="true"></i>
			</a>
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

		data(){
			return {
				uniqueIds: {}
			};
		},

		mounted() { this.checkDefault(); },

		watch: { modelValue() { this.checkDefault() } },

		methods:{
			setDefaultValue() {
				this.$emit('update:modelValue', []);
				this.$emit('modified', true);
			},
			checkDefault(){ if (this.required && this.isSet == false) this.setDefaultValue(); },

			createItemObjects() {
				let self = this;
				return new Promise((resolve) => {
					let uiProps = [];
					let objects = self.getDefaultObjectsFor(self.schema.items, self.currentRef, uiProps);

					if (objects.length == 0) return;
					else if (objects.length == 1) resolve(objects[0]);
					else self.$refs.moc.open(objects, uiProps, (obj) => {
						resolve(obj);
					});
				});
			},

			insertBefore(index)
			{
				let self = this;
				self.createItemObjects().then((obj) => {
					self.modelValue.splice(index, 0, obj);
					self.$emit('update:modelValue', self.modelValue);
					this.$emit('modified', true);
				});
			},

			add()
			{
				let self = this;
				self.createItemObjects().then((obj) => {
					self.modelValue.push(obj);
					self.$emit('update:modelValue', self.modelValue);
					this.$emit('modified', true);
				});
			},

			itemUp(index)
			{
				let prev = this.modelValue[index-1];
				let cur = this.modelValue[index];
				this.modelValue[index-1] = cur;
				this.modelValue[index] = prev;
				this.uniqueIds = {};

				this.$emit('update:modelValue', this.modelValue);
				this.$emit('modified', true);
			},

			itemDown(index)
			{
				let next = this.modelValue[index+1];
				let cur = this.modelValue[index];
				this.modelValue[index+1] = cur;
				this.modelValue[index] = next;
				this.uniqueIds = {};

				this.$emit('update:modelValue', this.modelValue);
				this.$emit('modified', true);
			},

			remove(index)
			{
				this.modelValue.splice(index, 1);
				this.uniqueIds = {};

				this.$emit('update:modelValue', this.modelValue);
				this.$emit('modified', true);
			},

			getUniqueId(index)
			{
				if (this.uniqueIds[index] == undefined) this.uniqueIds[index] = Math.floor(Math.random()* 999999999999999)
				return this.uniqueIds[index];
			},

			modified() { this.$emit('modified'); }
		
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
					this.$emit('modified', true);
				}
			}
		},
	}
</script>
