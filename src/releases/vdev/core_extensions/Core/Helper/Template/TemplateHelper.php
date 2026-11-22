<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Template;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Tools\ArrayTools;
use YTassy\Tycms\WF\BaseHelper;

class TemplateHelper extends BaseHelper
{
    use TraitAllHelpers;

    /** Le WF a déjà autorisé l’édition du Space cible ; aucun paramètre de configuration n’est exposé. */
    public function getEditorColorVariables(int $spaceId): array
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        if ($space === null) throw new TechException('Space de la template introuvable.');
        $template = $this->getTemplateExtension($space->template);
        return PageColors::normalizeCatalogue($template->getEditorColorVariables());
    }

    public function getTemplateConf(string $extension, ?int $spaceId = null) : Config {
        if ($spaceId == null) {
            $spaceId = $this->coreSpaceHelper->getCurrentSpace()->id;
        }
        return $this->db->selectOrmOneOrFail(Config::class, 'SELECT * FROM config WHERE extension = % AND space_id = %', [ $extension, $spaceId]);
    }

    /**
     * @return BaseTemplate
     */
    public function getTemplateExtension(string $name) : BaseTemplate {

        if (!str_starts_with($name, 'tpl_')) {
            $name = 'tpl_' . strtolower($name);
        }

        $all = $this->app->getExtensions();

        /** @var BaseTemplate|null */
        $template = $all[$name] ?? null;

        if ($template == null) throw new TechException('Template not found: ' . $name);

        return $template;
    }

    /**
     * @return array{0: Config, 1: BaseTemplate} 
     */
    public function getTemplateAndExtension(string $extension, ?int $spaceId = null) : array {

        $conf = $this->getTemplateConf($extension, $spaceId);

        $template = $this->getTemplateExtension($extension);
        
        return [$conf, $template];
    }


}
