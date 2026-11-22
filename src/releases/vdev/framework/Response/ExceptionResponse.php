<?php

namespace YTassy\Tycms\Response;

use Throwable;
use YTassy\Tycms\BaseApplicationItem;

class ExceptionResponse extends \Exception
{
	public Response $response;
	public ?Throwable $exception = null;
	
	public function __construct(Response $response, ?\Throwable $exception = null)
	{
		parent::__construct('');

		$this->response = $response;
		$this->exception = $exception;
	}

	public function getResponse(){
		return $this->response;
	}

	public static function throwException(string $message, ?\Throwable $exception = null){
		throw new self(new ApiMessageResponse(
			$message,
			RespLevel::ERROR,
			[ 'error' => true ]
		), $exception);
	}

	public static function throwInfo(string $message){
		throw new self(new ApiMessageResponse(
			$message,
			RespLevel::INFO,
			[ 'error' => false ]
		));
	}

	public static function throwSuccess(string $message){
		throw new self(new ApiMessageResponse(
			$message,
			RespLevel::SUCCESS,
			[ 'error' => false ]
		));
	}

	public static function throwWarning(string $message, ?\Throwable $exception = null){
		throw new self(new ApiMessageResponse(
			$message,
			RespLevel::WARNING,
			[ 'error' => false ]
		), $exception);
	}

	public static function throwDanger(string $message, ?\Throwable $exception = null){
		throw new self(new ApiMessageResponse(
			$message,
			RespLevel::DANGER,
			[ 'error' => false ]
		), $exception);
	}
}
