<?php

declare(strict_types=1);

namespace YTassy\Tycms\Plugins\Lms;

use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\IPageComponentProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\Plugins\Lms\PageComponents\CourseList;

/** Socle du LMS ; les cours, inscriptions et permissions métier restent à définir. */
final class Plugin extends BasePlugin implements IPageComponentProvider
{
    public function getName(): string { return 'LMS'; }

    public function getDescription(): string { return 'Socle de développement de la plateforme de formation.'; }

    public function getFrontendEntry(): ?string { return 'src/main.js'; }

    public function coreOnRegisterPageComponents(PageComponentRegistry $registry): void
    {
        // Le catalogue reste déclaratif : aucune donnée métier n’est chargée ici.
        $registry->register(CourseList::class);
    }

    protected function _getMigrations(): array { return []; }

 
    public function uninstallExtension(): void
    {
        // Todo Here => Remove tables
    }

}
