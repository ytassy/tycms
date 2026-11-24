<template>
	<section>
		<div class="d-flex justify-content-between align-items-center mb-3">
			<h1 class="mb-0">Comptes de service</h1>
			<button type="button" class="btn btn-primary" @click="openCreate">
				<i class="fa-solid fa-plus me-1"></i>Nouveau compte de service
			</button>
		</div>

		<p class="text-muted">
			Comptes sans connexion interactive, porteurs de groupes, rôles et permissions. Leurs jetons d’API
			servent aux intégrations externes (serveur MCP, API). Un jeton est affiché une seule fois, à sa création.
		</p>

		<table class="table align-middle">
			<thead>
				<tr>
					<th>Nom</th>
					<th>Adresse e-mail</th>
					<th>Space</th>
					<th>Jetons</th>
					<th class="text-end">Actions</th>
				</tr>
			</thead>
			<tbody>
				<tr v-for="account in store.accounts" :key="account.id">
					<td>{{ account.name }}</td>
					<td>{{ account.email }}</td>
					<td>#{{ account.spaceId }}</td>
					<td>{{ account.tokensCount }}</td>
					<td class="text-end">
						<button type="button" class="btn btn-sm btn-outline-primary" @click="select(account)">
							Jetons
						</button>
					</td>
				</tr>
				<tr v-if="!store.accounts.length">
					<td colspan="5" class="text-muted">
						{{ store.busy ? 'Chargement…' : 'Aucun compte de service.' }}
					</td>
				</tr>
			</tbody>
		</table>

		<!-- Jetons du compte sélectionné -->
		<div v-if="store.selectedAccount" class="border rounded p-3 mt-3">
			<div class="d-flex justify-content-between align-items-center mb-3">
				<h2 class="h5 mb-0">Jetons — {{ store.selectedAccount.name }}</h2>
				<button type="button" class="btn btn-sm btn-primary" @click="openTokenForm">
					<i class="fa-solid fa-key me-1"></i>Nouveau jeton
				</button>
			</div>

			<form v-if="tokenForm" class="row g-2 align-items-end mb-3" @submit.prevent="submitToken">
				<div class="col-sm-5">
					<label class="form-label" for="token-name">Nom du jeton</label>
					<input id="token-name" v-model.trim="tokenName" maxlength="119" class="form-control" placeholder="Production, Démo…">
				</div>
				<div class="col-sm-4">
					<label class="form-label" for="token-expires">Expiration (facultatif)</label>
					<input id="token-expires" v-model="tokenExpiresAt" type="datetime-local" class="form-control">
				</div>
				<div class="col-sm-3 d-flex gap-2">
					<button type="submit" class="btn btn-primary" :disabled="store.tokensBusy">Créer</button>
					<button type="button" class="btn btn-outline-secondary" @click="tokenForm = false">Annuler</button>
				</div>
			</form>

			<table class="table align-middle">
				<thead>
					<tr>
						<th>Nom</th>
						<th>Créé</th>
						<th>Dernière utilisation</th>
						<th>Expire</th>
						<th>État</th>
						<th class="text-end">Actions</th>
					</tr>
				</thead>
				<tbody>
					<tr v-for="token in store.tokens" :key="token.id">
						<td>{{ token.name }}</td>
						<td>{{ date(token.created) }}</td>
						<td>{{ token.lastUsedAt ? date(token.lastUsedAt) : '—' }}</td>
						<td>{{ token.expiresAt ? date(token.expiresAt) : '—' }}</td>
						<td>
							<span v-if="token.revoked" class="badge bg-secondary">Révoqué</span>
							<span v-else class="badge bg-success">Actif</span>
						</td>
						<td class="text-end">
							<button v-if="!token.revoked" type="button" class="btn btn-sm btn-outline-danger"
								@click="revoke(token)">
								Révoquer
							</button>
						</td>
					</tr>
					<tr v-if="!store.tokens.length">
						<td colspan="6" class="text-muted">{{ store.tokensBusy ? 'Chargement…' : 'Aucun jeton.' }}</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!-- Création d'un compte de service -->
		<modal :visible="createOpen" width="520px" closable @close="createOpen = false">
			<template #header>Nouveau compte de service</template>
			<template #body>
				<form id="service-account-create" @submit.prevent="submitCreate">
					<div class="mb-3">
						<label class="form-label" for="sa-name">Nom</label>
						<input id="sa-name" v-model.trim="draft.name" maxlength="119" class="form-control" placeholder="Assistant MCP">
					</div>
					<div class="mb-3">
						<label class="form-label" for="sa-email">Adresse e-mail</label>
						<input id="sa-email" v-model.trim="draft.email" type="email" maxlength="319" class="form-control" placeholder="service@exemple.fr">
					</div>
					<div>
						<label class="form-label" for="sa-space">ID du Space</label>
						<input id="sa-space" v-model.number="draft.spaceId" type="number" min="1" step="1" class="form-control">
					</div>
				</form>
			</template>
			<template #footer>
				<button type="submit" form="service-account-create" class="btn btn-primary" :disabled="store.busy">
					Créer
				</button>
				<button type="button" class="btn btn-outline-secondary esc" @click="createOpen = false">Annuler</button>
			</template>
		</modal>

		<!-- Affichage unique du jeton -->
		<modal :visible="newToken !== null" width="560px" closable @close="newToken = null">
			<template #header>Jeton créé</template>
			<template #body>
				<p class="text-muted">
					Ce secret ne sera plus jamais affiché. Copiez-le maintenant et conservez-le de façon sûre.
				</p>
				<div class="d-flex align-items-center gap-2">
					<code class="flex-grow-1 text-break">{{ newToken }}</code>
					<text_copy :text-to-copy="newToken ?? ''" :always-visible="true" :show-text-confirmation="true" title="Copier le jeton" />
				</div>
			</template>
			<template #footer>
				<button type="button" class="btn btn-primary esc" @click="newToken = null">J’ai copié le jeton</button>
			</template>
		</modal>
	</section>
