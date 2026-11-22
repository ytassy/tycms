<?php

namespace YTassy\Tycms\Response;

class Response
{
    /**
	 * @param array<string,string|array<string>> $httpHeaders
	 * @param ?string $body
	 * @param int|null $code
	 */
	function __construct(
		public array $httpHeaders,
		public readonly ?string $body = null,
		public readonly ?int $code = null
	)
    {

	}

	/**
	 * @param array<string,string|array<string>> $headers
	 */
    public function setHeaders(array $headers): void
    {
        foreach ($headers as $name => $header) {
			if (isset($this->httpHeaders[$name])) {
				if (is_array($this->httpHeaders[$name])) {
					if (is_array($header)) {
						$this->httpHeaders[$name] = array_merge($this->httpHeaders[$name], $header);
					} else {
						$this->httpHeaders[$name][] = $header;
					}
				} else {
					if (is_array($header)) {
						$this->httpHeaders[$name] = array_merge([$this->httpHeaders[$name], $header]);
					} else {
						$this->httpHeaders[$name] = [$this->httpHeaders[$name], $header];
					}
				}
			} else {
            	$this->httpHeaders[$name] = $header;
			}
        }
    }

	public function replaceAllHeaders(array $headers): void
	{
		$this->httpHeaders = $headers;
	}
}
