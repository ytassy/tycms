<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;

/** Seules les extensions proposant des composants participent à cet enregistrement. */
interface IPageComponentProvider
{
    public function coreOnRegisterPageComponents(PageComponentRegistry $registry): void;
}
