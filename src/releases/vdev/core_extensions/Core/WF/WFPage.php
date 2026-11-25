<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;
use YTassy\Tycms\Lib\Scss\ScssCompiler;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\DiagramShortCode;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHistoryHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageSize;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageCommonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageTabs;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageAccordion;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFPage extends BaseWF
{
    use TraitAllHelpers;
    use \YTassy\Tycms\CoreExts\Security\Traits\TraitSecurityHelpers;

    /** Catalogue de destinations limité au droit éditorial et au Space demandé. */
    public function linkMenuItems(int $spaceId): array
    {
        if (!$this->canEdit($spaceId)) ExceptionResponse::throwDanger('Vous ne pouvez pas choisir un lien dans cet espace.');
        return ['items' => (new PageLinks($this->coreMenuHelper, $this->coreMenuRouteHelper, $this->coreSpaceLanguageHelper))->menuOptions($spaceId)];
    }

    public function editorContext(int $spaceId): array
    {
        if (!$this->canRead($spaceId)) ExceptionResponse::throwDanger('Vous ne pouvez pas consulter cet espace.');
        if (!$this->canEdit($spaceId)) return [
            'context' => (object) ['plg_core_page_max_size' => PageSize::pageMaximum($this->app->getConf()),
                'plg_core_diagram_max_size' => PageSize::diagramMaximum($this->app->getConf())],
            'forms' => (object) [],
        ];

        return $this->corePageComponentRegistry->prepareEditor($spaceId);
    }

    public function directory(): array
    {
        $spaces = [];
        $pages = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if (!$this->canRead($space->id)) continue;
            $configuredLanguages = $this->coreSpaceLanguageHelper->listForSpace($space->id);
            $spaces[] = ['id' => $space->id, 'title' => $space->title,
                'canEdit' => $this->canEdit($space->id),
                'mermaidOptions' => DiagramShortCode::getDefaultOptions($this->coreShortCodeHelper->configuration($space->id)),
                'languages' => array_map(static fn ($language) => $language->locale,
                    array_values(array_filter($configuredLanguages, static fn ($language) => $language->enabled))),
                'configuredLanguages' => array_map(static fn ($language) => $language->locale,
                    $configuredLanguages),
                'defaultLocale' => $this->coreSpaceLanguageHelper->getDefaultLocale($space->id),
                'roles' => array_values($this->coreSpaceHelper->getRolesForSpace($space->id))];
            foreach ($this->corePageHelper->getPagesForExactSpace($space->id) as $page) {
                $pages[] = $this->metadata($page);
            }
        }
        return ['spaces' => $spaces, 'pages' => $pages,
            'components' => $this->corePageComponentRegistry->catalogue()];
    }

    public function read(int $pageId): array
    {
        $page = $this->getPageForRead($pageId);
        return [...$this->editorContext($page->space_id), 'page' => [
            ...$this->metadata($page),
            'raw_html' => $page->raw_html,
            'raw_scss' => $page->raw_scss,
            // Les pages des migrations historiques demandent encore une conversion de structure.
            'data' => $page->type === 2 ? $this->corePageComponentRegistry->normalizeDocument($page->data, $page->space_id, \YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode::EDITOR) : null,
            'revision' => $this->revision($page),
            'roles' => array_values($this->coreSpaceHelper->getRolesForSpace($page->space_id)),
        ]];
    }

    public function save(?int $pageId, object $input): array
    {
        $page = $pageId === null ? null : $this->getPageForEdit($pageId);
        $spaceId = $page?->space_id ?? (int) ($input->space_id ?? 0);
        if (!$this->canEdit($spaceId)) ExceptionResponse::throwDanger('Vous ne pouvez pas modifier cette page.');
        if ($page !== null && !hash_equals($this->revision($page), (string) ($input->revision ?? ''))) {
            ExceptionResponse::throwWarning('Cette page a été modifiée. Rechargez-la avant de sauvegarder.');
        }
        $type = (int) ($input->type ?? 0);
        if (!in_array($type, [1, 2], true)) ExceptionResponse::throwWarning('Type de page invalide.');
        if ($type === 1 && !$this->coreAclHelper->isRoot()) {
            ExceptionResponse::throwDanger('Le HTML brut est réservé aux super administrateurs.');
        }
        $name = trim((string) ($input->name ?? ''));
        if ($name === '' || mb_strlen($name) > 100) ExceptionResponse::throwWarning('Nom de page invalide.');
        $description = (string) ($input->description ?? '');
        if (mb_strlen($description) > 5000) ExceptionResponse::throwWarning('Description trop longue.');
        $scss = (string) ($input->raw_scss ?? '');
        $this->validateScssSource($scss);
        if ($type === 1 && strlen((string) ($input->raw_html ?? '')) > 500_000) {
            ExceptionResponse::throwWarning('HTML brut trop long.');
        }
        $data = (object) [
            'name' => $name, 'description' => $description, 'type' => $type,
            'raw_html' => (string) ($input->raw_html ?? ''),
            'data' => $input->data ?? null,
            'raw_scss' => $scss, 'raw_css' => '',
            // Legacy column kept for existing records; it no longer gates PAGE_CRUD.
            'access_edit_role_id' => $page?->access_edit_role_id,
        ];
        if ($type === 2) {
            $data->data = $this->validatePageDocument($data->data, $spaceId);
        }
        // Conserver l'état remplacé avant l'écriture : une première modification
        // après l'installation rend ainsi la page existante récupérable.
        if ($page !== null) {
            $this->corePageHistoryHelper->record($page, $this->session->getUserId());
        }
        // A new page needs its database ID before a stable CSS scope can be compiled.
        $saved = $this->corePageHelper->savePage($page, $spaceId, $data);
        $compiled = $this->compilePageCss($scss, $spaceId, $saved->id, $type === 2 ? $data->data : null);
        if ($compiled !== '') {
            $this->corePageHelper->updateCompiledCss($saved->id, $compiled);
            $saved = $this->corePageHelper->getPage($saved->id);
        }
        return ['page' => $this->metadata($saved), 'revision' => $this->revision($saved)];
    }

    /**
     * Lister les versions conservées d'une page.
     *
     * Le contenu complet n'est pas renvoyé ici : la liste sert à choisir une
     * date, puis `historyVersion()` transporte le JSON demandé.
     */
    public function history(int $pageId): array
    {
        $page = $this->getPageForRead($pageId);
        // Le nom de l'auteur est une donnée de compte : il n'est projeté que si
        // l'appelant possède aussi le droit de lecture des utilisateurs.
        $canReadUsers = $this->coreAclHelper->hasGlobalPermission(Permissions::USERS_READ, $page->space_id);
        $versions = [];

        foreach ($this->corePageHistoryHelper->listForPage($page->id) as $row) {
            $versions[] = [
                'id' => (int) $row->id,
                'userId' => $row->user_id === null ? null : (int) $row->user_id,
                'userName' => $canReadUsers
                    ? trim((string) ($row->first_name ?? '') . ' ' . (string) ($row->last_name ?? ''))
                    : '',
                'userType' => (string) ($row->user_type ?? ''),
                'name' => (string) $row->name,
                'description' => (string) $row->description,
                'type' => (int) $row->type,
                'dataSize' => (int) $row->data_size,
                'created' => (new \DateTimeImmutable((string) $row->created, new \DateTimeZone('UTC')))->format(DATE_ATOM),
            ];
        }

        return [
            'page' => $this->metadata($page),
            'versions' => $versions,
            'retention' => [
                'currentDayVersions' => PageHistoryHelper::CURRENT_DAY_VERSIONS,
                'days' => PageHistoryHelper::RETENTION_DAYS,
                'pastDayVersions' => 1,
            ],
        ];
    }

    /**
     * Lire une version précise d'une page, avec son contenu restaurable.
     *
     * La version est toujours relue avec l'identifiant de la page : un
     * identifiant de version seul ne suffit pas à changer de périmètre.
     */
    public function historyVersion(int $pageId, int $versionId): array
    {
        $page = $this->getPageForRead($pageId);
        $version = $this->corePageHistoryHelper->getVersion($page->id, $versionId);

        if ($version === null) {
            ExceptionResponse::throwWarning('Version de page introuvable.');
        }

        return [
            'id' => $version->id,
            'pageId' => $version->page_id,
            'userId' => $version->user_id,
            'created' => $version->created->format(DATE_ATOM),
            'name' => $version->name,
            'description' => $version->description,
            'type' => $version->type,
            'rawHtml' => $version->raw_html,
            'rawScss' => $version->raw_scss,
            'data' => $version->data,
            'accessEditRoleId' => $version->access_edit_role_id,
        ];
    }

    /** Render an unsaved draft through the public PHP renderer without persisting it. */
    public function preview(object $input): array
    {
        $pageId = $input->page_id ?? null;
        if ($pageId !== null && (!is_int($pageId) || $pageId < 1)) {
            ExceptionResponse::throwWarning('Page invalide.');
        }
        $page = $pageId === null ? null : $this->getPageForRead($pageId);
        $spaceId = $page?->space_id ?? (int) ($input->space_id ?? 0);
        if (!$this->canEdit($spaceId)) ExceptionResponse::throwDanger('Vous ne pouvez pas prévisualiser cette page.');
        if ($page !== null) $this->assertCanEditPage($page);
        $type = (int) ($input->type ?? 0);
        if (!in_array($type, [1, 2], true)) ExceptionResponse::throwWarning('Type de page invalide.');
        if ($type === 1 && !$this->coreAclHelper->isRoot()) {
            ExceptionResponse::throwDanger('Le HTML brut est réservé aux super administrateurs.');
        }
        $html = $type === 1 ? (string) ($input->raw_html ?? '') : '';
        if ($type === 1 && strlen($html) > 500_000) ExceptionResponse::throwWarning('HTML brut trop long.');
        $scss = (string) ($input->raw_scss ?? '');
        $this->validateScssSource($scss);
        $document = null;
        if ($type === 2) {
            $document = $this->validatePageDocument($input->data ?? null, $spaceId);
        }
        $draft = $page === null ? new Page($this) : clone $page;
        $draft->id = $page?->id ?? 0;
        $draft->space_id = $spaceId;
        $draft->type = $type;
        $draft->data = $document;
        // Réutiliser le nettoyage de la sauvegarde avant de rendre le brouillon HTML.
        $draft->raw_html = HtmlSanitizer::sanitize($html);
        $draft->raw_scss = $scss;
        $draft->raw_css = '';
        $css = $this->compilePageCss($scss, $spaceId, $draft->id, $document);
        $locale = $input->locale ?? $this->corePublicLocaleHelper->locale()
            ?? $this->coreSpaceLanguageHelper->getDefaultLocale($spaceId);

        if (!is_string($locale) || !in_array($locale, array_map(
            static fn ($language) => $language->locale,
            $this->coreSpaceLanguageHelper->listActiveForSpace($spaceId),
        ), true)) {
            ExceptionResponse::throwWarning('Langue de prévisualisation invalide.');
        }

        $rendered = (new PageRenderer($this))->render($draft, $locale, \YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode::PREVIEW);
        return [
            'html' => $rendered->getHtml(true),
            'css' => $css,
            'security_sources' => $this->securityPolicyHelper->forSpace($spaceId)->previewSources(),
        ];
    }

    public function canvas(object $input): array
    {
        $validated = $this->validateDraft($input);
        $nodeId = $input->node_id ?? null;
        if (!is_string($nodeId) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $nodeId) !== 1) {
            ExceptionResponse::throwWarning('Composant d’aperçu invalide.');
        }
        $spaceId = $input->space_id;
        $locale = $input->locale ?? $this->coreSpaceLanguageHelper->getDefaultLocale($spaceId);
        if (!is_string($locale) || !in_array($locale, array_column($this->coreSpaceLanguageHelper->listActiveForSpace($spaceId), 'locale'), true)) {
            ExceptionResponse::throwWarning('Langue de prévisualisation invalide.');
        }
        $page = new Page($this);
        $page->id = $input->page_id ?? 0;
        $page->space_id = $spaceId;
        $page->type = 2;
        $page->data = $validated['data'];
        return [
            'html' => (new PageRenderer($this))->renderCanvas($page, $nodeId, $locale),
            'css' => $this->compilePageCss('', $spaceId, $page->id, $page->data),
            'security_sources' => $this->securityPolicyHelper->forSpace($spaceId)->previewSources(),
        ];
    }

    /** Clean model-produced HTML before it can enter the interactive draft. */
    public function sanitizeAiHtml(object $input): array
    {
        $this->aiEditingSpace($input);
        $html = $input->html ?? null;
        if (!is_string($html) || strlen($html) > 500_000) {
            ExceptionResponse::throwWarning('HTML IA invalide ou trop long.');
        }
        return ['html' => HtmlSanitizer::sanitize($html)];
    }

    /** Valider et nettoyer un brouillon externe avant son entrée dans le canevas. */
    public function validateDraft(object $input): array
    {
        $pageId = $input->page_id ?? null;
        $spaceId = $input->space_id ?? null;
        if (($pageId !== null && (!is_int($pageId) || $pageId < 1))
            || !is_int($spaceId) || $spaceId < 1) {
            ExceptionResponse::throwWarning('Page ou espace invalide.');
        }
        $page = $pageId === null ? null : $this->getPageForRead($pageId);
        if (!$this->canEdit($spaceId) || ($page !== null
            && ($page->space_id !== $spaceId || $page->type !== 2))) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas modifier cette page visuelle.');
        }

        // Même contrat et mêmes droits médias que la sauvegarde, sans écriture.
        $document = $this->validatePageDocument($input->data ?? null, $spaceId);

        return ['data' => $document];
    }

    /** Validate a proposed visual tree without rendering or persisting it. */
    public function validateAiDraft(object $input): array
    {
        $spaceId = $this->aiEditingSpace($input);
        $this->validatePageDocument($input->data ?? null, $spaceId);

        return ['valid' => true];
    }

    private function aiEditingSpace(object $input): int
    {
        $pageId = $input->page_id ?? null;
        if ($pageId !== null && (!is_int($pageId) || $pageId < 1)) {
            ExceptionResponse::throwWarning('Page invalide.');
        }
        $page = $pageId === null ? null : $this->getPageForRead($pageId);
        $submittedSpaceId = $input->space_id ?? null;
        if (!is_int($submittedSpaceId) || $submittedSpaceId < 1) {
            ExceptionResponse::throwWarning('Espace de page invalide.');
        }
        $spaceId = $page?->space_id ?? $submittedSpaceId;
        if ($spaceId < 1 || !$this->canEdit($spaceId)
            || !$this->coreAclHelper->hasGlobalPermission(Permissions::AI_USE, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas utiliser l’IA pour modifier cette page.');
        }
        if ($page !== null && ($page->type !== 2 || !$this->canEdit($page->space_id))) {
            ExceptionResponse::throwDanger('Cette page ne peut pas être modifiée par l’IA.');
        }
        if ($submittedSpaceId !== $spaceId) {
            ExceptionResponse::throwWarning('Espace de page invalide.');
        }
        return $spaceId;
    }

    public function changeStatus(int $pageId, string $status): void
    {
        // Verrouiller et autoriser la page dans son Space réel avant la mutation.
        $page = $this->getPageForEdit($pageId);
        $status = filter_var($status, FILTER_VALIDATE_INT);
        if ($status === false || !in_array($status, [1, 0, -1, -2], true)) {
            ExceptionResponse::throwWarning('Statut de page invalide.');
        }
        $this->corePageHelper->updateStatus($page->id, $status);
    }

    public function delete(int $pageId): void
    {
        // La suppression définitive exige le droit d'édition et la corbeille.
        $page = $this->getPageForEdit($pageId);
        if ($page->status !== -2) {
            ExceptionResponse::throwWarning('Seules les pages placées dans la corbeille peuvent être supprimées.');
        }
        $this->corePageHelper->deleteTrashedPage($page->id);
    }

    private function metadata(Page $page): array
    {
        return ['id' => $page->id, 'space_id' => $page->space_id,
            'user_defined' => $page->user_defined, 'name' => $page->name,
            'description' => $page->description, 'type' => $page->type, 'status' => $page->status,
            'access_edit_role_id' => $page->access_edit_role_id,
            'updated' => $page->updated?->format(DATE_ATOM),
            'canEdit' => $this->canEdit($page->space_id)
                && ($page->type !== 1 || $this->coreAclHelper->isRoot())];
    }

    private function revision(Page $page): string
    {
        return hash('sha256', serialize([$page->name, $page->description, $page->type,
            $page->raw_html, $page->raw_scss, $page->data, $page->access_edit_role_id]));
    }

    private function getPageForRead(int $id): Page
    {
        $page = $this->corePageHelper->getPage($id);
        if ($page === null || !$this->canRead($page->space_id)) ExceptionResponse::throwDanger('Page introuvable ou inaccessible.');
        return $page;
    }

    private function getPageForEdit(int $id): Page
    {
        $page = $this->corePageHelper->getPageForUpdate($id);
        if ($page === null || !$this->canRead($page->space_id)) {
            ExceptionResponse::throwDanger('Page introuvable ou inaccessible.');
        }
        $this->assertCanEditPage($page);
        return $page;
    }

    private function assertCanEditPage(Page $page): void
    {
        if (!$this->canEdit($page->space_id)
            || ($page->type === 1 && !$this->coreAclHelper->isRoot())) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas modifier cette page.');
        }
    }

    private function validatePageComponents(object $document, int $spaceId): void
    {
        $this->corePageComponentRegistry->validateDocument($document, $spaceId);
    }

    /**
     * Valider et normaliser un document de page avec le même contrat partout :
     * taille configurée, structure v3, composants et médias du Space.
     */
    private function validatePageDocument(mixed $data, int $spaceId): object
    {
        PageSize::assertDocument($data, PageSize::pageMaximum($this->app->getConf()));
        $document = PageDocument::normalize($data);
        $this->validatePageComponents($document, $spaceId);

        return $document;
    }

    private function validateScssSource(string $scss): void
    {
        if (strlen($scss) > 100_000 || preg_match('/@at-root\b/i', $scss) || !$this->isScopeableScss($scss)) {
            ExceptionResponse::throwWarning('SCSS trop long ou directive non autorisée.');
        }
    }

    private function compilePageScss(string $scss, int $spaceId, int $pageId): string
    {
        $source = $scss === '' ? '' : '#page-' . $pageId . " {\n" . $scss . "\n}";
        $importRoot = $this->coreSpaceHelper->getSpace($spaceId)->getMediasDir();
        $compiled = ScssCompiler::compileScss($source, $importRoot);
        if ($compiled === null) ExceptionResponse::throwWarning('Le SCSS de la page est invalide.');
        return $compiled;
    }

    private function compilePageCss(string $scss, int $spaceId, int $pageId, ?object $document): string
    {
        $css = $this->compilePageScss($scss, $spaceId, $pageId);
        $common = $document === null ? '' : PageCommonStyles::compile($document, $pageId);
        $buttons = $document === null ? '' : PageButtonStyles::compile($document, $pageId);
        $tabs = $document === null ? '' : PageTabs::compile($document, $pageId);
        $accordion = $document === null ? '' : PageAccordion::compile($document, $pageId);
        return implode("\n", array_filter([$css, $common, $buttons, $tabs, $accordion], static fn (string $part): bool => $part !== ''));
    }

    private function canRead(int $spaceId): bool
    {
        // PAGE_CRUD a PAGE_READ pour prérequis explicite.
        return $this->coreAclHelper->hasGlobalPermission(Permissions::PAGE_READ, $spaceId);
    }

    private function canEdit(int $spaceId): bool
    {
        return $spaceId > 0 && $this->coreAclHelper->hasGlobalPermission(Permissions::PAGE_CRUD, $spaceId);
    }

    /** Refuses a closing brace that would escape the page selector wrapper. */
    private function isScopeableScss(string $scss): bool
    {
        $depth = 0;
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($scss);
        for ($i = 0; $i < $length; $i++) {
            $char = $scss[$i];
            $next = $scss[$i + 1] ?? '';
            if ($lineComment) {
                if ($char === "\n") $lineComment = false;
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') { $blockComment = false; $i++; }
                continue;
            }
            if ($quote !== null) {
                if ($char === '\\') { $i++; continue; }
                if ($char === $quote) $quote = null;
                continue;
            }
            if ($char === '/' && $next === '/') { $lineComment = true; $i++; continue; }
            if ($char === '/' && $next === '*') { $blockComment = true; $i++; continue; }
            if ($char === '"' || $char === "'") { $quote = $char; continue; }
            if ($char === '{') $depth++;
            if ($char === '}' && --$depth < 0) return false;
        }
        return $depth === 0 && $quote === null && !$blockComment;
    }
}
