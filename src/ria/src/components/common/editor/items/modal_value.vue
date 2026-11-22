<template>
	<modal
		:visible="visible"
		:showHeader="true"
		width="80rem"
		@close="close"
		:closable="true"
		:closeOnBg="true"
	>
		<template v-slot:header>
			Choisissez un élément
		</template>

		<template v-slot:body>
			<table class="table table-bordered table-hover">
				<tr v-for="(schema, index) in schemas" :key="index">
					<td class="p-3" style="width: 100%">
						<p v-if="schema._ui_text != undefined">
							<b>{{ schema._ui_text }}</b>
						</p>

						<CeItem
							v-model="values[index]"
							:schema="schema"
							:currentRef="schema"
							:required="true"
							:pointer="pointer"
							:local_pointer="local_pointer"
							:context="context"
						>
						</CeItem>
					</td>

					<td class="td white-space-nowrap p-3 vam">
						<button class="btn btn-primary" @click="select(index)" :enabled="values[index] !== undefined">
							Choisir
						</button>
					</td>
				</tr>
			</table>
		</template>

		<template v-slot:footer>

		</template>
	</modal>
</template>

<script>

		export default {
				props: {
			pointer: { default: "", type: String },
			local_pointer: { default: "", type: String },
			context: { default: null, type: Object },
		},

		data() {
			return {
				schemas: null,
				callback: null,
				visible: false,

				values: {}
			};
		},

		methods:{
			open(schemas, callback)
			{
				this.values = { };
				this.schemas = schemas;
				this.callback = callback;
				this.visible = true;
			},

			close(l)
			{
				this.visible = false;
			},

			select(index)
			{
				this.callback(this.values[index], this.schemas[index], index);
				this.visible = false;
			}
		}
		}
</script>
