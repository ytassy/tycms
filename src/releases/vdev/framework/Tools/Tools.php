<?php

namespace YTassy\Tycms\Tools;


class Tools {

	public static function isCommandLineInterface()
	{
		return (php_sapi_name() === 'cli');
	}

}