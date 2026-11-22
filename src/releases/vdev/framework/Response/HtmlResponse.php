<?php

namespace YTassy\Tycms\Response;


class HtmlResponse extends Response
{
	function __construct(?string $html = null, ?array $headers = null)
    {
		parent::__construct(
			httpHeaders: array_merge([
				'Content-Type' => 'text/html',
				'Cache-Control' => 'private, no-store'
			], $headers ?? []),

			body: $html,
			code: 200
		);
	}
	
}
