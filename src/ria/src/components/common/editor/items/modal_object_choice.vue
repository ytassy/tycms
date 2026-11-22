<template>
	<modal
		:visible="visible"
		:showHeader="true"
		width="80rem"
		@close="close"
		:closable="true"
		:closeOnBg="true
">
		<template v-slot:header>
			Choisissez un object
		</template>

		<template v-slot:body>
			<table class="table table-bordered table-hover">
				<tr
					v-for="(obj, index) in objects"
					:key="index" class="cursor-pointer hover-primary"
					@click="select(obj)"
				>
					<td class="p-3" style="width: 100%" v-if="uiProps[index] != null">
						<div class="text-alert text-bold float-right" v-if="uiProps[index]._ui_deprecated === true">
							Deprecated
						</div>

						<div v-if="uiProps[index]._ui_text != undefined">
							<strong>{{ uiProps[index]._ui_text }}</strong>
							<div v-if="uiProps[index]._ui_description != undefined" v-html="uiProps[index]._ui_description"></div>
						</div>

						<pre v-else>{{ JSON.stringify(obj, null, 2) }}</pre>
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
		data() {
			return {
				objects: null,
				callback: null,
				visible: false,
				uiProps: [],
			};
		},

		methods:{
			open(objects, uiProps, callback)
			{
				this.objects = objects;
				this.callback = callback;
				this.visible = true;
				this.uiProps = uiProps;
			},

			close(l)
			{
				this.visible = false;
			},

			select(choice)
			{
				if (choice !== null && typeof choice == 'object' &&  Array.isArray(choice) == false)
				{
					choice = Object.assign({}, choice); // Duplicate object
				}

				this.callback(choice);
				this.visible = false;
			}
		}
		}
</script>


