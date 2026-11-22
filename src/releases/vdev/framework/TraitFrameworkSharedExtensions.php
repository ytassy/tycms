<?php

namespace YTassy\Tycms;

use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;
use YTassy\Tycms\Session\SessionManager;

trait TraitFrameworkSharedExtensions
{
    protected SessionManager $sessionManager {
        get { return $this->getSharedExtensionsClass(SessionManager::class); }
    }

    protected FormFactory $formFactory {
        get { return $this->getSharedExtensionsClass(FormFactory::class); }
    }

    protected ObjectValidator $sharedObjectValidator {
        get { return $this->getSharedExtensionsClass(ObjectValidator::class); }
    }
}