</template>

<script>
export default {
	data() {
		return {
			createOpen: false,
			draft: { name: '', email: '', spaceId: null },
			tokenForm: false,
			tokenName: '',
			tokenExpiresAt: '',
			newToken: null,
		}
	},

	computed: {
		store() { return this.stores.useAdminServiceAccountsStore() },
	},

	mounted() {
		this.store.load()
	},

	beforeUnmount() {
		this.store.clear()
	},

	methods: {
		currentSpaceId() {
			return this.conf.getSpace()?.id ?? 1
		},

		openCreate() {
			this.draft = { name: '', email: '', spaceId: this.currentSpaceId() }
			this.createOpen = true
		},

		async submitCreate() {
			this.showModaleWait()
			try {
				await this.store.createAccount({
					name: this.draft.name,
					email: this.draft.email,
					spaceId: this.draft.spaceId,
				})
			} finally {
				this.hideModaleWait()
			}

			this.createOpen = false
			this.showModaleSuccess()
		},

		select(account) {
			this.store.selectedId = account.id
			this.tokenForm = false
			this.store.loadTokens(account.id)
		},

		openTokenForm() {
			this.tokenName = ''
			this.tokenExpiresAt = ''
			this.tokenForm = true
		},

		async submitToken() {
			this.showModaleWait()
			try {
				this.newToken = await this.store.createToken(
					this.store.selectedId,
					this.tokenName,
					this.tokenExpiresAt ? this.tokenExpiresAt.replace('T', ' ') + ':00' : null,
				)
			} finally {
				this.hideModaleWait()
			}

			this.tokenForm = false
		},

		async revoke(token) {
			if (!await this.askYesNoAsync('Révoquer ce jeton ? Il ne pourra plus être utilisé.')) return
			this.showModaleWait()
			try {
				await this.store.revokeToken(token.id)
			} finally {
				this.hideModaleWait()
			}
			this.showModaleSuccess()
		},

		date(value) {
			return value ? this.$date(value) : '—'
		},
	},
}
</script>