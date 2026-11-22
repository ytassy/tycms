<?php

namespace YTassy\Tycms\CoreExts\Core\WF\Admin;

use RuntimeException;
use Throwable;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\DB\Migrations\Migration;
use YTassy\Tycms\Extensions\BaseExtension;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogCompatibility;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Models\ExtensionInstallation;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFExtensions extends BaseWF
{
    use TraitAllHelpers;
    
    /**
     * @return array
     */
    public function getDatas(): array
    {
        $allExtensions = $this->app->getExtensions();
        $spaceIdsByExtension = [];
        $spaceIds = [];

        // Chaque extension fournit les droits de son propre formulaire.
        foreach ($allExtensions as $name => $extension) {
            $allowedIds = $this->getConfigurationSpaceIds($extension);
            if ($allowedIds === []) {
                continue;
            }

            $spaceIdsByExtension[$name] = $allowedIds;
            $spaceIds = array_merge($spaceIds, $allowedIds);
        }

        $spaceIds = array_values(array_unique($spaceIds));
        if ($spaceIds === []) {
            throw new AccessDeniedException();
        }

        $spaces = $this->coreSpaceHelper->getSpacesByIds($spaceIds);
        $spaceData = [];
        foreach ($spaces as $id => $space) {
            $params = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
            $spaceData[$id] = $space->jsonSerialize();
            $spaceData[$id]['mail_from'] = (string) ($params['mail_from'] ?? '');
        }

        // Get Form of Extensions
        $forms = $this->coreExtensionsHelper->getFormsByExtension();

        // Get Values of Conf for Spaces
        $confs = $this->coreExtensionsHelper->getExtensionsConfForSpaces($spaceIds);

        $extensions = [];

        foreach ($allExtensions as $name =>$extension) {
            if (!isset($spaceIdsByExtension[$name])) {
                continue;
            }

            /** @var ?Form */
            $form = $forms[$name] ?? null;

            // Ne transmettre que les paramètres des Spaces autorisés pour cette extension.
            $allowedIds = $spaceIdsByExtension[$name];
            $configurationBySpace = array_intersect_key(
                $confs[$name] ?? [],
                array_fill_keys($allowedIds, true),
            );

            foreach ($configurationBySpace as $id => $config) {
                if (!is_object($config)) continue;
                $configurationBySpace[$id] = clone $config;
                $configurationBySpace[$id]->params = (object) $extension->prepareConfigForEditor(
                    $config->space_id, (array) $config->params,
                );
            }

            $hiddenSectionsBySpace = [];
            foreach ($allowedIds as $id) $hiddenSectionsBySpace[$id] = $extension->getConfigHiddenSections($id);

            $extensions[$name] = [
                'name' => $name,
                'title' => $extension->getName(),
                'version' => $extension->getVersion(),
                'description' => $extension->getDescription(),
                'icon' => $extension->getIconClass(),
                'form' => $form == null ? null : $form->getDefForRia(),
                'space_ids' => $allowedIds,
                'hidden_sections_by_space' => $hiddenSectionsBySpace,
                'conf_by_spaces' => $configurationBySpace,
            ];
        }

        return [
            'spaces' => $spaceData,
            'extensions' => $extensions
        ];
    }

    /**
     * Saves the configuration of one extension in one authorised space.
     * The submitted object is validated against the trusted form owned by the
     * extension; neither the browser nor a plugin configuration can provide a
     * schema at request time.
     *
     * @return array{space_id:int,extension:string,params:array<string,mixed>}
     */
    public function saveConfiguration(int $spaceId, string $extensionName, object $params): array
    {
        $extension = $this->app->getExtensions()[$extensionName] ?? null;
        if ($extension === null || !$this->canEditConfiguration($extension, $spaceId)) {
            throw new AccessDeniedException();
        }

        $extension->assertConfigSubmissionAllowed($spaceId, $params);

        /** @var ?Form $form */
        $form = $this->coreExtensionsHelper->getFormsByExtension()[$extensionName] ?? null;
        if ($form === null) {
            ExceptionResponse::throwWarning('Cette extension ne possède pas de formulaire de configuration.');
        }

        $values = $this->validateConfiguration($form, $spaceId, $extensionName, $params);
        $configuration = $form->resolveComputedValues(
            $values,
            [
                'space_id' => $spaceId,
                'extension' => $extensionName,
            ],
        );

        try {
            $configuration = $extension->prepareConfigForStorage($spaceId, $configuration);
        } catch (RuntimeException $exception) {
            // Garder l'origine et la cause du hook sans sérialiser la configuration soumise.
            ExceptionResponse::throwWarning($exception->getMessage(), new LogEventException(
                'EXTENSION_CONFIG_PREPARATION_FAILED', 'La préparation de la configuration d’une extension a échoué.',
                'extensions', ['space_id' => $spaceId, 'source' => $extensionName], previous: $exception,
            ));
        }
        $this->coreExtensionsHelper->saveExtensionConfig($spaceId, $extensionName, $configuration);

        return [
            'space_id' => $spaceId,
            'extension' => $extensionName,
            'params' => $extension->prepareConfigForEditor($spaceId, $configuration),
        ];
    }

    public function sendTemplateTestMail(int $spaceId, string $recipient, string $mode, object $params): bool
    {
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::TEMPLATE_EDIT, $spaceId)) {
            throw new AccessDeniedException();
        }

        $space = $this->coreSpaceHelper->getSpacesByIds([$spaceId])[$spaceId] ?? null;
        if ($space === null) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }
        if ($space->template !== 'tpl_main') {
            ExceptionResponse::throwWarning('La Template Main doit être attribuée à cet espace pour effectuer cet essai.');
        }
        if ($this->app->getConf()->mailonline === false) {
            ExceptionResponse::throwWarning('L’envoi d’e-mails est désactivé sur ce site.');
        }
        $spaceParams = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
        if (!($spaceParams['mail_online'] ?? true)) {
            ExceptionResponse::throwWarning('Activez l’envoi d’e-mails pour effectuer un essai.');
        }
        $recipient = trim($recipient);
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            ExceptionResponse::throwWarning('L’adresse e-mail du destinataire est invalide.');
        }
        if (!in_array($mode, ['default', 'blank'], true)) {
            ExceptionResponse::throwWarning('Mode de rendu e-mail inconnu.');
        }

        /** @var ?Form $form */
        $form = $this->coreExtensionsHelper->getFormsByExtension()[$space->template] ?? null;
        if ($form === null) {
            ExceptionResponse::throwWarning('Le formulaire de la Template Main est introuvable.');
        }
        $values = $this->validateConfiguration($form, $spaceId, $space->template, $params, false);

        try {
            $sent = $this->coreMailAdminHelper->sendTemplateTestHtml($space, $recipient, (object) $values, $mode);
        } catch (\Throwable $exception) {
            // Conserver la réponse existante ; le runner déduplique cette cause déjà capturée.
            $this->logException(new LogEventException('TEMPLATE_TEST_MAIL_FAILED',
                'L’essai de l’habillage e-mail a échoué.', 'mail', ['space_id' => $space->id, 'mode' => $mode], 'ERROR', $exception));
            throw $exception;
        }

        if (!$sent) {
            $this->logWarning('L’e-mail d’essai de l’habillage n’a pas été envoyé.',
                ['space_id' => $space->id, 'mode' => $mode], 'TEMPLATE_TEST_MAIL_NOT_SENT', 'mail');
        }

        return $sent;
    }

    public function canEditAnyConfiguration(): bool
    {
        // La navigation doit couvrir aussi les permissions choisies par les extensions.
        foreach ($this->app->getExtensions() as $extension) {
            if ($this->getConfigurationSpaceIds($extension) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Liste les extensions chargées et leurs migrations en attente.
     * Réservé à ROOT : ces versions concernent le schéma partagé de l'instance.
     *
     * @return array{extensions:array<string,array<string,mixed>>}
     */
    public function getMigrationsStatus(): array
    {
        $this->assertRoot();

        $extensions = [];
        $db = $this->app->getDatabase();

        // La provenance vient uniquement de la base locale : Gestion ne
        // contacte jamais les URLs des catalogues.
        $installations = [];
        try {
            foreach ($db->selectOrm(ExtensionInstallation::class, 'SELECT * FROM extension_installations') as $installation) {
                $installations[$installation->extension_id] = $installation;
            }
        } catch (Throwable) {
            // Une table absente ne doit pas empêcher l’affichage des migrations.
        }

        foreach ($this->app->getExtensions() as $name => $extension) {
            $isCore = $this->app->isCoreExtension($name);
            $installation = $isCore ? null : ($installations[$name] ?? null);
            $provenance = $isCore ? 'core' : ($installation !== null ? 'catalog' : 'unmanaged');

            // Une extension installée sera raccordée au même parcours plus tard ;
            // elle est déjà listée pour rendre l'origine visible.
            $extensions[$name] = [
                'name' => $name,
                'title' => $extension->getName(),
                'version' => $extension->getVersion(),
                'description' => $extension->getDescription(),
                'icon' => $extension->getIconClass(),
                'core' => $isCore,
                'migrations_managed' => $isCore,
                'pending_migrations' => Migration::getPendingVersions($db, $name, $extension),
                'provenance' => $provenance,
                'source_id' => $installation?->source_id,
                'source_name' => $isCore
                    ? 'Core'
                    : ($installation?->source_name ?? 'Hors catalogue'),
            ];
        }

        return ['extensions' => $extensions];
    }

    /**
     * Liste les extensions installées avec leur état de mise à jour depuis
     * leur catalogue d'origine. Réservé à ROOT.
     *
     * @return array{extensions:array<string,array<string,mixed>>}
     */
    public function getUpdatesStatus(): array
    {
        $this->assertRoot();

        $extensions = [];
        foreach ($this->app->getExtensions() as $name => $extension) {
            if ($this->app->isCoreExtension($name)) {
                continue;
            }
            $extensions[$name] = $this->extensionUpdateStatus($name, $extension);
        }

        return ['extensions' => $extensions];
    }

    /** @return array<string, mixed> */
    private function extensionUpdateStatus(string $name, BaseExtension $extension): array
    {
        $status = [
            'name' => $name,
            'title' => $extension->getName(),
            'version' => null,
            'latest_version' => null,
            'source_id' => null,
            'source_name' => null,
            'compatible' => null,
            'pending_update' => null,
            'warning' => null,
            'error' => null,
            'status' => 'unmanaged',
        ];

        try {
            $status['version'] = $extension->getVersion();
        } catch (Throwable $exception) {
            $status['status'] = 'error';
            $status['error'] = 'Version locale illisible : ' . $exception->getMessage();

            return $status;
        }

        try {
            $installation = $this->db->selectOrmOneOrNull(
                ExtensionInstallation::class,
                'SELECT * FROM extension_installations WHERE extension_id = % LIMIT 1',
                [$name],
            );
        } catch (Throwable) {
            $status['status'] = 'error';
            $status['error'] = 'La table des installations est indisponible.';

            return $status;
        }

        // Une extension chargée sans provenance n'est pas gérée par un catalogue.
        if ($installation === null) {
            return $status;
        }

        $status['source_id'] = $installation->source_id;

        try {
            $status['pending_update'] = $this->coreExtensionInstaller->pendingUpdate($name);
        } catch (Throwable $exception) {
            $status['status'] = 'error';
            $status['error'] = $exception->getMessage();

            return $status;
        }

        $source = $installation->source_id !== null
            ? $this->coreCatalogSourceHelper->get($installation->source_id)
            : null;
        if ($source !== null) {
            $status['source_name'] = $source->name;
        }

        // Une préparation interrompue doit rester visible même si la version
        // chargée ne correspond plus encore à la provenance enregistrée.
        if ($status['pending_update'] !== null) {
            $status['status'] = 'pending_update';
            $status['latest_version'] = $status['pending_update']['to_version'];

            return $status;
        }

        if ($installation->status !== 'installed' || $installation->version !== $status['version']) {
            $status['status'] = 'inconsistent';
            $status['error'] = 'La provenance enregistrée ne correspond pas à la version chargée.';

            return $status;
        }

        if ($source === null || !$source->enabled) {
            $status['status'] = 'source_unavailable';
            $status['error'] = 'Source de catalogue introuvable ou désactivée.';

            return $status;
        }

        try {
            $latest = $this->coreCatalogSourceClient->latestVersion($source, $name);
            $status['latest_version'] = $latest;

            if (version_compare($latest, $status['version'], '>') === false) {
                $status['status'] = 'up_to_date';

                return $status;
            }

            $verified = $this->coreCatalogSourceClient->verifiedVersion($source, $name, $latest);
            $status['compatible'] = $verified['compatible'];
            $status['warning'] = $verified['compatible'] === null
                ? 'Compatibilité CMS/PHP non évaluée en release DEV.'
                : null;

            if ($verified['compatible'] === false) {
                $status['status'] = 'incompatible';

                return $status;
            }

            if (!CatalogCompatibility::sourceAllows(
                $verified['metadata']['source_versions'] ?? null,
                $status['version'],
            )) {
                $status['status'] = 'update_blocked';

                return $status;
            }

            $status['status'] = 'update_available';
        } catch (Throwable $exception) {
            $status['status'] = 'error';
            $status['error'] = $exception->getMessage();
        }

        return $status;
    }

    /**
     * Désinstalle une extension installée depuis un catalogue.
     *
     * @return array<string, mixed>
     */
    public function uninstall(string $extension): array
    {
        $this->assertRoot();

        if (preg_match('/\A[a-z0-9_]{1,64}\z/', $extension) !== 1) {
            throw new AccessDeniedException();
        }

        try {
            $result = $this->coreExtensionUninstaller->uninstall($extension);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'EXTENSION_UNINSTALL_FAILED',
                'La désinstallation d’une extension a échoué.',
                'extensions',
                ['extension_id' => $extension],
                'ERROR',
                $exception,
            ));
            ExceptionResponse::throwWarning('La désinstallation a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Extension désinstallée.',
            ['extension_id' => $extension, 'version' => $result['version'] ?? null],
            'EXTENSION_UNINSTALLED',
            'extensions',
        );

        return $result;
    }

    /**
     * Exécute les migrations en attente d'une extension Core active.
     * L'identifiant est relu dans les extensions chargées ; le navigateur ne
     * fournit ni classe, ni chemin, ni liste de versions.
     *
     * @return array{extension:string,executed:list<string>,pending_migrations:list<string>}
     */
    public function runMigrations(string $extensionName): array
    {
        $this->assertRoot();

        $extension = $this->app->getExtensions()[$extensionName] ?? null;
        if ($extension === null || !$this->app->isCoreExtension($extensionName)) {
            throw new AccessDeniedException();
        }

        $db = $this->app->getDatabase();
        $pending = Migration::getPendingVersions($db, $extensionName, $extension);

        // Un double clic ne doit pas produire de seconde exécution.
        if ($pending === []) {
            return ['extension' => $extensionName, 'executed' => [], 'pending_migrations' => []];
        }

        try {
            // Migration relit la table et reprend le verrou commun aux mises à jour.
            (new Migration($this->app))->runMigrations($extensionName, $extension);
        } catch (\Throwable $exception) {
            // Conserver l'extension concernée ; le runner garde la cause interne.
            $this->logException(new LogEventException(
                'EXTENSION_MIGRATION_FAILED',
                'L’exécution des migrations d’une extension a échoué.',
                'extensions',
                ['extension' => $extensionName],
                'ERROR',
                $exception,
            ));
            throw $exception;
        }

        $remaining = Migration::getPendingVersions($db, $extensionName, $extension);

        if ($remaining !== []) {
            // Ne pas annoncer une réussite si une version reste absente.
            $this->logWarning(
                'Des migrations d’extension restent en attente après l’exécution.',
                ['extension' => $extensionName, 'remaining' => $remaining],
                'EXTENSION_MIGRATIONS_INCOMPLETE',
                'extensions',
            );
        } else {
            $this->logInfo(
                'Migrations d’extension exécutées.',
                ['extension' => $extensionName, 'versions' => $pending],
                'EXTENSION_MIGRATIONS_RUN',
                'extensions',
            );
        }

        return [
            'extension' => $extensionName,
            'executed' => $pending,
            'pending_migrations' => $remaining,
        ];
    }

    /** Les migrations modifient le schéma partagé par tous les Spaces. */
    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }
    }

    /** @return array<int> */
    private function getConfigurationSpaceIds(BaseExtension $extension): array
    {
        $permissions = $extension->getConfigEditPermissions();
        if ($permissions === []) {
            return [];
        }

        return $this->coreAclHelper->getSpacesHavingGlobalPermission($permissions);
    }

    private function canEditConfiguration(BaseExtension $extension, int $spaceId): bool
    {
        foreach ($extension->getConfigEditPermissions() as $permission) {
            if ($this->coreAclHelper->hasGlobalPermission($permission, $spaceId)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function validateConfiguration(
        Form $form, int $spaceId, string $extensionName, object $params, bool $requireMediaAccess = true,
    ): array
    {
        $validationResult = $form->validateSubmittedValues($params, [
            'space_id' => $spaceId,
            'extension' => $extensionName,
        ]);
        if (!$validationResult->isValid()) {
            ExceptionResponse::throwWarning(
                'La configuration est invalide : ' . ($validationResult->getFirstError()?->message ?? 'valeur non autorisée.'),
            );
        }

        /** @var object $normalizedParams */
        $normalizedParams = $validationResult->value;
        $values = $form->getInitialValues(get_object_vars($normalizedParams));
        foreach ($form->getDefinition()['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? null) === 'core.font_family') {
                    // Un choix désactivé reste conservable, mais ne peut pas être adopté ailleurs.
                    $value = $values[$field['name']] ?? null;
                    try {
                        $this->coreFontHelper->assertSelectable($value);
                    } catch (RuntimeException) {
                        $existing = $this->coreExtensionsHelper->getExtensionsConfForSpaces([$spaceId])[$extensionName][$spaceId] ?? null;
                        $previous = $existing?->params;
                        $previousValues = is_object($previous) ? get_object_vars($previous) : (is_array($previous) ? $previous : []);
                        try {
                            $this->coreFontHelper->assertSelectable($value, $previousValues[$field['name']] ?? null);
                        } catch (RuntimeException $exception) {
                            ExceptionResponse::throwWarning($exception->getMessage());
                        }
                    }
                }
                if (($field['type'] ?? null) !== 'core.media_file') {
                    continue;
                }
                $url = (string) ($values[$field['name']] ?? '');
                if ($url === '') {
                    continue;
                }
                if (($field['manualInputMode'] ?? 'media') === 'any' && ($field['allowManualInput'] ?? true) === true) {
                    try {
                        $this->coreMediaHelper->assertDirectFileUrl($url);
                    } catch (RuntimeException $exception) {
                        ExceptionResponse::throwWarning($exception->getMessage());
                    }
                    continue;
                }
                if ($requireMediaAccess && !$this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $spaceId)) {
                    throw new AccessDeniedException();
                }
                $space = $this->coreSpaceHelper->getSpacesByIds([$spaceId])[$spaceId] ?? null;
                if ($space === null) {
                    ExceptionResponse::throwWarning('Espace introuvable.');
                }
                try {
                    $this->coreMediaHelper->assertSelectableFile($space, $url, (string) ($field['mediaType'] ?? 'all'));
                } catch (RuntimeException $exception) {
                    ExceptionResponse::throwWarning($exception->getMessage(), $exception instanceof LogEventException ? $exception : null);
                }
            }
        }
        return $values;
    }

}
