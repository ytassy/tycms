<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
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

    public function save(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        $key = $this->editableKey($data->key);
        $locale = $this->activeLocale($spaceId, $data->locale);
        $definition = $this->coreEmailTemplateRegistry->get($key)['definition'];
        $subject = $data->subject;
        $body = $data->bodyHtml;

        try {
            $body = EmailTemplateContent::validate(
                $subject, $body, array_column($definition->variables, null, 'name'),
            );
            EmailTemplateContent::assertImages($body, $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId], $this->coreMediaHelper);
        } catch (ApplicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->coreEmailTemplateHelper->save($spaceId, $key, $locale, $subject, $body, $this->session->getUserId());
    }

    public function restore(int $spaceId, object $data): void
    {
        $this->assertEdit($spaceId);
        $key = $this->editableKey($data->key);
        $locale = $this->activeLocale($spaceId, $data->locale);
        $this->coreEmailTemplateHelper->restore($spaceId, $key, $locale);
    }

    public function preview(int $spaceId, object $data): array
    {
        $space = $this->assertEdit($spaceId);
        $key = $this->knownKey($data->key);
        $locale = $this->activeLocale($spaceId, $data->locale);
        $definition = $this->coreEmailTemplateRegistry->get($key)['definition'];
        $variables = array_column($definition->variables, null, 'name');
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

    private function editableKey(string $key): string
    {
        $entry = $this->coreEmailTemplateRegistry->get($key);
        if ($entry === null || !$entry['definition']->customizable) {
            ExceptionResponse::throwWarning("Ce modèle d'e-mail n'est pas personnalisable.");
        }
        return $key;
    }

    private function knownKey(string $key): string
    {
        if ($this->coreEmailTemplateRegistry->get($key) === null) {
            ExceptionResponse::throwWarning("Modèle d'e-mail inconnu.");
        }
        return $key;
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
