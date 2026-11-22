<?php

namespace YTassy\Tycms\Response;

/**
 * Api file Response
 *
 */
class FileResponse extends Response
{
	public ?string $filePath;
	public ?string $content;
	public ?string $downloadFileName;
	public bool $deleteFileAfterDownload;
	public ?string $contentType;

	function __construct(?string $filePath = null, ?string $content = null, ?string $downloadFileName = null, 
		bool $deleteFileAfterDownload = false, ?string $contentType = null)
    {
		parent::__construct(
			httpHeaders: ['Content-Type' => $contentType ?? 'application/octet-stream'],
			body: $content,
			code: 200
		);

		
		$this->filePath = $filePath;
		$this->content = $content;
		$this->downloadFileName = $downloadFileName;
		$this->deleteFileAfterDownload = $deleteFileAfterDownload;
		$this->contentType = $contentType ?? 'application/octet-stream';
	}

	

	/**
	 * Build a ApiFileResponse from a File object
	 * 
	 * @return FileResponse
	 */
	public static function fromContent(string $content, string $name, ?string $contentType = null)
    {
		return new FileResponse(null, $content, $name, false, $contentType);
	}
	
}
