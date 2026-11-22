<?php

namespace YTassy\Tycms\Extensions;

use YTassy\Tycms\Application;

abstract class BasePlugin extends BaseExtension
{

    public function onApplicationPrepare(Application $app) {}

}
