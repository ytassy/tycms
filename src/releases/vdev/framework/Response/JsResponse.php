<?php

namespace YTassy\Tycms\Response;


class JsResponse extends Response
{
	
	function __construct(
		public readonly ?string $js = null
	)
    {
		parent::__construct(
			httpHeaders: ['Content-Type' => 'text/javascript'],
			body: $js,
			code: 200
		);

	}

}
