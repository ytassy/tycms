<?php

namespace YTassy\Tycms\Response;


class ApiResponse extends JsonResponse
{

	private string $message;
	private ?RespLevel $message_level = null;

	function __construct(array $data, int $code = 200)
    {	
		$data['error'] = $data['error'] ?? false;
		$data['is_message'] = $data['is_message'] ?? false;
		$data['message_level'] = $data['message_level'] ?? '';
		$data['message'] = $data['message'] ?? '';

		$this->message = $data['message'];
		$this->message_level = match (true) {
			$data['message_level'] instanceof RespLevel => $data['message_level'],
			$data['message_level'] !== '' => RespLevel::from($data['message_level']),
			default => null,
		};
		
		parent::__construct($data, $code);
	}

	public function getMessage(){
		return $this->message;
	}

	public function getMessageLevel(): ?RespLevel{
		return $this->message_level;
	}
}
