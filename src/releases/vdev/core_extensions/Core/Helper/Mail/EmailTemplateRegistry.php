<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Exceptions\TechException;

final class EmailTemplateRegistry extends BaseHelper
{
    /** @var array<string, array{owner:string, definition:EmailTemplateDefinition}> */
    private array $entries = [];

    public function register(string $owner, EmailTemplateDefinition $definition): void
    {
        $key = $owner . '__' . $definition->id;
        // `custom` est réservé aux modèles créés depuis l'administration.
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $owner) !== 1
            || $owner === 'custom'
            || preg_match('/^[a-z][a-z0-9_]*$/D', $definition->id) !== 1
            || strlen($key) > 150 || isset($this->entries[$key])
            || !in_array($definition->renderMode, ['default', 'blank'], true)
            || trim($definition->label) === '' || !isset($definition->variants['fr'])) {
            throw new TechException("Définition de modèle d'e-mail invalide : {$key}.");
        }

        $variables = [];
        foreach ($definition->variables as $variable) {
            if (!$variable instanceof EmailTemplateVariable
                || preg_match('/^[a-z][a-z0-9_]*$/D', $variable->name) !== 1
                || isset($variables[$variable->name])
                || !in_array($variable->type, ['text', 'url', 'https_url'], true)) {
                throw new TechException("Variable invalide pour {$key}.");
            }
            $variables[$variable->name] = $variable;
        }

        foreach ($definition->variants as $locale => $variant) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $locale) !== 1
                || !is_array($variant) || !isset($variant['subject'], $variant['bodyHtml'])
                || !is_string($variant['subject']) || !is_string($variant['bodyHtml'])) {
                throw new TechException("Variante invalide pour {$key}.");
            }
            try {
                EmailTemplateContent::validate($variant['subject'], $variant['bodyHtml'], $variables);
            } catch (ApplicationException $exception) {
                throw new TechException("Contenu invalide pour {$key} : " . $exception->getMessage());
            }
        }

        $this->entries[$key] = ['owner' => $owner, 'definition' => $definition];
    }

    /** @return array<string, array{owner:string, definition:EmailTemplateDefinition}> */
    public function all(): array
    {
        ksort($this->entries);
        return $this->entries;
    }

    /** @return array{owner:string, definition:EmailTemplateDefinition}|null */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }
}
