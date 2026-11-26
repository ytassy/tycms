<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\CustomEmailTemplate;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateContent;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

final class WFEmailTemplate extends BaseWF
{
    use TraitAllHelpers;

    public function spaces(): array
    {
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if (!$this->coreAclHelper->hasGlobalPermission(Permissions::EMAIL_TEMPLATE_EDIT, $space->id)) {
                continue;
            }
            $languages = $this->coreSpaceLanguageHelper->listActiveForSpace($space->id);
            $spaces[] = [
                'id' => $space->id,
                'title' => $space->title,
                'locales' => array_map(static fn ($language) => $language->locale, $languages),
                'defaultLocale' => $this->coreSpaceLanguageHelper->getDefaultLocale($space->id),
            ];
        }
        return ['spaces' => $spaces];
    }

    public function entries(int $spaceId, string $locale): array
    {
        $this->assertEdit($spaceId);
        $entries = [];
        foreach ($this->coreEmailTemplateRegistry->all() as $key => $_) {
            $entries[] = $this->describe($spaceId, $locale, $key);
        }
        foreach ($this->coreEmailTemplateHelper->customKeys($spaceId) as $key) {
            $entries[] = $this->describe($spaceId, $locale, $key);
        }

        // Les modèles du code et les modèles personnalisés restent dans une liste unique.
        usort($entries, static fn (array $left, array $right): int => strcmp($left['label'], $right['label']));

        return ['entries' => $entries];
    }

    public function describe(int $spaceId, string $locale, string $key): array
    {
        $this->assertEdit($spaceId);
        try {
            return $this->coreEmailTemplateHelper->describe($key, $spaceId, $locale);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    public function create(int $spaceId, object $data): array
    {
        $this->assertEdit($spaceId);
        try {
            $key = $this->coreEmailTemplateHelper->createCustom(
                $spaceId, $data, $this->session->getUserId(),
            );
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
            throw $exception;
        }

        return ['key' => $key];
    }

    public function updateMeta(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        try {
            $this->coreEmailTemplateHelper->updateCustom(
                $spaceId, $data, $this->session->getUserId(),
            );
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    public function delete(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        try {
            $this->coreEmailTemplateHelper->deleteCustom($spaceId, $data->key);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    public function save(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        $entry = $this->editableEntry($spaceId, $data->key);
        $locale = $this->activeLocale($spaceId, $data->locale);
        $definition = $entry['definition'];
        $subject = $data->subject;
        $body = $data->bodyHtml;

        try {
            $body = EmailTemplateContent::validate(
                $subject, $body, CustomEmailTemplate::variableMap($definition->variables),
            );
            EmailTemplateContent::assertImages($body, $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId], $this->coreMediaHelper);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->coreEmailTemplateHelper->save($spaceId, $data->key, $locale, $subject, $body, $this->session->getUserId());
    }

    public function restore(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        $entry = $this->editableEntry($spaceId, $data->key);
        $locale = $this->activeLocale($spaceId, $data->locale);

        // Le contenu de base d'un modèle personnalisé ne peut pas disparaître :
        // il ne serait alors plus possible de le prévisualiser ni de l'envoyer.
        if ($entry['custom'] && $entry['ownerSpaceId'] === $spaceId && $locale === $entry['baseLocale']) {
            ExceptionResponse::throwWarning(
                'Le contenu de base de ce modèle ne peut pas être retiré. Modifiez-le à la place.',
            );
        }

        $this->coreEmailTemplateHelper->restore($spaceId, $data->key, $locale);
    }

    public function preview(int $spaceId, object $data): array
    {
        $space = $this->assertEdit($spaceId);
        $entry = $this->resolvedEntry($spaceId, $data->key);
        $definition = $entry['definition'];
        $locale = $this->activeLocale($spaceId, $data->locale);
        $variables = CustomEmailTemplate::variableMap($definition->variables);
        $examples = [];
        foreach ($variables as $variable) {
            $examples[$variable->name] = $variable->example;
        }

        try {
            [$subject, $body] = EmailTemplateContent::render($data->subject, $data->bodyHtml, $variables, $examples);
            EmailTemplateContent::assertImages($body, $space, $this->coreMediaHelper);
            $html = $this->coreMailHelper->renderEmailPreview($body, $space, $definition->renderMode, $locale);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        return ['subject' => $subject, 'html' => $html];
    }

    private function assertEdit(int $spaceId): \YTassy\Tycms\CoreExts\Core\Models\Space
    {
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::EMAIL_TEMPLATE_EDIT, $spaceId)) {
            ExceptionResponse::throwDanger("Vous ne pouvez pas modifier les modèles d'e-mail de cet espace.");
        }
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }
        return $space;
    }

    /** @return array{owner:string, definition:\YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition,
     *                custom:bool, ownerSpaceId:?int, baseLocale:?string} */
    private function editableEntry(int $spaceId, string $key): array
    {
        $entry = $this->resolvedEntry($spaceId, $key);
        if (!$entry['definition']->customizable) {
            ExceptionResponse::throwWarning("Ce modèle d'e-mail n'est pas personnalisable.");
        }

        return $entry;
    }

    /** @return array{owner:string, definition:\YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition,
     *                custom:bool, ownerSpaceId:?int, baseLocale:?string} */
    private function resolvedEntry(int $spaceId, string $key): array
    {
        try {
            return $this->coreEmailTemplateHelper->resolveEntry($key, $spaceId);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
            throw $exception;
        }
    }

    private function activeLocale(int $spaceId, string $locale): string
    {
        try {
            $this->coreEmailTemplateHelper->assertActiveLocale($spaceId, $locale);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
        return $locale;
    }
}
