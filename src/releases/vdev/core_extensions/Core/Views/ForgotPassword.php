<?php

namespace YTassy\Tycms\CoreExts\Core\Views;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;

class ForgotPassword extends BaseView
{
    protected string $csrfToken;
    protected string $loginUrl;

    public function __construct(BaseApplicationItem $app)
    {
        parent::__construct($app);

        $this->csrfToken = $this->session->getCsrfToken();
        $this->loginUrl = $this->coreUserHelper->getLoginUrl();
    }
}
