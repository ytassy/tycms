<template>
	<section class="page-testimonials" :class="classes" :style="variables">
		<div class="page-testimonials-track" tabindex="0" role="list">
			<article v-for="(entry, index) in item.props.testimonials" :key="index" class="page-testimonials-entry" role="listitem">
				<div class="page-testimonials-card">
					<img v-if="item.props.portrait_position === 'top' && portrait(entry)" :src="portrait(entry)" class="page-testimonials-portrait" alt="">
					<div v-if="entry.rating !== null" class="page-testimonials-rating" role="img" :aria-label="`${entry.rating} sur 5`">
						<span v-for="star in 5" :key="star" :class="{ 'page-testimonials-star-empty': star > entry.rating }" aria-hidden="true">★</span>
					</div>
					<div class="page-testimonials-text" v-html="html(entry.text)"></div>
					<footer v-if="item.props.author_position === 'inside'" class="page-testimonials-author">
						<img v-if="item.props.portrait_position === 'author' && portrait(entry)" :src="portrait(entry)" class="page-testimonials-portrait" alt="">
						<div><div class="page-testimonials-name">{{ text(entry.author) }}</div><div class="page-testimonials-role">{{ text(entry.role) }}</div></div>
					</footer>
				</div>
				<footer v-if="item.props.author_position === 'outside'" class="page-testimonials-author">
					<img v-if="item.props.portrait_position === 'author' && portrait(entry)" :src="portrait(entry)" class="page-testimonials-portrait" alt="">
					<div><div class="page-testimonials-name">{{ text(entry.author) }}</div><div class="page-testimonials-role">{{ text(entry.role) }}</div></div>
				</footer>
			</article>
		</div>
		<p v-if="item.props.mode === 'carousel'" class="small text-muted mt-2 mb-0">Faites défiler les cartes horizontalement. La navigation et la lecture automatique s’activent sur le site.</p>
	</section>
</template>
<script>
import DOMPurify from 'dompurify'
import { collectionVariables, testimonialStyleFields } from '../../../common/pageCollectionStyle.js'
import { safePageImageUrl } from '../../../common/safePageImageUrl.js'
import { selectLocalizedText } from '../../../common/localizedText.js'

export default {
	props: { item: Object, node: Object, context: Object, components: Array, viewport: String },
	computed: {
		store() { return this.stores.useAdminPagesStore() },
		classes() {
			const p = this.item.props
			return [`page-testimonials--${p.mode}`, `page-testimonials--${p.align}`, `page-testimonials--portrait-${p.portrait_shape}`,
				{ 'page-testimonials--equal': p.equal_height, 'page-testimonials--quotes': p.show_quotes }]
		},
		variables() {
			const result = collectionVariables(this.item.props.testimonials_style, testimonialStyleFields)
			for (const screen of ['desktop', 'tablet', 'mobile']) result[`--pc-columns-${screen}`] = this.item.props[`columns_${screen}`]
			return result
		},
	},
	methods: {
		text(value) { return selectLocalizedText(value, this.store.previewLocale) },
		html(value) { return DOMPurify.sanitize(this.text(value)) },
		portrait(entry) { return safePageImageUrl(entry.portrait) },
	},
}
</script>
