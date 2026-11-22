<template>
		<div>

				<modal :visible="modalSuccessVisible" :showHeader="false" width="300px" :zIndex="9999999">
			<template v-slot:body>

								<div class="tbl-100">
										<div class="td vam">
												<i class="fa-solid fa-check-circle fa-3x text-success fa-fw"></i>
										</div>
										<div class="td vam tac w-100 fz-15">
						<template v-if="modalSuccessText === null">Terminé</template>
						<template v-else>{{ modalSuccessText }}</template>
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
		modalSuccessVisible() { return this.stores.useAppStore().modalSuccessVisible; },
		modalSuccessText() { return this.stores.useAppStore().modalSuccessText; }
	},
}


</script>


