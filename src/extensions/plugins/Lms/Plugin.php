<?php

declare(strict_types=1);

namespace YTassy\Tycms\Plugins\Lms;

use Override;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
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

    /** Paramètres encore généraux, stockés dans la table config comme ceux du Core. */
    public function coreRegisterConfigForm(): ?Form
    {
        return new Form($this, [
            'id' => 'lms.config',
            'version' => 1,
            'sections' => [[
                'id' => 'catalog',
                'label' => 'Catalogue',
                'fields' => [
                    [
                        'name' => 'courses_per_page',
                        'type' => 'core.number',
                        'default' => 12,
                        'label' => 'Cours par page',
                        'description' => 'Nombre de cours affichés dans une liste paginée.',
                        'validation' => ['minimum' => 1, 'maximum' => 100],
                        'ui' => ['step' => 1],
                    ],
                    [
                        'name' => 'show_course_count',
                        'type' => 'core.switch',
                        'default' => true,
                        'label' => 'Afficher le nombre de cours',
                    ],
                ],
            ]],
        ]);
    }

    /** La vue est compilée et enregistrée par le bundle Vue de l’extension. */
    #[Override]
    public function getConfigViewComponentName(): ?string
    {
        return 'lms/config';
    }

    protected function _getMigrations(): array { return []; }

 
    public function uninstallExtension(): void
    {
        // Todo Here => Remove tables
    }

}
