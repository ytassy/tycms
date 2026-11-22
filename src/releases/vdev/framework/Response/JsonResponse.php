<?php

namespace YTassy\Tycms\Response;


class JsonResponse extends Response
{
	function __construct(array $data, int $code = 200, ?array $headers = null)
    {	
		parent::__construct(
			httpHeaders: array_merge([
				'Content-Type' => 'application/json',
				'Cache-Control' => 'private, no-store'
			], $headers ?? []),
			body: json_encode($data),
			code: $code
		);
	}
}
