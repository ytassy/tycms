<template>
		<div>

				<modal :visible="modalWaitVisible" :showHeader="false" width="300px" :zIndex="9999999">
			<template v-slot:body>

								<div class="tbl-100">
										<div class="td vam p-3">
												<spinner size="md"></spinner>
										</div>
										<div class="td vam tac w-100">
												Veuillez patientez...
										</div>
								</div>

			</template>
		</modal>

		</div>
</template>

<script>

export default {
	computed:
	{
		modalWaitVisible() { return this.stores.useAppStore().modalWaitVisible; }
	},
}

</script>


