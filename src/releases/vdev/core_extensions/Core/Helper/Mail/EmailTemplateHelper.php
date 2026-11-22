<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Exceptions\ApplicationException;

final class EmailTemplateHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    /** @return array<string, mixed> */
    public function describe(string $key, int $spaceId, string $locale): array
    {
        $entry = $this->coreEmailTemplateRegistry->get($key);
        if ($entry === null) {
            throw new ApplicationException("Modèle d'e-mail inconnu.");
        }

        $this->assertActiveLocale($spaceId, $locale);
        $definition = $entry['definition'];
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
            $effectiveLocale = $locale;
        } else {
            $effectiveLocale = isset($definition->variants[$locale]) ? $locale : 'fr';
            $variant = $definition->variants[$effectiveLocale];
            $source = $effectiveLocale === $locale ? 'code' : 'code_fr_fallback';
            $sourceSpaceId = null;
        }

        // Un modèle ancien devenu incompatible doit échouer avant l'envoi.
        $variables = array_column($definition->variables, null, 'name');
        $body = EmailTemplateContent::validate($variant['subject'], $variant['bodyHtml'], $variables);
        $ownerSpaceId = $selected === null ? $spaceId : (int) $selected->space_id;
        $ownerSpace = $this->coreSpaceHelper->getAllSpacesByIds()[$ownerSpaceId] ?? null;
        if ($ownerSpace === null) {
            throw new ApplicationException('Espace propriétaire du modèle introuvable.');
        }
        EmailTemplateContent::assertImages($body, $ownerSpace, $this->coreMediaHelper);

        return [
            'key' => $key,
            'owner' => $entry['owner'],
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
        $definition = $this->coreEmailTemplateRegistry->get($key)['definition'];
        $variables = array_column($definition->variables, null, 'name');
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
}
