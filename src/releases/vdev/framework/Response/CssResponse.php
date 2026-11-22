<?php

namespace YTassy\Tycms\Response;


class CssResponse extends Response
{
	
	function __construct(
		public readonly ?string $css
	)
    {
		parent::__construct(
			httpHeaders: [
				'Content-Type' => 'text/css; charset=UTF-8',
				'Cache-Control' => 'private, no-cache, no-store',
				'Vary' => 'Cookie',
				'X-Content-Type-Options' => 'nosniff',
			],
			body: $css,
			code: 200
		);

	}

}
