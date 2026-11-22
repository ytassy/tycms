<?php

namespace YTassy\Tycms\CoreExts\Core\Views;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseView;


class ResetPassword extends BaseView
{

    protected string $csrfToken;
    protected string $loginUrl;
    protected string $formAction;
    protected bool $valid;

    public function __construct(BaseApplicationItem $app, string $token, bool $valid)
    {
        parent::__construct($app);

        $this->valid = $valid;
        $this->csrfToken = $this->session->getCsrfToken();
        $this->loginUrl = $this->coreUserHelper->getLoginUrl();
        $this->formAction = $this->url('PasswordResetSave', ['token' => $token]);
    }
}
