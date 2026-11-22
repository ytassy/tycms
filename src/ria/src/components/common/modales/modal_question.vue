<template>
		<div>
				<modal :visible="visible" :showHeader="true" width="700px" :zIndex="9999995">
			<template v-slot:header>
				Question
			</template>
			<template v-slot:body>

								<div class="tbl-100">
										<div class="td p-3">
						<i class="fa-solid fa-question-circle fa-3x" aria-hidden="true"></i>
										</div>

					<div class="td ps-3 vam w-100">
						<p v-text-ml="modalQuestionText"></p>
										</div>
								</div>

			</template>
			<template v-slot:footer>

				<template v-if="modalQuestionOptions == null">
					<button v-on:click="answer(true)" type="button" class="btn btn-success"><i class="fa-solid fa-check"></i> Oui</button>
					&nbsp;
					<button v-on:click="answer(false)" type="button" class="btn btn-primary esc"><i class="fa-solid fa-times"></i> Non</button>
				</template>
				<template v-else>
					<span v-for="(text, value) in modalQuestionOptions" :key="value">
						&nbsp;
						<button v-on:click="answer(value)" type="button" class="btn btn-primary ml-2"> {{ text }}</button>
					</span>
				</template>
			</template>

		</modal>

		</div>
</template>


<script>

export default {

	computed:{
		modalQuestionText() { return this.stores.useAppStore().modalQuestionText; },
		modalQuestionCallback() { return this.stores.useAppStore().modalQuestionCallback; },
		modalQuestionOptions() { return this.stores.useAppStore().modalQuestionOptions; },

		visible: {
			get() { return this.modalQuestionText !== null }
		},
	},

	methods:{
		answer(response)
		{
			this.stores.useAppStore().answerQuestion(response);
		}
	}

}

</script>

