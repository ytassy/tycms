<?php

namespace YTassy\Tycms\CoreExts\Core\Views;

use Configuration;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;

class SpaceChoose extends BaseView
{

    protected Configuration $conf;
    protected array $spaces;
    protected string $csrfToken;
    
    public function __construct(BaseApplicationItem $app)
    {
        parent::__construct($app);

        $this->conf = $app->app->getConf();
        $this->csrfToken = $this->session->getCsrfToken();

        $this->spaces = $this->coreSpaceHelper->getDefaultSpacesFromContext();

    }

    

}
