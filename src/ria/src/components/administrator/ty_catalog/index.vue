<template>
	<div class="ty-catalog-admin">
		<section v-if="editorVisible">
			<header class="d-flex align-items-start justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">
						{{ editingId === null ? 'Nouveau catalogue' : 'Modifier le catalogue' }}
						<span v-if="editingId !== null" class="badge text-bg-light border align-middle">
							ID {{ editingId }}
						</span>
					</h1>
					<p class="text-body-secondary mb-0">
						Catalogue de l’Espace « {{ editorSpaceTitle }} ». Le slug détermine l’adresse publique.
					</p>
				</div>
				<button type="button" class="btn btn-outline-secondary esc" :disabled="store.saving" @click="closeEditor">
					Retour à la liste
				</button>
			</header>

			<div v-if="store.error" class="alert alert-warning" role="alert">{{ store.error }}</div>

			<form @submit.prevent="save">
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label" for="ty-catalog-name">Nom</label>
						<input id="ty-catalog-name" v-model.trim="draft.name" type="text" class="form-control"
							maxlength="150" required>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="ty-catalog-slug">Slug public</label>
						<input id="ty-catalog-slug" v-model.trim="draft.slug" type="text" class="form-control"
							maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" required>
						<div class="form-text">{{ publicUrl }}</div>
					</div>
					<div v-if="overview?.is_root" class="col-md-6">
						<label class="form-label" for="ty-catalog-space">Espace propriétaire</label>
						<select id="ty-catalog-space" v-model.number="draft.space_id" class="form-select">
							<option v-for="space in (overview?.spaces ?? [])" :key="space.id" :value="space.id">
								{{ space.title }}
							</option>
						</select>
						<div class="form-text">
							ROOT peut déplacer le catalogue. Changer d’Espace modifie les permissions d’administration.
						</div>
					</div>
					<div class="col-12">
						<label class="form-label" for="ty-catalog-description">Description</label>
						<textarea id="ty-catalog-description" v-model="draft.description" class="form-control"
							rows="3" maxlength="5000" />
					</div>
					<div class="col-12">
						<div class="form-check">
							<input id="ty-catalog-enabled" v-model="draft.enabled" type="checkbox" class="form-check-input">
							<label class="form-check-label" for="ty-catalog-enabled">Catalogue actif</label>
						</div>
					</div>

					<div class="col-12">
						<h2 class="h5 mb-0 mt-2">Conservation des versions</h2>
						<p class="text-body-secondary small mb-0">
							Par extension. Les brouillons comptent. La dernière version publiée et son ZIP local sont toujours conservés.
						</p>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="ty-catalog-keep-versions">Versions conservées</label>
						<input id="ty-catalog-keep-versions" v-model.number="draft.keep_versions" type="number" class="form-control"
							min="1" max="100" required>
						<div class="form-text">Valeur par défaut : 4.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="ty-catalog-keep-archives">ZIP conservés</label>
						<input id="ty-catalog-keep-archives" v-model.number="draft.keep_archives" type="number" class="form-control"
							min="1" max="100" required>
						<div class="form-text">Au-delà, le ZIP local est supprimé et les métadonnées restent. Les références externes ne sont pas touchées.</div>
					</div>

					<template v-if="overview?.can_manage_keys">
						<div class="col-12">
							<h2 class="h5 mb-0 mt-2">Clés de confiance</h2>
							<p class="text-body-secondary small mb-0">
								Réservées à ROOT. Une paire de clés Ed25519 couvre toutes les extensions de ce catalogue.
							</p>
						</div>
						<div class="col-md-6">
							<label class="form-label" for="ty-catalog-key-id">Identifiant de clé</label>
							<input id="ty-catalog-key-id" v-model.trim="draft.key_id" type="text" class="form-control"
								maxlength="64" placeholder="extensions-store-1">
						</div>
						<div class="col-md-6">
							<label class="form-label" for="ty-catalog-public-key">Clé publique Ed25519 (base64)</label>
							<input id="ty-catalog-public-key" v-model.trim="draft.public_key" type="text" class="form-control"
								maxlength="128">
						</div>
					</template>
				</div>

				<h2 class="h5 mt-4">Références amies</h2>
				<p class="text-body-secondary">
					Ces catalogues sont proposés à la découverte. Une référence n’autorise ni installation ni adoption automatique.
				</p>

				<div v-if="draft.links.length === 0" class="alert alert-secondary">Aucune référence déclarée.</div>

				<div v-for="(link, index) in draft.links" :key="link.key" class="border rounded p-3 mb-2">
					<div class="row g-2">
						<div class="col-md-4">
							<label class="form-label" :for="`ty-catalog-link-name-${link.key}`">Nom</label>
							<input :id="`ty-catalog-link-name-${link.key}`" v-model.trim="link.name" type="text"
								class="form-control" maxlength="150" required>
						</div>
						<div class="col-md-6">
							<label class="form-label" :for="`ty-catalog-link-url-${link.key}`">URL HTTPS</label>
							<input :id="`ty-catalog-link-url-${link.key}`" v-model.trim="link.target_url" type="url"
								class="form-control" maxlength="500" placeholder="https://exemple.org/tycms-catalog/catalogue" required>
						</div>
						<div class="col-md-2">
							<label class="form-label" :for="`ty-catalog-link-position-${link.key}`">Position</label>
							<input :id="`ty-catalog-link-position-${link.key}`" v-model.number="link.position" type="number"
								class="form-control" min="0" max="9999">
						</div>
						<div class="col-12">
							<label class="form-label" :for="`ty-catalog-link-description-${link.key}`">Description</label>
							<input :id="`ty-catalog-link-description-${link.key}`" v-model.trim="link.description" type="text"
								class="form-control" maxlength="2000">
						</div>
					</div>
					<button type="button" class="btn btn-outline-danger btn-sm mt-2" @click="removeLink(index)">
						<i class="fa-solid fa-trash" aria-hidden="true" /> Retirer
					</button>
				</div>

				<button type="button" class="btn btn-outline-primary btn-sm" :disabled="draft.links.length >= 50" @click="addLink">
					<i class="fa-solid fa-plus" aria-hidden="true" /> Ajouter une référence
				</button>

				<div class="d-flex justify-content-end gap-2 mt-4">
					<button type="button" class="btn btn-outline-secondary" :disabled="store.saving" @click="closeEditor">Annuler</button>
					<button type="submit" class="btn btn-primary ctrl-s" :disabled="store.saving">
						<span v-if="store.saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer
					</button>
				</div>
			</form>
		</section>

		<section v-else-if="versionsVisible">
			<header class="d-flex align-items-start justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">
						Versions — {{ versionsCatalog?.name || 'catalogue' }}
						<span v-if="versionsCatalog" class="badge text-bg-light border align-middle">
							ID {{ versionsCatalog.id }}
						</span>
					</h1>
					<p class="text-body-secondary mb-0">
						Déposez un brouillon, puis publiez-le explicitement. Une version publiée est immuable.
					</p>
				</div>
				<button type="button" class="btn btn-outline-secondary esc" :disabled="store.depositSaving" @click="closeVersions">
					Retour aux catalogues
				</button>
			</header>

			<div v-if="store.versionsError" class="alert alert-warning" role="alert">{{ store.versionsError }}</div>

			<section class="mb-4">
				<h2 class="h5">Déposer un brouillon</h2>
				<template v-if="store.versionsCanEdit">
					<div class="row g-3">
						<div class="col-md-4">
							<label class="form-label" for="ty-catalog-deposit-mode">Mode de dépôt</label>
							<select id="ty-catalog-deposit-mode" v-model="deposit.mode" class="form-select" :disabled="store.depositSaving">
								<option value="local">Téléverser les fichiers</option>
								<option value="external">Référence externe</option>
							</select>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ty-catalog-deposit-name">Nom affiché (facultatif)</label>
							<input id="ty-catalog-deposit-name" v-model.trim="deposit.displayName" type="text" class="form-control" maxlength="150">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="ty-catalog-deposit-description">Description (Markdown basique, facultatif)</label>
							<textarea id="ty-catalog-deposit-description" v-model="deposit.description" class="form-control"
								rows="2" maxlength="2000" />
						</div>

						<template v-if="deposit.mode === 'local'">
							<div class="col-md-4">
								<label class="form-label" for="ty-catalog-deposit-archive">ZIP signé</label>
								<input id="ty-catalog-deposit-archive" ref="depositArchive" type="file" class="form-control" accept=".zip,application/zip"
									@change="deposit.archive = $event.target.files[0] || null">
							</div>
							<div class="col-md-4">
								<label class="form-label" for="ty-catalog-deposit-notes">Notes de version (.md)</label>
								<input id="ty-catalog-deposit-notes" ref="depositNotes" type="file" class="form-control" accept=".md,text/markdown,text/plain"
									@change="deposit.notes = $event.target.files[0] || null">
							</div>
						</template>
						<template v-else>
							<div class="col-md-6">
								<label class="form-label" for="ty-catalog-deposit-archive-url">URL HTTPS du ZIP</label>
								<input id="ty-catalog-deposit-archive-url" v-model.trim="deposit.archiveUrl" type="url" class="form-control" maxlength="2000">
							</div>
							<div class="col-md-6">
								<label class="form-label" for="ty-catalog-deposit-notes-url">URL HTTPS des notes</label>
								<input id="ty-catalog-deposit-notes-url" v-model.trim="deposit.notesUrl" type="url" class="form-control" maxlength="2000">
							</div>
						</template>

						<div class="col-md-4">
							<label class="form-label" for="ty-catalog-deposit-signature">Enveloppe de signature (.json)</label>
							<input id="ty-catalog-deposit-signature" ref="depositSignature" type="file" class="form-control" accept=".json,application/json"
								@change="deposit.signature = $event.target.files[0] || null">
						</div>
					</div>

					<p class="text-body-secondary small mt-2">
						Le serveur vérifie la signature avec la clé de confiance du catalogue, le ZIP et les notes avant d’enregistrer un brouillon. Aucun PHP déposé n’est exécuté.
					</p>
					<button type="button" class="btn btn-primary" :disabled="store.depositSaving" @click="depositVersion">
						<span v-if="store.depositSaving" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
						Déposer le brouillon
					</button>
				</template>
				<p v-else class="alert alert-info mb-0">Vous pouvez consulter les versions, mais pas en déposer.</p>
			</section>

			<section>
				<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
					<h2 class="h3 mb-0">Versions</h2>
					<button v-if="store.versionsCanPublish" type="button" class="btn btn-outline-danger btn-sm"
						:disabled="store.depositSaving" @click="cleanupVersions">
						<i class="fa-solid fa-broom" aria-hidden="true" /> Nettoyer maintenant
					</button>
				</div>
				<div v-if="ficheOpen" class="card mb-3">
					<div class="card-body">
						<h3 class="h6">Fiche de {{ ficheForm.identifier }}</h3>
						<p class="text-body-secondary small">Ce nom et cette description alimentent l’API publique.</p>
						<div class="row g-2">
							<div class="col-md-5">
								<label class="form-label" for="ty-catalog-fiche-name">Nom affiché</label>
								<input id="ty-catalog-fiche-name" v-model.trim="ficheForm.name" type="text" class="form-control"
									maxlength="150" required>
							</div>
							<div class="col-md-7">
								<label class="form-label" for="ty-catalog-fiche-image">URL de l’image</label>
								<input id="ty-catalog-fiche-image" v-model.trim="ficheForm.imageUrl" type="url" class="form-control"
									maxlength="2000" placeholder="https://exemple.org/logo.png">
								<div class="form-text">URL absolue HTTPS. L’aperçu dépend des origines d’images autorisées dans Sécurité.</div>
							</div>
							<div class="col-12">
								<label class="form-label" for="ty-catalog-fiche-description">Description (Markdown basique)</label>
								<textarea id="ty-catalog-fiche-description" v-model="ficheForm.description" class="form-control"
									rows="4" maxlength="2000" />
								<div class="form-text">Gras, italique, listes et liens. HTML brut et images ne sont pas rendus ; utiliser le champ image.</div>
							</div>
							<div v-if="ficheForm.description" class="col-12">
								<div class="border rounded p-2 markdown-preview" v-html="descriptionPreview"></div>
							</div>
							<div v-if="ficheForm.imageUrl" class="col-12">
								<img :src="ficheForm.imageUrl" alt="" class="fiche-preview" referrerpolicy="no-referrer">
							</div>
						</div>
						<div class="d-flex gap-2 mt-2">
							<button type="button" class="btn btn-primary btn-sm" :disabled="store.ficheSaving" @click="saveFiche">
								<span v-if="store.ficheSaving" class="spinner-border spinner-border-sm me-1" aria-hidden="true" />Enregistrer la fiche
							</button>
							<button type="button" class="btn btn-outline-secondary btn-sm" :disabled="store.ficheSaving"
								@click="ficheOpen = false">Annuler</button>
						</div>
					</div>
				</div>

				<div v-if="store.versionsLoading" class="py-4 text-center"><spinner /></div>
				<template v-else>
					<div v-if="versionGroups.length === 0" class="alert alert-info">Aucune version déposée pour ce catalogue.</div>
					<div v-for="group in versionGroups" :key="group.identifier" class="mb-4">
						<h3 class="h4 mb-2">
							{{ group.name }} 
						</h3>
						<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
							<button v-if="store.versionsCanEdit" type="button" class="btn btn-outline-primary btn-sm"
									@click="openFiche(group)">
									<i class="fa-solid fa-pen" aria-hidden="true" /> Fiche
							</button>
							<div>
								<span class="badge bg-primary fz-70">{{ group.identifier }}</span>
								<p v-if="group.description" class="text-body-secondary small mb-1" style="white-space: pre-line">{{ group.description }}</p>
							</div>
						</div>
						<div v-for="version in group.versions" :key="version.id" class="card mb-2">
							<div class="card-body d-flex flex-wrap align-items-center gap-3">
								<div class="flex-grow-1">
									<div class="d-flex flex-wrap align-items-center gap-2">
										<strong>{{ version.version }}</strong>
										<span :class="['badge', statusClass(version.status)]">{{ statusLabel(version.status) }}</span>
										<span v-if="version.status === 'published'" :class="['badge', channelClass(version.channel)]">
											{{ channelLabel(version.channel) }}
										</span>
										<span class="badge text-bg-light text-dark">
											{{ version.storage_type === 'local' ? 'Stockage local' : 'Référence externe' }}
										</span>
										<span v-if="version.archive_removed_at" class="badge text-bg-secondary">ZIP supprimé</span>
									</div>
									<p class="mb-0 small text-body-secondary">
										{{ formatBytes(version.archive_bytes) }} · SHA-256
										<code>{{ version.archive_sha256.slice(0, 16) }}…</code>
										<span v-if="version.published_at"> · publiée le {{ version.published_at }}</span>
										<span v-if="version.withdrawn_at"> · retirée le {{ version.withdrawn_at }}</span>
									</p>
									<p v-if="version.archive_removed_at" class="mb-0 small text-body-secondary">
										Le ZIP a été supprimé par la retention ; les métadonnées restent consultables.
									</p>
								</div>
								<div v-if="store.versionsCanPublish" class="d-flex gap-2">
									<template v-if="version.status === 'draft'">
										<button type="button" class="btn btn-primary btn-sm"
											:disabled="store.depositSaving || !!version.archive_removed_at"
											@click="versionAction('publish', version, 'beta')">Publier en bêta</button>
										<button type="button" class="btn btn-outline-primary btn-sm"
											:disabled="store.depositSaving || !!version.archive_removed_at"
											@click="versionAction('publish', version, 'release')">Publier en release</button>
									</template>
									<template v-else-if="version.status === 'published'">
										<button v-if="version.channel === 'beta'" type="button" class="btn btn-success btn-sm"
											:disabled="store.depositSaving" @click="versionAction('promote', version)">Promouvoir en release</button>
										<button type="button" class="btn btn-outline-danger btn-sm"
											:disabled="store.depositSaving" @click="versionAction('withdraw', version)">Retirer</button>
									</template>
									<button v-else-if="version.status === 'withdrawn'" type="button" class="btn btn-outline-primary btn-sm"
										:disabled="store.depositSaving || !!version.archive_removed_at" @click="versionAction('reactivate', version)">Réactiver</button>
								</div>
							</div>
						</div>
					</div>
				</template>
			</section>
		</section>

		<section v-else-if="tokensVisible">
			<header class="d-flex align-items-start justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">
						Jetons — {{ tokensCatalog?.name || 'catalogue' }}
						<span v-if="tokensCatalog" class="badge text-bg-light border align-middle">
							ID {{ tokensCatalog.id }}
						</span>
					</h1>
					<p class="text-body-secondary mb-0">
						Jetons de publication distante, limités à ce catalogue. Le secret n’est affiché qu’à la création.
					</p>
				</div>
				<button type="button" class="btn btn-outline-secondary esc" :disabled="store.tokenSaving" @click="closeTokens">
					Retour aux catalogues
				</button>
			</header>

			<div v-if="store.tokensError" class="alert alert-warning" role="alert">{{ store.tokensError }}</div>

			<section class="mb-4">
				<h2 class="h5">Créer un jeton</h2>
				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label" for="ty-catalog-token-label">Libellé</label>
						<input id="ty-catalog-token-label" v-model.trim="tokenForm.label" type="text" class="form-control"
							maxlength="150" placeholder="CI, poste de publication…" :disabled="store.tokenSaving">
					</div>
					<div class="col-md-3">
						<label class="form-label" for="ty-catalog-token-days">Expiration (jours)</label>
						<input id="ty-catalog-token-days" v-model.number="tokenForm.days" type="number" class="form-control"
							min="0" max="3650" :disabled="store.tokenSaving">
						<div class="form-text">0 = sans expiration</div>
					</div>
					<div class="col-md-3 d-flex flex-column justify-content-end pb-2">
						<div class="form-check">
							<input id="ty-catalog-token-deposit" v-model="tokenForm.deposit" type="checkbox" class="form-check-input"
								:disabled="store.tokenSaving">
							<label class="form-check-label" for="ty-catalog-token-deposit">Dépôt de brouillon</label>
						</div>
						<div class="form-check">
							<input id="ty-catalog-token-publish" v-model="tokenForm.publish" type="checkbox" class="form-check-input"
								:disabled="store.tokenSaving">
							<label class="form-check-label" for="ty-catalog-token-publish">Publier, retirer, réactiver</label>
						</div>
					</div>
				</div>
				<button type="button" class="btn btn-primary mt-3" :disabled="store.tokenSaving" @click="createToken">
					<span v-if="store.tokenSaving" class="spinner-border spinner-border-sm me-2" aria-hidden="true" />
					Créer le jeton
				</button>
			</section>

			<div v-if="store.createdToken" class="alert alert-warning" role="alert">
				<p class="fw-semibold mb-1">Jeton créé. Copiez-le maintenant : il ne sera plus affiché.</p>
				<div class="d-flex align-items-center gap-2">
					<code class="text-break">{{ store.createdToken }}</code>
					<text_copy :text-to-copy="store.createdToken" show-text-confirmation />
				</div>
			</div>

			<section>
				<h2 class="h5">Jetons existants</h2>
				<div v-if="store.tokensLoading" class="py-4 text-center"><spinner /></div>
				<template v-else>
					<div v-if="store.tokens.length === 0" class="alert alert-info">Aucun jeton pour ce catalogue.</div>
					<div v-for="token in store.tokens" :key="token.id" class="card mb-2">
						<div class="card-body d-flex flex-wrap align-items-center gap-3">
							<div class="flex-grow-1">
								<div class="d-flex flex-wrap align-items-center gap-2">
									<strong>{{ token.label }}</strong>
									<span :class="['badge', tokenStatusClass(token)]">{{ tokenStatus(token) }}</span>
								</div>
								<p class="mb-0 small text-body-secondary">
									#{{ token.id }} · droits : {{ tokenAbilitiesLabel(token) }}
									· expiration : {{ tokenExpiryLabel(token) }}
									· dernier usage : {{ token.last_used_at || 'jamais' }}
								</p>
							</div>
							<button v-if="!token.revoked_at" type="button" class="btn btn-outline-danger btn-sm"
								:disabled="store.tokenSaving" @click="revokeToken(token)">Révoquer</button>
						</div>
					</div>
				</template>
			</section>
		</section>

		<section v-else>
			<div class="d-flex align-items-center justify-content-between gap-3 mb-3">
				<div>
					<h1 class="mb-1">Catalogues TyCMS</h1>
					<p v-if="overview?.is_root" class="text-body-secondary mb-0">
						Tous les catalogues, tous les Espaces confondus. ROOT peut éditer et déplacer chaque catalogue.
					</p>
					<p v-else class="text-body-secondary mb-0">Catalogues hébergés par l’Espace « {{ spaceTitle }} ».</p>
				</div>
				<button v-if="overview?.can_edit" type="button" class="btn btn-primary btn-sm"
					:disabled="store.loading || store.saving" @click="openEditor(null)">
					<i class="fa-solid fa-plus" aria-hidden="true" /> Nouveau catalogue
				</button>
			</div>

			<div v-if="store.error" class="alert alert-warning" role="alert">{{ store.error }}</div>
			<div v-if="store.loading" class="py-5 text-center"><spinner /></div>

			<template v-else-if="overview">
				<p v-if="overview.can_manage_keys" class="text-body-secondary small">
					Racine privée des paquets : <code>{{ overview.storage_root }}</code>. Elle se règle dans <code>configuration.php</code>.
				</p>
				<p v-if="!overview.can_edit" class="alert alert-info">
					Vous pouvez consulter les catalogues, mais pas les modifier.
				</p>

				<div v-if="catalogs.length === 0" class="alert alert-info">
					{{ overview.is_root ? 'Aucun catalogue n’est encore hébergé.' : 'Aucun catalogue n’est encore hébergé par cet Espace.' }}
				</div>

				<div v-for="catalog in catalogs" :key="catalog.id" class="card mb-3">
					<div class="card-body d-flex flex-wrap align-items-start gap-3">
						<div class="flex-grow-1">
							<div class="d-flex flex-wrap align-items-center gap-2">
								<h2 class="h5 mb-0">{{ catalog.name }}</h2>
								<span class="badge text-bg-light border"
									title="Identifiant à utiliser dans la configuration du script de publication">
									ID {{ catalog.id }}
								</span>
								<span v-if="overview.is_root" class="badge text-bg-light border">
									Espace : {{ catalog.space_title }}
								</span>
								<span :class="['badge', catalog.enabled ? 'text-bg-success' : 'text-bg-secondary']">
									{{ catalog.enabled ? 'Actif' : 'Désactivé' }}
								</span>
								<span v-if="!catalog.key_id" class="badge text-bg-warning">Clé non configurée</span>
							</div>
							<p v-if="catalog.description" class="text-body-secondary mb-1 mt-1">{{ catalog.description }}</p>
							<p class="mb-1 small"><code>/tycms-catalog/{{ catalog.slug }}</code></p>
							<p class="mb-0 small text-body-secondary">
								{{ catalog.links.length }} référence{{ catalog.links.length > 1 ? 's' : '' }} amie{{ catalog.links.length > 1 ? 's' : '' }}
							</p>
							<p class="mb-0 small text-body-secondary">
								Conservation : {{ catalog.keep_versions }} versions / {{ catalog.keep_archives }} ZIP
							</p>
						</div>
						<div class="d-flex gap-2">
							<button v-if="overview.can_manage_tokens" type="button" class="btn btn-outline-secondary btn-sm" @click="openTokens(catalog)">
								<i class="fa-solid fa-key" aria-hidden="true" /> Jetons
							</button>
							<button type="button" class="btn btn-outline-secondary btn-sm" @click="openVersions(catalog)">
								<i class="fa-solid fa-layer-group" aria-hidden="true" /> Versions
							</button>
							<button v-if="overview.can_edit" type="button" class="btn btn-outline-primary btn-sm" @click="openEditor(catalog)">
								<i class="fa-solid fa-pen-to-square" aria-hidden="true" /> Modifier
							</button>
						</div>
					</div>
				</div>
			</template>
		</section>
	</div>
