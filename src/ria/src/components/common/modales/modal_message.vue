<template>
		<div>
				<modal
			:visible="visible"
			:showHeader="true"
			width="55rem"
			:zIndex="99999991"
			:closable="false"
			@close="close"
		>
			<template v-slot:header>
				<span v-if="modalMessageCode == 1" class="">Information</span>
				<span v-else-if="modalMessageCode == 2" class="">Confirmation</span>
				<span v-else-if="modalMessageCode == 3" class="">Attention</span>
				<span v-else-if="modalMessageCode == 4" class="">Attention</span>
				<span v-else-if="modalMessageCode == 5" class="">Erreur</span>
				<span v-else class="">Erreur</span>
			</template>

			<template v-slot:body>
								<div class="tbl-100">
										<div class="td p-3">
												<i v-if="modalMessageCode == 1" class="fa-solid fa-info-circle text-primary fa-3x"></i>
						<i v-else-if="modalMessageCode == 2" class="fa-solid fa-check-circle text-success fa-3x" > </i>
						<i v-else-if="modalMessageCode == 3" class="fa-solid fa-exclamation-circle text-warning fa-3x"></i>
						<i v-else-if="modalMessageCode == 4" class="fa-solid fa-exclamation-circle text-danger fa-3x"></i>
						<i v-else-if="modalMessageCode == 5" class="fa-solid fa-exclamation-circle text-danger fa-3x"></i>
						<i v-else class="fa-solid fa-exclamation-circle text-danger fa-3x"></i>
										</div>

										<div
						v-if="modalMessageException"
						class="td ps-3 vam w-100">
						Une erreur inconnue est survenue.
										</div>

					<div v-if="visible" class="td ps-3 vam w-100" >

						<p v-text-ml="modalMessageText" style="white-space: pre-wrap;"></p>
					</div>
				</div>

			</template>

			<template v-slot:footer v-if="(modalMessageException == false || modalMessageException == null) && visible">
				<button v-on:click="close" style="min-width: 100px"
					type="button"
					class="btn btn-primary esc">
					OK
				</button>
			</template>
		</modal>
		</div>
</template>

<script>

export default {

	computed:{
		modalMessageText() { return this.stores.useAppStore().modalMessageText; },
		modalMessageCode() { return this.stores.useAppStore().modalMessageCode; },
		modalMessageException() { return this.stores.useAppStore().modalMessageException; },

		visible: {
			get() { return this.modalMessageText !== null || this.modalMessageException == true; }
		},
	},

	methods:{
		close()
		{
			this.hideModaleMessage();				
		}
	}
}

</script>


