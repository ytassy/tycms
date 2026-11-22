<?php

namespace YTassy\Tycms\CoreExts\Test\Views;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\RiaView;

class Test extends RiaView
{
    protected $id;

    public function __construct(BaseApplicationItem $app, int $id)
    {
        parent::__construct($app, 'tests/test1', [
            'id' => $id
        ], false, true);
    }

   

}
