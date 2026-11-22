<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Admin;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\ArrayTools;

class ExtensionsHelper extends BaseHelper
{
    /** @var array<string, Form> */
    private array $formsByExtension = [];
    

	public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
       
    }

    public function getFormsByExtension(): array {
        return $this->formsByExtension;
    }

    /** @param $formsByExtension array<string, Form> */
    public function setFormsByExtension(array $formsByExtension): void {
        $this->formsByExtension = $formsByExtension;
    }


    public function checkAndCreateExtensionConfig(): void
    {
        $extensions = array_keys($this->app->getExtensions());

        if ($extensions === []) return;

        $extensionsQuery = implode(' UNION ALL ',
            array_fill(0, count($extensions), 'SELECT % AS extension'));

        $missingConfigurations = $this->db->select(
             "SELECT spaces.id AS space_id, extensions.extension
             FROM spaces
             CROSS JOIN ($extensionsQuery) AS extensions
             LEFT JOIN config ON config.space_id = spaces.id AND config.extension = extensions.extension
             WHERE config.space_id IS NULL",
            $extensions
        );

        foreach ($missingConfigurations as $configuration) {
            $form = $this->formsByExtension[$configuration->extension] ?? null;

            $defaultParams = $form ? $form->getDefaultValues() : (object)[];
            if ($form !== null && $form->hasComputedFields()) {
                $defaultParams = $form->resolveComputedValues($defaultParams, [
                    'space_id' => (int) $configuration->space_id,
                    'extension' => (string) $configuration->extension,
                ]);
            }

            $this->db->execute(
                'INSERT IGNORE INTO config (space_id, extension, params) VALUES (%, %, %)',
                [$configuration->space_id, $configuration->extension, $defaultParams]
            );
        }
    }


    /** @param array<int> $spaceIds */
    public function getExtensionsConfForSpaces(array $spaceIds): array
    {
        $confs = $this->db->selectOrm(Config::class,
            'SELECT * FROM config WHERE space_id IN (%)',
            [new FormatIn($spaceIds)]
        );

        return ArrayTools::indexArray($confs, ['extension', 'space_id']);
    }

    /**
     * Inserts a newly-enabled extension configuration or replaces its params.
     * The workflow has already checked the extension and the space ACL.
     *
     * @param array<string,mixed> $params
     */
    public function saveExtensionConfig(int $spaceId, string $extensionName, array $params): void
    {
        $this->db->execute(
            'INSERT INTO config (space_id, extension, params) VALUES (%, %, %) '
            . 'ON DUPLICATE KEY UPDATE params = VALUES(params)',
            [$spaceId, $extensionName, $params],
        );
    }

}
