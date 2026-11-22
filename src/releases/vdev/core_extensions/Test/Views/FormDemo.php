<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Test\Views;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Lib\Forms\CallbackFormOptionsProvider;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\RiaView;

/** Temporary visual integration of the declarative form foundation. */
final class FormDemo extends RiaView
{
    public function __construct(BaseApplicationItem $app)
    {
        $form = (new Form($app))
            ->setId('test.course.edit')
            ->setLabel('Cours de démonstration')
            ->setDescription('Exemple de formulaire déclaré en PHP et rendu par Vue.')
            ->addSection('general', 'Informations générales')
            ->addField('general', 'title', 'core.text', [
                'label' => 'Titre',
                'required' => true,
                'validation' => ['maxLength' => 255],
            ])
            ->addField('general', 'categoryId', 'core.select', [
                'label' => 'Catégorie',
                'placeholder' => '-- Choisir une catégorie --',
                'optionsProvider' => new CallbackFormOptionsProvider(static fn ($context) => [
                    ['value' => 1, 'label' => 'Développement'],
                    ['value' => 2, 'label' => 'Design'],
                ]),
            ])
            ->addField('general', 'price', 'core.number', [
                'label' => 'Prix',
                'default' => 0,
                'validation' => ['minimum' => 0],
                'step' => 0.01,
            ])
            ->addField('general', 'showNavigation', 'core.radio', [
                'label' => 'Afficher la navigation',
                'default' => false,
                'options' => [
                    ['value' => true, 'label' => 'Oui'],
                    ['value' => false, 'label' => 'Non'],
                ],
            ])
            ->addField('general', 'navigationLabel', 'core.text', [
                'label' => 'Libellé du bouton suivant',
                'default' => 'Suivant',
                'visibleWhen' => [
                    'field' => 'showNavigation',
                    'operator' => 'equals',
                    'value' => true,
                ],
            ])
            ->addNote('general', 'Options avancées', 'Le champ JSON est rendu par le générateur de schéma existant.')
            ->addSection('advanced', 'Paramètres avancés')
            ->addField('advanced', 'params', 'core.json', [
                'label' => 'Paramètres JSON',
                'default' => ['maxLessons' => 10],
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'maxLessons' => [
                            'type' => 'integer',
                            'minimum' => 1,
                            'maximum' => 100,
                            '_ui_text' => 'Nombre maximum de leçons',
                        ],
                    ],
                    'required' => ['maxLessons'],
                ],
            ]);

        parent::__construct($app, 'tests/forms', [
            'formDefinition' => $form->getDefForRia([
                'title' => 'Initiation au framework',
                'categoryId' => 1,
            ]),
        ], false, true);
    }
}
