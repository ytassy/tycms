<?php

namespace YTassy\Tycms\CoreExts\Test;

use Override;
use YTassy\Tycms\CoreExts\Test\Controllers\TestController;
use YTassy\Tycms\Extensions\BasePlugin;

class Plugin extends BasePlugin
{

    public function getName(): string
    {
        return "Test";
    }

    public function getVersion(): string
    {
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return "Test component";
    }
    
    #[Override]
	protected function _getMigrations(): array
	{
		return [
            //'1.0.2' => [ Mig_1_0_2::class ] 
        ];
	}

    public function getControllers(): array
    {
        return [
            new TestController($this)
        ];
    }




}
