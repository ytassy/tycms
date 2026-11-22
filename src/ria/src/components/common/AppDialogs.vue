<template>
	<ModalWait />
	<ModalSuccess />
	<ModalMessage />
	<ModalQuestion />
	<ModalInput />
</template>

<script>
import ModalMessage from './modales/modal_message.vue'
import ModalQuestion from './modales/modal_question.vue'
import ModalInput from './modales/modal_input.vue'
import ModalSuccess from './modales/modal_success.vue'
import ModalWait from './modales/modal_wait.vue'

export default {
	components: { ModalMessage, ModalQuestion, ModalInput, ModalSuccess, ModalWait },
}
</script>
