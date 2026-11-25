<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;

/**
 * Contenu restaurable d'une version de page.
 *
 * Le résultat reprend les champs de la page et le document JSON normalisé. Un
 * agent peut donc inspecter une ancienne version ou la réinjecter dans un
 * parcours d'écriture, sans lire la base directement.
 */
final class ReadPageVersionAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $pageId = ValueTools::assertInt($params['pageId'] ?? null, $context->span);
        $versionId = ValueTools::assertInt($params['versionId'] ?? null, $context->span);

        try {
            /** @var WFPage $workflow */
            $workflow = $this->getSharedExtensionsClass(WFPage::class);

            $version = $workflow->historyVersion($pageId, $versionId);

            // Le WF renvoie le document tel que l'éditeur l'attend (objets).
            // Le langage ne transporte que des scalaires, listes et maps.
            $version['data'] = self::plainValue($version['data']);

            return $version;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }

    /**
     * Réduire un document JSON en tableaux simples, récursivement.
     *
     * Une map vide reste un tableau vide, comme dans le catalogue des
     * composants : le langage ne distingue pas autrement map et liste.
     */
    private static function plainValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) return $value;

        $plain = [];
        foreach ($value as $key => $item) {
            $plain[$key] = self::plainValue($item);
        }

        return $plain;
    }
}
