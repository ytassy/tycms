<template>
<h3 class="page-panel-heading">Largeurs</h3>
			<!-- Chaque format conserve les mêmes valeurs et la même règle d’héritage. -->
			<div v-for="breakpoint in breakpoints" :key="breakpoint.id" class="page-field">
				<span :id="`page-column-width-${breakpoint.id}`">{{ breakpoint.label }}</span>
				<div class="d-flex flex-wrap gap-1 mt-1" role="group" :aria-labelledby="`page-column-width-${breakpoint.id}`">
					<button v-if="breakpoint.id !== 'xs'" type="button" class="btn btn-sm"
						:class="!node.sizes[breakpoint.id] ? 'btn-primary' : 'btn-outline-secondary'"
						:aria-pressed="!node.sizes[breakpoint.id]" @click="node.sizes[breakpoint.id] = 0">Non défini</button>
					<button v-for="size in 12" :key="size" type="button" class="btn btn-sm px-2"
						:class="node.sizes[breakpoint.id] === size ? 'btn-primary' : 'btn-outline-secondary'"
						:aria-pressed="node.sizes[breakpoint.id] === size" :aria-label="`${size}/12`"
						@click="node.sizes[breakpoint.id] = size">{{ size }}</button>
				</div>
			</div>
		
</template>
<script>
import pageComponentFormMixin from '../pageComponentFormMixin.js'
export default { mixins: [pageComponentFormMixin] }
</script>
