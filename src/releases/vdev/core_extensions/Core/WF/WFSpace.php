<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\Sso\SsoAutoLinkPolicy;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFSpace extends BaseWF
{
    use TraitAllHelpers;

    /** @return array<int,array<string,mixed>> */
    public function getSpaces(): array
    {
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            $canEdit = $this->canEditSpace($space);
            $canAdmin = $this->canAdminSpace($space);
            if (!$canEdit && !$canAdmin) {
                continue;
            }

            $spaces[] = [
                'id' => $space->id,
                'parent_id' => $space->parent_id,
                'title' => $space->title,
                'template' => $space->template,
                'hosts' => is_array($space->hosts) ? $space->hosts : [],
                'can_edit' => $canEdit || $canAdmin,
                'can_admin' => $canAdmin,
            ];
        }

        return $spaces;
    }

    /** @return array{form:array<string,mixed>,canDelete:bool} */
    public function getSpaceEditorForm(?int $spaceId = null): array
    {
        if ($spaceId === null) {
            $this->assertCanCreateSpace();
            $form = $this->coreSpaceFormsHelper->getSpaceForm(null, true, false);

            return [
                'form' => $form->getDefForRia(array_merge($this->getDefaultParams(), [
                    'medias_dir' => 's' . rand(1000,9999),
                ])),
                'canDelete' => false,
            ];
        }

        $space = $this->getSpaceOrFail($spaceId);
        $this->assertCanEditSpace($space);
        $canAdmin = $this->canAdminSpace($space);
        $form = $this->coreSpaceFormsHelper->getSpaceForm($space, $canAdmin, $this->canEditSpace($space));

        return [
            'form' => $form->getDefForRia($this->getFormValues($space), ['spaceId' => $space->id]),
            'canDelete' => $canAdmin && $space->id !== 1,
        ];
    }

    public function createSpace(object $data): Space
    {
        $this->assertCanCreateSpace();
        // Préserver le comportement des appels existants qui omettent ce champ.
        if (!property_exists($data, 'medias_dir')) {
            $data = clone $data;
            $data->medias_dir = 'space-' . bin2hex(random_bytes(8));
        }
        $data = $this->normalizeSpaceData($data, null, true);
        $this->assertCanCreateInParent($data->parent_id);

        if ($this->db->selectOneOrNull('SELECT id FROM spaces WHERE medias_dir = % LIMIT 1', [$data->medias_dir]) !== null) {
            ExceptionResponse::throwWarning('Ce dossier de médias est déjà utilisé par un espace.');
        }

        // Créer exclusivement un dossier neuf, sans adopter un contenu préexistant.
        $path = rtrim($this->app->getMediasRoot(), '/\\') . DIRECTORY_SEPARATOR . $data->medias_dir;
        if (!@mkdir($path, 0775)) {
            ExceptionResponse::throwWarning('Le dossier de médias existe déjà ou ne peut pas être créé.', new LogEventException(
                'SPACE_MEDIA_DIRECTORY_CREATE_FAILED', 'Le dossier de médias du nouveau Space ne peut pas être créé.', 'space',
            ));
        }
        try {
            $space = $this->coreSpaceAdminHelper->createSpace($data);
            $this->coreSpaceHelper->load();
        } catch (\Throwable $exception) {
            @rmdir($path);
            if ($exception instanceof \mysqli_sql_exception && $exception->getCode() === 1062) {
                ExceptionResponse::throwWarning('Ce dossier de médias est déjà utilisé par un espace.');
            }
            throw $exception;
        }

        return $space;
    }

    public function updateSpace(int $spaceId, object $data): Space
    {
        // Le renommage doit pouvoir être annulé si l'enregistrement SQL échoue.
        $this->db->execute('START TRANSACTION');
        $oldPath = null;
        $newPath = null;
        $renamed = false;
        try {
            // Sérialiser les éditions de Spaces, y compris le choix d'un même dossier.
            $this->db->select('SELECT id FROM spaces ORDER BY id FOR UPDATE');
            $space = $this->getSpaceOrFail($spaceId);
            $this->assertCanEditSpace($space);
            $canAdmin = $this->canAdminSpace($space);
            $data = $this->normalizeSpaceData($data, $space, $canAdmin);

            if ($canAdmin) {
                $this->assertCanCreateInParent($data->parent_id);
                if ($data->parent_id !== null && $this->wouldCreateParentCycle($space->id, $data->parent_id)) {
                    ExceptionResponse::throwWarning('Un espace ne peut pas être son propre parent ou enfant.');
                }
            }

            if ($data->medias_dir !== $space->medias_dir) {
                if ($this->db->selectOneOrNull('SELECT id FROM spaces WHERE medias_dir = % AND id != % LIMIT 1', [$data->medias_dir, $space->id]) !== null) {
                    ExceptionResponse::throwWarning('Ce dossier de médias est déjà utilisé par un espace.');
                }

                // Ne pas suivre un lien symbolique ni sortir de la racine configurée.
                if ($space->medias_dir === '' || in_array($space->medias_dir, ['.', '..'], true)
                    || strpbrk($space->medias_dir, "/\\\0") !== false) {
                    ExceptionResponse::throwWarning('Le dossier actuel ne peut pas être renommé automatiquement.');
                }
                $root = rtrim($this->app->getMediasRoot(), '/\\');
                $oldPath = $root . DIRECTORY_SEPARATOR . $space->medias_dir;
                $newPath = $root . DIRECTORY_SEPARATOR . $data->medias_dir;
                if (is_link($oldPath) || !is_dir($oldPath) || file_exists($newPath) || is_link($newPath)) {
                    ExceptionResponse::throwWarning('Le dossier actuel est inaccessible ou la destination existe déjà.');
                }
            }

            $updatedSpace = $this->coreSpaceAdminHelper->updateSpace($space->id, $data);
            if ($oldPath !== null) {
                // Réserver la destination sans écraser un dossier créé entre les contrôles.
                if (!@mkdir($newPath, 0775)) {
                    ExceptionResponse::throwWarning('Le dossier de destination existe déjà ou ne peut pas être créé.', new LogEventException(
                        'SPACE_MEDIA_DESTINATION_FAILED', 'La destination du dossier de médias ne peut pas être réservée.', 'space',
                        ['space_id' => $space->id],
                    ));
                }
                if (!@rename($oldPath, $newPath)) {
                    @rmdir($newPath);
                    ExceptionResponse::throwWarning('Impossible de renommer le dossier des médias.', new LogEventException(
                        'SPACE_MEDIA_RENAME_FAILED', 'Le renommage du dossier de médias a échoué.', 'space',
                        ['space_id' => $space->id], level: 'ERROR',
                    ));
                }
                $renamed = true;
            }
            $this->db->execute('COMMIT');
        } catch (\Throwable $exception) {
            try {
                $this->db->execute('ROLLBACK');
            } finally {
                if ($renamed && (file_exists($oldPath) || is_link($oldPath) || !@rename($newPath, $oldPath))) {
                    throw new LogEventException('SPACE_MEDIA_RESTORE_FAILED',
                        'Échec de restauration du dossier des médias ; une intervention manuelle est nécessaire.',
                        'space', ['space_id' => $space->id], 'ERROR', $exception);
                }
            }
            throw $exception;
        }

        // Cette route gère son commit : ce succès décrit un renommage réellement validé.
        if ($renamed) {
            $this->logInfo('Le dossier de médias du Space a été renommé.', ['space_id' => $space->id],
                'SPACE_MEDIA_RENAMED', 'space');
        }
        $this->coreSpaceHelper->load();

        return $updatedSpace;
    }

    /** @return array{languages:list<array{locale:string,enabled:bool,is_default:bool}>,canEdit:bool} */
    public function getSpaceLanguages(int $spaceId): array
    {
        $space = $this->getSpaceOrFail($spaceId);
        $this->assertCanEditSpace($space);

        return [
            'languages' => array_map(static fn ($language) => [
                'locale' => $language->locale,
                'enabled' => $language->enabled,
                'is_default' => $language->is_default,
            ], $this->coreSpaceLanguageHelper->listForSpace($spaceId)),
            'canEdit' => $this->canAdminSpace($space),
        ];
    }

    /** @param list<object> $languages */
    public function saveSpaceLanguages(int $spaceId, array $languages): array
    {
        $space = $this->getSpaceOrFail($spaceId);
        if (!$this->canAdminSpace($space)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour administrer les langues de cet espace.');
        }

        $this->coreSpaceLanguageAdminHelper->lockSpace($spaceId);
        $existing = [];
        foreach ($this->coreSpaceLanguageHelper->listForSpace($spaceId) as $language) {
            $existing[strtolower($language->locale)] = $language;
        }

        if (count($languages) < 1 || count($languages) > 20) {
            ExceptionResponse::throwWarning('Un espace doit contenir entre 1 et 20 langues.');
        }

        $normalized = [];
        $seen = [];
        $defaultCount = 0;
        foreach ($languages as $language) {
            if (!is_object($language)
                || !is_string($language->locale ?? null)
                || !is_bool($language->enabled ?? null)
                || !is_bool($language->is_default ?? null)) {
                ExceptionResponse::throwWarning('Une langue est invalide.');
            }

            $locale = strtolower(trim($language->locale));
            if (strlen($locale) > 35 || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale) !== 1) {
                ExceptionResponse::throwWarning('Code de langue invalide. Utilisez par exemple fr, en ou fr-ca.');
            }
            if (isset($seen[$locale])) {
                ExceptionResponse::throwWarning('Chaque langue ne peut apparaître qu’une seule fois.');
            }
            $seen[$locale] = true;

            if ($language->is_default) {
                $defaultCount++;
                if (!$language->enabled) {
                    ExceptionResponse::throwWarning('La langue par défaut doit être active.');
                }
            }

            $normalized[] = [
                'id' => $existing[$locale]->id ?? null,
                'locale' => $locale,
                'enabled' => $language->enabled,
                'is_default' => $language->is_default,
            ];
        }

        if ($defaultCount !== 1) {
            ExceptionResponse::throwWarning('Choisissez exactement une langue par défaut.');
        }
        foreach ($existing as $locale => $_) {
            if (!isset($seen[$locale])) {
                ExceptionResponse::throwWarning('Une langue enregistrée ne peut pas être supprimée ; désactivez-la.');
            }
        }

        $this->coreSpaceLanguageAdminHelper->save($spaceId, $normalized);
        return $this->getSpaceLanguages($spaceId);
    }

    /** @return array<string,mixed> */
    public function getSpaceNavigation(int $spaceId): array
    {
        $space = $this->getSpaceOrFail($spaceId);
        $this->assertCanEditSpace($space);

        $options = [];
        foreach ($this->coreMenuAdminHelper->getMenusAndItemsOfSpace($spaceId) as $menu) {
            foreach ($menu->items as $item) {
                $options[] = [
                    'id' => $item->id,
                    'label' => $menu->name . ' — ' . $item->title,
                    'homeCapable' => $item->status === 1 && $this->isRoutableHomeItem($item),
                    'userSpaceCapable' => $item->status === 1 && $this->isRoutableUserSpaceItem($item),
                    'authCapable' => $this->isRoutableUserSpaceItem($item),
                ];
            }
        }

        $languages = [];
        foreach ($this->coreSpaceLanguageHelper->listActiveForSpace($spaceId) as $language) {
            $languages[] = [
                'locale' => $language->locale,
                'home_item_id' => $language->menu_item_home_id ?? 0,
                'user_space_item_id' => $language->menu_item_user_space_id ?? 0,
            ];
        }

        return [
            'home_item_id' => $space->menu_item_home_id ?? 0,
            'user_space_item_id' => $space->menu_item_user_space_id ?? 0,
            'login_item_id' => $space->menu_item_login_id ?? 0,
            'register_item_id' => $space->menu_item_register_id ?? 0,
            'languages' => $languages,
            'options' => $options,
            'canEdit' => true,
        ];
    }

    public function saveSpaceNavigation(int $spaceId, object $navigation): array
    {
        $space = $this->getSpaceOrFail($spaceId);
        $this->assertCanEditSpace($space);
        $this->coreSpaceLanguageAdminHelper->lockSpace($spaceId);

        $homeId = $this->normalizeNullableId($navigation->home_item_id ?? null);
        $userSpaceId = $this->normalizeNullableId($navigation->user_space_item_id ?? null);
        $loginId = $this->normalizeNullableId($navigation->login_item_id ?? $space->menu_item_login_id);
        $registerId = $this->normalizeNullableId($navigation->register_item_id ?? $space->menu_item_register_id);
        $submitted = $navigation->languages ?? null;
        if (!is_array($submitted)) {
            ExceptionResponse::throwWarning('Navigation par langue invalide.');
        }

        // Les choix sont toujours vérifiés dans le Space propriétaire.
        $menus = $this->coreMenuAdminHelper->getMenusAndItemsOfSpace($spaceId);
        $itemsById = [];
        foreach ($menus as $menu) {
            foreach ($menu->items as $item) $itemsById[$item->id] = $item;
        }

        if ($homeId !== null) $this->assertHomeCandidate($homeId, $itemsById);
        if ($userSpaceId !== null) $this->assertUserSpaceCandidate($userSpaceId, $itemsById);

        // Les liens d'authentification sont libres ; vérifier seulement leur Space réel.
        foreach ([$loginId, $registerId] as $authId) {
            $this->normalizeMenuItemId($authId, $space);
        }

        $active = [];
        foreach ($this->coreSpaceLanguageHelper->listActiveForSpace($spaceId) as $language) {
            $active[$language->locale] = true;
        }

        $normalized = [];
        $seen = [];
        foreach ($submitted as $row) {
            if (!is_object($row) || !is_string($row->locale ?? null)
                || !isset($active[$row->locale]) || isset($seen[$row->locale])) {
                ExceptionResponse::throwWarning('Langue de navigation invalide ou en double.');
            }

            $seen[$row->locale] = true;
            $localizedId = $this->normalizeNullableId($row->home_item_id ?? null);
            $localizedUserId = $this->normalizeNullableId($row->user_space_item_id ?? null);
            if ($localizedId !== null) $this->assertHomeCandidate($localizedId, $itemsById);
            if ($localizedUserId !== null) $this->assertUserSpaceCandidate($localizedUserId, $itemsById);

            // Le choix effectif doit rester visible dans la langue concernée.
            $effectiveId = $localizedId ?? $homeId;
            if ($effectiveId !== null
                && !$this->coreMenuLanguageHelper->isVisible($itemsById[$effectiveId], $row->locale, $itemsById)) {
                ExceptionResponse::throwWarning('L’accueil sélectionné est masqué dans la langue ' . $row->locale . '.');
            }

            $effectiveUserId = $localizedUserId ?? $userSpaceId;
            if ($effectiveUserId !== null
                && !$this->coreMenuLanguageHelper->isVisible($itemsById[$effectiveUserId], $row->locale, $itemsById)) {
                ExceptionResponse::throwWarning('L’espace utilisateur sélectionné est masqué dans la langue ' . $row->locale . '.');
            }

            $normalized[] = [
                'locale' => $row->locale,
                'home_item_id' => $localizedId,
                'user_space_item_id' => $localizedUserId,
            ];
        }

        if (count($seen) !== count($active)) {
            ExceptionResponse::throwWarning('Toutes les langues actives doivent être renseignées.');
        }

        $this->coreSpaceAdminHelper->saveNavigation($spaceId, $homeId, $userSpaceId, $normalized, $loginId, $registerId);
        $this->coreSpaceHelper->load();

        return $this->getSpaceNavigation($spaceId);
    }

    /** @param array<int,MenuItem> $itemsById */
    private function assertHomeCandidate(int $itemId, array $itemsById): void
    {
        $item = $itemsById[$itemId] ?? null;
        if ($item === null || $item->status !== 1 || !$this->isRoutableHomeItem($item)) {
            ExceptionResponse::throwWarning('L’accueil doit être une entrée publiée avec une route publique GET de ce Space.');
        }

        $seen = [];
        $parentId = $item->parent_id;
        while ($parentId !== null) {
            if (isset($seen[$parentId]) || !isset($itemsById[$parentId]) || $itemsById[$parentId]->status !== 1) {
                ExceptionResponse::throwWarning('La hiérarchie de l’accueil doit être publiée dans ce Space.');
            }
            $seen[$parentId] = true;
            $parentId = $itemsById[$parentId]->parent_id;
        }
    }

    /** @param array<int,MenuItem> $itemsById */
    private function assertUserSpaceCandidate(int $itemId, array $itemsById): void
    {
        $item = $itemsById[$itemId] ?? null;
        if ($item === null || $item->status !== 1 || !$this->isRoutableUserSpaceItem($item)) {
            ExceptionResponse::throwWarning('L’espace utilisateur doit être une entrée publiée avec une route publique GET de ce Space.');
        }

        // Les parents participent au chemin de la route ; ils doivent être publiés.
        $seen = [];
        $parentId = $item->parent_id;
        while ($parentId !== null) {
            if (isset($seen[$parentId]) || !isset($itemsById[$parentId]) || $itemsById[$parentId]->status !== 1) {
                ExceptionResponse::throwWarning('La hiérarchie de l’espace utilisateur doit être publiée dans ce Space.');
            }
            $seen[$parentId] = true;
            $parentId = $itemsById[$parentId]->parent_id;
        }
    }

    private function isRoutableHomeItem(MenuItem $item): bool
    {
        $definition = $this->coreMenuRouteHelper->getMenuItemDef($item);

        return $definition?->controller !== null
            && $definition->controllerUrlDefined === null
            && isset($definition->methodsByHttpMethods['GET']);
    }

    private function isRoutableUserSpaceItem(MenuItem $item): bool
    {
        $definition = $this->coreMenuRouteHelper->getMenuItemDef($item);

        // Les routes techniques GET peuvent aussi servir de destination de navigation.
        return $definition?->controller !== null
            && isset($definition->methodsByHttpMethods['GET']);
    }

    public function sendTestMail(int $spaceId, string $recipient, object $settings): bool
    {
        $space = $this->getSpaceOrFail($spaceId);
        $this->assertCanEditSpace($space);

        if ($this->app->getConf()->mailonline === false) {
            ExceptionResponse::throwWarning('L’envoi d’e-mails est désactivé sur ce site.');
        }

        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            ExceptionResponse::throwWarning('L’adresse e-mail du destinataire est invalide.');
        }
        if (!$settings->mail_online) {
            ExceptionResponse::throwWarning('Activez l’envoi d’e-mails pour effectuer un essai.');
        }

        try {
            $sent = $this->coreMailAdminHelper->sendTestHtml($space, $recipient, get_object_vars($settings));
        } catch (\Throwable $exception) {
            // Aucun réglage SMTP ni destinataire dans le diagnostic ; conserver le traitement public existant.
            $this->logException(new LogEventException('SMTP_TEST_FAILED', 'L’essai des réglages SMTP a échoué.',
                'mail', ['space_id' => $space->id], 'ERROR', $exception));
            throw $exception;
        }

        if (!$sent) {
            $this->logWarning('L’e-mail d’essai SMTP n’a pas été envoyé.', ['space_id' => $space->id],
                'SMTP_TEST_NOT_SENT', 'mail');
        }

        return $sent;
    }

    public function deleteSpace(int $spaceId): bool
    {
        $space = $this->getSpaceOrFail($spaceId);
        if ($space->id === 1) {
            ExceptionResponse::throwWarning('L’espace racine ne peut pas être supprimé.');
        }
        if (!$this->canAdminSpace($space)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour supprimer cet espace.');
        }

        $blockers = $this->coreSpaceAdminHelper->getDeletionBlockers($space->id);
        if ($blockers !== []) {
            ExceptionResponse::throwWarning('Cet espace ne peut pas être supprimé car il contient ' . implode(', ', $blockers) . '.');
        }

        if (!$this->coreSpaceAdminHelper->deleteSpace($space->id)) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }
        $this->coreSpaceHelper->load();

        return true;
    }

    private function getSpaceOrFail(int $spaceId): Space
    {
        if ($spaceId < 1) {
            ExceptionResponse::throwWarning('Espace invalide.');
        }

        $space = $this->coreSpaceAdminHelper->getSpace($spaceId);
        if ($space === null) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }

        return $space;
    }

    private function assertCanCreateSpace(): void
    {
        if ($this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::SPACE_ADMIN) === []) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour créer un espace.');
        }
    }

    private function assertCanCreateInParent(?int $parentId): void
    {
        $scopeId = $parentId ?? 1;
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $scopeId)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour administrer cet espace parent.');
        }
    }

    private function assertCanEditSpace(Space $space): void
    {
        if (!$this->canEditSpace($space) && !$this->canAdminSpace($space)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier cet espace.');
        }
    }

    private function canEditSpace(Space $space): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_EDIT, $space->id);
    }

    private function canAdminSpace(Space $space): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $space->id);
    }

    private function normalizeSpaceData(object $data, ?Space $space, bool $canAdmin): object
    {
        // Ne jamais conserver le HTML actif reçu depuis l’éditeur riche ou un appel direct.
        $data = clone $data;
        if (is_string($data->description ?? null)) {
            $data->description = HtmlSanitizer::sanitize($data->description);
        }

        $form = $this->coreSpaceFormsHelper->getSpaceForm(
            $space, $canAdmin, $space !== null && $this->canEditSpace($space)
        );
        $validation = $form->validateSubmittedValues($data);
        if (!$validation->isValid()) {
            $message = $validation->getFirstError()?->message ?? 'valeur non autorisée.';
            ExceptionResponse::throwWarning('Les données de l’espace sont invalides : ' . $message);
        }

        /** @var object $values */
        $values = $validation->value;
        $values = (object) $form->getInitialValues(get_object_vars($values));
        $existingParams = $space === null ? [] : $this->toArray($space->params);
        $params = array_merge($this->getDefaultParams(), $existingParams);
        unset($params['logo_email']);

        foreach (array_keys($this->getDefaultParams()) as $key) {
            if ($key === 'mail_smtp_pass') {
                continue;
            }
            $params[$key] = $values->$key ?? $params[$key];
        }

        // Une modification partielle conserve le choix enregistré ; la création utilise le défaut.
        if ($space !== null && !property_exists($data, 'registration_auto_member_role')) {
            $params['registration_auto_member_role'] = $existingParams['registration_auto_member_role'];
        }

        // Une valeur null doit pouvoir effacer une cible existante, sans être ignorée par ?? ci-dessus.
        if ($canAdmin && property_exists($data, 'registration_target_space_id')) {
            $targetId = $values->registration_target_space_id;
            if ($targetId === $space?->id) $targetId = null;
            if ($targetId !== null) {
                $target = $this->getSpaceOrFail($targetId);
                $previousTargetId = $existingParams['registration_target_space_id'] ?? null;
                if ($targetId !== $previousTargetId && !$this->canAdminSpace($target)) {
                    ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour choisir cet espace principal d’inscription.');
                }
            }
            $params['registration_target_space_id'] = $targetId;
        }

        // L’omission du logo conserve la sélection ; seul un nouveau choix demande les droits médias.
        if (!property_exists($data, 'logo')) $params['logo'] = $existingParams['logo'] ?? '';
        if ($space !== null && $params['logo'] !== ($existingParams['logo'] ?? '') && $params['logo'] !== '') {
            if (!$this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $space->id)) {
                ExceptionResponse::throwDanger('Vous ne pouvez pas sélectionner les médias de cet espace.');
            }
            try {
                $this->coreMediaHelper->assertSelectableFile($space, $params['logo'], 'image');
            } catch (\RuntimeException $exception) {
                // Un média invalide reste une validation ; une panne de stockage conserve sa cause qualifiée.
                ExceptionResponse::throwWarning($exception->getMessage(), $exception instanceof LogEventException ? $exception : null);
            }
        }

        $params['sso_auto_link_existing_account'] = SsoAutoLinkPolicy::mode($params['sso_auto_link_existing_account']);
        foreach (['registration_roles', 'user_roles'] as $setting) {
            $roleMap = $this->toArray($params[$setting]);
            if ($canAdmin && $space !== null && property_exists($values, $setting)) {
                // L'éditeur utilise des noms de champs textuels ; le stockage garde les IDs d'espace.
                $roleMap = [];
                foreach ($this->toArray($values->$setting) as $target => $roles) {
                    $key = $target === 'this' ? 'this' : (int) substr((string) $target, 6);
                    $roleMap[$key] = $roles;
                }
            }
            // Les rôles implicites ne doivent jamais être attribués par une inscription,
            // même si un ancien réglage ou un appel direct les contient encore.
            foreach ($roleMap as $roles) {
                if (!is_array($roles)) {
                    ExceptionResponse::throwWarning('Configuration des rôles d’inscription invalide.');
                }
                foreach ($roles as $roleId) {
                    if (!is_int($roleId) || Roles::isImplicit($roleId)) {
                        ExceptionResponse::throwWarning(
                            'Les rôles Invité et Utilisateur sont réservés au système et ne peuvent pas être attribués à l’inscription.'
                        );
                    }
                }
            }
            $params[$setting] = $roleMap;
        }
        foreach (['registration_groups', 'user_groups'] as $setting) {
            if ($canAdmin && $space !== null && property_exists($values, $setting)) {
                $params[$setting] = $values->$setting;
            }

            // Le formulaire limite les choix, puis le WF revérifie le type et
            // l'espace réel de chaque groupe avant de stocker sa référence.
            if (!is_array($params[$setting])) {
                ExceptionResponse::throwWarning('Configuration des groupes invalide.');
            }
            foreach ($params[$setting] as $groupId) {
                $group = is_int($groupId) ? $this->coreUserGroupAdminHelper->getGroup($groupId, true) : null;
                if ($group === null || $group->type !== 0
                    || in_array($group->id, UserGroups::getAllIds(), true)
                    || ($space !== null && $group->space_id !== $space->id)) {
                    ExceptionResponse::throwWarning('Un groupe sélectionné est indisponible pour cet espace.');
                }
            }
        }
        $params = $this->coreMailAdminHelper->protectSmtpPassword(
            $params, (string) ($values->mail_smtp_pass ?? '')
        );

        $params['timezone'] = trim((string) $params['timezone']);
        if (!in_array($params['timezone'], timezone_identifiers_list(), true)) {
            ExceptionResponse::throwWarning('Le fuseau horaire est invalide.');
        }
        $params['mail_smtp_port'] = filter_var($params['mail_smtp_port'], FILTER_VALIDATE_INT);
        if ($params['mail_smtp_port'] === false || $params['mail_smtp_port'] < 1 || $params['mail_smtp_port'] > 65535) {
            ExceptionResponse::throwWarning('Le port SMTP est invalide.');
        }

        $title = trim((string) ($values->title ?? $space?->title ?? ''));
        if ($title === '') {
            ExceptionResponse::throwWarning('Le nom de l’espace est obligatoire.');
        }

        if ($canAdmin) {
            $parentId = $this->normalizeNullableId($values->parent_id ?? null);
            if ($parentId !== null && !isset($this->coreSpaceHelper->getAllSpacesByIds()[$parentId])) {
                ExceptionResponse::throwWarning('Espace parent introuvable.');
            }
            $hosts = $this->normalizeHosts((string) ($values->hosts ?? ''));
            $template = (string) ($values->template ?? '');
        } else {
            $parentId = $space->parent_id;
            $hosts = is_array($space->hosts) ? $space->hosts : [];
            $template = $space->template;
        }

        // Seul le Space racine peut rester sans parent ; tout autre Space en exige un.
        if ($space?->id === 1) {
            if ($parentId !== null) {
                ExceptionResponse::throwWarning('L’espace racine ne peut pas avoir de parent.');
            }
        } elseif ($parentId === null) {
            ExceptionResponse::throwWarning('Choisissez un espace parent : seul l’espace racine peut ne pas en avoir.');
        }

        if ($canAdmin && $space !== null && property_exists($values, 'sso_provider_ids')) {
            $allowedOwnerIds = [$space->id];
            if ($parentId !== null) {
                $allowedOwnerIds = array_merge($allowedOwnerIds, $this->coreSpaceHelper->getSpaceIdsWithParents($parentId));
            }
            $providers = [];
            foreach ($this->coreSsoProviderHelper->getProvidersForSpace($space) as $provider) {
                $providers[(int) $provider->id] = $provider;
            }
            foreach ($params['sso_provider_ids'] as $providerId) {
                if (!isset($providers[$providerId])
                    || !in_array((int) $providers[$providerId]->space_id, $allowedOwnerIds, true)) {
                    ExceptionResponse::throwWarning('Un fournisseur SSO sélectionné n’appartient pas à cet espace ou à ses ancêtres.');
                }
            }
        }

        $mainHost = trim((string) $params['main_host']);
        if ($mainHost !== '') {
            $mainHost = $this->normalizeHost($mainHost);
            $params['main_host'] = $mainHost;
            if ($canAdmin && !in_array($mainHost, $hosts, true)) {
                $hosts[] = $mainHost;
            }
        }

        $mediaDir = $space?->medias_dir ?? '';
        if ($canAdmin && property_exists($data, 'medias_dir')) {
            $mediaDir = $values->medias_dir;
            if (($space === null || $mediaDir !== $space->medias_dir) && preg_match('/\A[A-Za-z0-9_-]{1,50}\z/', $mediaDir) !== 1) {
                ExceptionResponse::throwWarning('Le dossier des médias doit contenir uniquement des lettres, chiffres, tirets ou underscores (50 caractères maximum).');
            }
        }

        return (object) [
            'parent_id' => $parentId,
            'template' => $template,
            'medias_dir' => $mediaDir,
            'hosts' => $hosts,
            'title' => $title,
            'params' => $params,
            'menu_item_home_id' => $this->normalizeMenuItemId($values->menu_item_home_id ?? $space?->menu_item_home_id ?? 0, $space),
            'menu_item_user_space_id' => $this->normalizeMenuItemId($values->menu_item_user_space_id ?? $space?->menu_item_user_space_id ?? 0, $space),
            'menu_item_login_id' => $space?->menu_item_login_id,
            'menu_item_register_id' => $space?->menu_item_register_id,
        ];
    }

    /** @return array<string,mixed> */
    private function getFormValues(Space $space): array
    {
        $params = array_merge($this->getDefaultParams(), $this->toArray($space->params));
        $params['sso_auto_link_existing_account'] = SsoAutoLinkPolicy::mode($params['sso_auto_link_existing_account']);
        if (in_array($params['registration_target_space_id'], [null, '', 0, $space->id], true)) {
            $params['registration_target_space_id'] = null;
        }
        foreach (['registration_roles', 'user_roles'] as $setting) {
            $mapped = [];
            foreach ($this->toArray($params[$setting]) as $target => $roles) {
                $key = $target === 'this' ? 'this' : 'space_' . $target;
                $mapped[$key] = $roles;
            }
            $params[$setting] = (object) $mapped;
        }
        $params['mail_smtp_pass'] = '';

        return array_merge($params, [
            'parent_id' => $space->parent_id ?? 0,
            'template' => $space->template,
            'medias_dir' => $space->medias_dir,
            'hosts' => implode("\n", is_array($space->hosts) ? $space->hosts : []),
            'title' => $space->title,
        ]);
    }

    /** @return array<string,mixed> */
    private function getDefaultParams(): array
    {
        return [
            'main_host' => '', 'logo' => '', 'short_description' => '', 'description' => '',
            'timezone' => 'Europe/Paris', 'mail_online' => true, 'mailer' => 'smtp',
            'mail_from' => '', 'mail_from_name' => '', 'mail_smtp_auth' => true,
            'mail_smtp_user' => '', 'mail_smtp_pass' => '', 'mail_smtp_pass_key_version' => 0,
            'mail_smtp_host' => '',
            'mail_smtp_secure' => 'ssl', 'mail_smtp_port' => 465,
            'page_browser_title' => 'Mon site', 'page_browser_description' => 'Mon site',
            'page_meta_robots' => 'index, follow',
            'sso_provider_ids' => [], 'sso_auto_link_existing_account' => 0,
            'sso_allow_reset_local_password' => false,
            'registration_enabled' => false, 'registration_target_space_id' => null,
            'registration_auto_member_role' => true,
            'registration_roles' => [], 'user_roles' => [],
            'registration_groups' => [], 'user_groups' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function toArray(mixed $value): array
    {
        return is_object($value) ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /** @return list<string> */
    private function normalizeHosts(string $rawHosts): array
    {
        $rawHosts = trim($rawHosts);
        if ($rawHosts === '') {
            return [];
        }

        $hosts = preg_split('/[\s,]+/', $rawHosts) ?: [];
        $normalizedHosts = [];
        foreach ($hosts as $host) {
            $host = $this->normalizeHost($host);
            if (!in_array($host, $normalizedHosts, true)) {
                $normalizedHosts[] = $host;
            }
        }
        return $normalizedHosts;
    }

    private function normalizeHost(string $host): string
    {
        $host = strtolower(rtrim(trim($host), '.'));

        // Conserver uniquement le domaine et son port, sans protocole, pour le serveur local.
        if ($this->app->getConf()->dev
            && preg_match('/^localhost:([0-9]{1,5})$/D', $host, $matches) === 1
            && (int) $matches[1] >= 1 && (int) $matches[1] <= 65535) {
            return $host;
        }

        $isDomain = preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host) === 1;
        if ($host === '' || ($host !== 'localhost' && filter_var($host, FILTER_VALIDATE_IP) === false && !$isDomain)) {
            ExceptionResponse::throwWarning('Un des hôtes est invalide.');
        }
        return $host;
    }

    private function normalizeNullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            ExceptionResponse::throwWarning('Identifiant invalide.');
        }
        return $id;
    }

    private function normalizeMenuItemId(mixed $value, ?Space $space): ?int
    {
        $itemId = $this->normalizeNullableId($value);
        if ($itemId === null || $space === null) {
            return $itemId;
        }
        $menu = $this->coreMenuAdminHelper->getMenuForItem($itemId);
        if ($menu === null || $menu->space_id !== $space->id) {
            ExceptionResponse::throwWarning('L’élément de menu sélectionné n’appartient pas à cet espace.');
        }
        return $itemId;
    }

    private function wouldCreateParentCycle(int $spaceId, int $parentId): bool
    {
        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        while (isset($spaces[$parentId])) {
            if ($parentId === $spaceId) {
                return true;
            }
            $parentId = $spaces[$parentId]->parent_id;
            if ($parentId === null) {
                return false;
            }
        }
        return false;
    }
}
