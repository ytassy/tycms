<?php

namespace YTassy\Tycms\Response;


class ApiMessageResponse extends ApiResponse
{
	public array $data;

	function __construct(string $message, RespLevel $level, array $data = [])
    {
		parent::__construct(
			array_merge(self::getMessageData($message, $level), $data)
		);
	}

	public static function getMessageData(string $message, RespLevel $level){
		return [
			'error' => false,
			'is_message' => true,
			'message_level' => $level,
			'message' => $message
		];
	}
	
}
