<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\ApplicationException;

final class EmailTemplateHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    /** @return array<string, mixed> */
    public function describe(string $key, int $spaceId, string $locale): array
    {
        // Un modèle du code prime ; sinon on cherche un modèle personnalisé visible.
        $entry = $this->resolveEntry($key, $spaceId);

        $this->assertActiveLocale($spaceId, $locale);
        $definition = $entry['definition'];
        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($ancestors === []) {
            throw new ApplicationException('Espace inconnu.');
        }

        $local = null;
        $selected = null;
        if ($definition->customizable) {
            // Une seule lecture couvre le Space et ses parents.
            $rows = $this->db->select(
                'SELECT space_id, subject, body_html, updated_at FROM email_template_overrides '
                    . 'WHERE template_key = % AND locale = % AND space_id IN (%)',
                [$key, $locale, FormatIn::get($ancestors)],
            );
            $bySpace = [];
            foreach ($rows as $row) {
                $bySpace[(int) $row->space_id] = $row;
            }
            $local = $bySpace[$spaceId] ?? null;
            foreach ($ancestors as $ancestorId) {
                if (isset($bySpace[$ancestorId])) {
                    $selected = $bySpace[$ancestorId];
                    break;
                }
            }
        }

        if ($selected !== null) {
            $variant = ['subject' => $selected->subject, 'bodyHtml' => $selected->body_html];
            $source = (int) $selected->space_id === $spaceId ? 'space' : 'parent';
            $sourceSpaceId = (int) $selected->space_id;
            $contentSpaceId = $sourceSpaceId;
            $effectiveLocale = $locale;
        } elseif ($entry['custom']) {
            // Sans contenu dans la langue demandée, le modèle personnalisé reprend
            // le contenu de base de son espace propriétaire.
            $effectiveLocale = $entry['baseLocale'];
            $base = $effectiveLocale === $locale ? null : $this->db->selectOneOrNull(
                'SELECT space_id, subject, body_html FROM email_template_overrides '
                    . 'WHERE template_key = % AND locale = % AND space_id = %',
                [$key, $effectiveLocale, $entry['ownerSpaceId']],
            );
            if ($base === null) {
                throw new ApplicationException("Aucun contenu n'est défini pour ce modèle personnalisé.");
            }

            $variant = ['subject' => $base->subject, 'bodyHtml' => $base->body_html];
            $source = 'custom';
            $sourceSpaceId = $entry['ownerSpaceId'];
            $contentSpaceId = $sourceSpaceId;
        } else {
            $effectiveLocale = isset($definition->variants[$locale]) ? $locale : 'fr';
            $variant = $definition->variants[$effectiveLocale];
            $source = $effectiveLocale === $locale ? 'code' : 'code_fr_fallback';
            $sourceSpaceId = null;
            $contentSpaceId = $spaceId;
        }

        // Un modèle ancien devenu incompatible doit échouer avant l'envoi.
        $variables = CustomEmailTemplate::variableMap($definition->variables);
        $body = EmailTemplateContent::validate($variant['subject'], $variant['bodyHtml'], $variables);
        $contentSpace = $spaces[$contentSpaceId] ?? null;
        if ($contentSpace === null) {
            throw new ApplicationException('Espace propriétaire du modèle introuvable.');
        }
        EmailTemplateContent::assertImages($body, $contentSpace, $this->coreMediaHelper);

        return [
            'key' => $key,
            'owner' => $entry['owner'],
            'custom' => $entry['custom'],
            'ownerSpaceId' => $entry['ownerSpaceId'],
            'ownerSpaceTitle' => $entry['ownerSpaceId'] === null
                ? null
                : ($spaces[$entry['ownerSpaceId']]->title ?? null),
            'baseLocale' => $entry['baseLocale'],
            'label' => $definition->label,
            'description' => $definition->description,
            'customizable' => $definition->customizable,
            'renderMode' => $definition->renderMode,
            'locale' => $locale,
            'effectiveLocale' => $effectiveLocale,
            'subject' => $variant['subject'],
            'bodyHtml' => $body,
            'source' => $source,
            'sourceSpaceId' => $sourceSpaceId,
            'sourceSpaceTitle' => $sourceSpaceId === null
                ? null
                : ($spaces[$sourceSpaceId]->title ?? null),
            'local' => $local === null ? null : [
                'subject' => $local->subject,
                'bodyHtml' => $local->body_html,
                'updatedAt' => $local->updated_at,
            ],
            'variables' => array_map(static fn ($variable) => [
                'name' => $variable->name,
                'type' => $variable->type,
                'required' => $variable->required,
                'example' => $variable->example,
                'allowedInSubject' => $variable->allowedInSubject,
                'requiredInBody' => $variable->requiredInBody,
            ], $definition->variables),
        ];
    }

    /** @param array<string,string> $values @return array{subject:string, bodyHtml:string, renderMode:string, locale:string} */
    public function prepare(string $key, int $spaceId, string $locale, array $values): array
    {
        $description = $this->describe($key, $spaceId, $locale);
        $definition = $this->resolveEntry($key, $spaceId)['definition'];
        $variables = CustomEmailTemplate::variableMap($definition->variables);
        [$subject, $body] = EmailTemplateContent::render(
            $description['subject'], $description['bodyHtml'], $variables, $values,
        );

        return [
            'subject' => $subject,
            'bodyHtml' => $body,
            'renderMode' => $definition->renderMode,
            'locale' => $description['effectiveLocale'],
        ];
    }

    public function assertActiveLocale(int $spaceId, string $locale): void
    {
        if (!in_array($locale, array_map(
            static fn ($language): string => $language->locale,
            $this->coreSpaceLanguageHelper->listActiveForSpace($spaceId),
        ), true)) {
            throw new ApplicationException('Langue inactive ou inconnue pour cet espace.');
        }
    }

    public function save(int $spaceId, string $key, string $locale, string $subject, string $body, ?int $userId): void
    {
        $this->db->execute(<<<SQL
            INSERT INTO email_template_overrides (space_id, template_key, locale, subject, body_html, updated_by)
            VALUES (%, %, %, %, %, %)
            ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html),
                updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP
        SQL, [$spaceId, $key, $locale, $subject, $body, $userId]);
    }

    public function restore(int $spaceId, string $key, string $locale): void
    {
        $this->db->execute(
            'DELETE FROM email_template_overrides WHERE space_id = % AND template_key = % AND locale = %',
            [$spaceId, $key, $locale],
        );
    }

    /**
     * Résout une définition du code ou un modèle personnalisé visible dans le Space.
     *
     * @return array{owner:string, definition:EmailTemplateDefinition, custom:bool,
     *               ownerSpaceId:?int, baseLocale:?string}
     */
    public function resolveEntry(string $key, int $spaceId): array
    {
        $code = $this->coreEmailTemplateRegistry->get($key);
        if ($code !== null) {
            return [
                'owner' => $code['owner'],
                'definition' => $code['definition'],
                'custom' => false,
                'ownerSpaceId' => null,
                'baseLocale' => null,
            ];
        }

        $row = $this->findCustomTemplate($key, $spaceId);
        if ($row === null) {
            throw new ApplicationException("Modèle d'e-mail inconnu.");
        }

        return [
            'owner' => 'custom',
            'definition' => CustomEmailTemplate::definition($row),
            'custom' => true,
            'ownerSpaceId' => (int) $row->space_id,
            'baseLocale' => (string) $row->base_locale,
        ];
    }

    /** @return list<string> */
    public function customKeys(int $spaceId): array
    {
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($ancestors === []) {
            return [];
        }

        $rows = $this->db->select(
            'SELECT template_key FROM email_templates WHERE space_id IN (%) ORDER BY label, id',
            [FormatIn::get($ancestors)],
        );

        return array_map(static fn (object $row): string => (string) $row->template_key, $rows);
    }

    public function createCustom(int $spaceId, object $data, ?int $userId): string
    {
        // Valider la définition et le contenu initial avant toute écriture.
        $label = CustomEmailTemplate::label($data->label ?? null);
        $description = CustomEmailTemplate::description($data->description ?? '');
        $renderMode = CustomEmailTemplate::renderMode($data->renderMode ?? 'default');
        $variables = CustomEmailTemplate::variables($data->variables ?? []);
        $locale = is_string($data->locale ?? null) ? trim($data->locale) : '';
        if ($locale === '') {
            throw new ApplicationException('La langue du modèle est invalide.');
        }
        $this->assertActiveLocale($spaceId, $locale);

        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) {
            throw new ApplicationException('Espace inconnu.');
        }

        $subject = $data->subject ?? '';
        $body = $data->bodyHtml ?? '';
        if (!is_string($subject) || !is_string($body)) {
            throw new ApplicationException("Objet ou contenu du modèle d'e-mail invalide.");
        }
        $body = EmailTemplateContent::validate(
            $subject, $body, CustomEmailTemplate::variableMap($variables),
        );
        EmailTemplateContent::assertImages($body, $space, $this->coreMediaHelper);

        $key = $this->generateCustomKey($label);
        $this->db->execute(
            'INSERT INTO email_templates '
                . '(space_id, template_key, label, description, render_mode, base_locale, variables, created_by, updated_by) '
                . 'VALUES (%, %, %, %, %, %, %, %, %)',
            [$spaceId, $key, $label, $description, $renderMode, $locale,
                CustomEmailTemplate::encodeVariables($variables), $userId, $userId],
        );

        // Le contenu de base appartient à l'espace propriétaire, dans sa langue de création.
        $this->save($spaceId, $key, $locale, $subject, $body, $userId);

        return $key;
    }

    public function updateCustom(int $spaceId, object $data, ?int $userId): void
    {
        $key = is_string($data->key ?? null) ? trim($data->key) : '';
        $this->findOwnedCustom($spaceId, $key);

        $label = CustomEmailTemplate::label($data->label ?? null);
        $description = CustomEmailTemplate::description($data->description ?? '');
        $renderMode = CustomEmailTemplate::renderMode($data->renderMode ?? 'default');
        $variables = CustomEmailTemplate::variables($data->variables ?? []);

        // Tous les contenus existants, y compris dans les espaces descendants,
        // doivent rester valides avec la nouvelle liste de variables.
        $map = CustomEmailTemplate::variableMap($variables);
        $contents = $this->db->select(
            'SELECT subject, body_html FROM email_template_overrides WHERE template_key = %',
            [$key],
        );
        foreach ($contents as $content) {
            try {
                EmailTemplateContent::validate(
                    (string) $content->subject, (string) $content->body_html, $map,
                );
            } catch (ApplicationException $exception) {
                throw new ApplicationException(
                    'Le contenu existant de ce modèle utilise une variable retirée ou renommée. '
                    . 'Corrigez les textes avant de modifier les variables.',
                    previous: $exception,
                );
            }
        }

        $this->db->execute(
            'UPDATE email_templates SET label = %, description = %, render_mode = %, variables = %, updated_by = % '
                . 'WHERE template_key = %',
            [$label, $description, $renderMode, CustomEmailTemplate::encodeVariables($variables), $userId, $key],
        );
    }

    public function deleteCustom(int $spaceId, string $key): void
    {
        $this->findOwnedCustom($spaceId, $key);

        // La clé globale permet de retirer aussi les personnalisations des espaces descendants.
        $this->db->execute('DELETE FROM email_template_overrides WHERE template_key = %', [$key]);
        $this->db->execute('DELETE FROM email_templates WHERE template_key = %', [$key]);
    }

    /** @return object|null */
    private function findCustomTemplate(string $key, int $spaceId): ?object
    {
        $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($spaceId);
        if ($ancestors === []) {
            return null;
        }

        $rows = $this->db->select(
            'SELECT id, space_id, template_key, label, description, render_mode, base_locale, variables '
                . 'FROM email_templates WHERE template_key = % AND space_id IN (%)',
            [$key, FormatIn::get($ancestors)],
        );

        $bySpace = [];
        foreach ($rows as $row) {
            $bySpace[(int) $row->space_id] = $row;
        }

        foreach ($ancestors as $ancestorId) {
            if (isset($bySpace[$ancestorId])) {
                return $bySpace[$ancestorId];
            }
        }

        return null;
    }

    private function findOwnedCustom(int $spaceId, string $key): object
    {
        $row = $this->findCustomTemplate($key, $spaceId);
        if ($row === null || (int) $row->space_id !== $spaceId) {
            throw new ApplicationException(
                "Ce modèle personnalisé est introuvable ou n'appartient pas à cet espace.",
            );
        }

        return $row;
    }

    private function generateCustomKey(string $label): string
    {
        $base = CustomEmailTemplate::KEY_PREFIX . CustomEmailTemplate::slug($label);
        $key = $base;
        $suffix = 2;

        while ($this->customKeyInUse($key)) {
            $key = $base . '_' . $suffix++;
            if (strlen($key) > 150) {
                throw new ApplicationException('La clé du modèle est trop longue.');
            }
        }

        return $key;
    }

    private function customKeyInUse(string $key): bool
    {
        if ($this->coreEmailTemplateRegistry->get($key) !== null) {
            return true;
        }

        // Une surcharge orpheline réserve aussi la clé pour ne jamais ressusciter
        // un ancien contenu lors d'une future création.
        return $this->db->selectOneOrNull(
            'SELECT 1 AS present FROM email_templates WHERE template_key = % '
                . 'UNION ALL SELECT 1 AS present FROM email_template_overrides WHERE template_key = % LIMIT 1',
            [$key, $key],
        ) !== null;
    }
}