</template>

<script>
import { renderMarkdown } from '../../../application/markdown.js'

let linkKey = 0

function emptyDraft() {
	return {
		space_id: null,
		name: '',
		slug: '',
		description: '',
		enabled: true,
		keep_versions: 4,
		keep_archives: 4,
		key_id: null,
		public_key: null,
		links: [],
	}
}

function emptyDeposit() {
	return {
		mode: 'local',
		displayName: '',
		description: '',
		archive: null,
		notes: null,
		signature: null,
		archiveUrl: '',
		notesUrl: '',
	}
}

function emptyTokenForm() {
	return {
		label: '',
		deposit: true,
		publish: false,
		days: 365,
	}
}

function emptyFiche() {
	return {
		identifier: '',
		name: '',
		description: '',
		imageUrl: '',
	}
}

function copy(value) {
	return value === undefined ? undefined : JSON.parse(JSON.stringify(value))
}

export default {
	data() {
		return {
			editorVisible: false,
			versionsVisible: false,
			tokensVisible: false,
			versionsCatalog: null,
			tokensCatalog: null,
			editingId: null,
			editingSpaceId: null,
			draft: emptyDraft(),
			deposit: emptyDeposit(),
			tokenForm: emptyTokenForm(),
			ficheOpen: false,
			ficheForm: emptyFiche(),
		}
	},

	computed: {
		store() {
			return this.stores.useAdminTyCatalogStore()
		},

		overview() {
			return this.store.overview
		},

		catalogs() {
			return this.overview?.catalogs ?? []
		},

		versionGroups() {
			const groups = new Map()
			for (const version of this.store.versions) {
				const identifier = version.extension_identifier
				if (!groups.has(identifier)) {
					groups.set(identifier, {
						identifier,
						name: version.extension_name || identifier,
						description: version.extension_description || '',
						imageUrl: version.extension_image_url || '',
						versions: [],
					})
				}
				groups.get(identifier).versions.push(version)
			}
			return [...groups.values()]
		},

		descriptionPreview() {
			return renderMarkdown(this.ficheForm.description || '')
		},

		spaceTitle() {
			return this.overview?.space?.title || `#${this.overview?.space?.id ?? ''}`
		},

		editorSpaceTitle() {
			const selected = (this.overview?.spaces ?? []).find((space) => space.id === this.draft.space_id)
			return selected?.title || this.spaceTitle
		},

		publicUrl() {
			const slug = this.draft.slug || '<slug>'
			return `${window.location.origin}/tycms-catalog/${slug}`
		},
	},

	async mounted() {
		try {
			await this.store.load()
		} catch {
			// Le store conserve le message ; la page garde son état vide.
		}
	},

	methods: {
		openEditor(catalog) {
			this.store.clearError()
			this.versionsVisible = false
			this.tokensVisible = false
			this.editingId = catalog?.id ?? null
			this.editingSpaceId = catalog?.space_id ?? null
			this.draft = catalog === null || catalog === undefined
				? { ...emptyDraft(), space_id: this.overview?.space?.id ?? null }
				: {
					space_id: catalog.space_id,
					name: catalog.name,
					slug: catalog.slug,
					description: catalog.description,
					enabled: catalog.enabled,
					keep_versions: catalog.keep_versions ?? 4,
					keep_archives: catalog.keep_archives ?? 4,
					key_id: catalog.key_id,
					public_key: catalog.public_key,
					links: catalog.links.map((link) => ({
						...copy(link),
						key: `link-${++linkKey}`,
					})),
				}
			this.editorVisible = true
		},

		async openVersions(catalog) {
			this.store.clearVersionsError()
			this.editorVisible = false
			this.tokensVisible = false
			this.versionsVisible = true
			this.versionsCatalog = catalog
			this.resetDeposit()
			try {
				await this.store.loadVersions(catalog.id)
			} catch {
				// Le store conserve le message.
			}
		},

		closeVersions() {
			if (this.store.depositSaving) return
			this.versionsVisible = false
			this.versionsCatalog = null
			this.store.clearVersionsError()
		},

		async openTokens(catalog) {
			this.store.clearTokensError()
			this.store.clearCreatedToken()
			this.editorVisible = false
			this.versionsVisible = false
			this.tokensVisible = true
			this.tokensCatalog = catalog
			this.tokenForm = emptyTokenForm()
			try {
				await this.store.loadTokens(catalog.id)
			} catch {
				// Le store conserve le message.
			}
		},

		closeTokens() {
			if (this.store.tokenSaving) return
			this.tokensVisible = false
			this.tokensCatalog = null
			this.store.clearTokensError()
			this.store.clearCreatedToken()
		},

		async createToken() {
			if (this.store.tokenSaving || !this.tokensCatalog) return

			const label = this.tokenForm.label.trim()
			const abilities = []
			if (this.tokenForm.deposit) abilities.push('deposit')
			if (this.tokenForm.publish) abilities.push('publish')
			if (label === '' || abilities.length === 0) {
				this.store.tokensError = 'Renseignez un libellé et au moins un droit.'
				return
			}

			try {
				await this.store.createToken({
					catalogId: this.tokensCatalog.id,
					label,
					abilities,
					days: Number(this.tokenForm.days) || 0,
				})
				this.showModaleSuccess('Jeton créé. Copiez-le maintenant.')
				this.tokenForm = emptyTokenForm()
			} catch {
				// Le client API présente le refus.
			}
		},

		async revokeToken(token) {
			if (this.store.tokenSaving || !this.tokensCatalog) return
			const confirmed = await this.askYesNoAsync(`Révoquer le jeton « ${token.label} » ? Cette action est immédiate.`)
			if (!confirmed) return

			try {
				await this.store.revokeToken(this.tokensCatalog.id, token.id)
				this.showModaleSuccess('Jeton révoqué.')
			} catch {
				// Le client API présente le refus.
			}
		},

		tokenStatus(token) {
			if (token.revoked_at) return 'Révoqué'
			if (!token.enabled) return 'Désactivé'
			if (token.expires_at && new Date(token.expires_at) <= new Date()) return 'Expiré'
			return 'Actif'
		},

		tokenStatusClass(token) {
			if (token.revoked_at || !token.enabled) return 'text-bg-secondary'
			if (token.expires_at && new Date(token.expires_at) <= new Date()) return 'text-bg-warning'
			return 'text-bg-success'
		},

		tokenAbilitiesLabel(token) {
			return token.abilities
				.map((ability) => ability === 'deposit' ? 'Dépôt' : ability === 'publish' ? 'Publication' : ability)
				.join(', ')
		},

		tokenExpiryLabel(token) {
			return token.expires_at || 'Aucune'
		},

		resetDeposit() {
			this.deposit = emptyDeposit()
			for (const ref of ['depositArchive', 'depositNotes', 'depositSignature']) {
				if (this.$refs[ref]) this.$refs[ref].value = ''
			}
		},

		async depositVersion() {
			if (this.store.depositSaving || !this.versionsCatalog) return
			const catalogId = this.versionsCatalog.id

			if (!this.deposit.signature) {
				this.store.versionsError = 'L’enveloppe de signature est requise.'
				return
			}

			try {
				if (this.deposit.mode === 'local') {
					if (!this.deposit.archive || !this.deposit.notes) {
						this.store.versionsError = 'Le ZIP et les notes de version sont requis.'
						return
					}
					await this.store.depositLocal(catalogId, {
						displayName: this.deposit.displayName,
						description: this.deposit.description,
						archive: this.deposit.archive,
						notes: this.deposit.notes,
						signature: this.deposit.signature,
					})
				} else {
					if (!this.deposit.archiveUrl || !this.deposit.notesUrl) {
						this.store.versionsError = 'Les URL du ZIP et des notes sont requises.'
						return
					}
					const envelope = await this.deposit.signature.text()
					await this.store.depositReference({
						catalogId,
						archiveUrl: this.deposit.archiveUrl,
						notesUrl: this.deposit.notesUrl,
						envelope,
						displayName: this.deposit.displayName,
						description: this.deposit.description,
					})
				}

				this.showModaleSuccess('Brouillon déposé.')
				this.resetDeposit()
			} catch {
				// Le client API ou le store présente le refus.
			}
		},

		async versionAction(action, version, channel = null) {
			if (this.store.depositSaving || !this.versionsCatalog) return
			const labels = {
				publish: channel === 'beta' ? 'Publier en bêta' : 'Publier en release',
				promote: 'Promouvoir en release',
				withdraw: 'Retirer',
				reactivate: 'Réactiver',
			}
			const label = labels[action] || action
			const confirmed = await this.askYesNoAsync(
				`${label} la version ${version.extension_identifier} ${version.version} ?`,
			)
			if (!confirmed) return

			try {
				const result = await this.store.versionAction(action, this.versionsCatalog.id, version.id, channel)
				const done = {
					publish: channel === 'beta' ? 'publiée en bêta' : 'publiée en release',
					promote: 'promue en release',
					withdraw: 'retirée',
					reactivate: 'réactivée',
				}
				const cleanup = result?.cleanup
				let summary = `Version ${done[action] || action}.`
				if (cleanup) {
					summary += ` Nettoyage : ${cleanup.versions_deleted} version(s), ${cleanup.archives_deleted} ZIP.`
				}
				if (cleanup?.errors?.length) this.showModaleWarning(summary + ' Certaines opérations ont échoué.')
				else this.showModaleSuccess(summary)
			} catch {
				// Le client API présente le refus.
			}
		},

		async cleanupVersions() {
			if (this.store.depositSaving || !this.versionsCatalog) return
			const confirmed = await this.askYesNoAsync(
				`Nettoyer les versions de « ${this.versionsCatalog.name} » ? Les versions hors fenêtre et les ZIP au-delà de la limite seront supprimés.`,
			)
			if (!confirmed) return

			try {
				const result = await this.store.cleanupVersions(this.versionsCatalog.id)
				this.showCleanup(result?.cleanup)
			} catch {
				// Le client API présente le refus.
			}
		},

		showCleanup(cleanup) {
			if (!cleanup) return
			const summary = `Nettoyage : ${cleanup.versions_deleted} version(s) et ${cleanup.archives_deleted} ZIP supprimé(s).`
			if (cleanup.errors?.length) this.showModaleWarning(summary + ' Certaines opérations ont échoué.')
			else this.showModaleSuccess(summary)
		},

		openFiche(group) {
			this.store.clearVersionsError()
			this.ficheForm = {
				identifier: group.identifier,
				name: group.name,
				description: group.description,
				imageUrl: group.imageUrl,
			}
			this.ficheOpen = true
		},

		async saveFiche() {
			if (this.store.ficheSaving || !this.versionsCatalog) return
			if (this.ficheForm.name.trim() === '') {
				this.store.versionsError = 'Le nom affiché est requis.'
				return
			}

			try {
				await this.store.saveExtensionFiche({
					catalogId: this.versionsCatalog.id,
					identifier: this.ficheForm.identifier,
					displayName: this.ficheForm.name,
					description: this.ficheForm.description,
					imageUrl: this.ficheForm.imageUrl || null,
				})
				this.ficheOpen = false
				this.showModaleSuccess('Fiche enregistrée.')
			} catch {
				// Le client API présente le refus.
			}
		},

		statusLabel(status) {
			return { draft: 'Brouillon', published: 'Publiée', withdrawn: 'Retirée' }[status] || status
		},

		statusClass(status) {
			return { draft: 'text-bg-warning', published: 'text-bg-success', withdrawn: 'text-bg-secondary' }[status] || 'text-bg-secondary'
		},

		channelLabel(channel) {
			return { beta: 'Bêta', release: 'Release' }[channel] || channel
		},

		channelClass(channel) {
			// La bêta se distingue du release sans reprendre le vert du statut publié.
			return channel === 'beta' ? 'text-bg-info' : 'text-bg-light text-dark'
		},

		formatBytes(bytes) {
			if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} Mo`
			if (bytes >= 1024) return `${Math.round(bytes / 1024)} Ko`
			return `${bytes} o`
		},

		closeEditor() {
			if (this.store.saving) return
			this.editorVisible = false
			this.editingId = null
			this.editingSpaceId = null
			this.draft = emptyDraft()
			this.store.clearError()
		},

		addLink() {
			if (this.draft.links.length >= 50) return
			this.draft.links.push({
				key: `link-${++linkKey}`,
				name: '',
				target_url: '',
				description: '',
				position: this.draft.links.length,
			})
		},

		removeLink(index) {
			this.draft.links.splice(index, 1)
		},

		async save() {
			if (this.store.saving) return

			// ROOT peut changer l’Espace propriétaire. Le déplacement change les
			// permissions d'administration : demander une confirmation explicite.
			if (this.overview?.is_root && this.editingId !== null
				&& this.draft.space_id !== this.editingSpaceId) {
				const destination = (this.overview.spaces ?? []).find((space) => space.id === this.draft.space_id)
				const confirmed = await this.askYesNoAsync(
					`Déplacer le catalogue « ${this.draft.name} » vers l’Espace « ${destination?.title ?? this.draft.space_id} » ?`
					+ ' Les permissions d’administration seront celles de l’Espace cible.',
				)
				if (!confirmed) return
			}

			const data = {
				id: this.editingId,
				name: this.draft.name,
				slug: this.draft.slug,
				description: this.draft.description,
				enabled: this.draft.enabled,
				keep_versions: Number(this.draft.keep_versions) || 4,
				keep_archives: Number(this.draft.keep_archives) || 4,
				links: this.draft.links.map((link) => ({
					name: link.name,
					target_url: link.target_url,
					description: link.description,
					position: Number(link.position) || 0,
				})),
			}

			// Le serveur ignore ces champs pour un compte non ROOT ; ne pas les envoyer.
			if (this.overview?.can_manage_keys) {
				data.key_id = this.draft.key_id || null
				data.public_key = this.draft.public_key || null
			}
			if (this.overview?.is_root) {
				data.spaceId = this.draft.space_id
			}

			try {
				await this.store.save(data)
				this.showModaleSuccess('Catalogue enregistré.')
				this.editorVisible = false
				this.editingId = null
				this.editingSpaceId = null
				this.draft = emptyDraft()
			} catch {
				// Le client API présente le message serveur ; conserver le brouillon.
			}
		},
	},
}
</script>

<style scoped>
.markdown-preview { max-width: 100%; }
.markdown-preview :deep(p) { margin-bottom: .5rem; }
.markdown-preview :deep(:is(ul, ol)) { margin-bottom: .5rem; padding-left: 1.25rem; }
.markdown-preview :deep(> :last-child) { margin-bottom: 0; }
.fiche-preview { max-height: 120px; max-width: 100%; object-fit: contain; }
</style>
