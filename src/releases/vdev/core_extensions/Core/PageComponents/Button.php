<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderMode;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonStyles;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\HtmlTools;

/** Composant Bouton fourni par Core. */
final class Button extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.button';
    }

    public static function getLabel(): string
    {
        return 'Bouton';
    }

    public static function getCategory(): string
    {
        return 'Navigation';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'text' => ['type' => 'string'],
                'variant' => ['type' => 'string'],
                'size' => ['type' => 'string'],
                'button_style' => ['type' => 'object', 'additionalProperties' => true],
                'href' => ['type' => 'string'],
                'link_type' => ['type' => 'string'],
                'menu_item_id' => ['type' => 'integer'],
                'link_new_tab' => ['type' => 'boolean'],
                'button_action' => ['type' => 'string'],
                'automation_scenario_key' => ['type' => 'string'],
                'success_message_mode' => ['type' => 'string'],
                'success_message' => ['type' => 'string'],
                'reload_on_close' => ['type' => 'boolean'],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return [
            'text' => '',
            'href' => '',
            'button_action' => 'link',
            'reload_on_close' => false,
        ];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/button';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/button';
    }

    /**
     * Seuls les boutons d'automatisation sont repris par Vue.
     *
     * Les boutons de lien restent un rendu serveur classique, sans coût de
     * montage supplémentaire.
     */
    public static function getPublicVueComponentNameFor(object $node): ?string
    {
        return (string) ($node->props->button_action ?? 'link') === 'automation'
            ? 'core/automation-button'
            : null;
    }

    public function getPublicProps(object $node, PageRenderContext $context): object
    {
        $props = parent::getPublicProps($node, $context);

        if ((string) ($props->button_action ?? 'link') !== 'automation') {
            return $props;
        }

        $key = is_string($props->automation_scenario_key ?? null)
            ? $props->automation_scenario_key
            : '';
        $pageId = (int) $context->page->id;
        $nodeId = (string) $node->id;

        // Ces valeurs éphémères alimentent le composant Vue ; elles ne sont
        // jamais persistées dans le document de page.
        $props->label = $context->text($props->text ?? 'Bouton');
        $props->classes = PageButtonStyles::classes($props);
        $props->enabled = $context->mode === PageRenderMode::PUBLIC
            && $key !== ''
            && $this->corePageButtonHelper->findPublished($context->spaceId, $key) !== null;
        $props->pageId = $pageId;
        $props->nodeId = $nodeId;
        $props->locale = $context->locale;
        $props->buttonToken = $this->corePageButtonToken->sign(
            $pageId,
            $nodeId,
            $this->session->getCsrfToken(),
        );
        $props->endpoint = '/page/button';
        $props->reloadOnClose = (bool) ($props->reload_on_close ?? false);

        return $props;
    }

    public static function getEditorFields(): array
    {
        return [
            ['name' => 'text', 'label' => 'Libellé', 'type' => 'text', 'localized' => true],
            [
                'name' => 'success_message',
                'label' => 'Message après le clic',
                'type' => 'text',
                'localized' => true,
                'maxLength' => 300,
            ],
        ];
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        $data = clone $data;
        $action = $data->button_action ?? 'link';

        if (!in_array($action, ['link', 'automation'], true)) {
            ExceptionResponse::throwWarning('Action de bouton invalide.');
        }

        $data->button_action = $action;

        if ($action === 'automation') {
            $key = $data->automation_scenario_key ?? null;

            if (!is_string($key)) {
                ExceptionResponse::throwWarning('Scénario du bouton invalide.');
            }

            $this->corePageButtonHelper->assertScenarioKey($context->spaceId, $key);

            // Quatre états explicites : message générique, texte propre au
            // bouton, sortie du scénario, ou aucune confirmation.
            $messageMode = $data->success_message_mode ?? 'default';

            if (!in_array($messageMode, ['default', 'custom', 'result', 'none'], true)) {
                ExceptionResponse::throwWarning('Mode de message du bouton invalide.');
            }

            $data->success_message_mode = $messageMode;

            if ($messageMode !== 'custom') {
                unset($data->success_message);
            }

            $data->reload_on_close = (bool) ($data->reload_on_close ?? false);

            unset($data->href, $data->link_type, $data->menu_item_id, $data->link_new_tab);
        } else {
            unset(
                $data->automation_scenario_key,
                $data->success_message_mode,
                $data->success_message,
                $data->reload_on_close,
            );
            $this->validateLink($data, $context);
        }

        PageButtonStyles::validate($data);

        return $data;
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $text = HtmlTools::escape($context->text($props->text ?? 'Bouton'));
        $classes = PageButtonStyles::classes($props);

        if (($props->button_action ?? 'link') === 'automation') {
            $key = is_string($props->automation_scenario_key ?? null) ? $props->automation_scenario_key : '';

            if ($context->mode !== PageRenderMode::PUBLIC
                || $key === ''
                || $this->corePageButtonHelper->findPublished($context->spaceId, $key) === null) {
                return '<span class="' . $classes . ' disabled" aria-disabled="true">' . $text . '</span>';
            }

            // Repli avant le montage Vue : le composant remplace ce bouton
            // inactif et exécute le scénario par l'API.
            return '<button type="button" class="' . $classes . '" disabled aria-disabled="true">' . $text . '</button>';
        }

        $href = $this->linkHref($context, $props);

        if ($href === null) {
            return '<span class="' . $classes . ' disabled" aria-disabled="true">' . $text . '</span>';
        }

        return '<a class="' . $classes . '" href="' . HtmlTools::escape($href) . '"'
            . PageLinks::targetAttributes($props) . '>' . $text . '</a>';
    }
}
