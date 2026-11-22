<?php

namespace YTassy\Tycms\Extensions;


abstract class BaseTemplate extends BaseExtension
{
    /** @return array<string> */
    public function getConfigEditPermissions(): array
    {
        // Les templates utilisent le droit d'édition du site par défaut.
        return ['plg_core__TEMPLATE_EDIT'];
    }

    /** @return list<array{variable:string,label:string}> Couleurs globales proposées aux éditeurs visuels et de texte enrichi. */
    public function getEditorColorVariables(): array
    {
        return [];
    }

    abstract public function render(mixed $content, object $templateParams): string;

    /** Wrap trusted HTML in one of the email layouts supported by this template. */
    abstract public function renderEmail(
        string $content,
        object $templateParams,
        string $mode,
        EmailRenderContext $context,
    ): string;

    
}
