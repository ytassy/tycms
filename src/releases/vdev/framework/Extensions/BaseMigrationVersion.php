<?php

namespace YTassy\Tycms\Extensions;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Routing\Router;

abstract class BaseMigrationVersion extends BaseApplicationItem
{


    /**
     * @return array Result of migration to store
     */
    abstract public function run(): array;

}
