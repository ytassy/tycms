<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Template;

use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\Response\HtmlResponse;

class TemplateResponse extends HtmlResponse
{
	function __construct(
		string|ContentTemplate $content,
		BaseTemplate $template,
		object $templateParams)
    {
		parent::__construct($template->render($content, $templateParams));
	}
	
}
