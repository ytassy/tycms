<?php

namespace YTassy\Tycms\DB;

use YTassy\Tycms\Application;
use YTassy\Tycms\Exceptions\TechException;

class FormatIn
{

	private array $items;
    
	public function __construct(array $items)
	{
		if (count($items) < 1) throw new TechException('Empty Array');

		foreach($items as $i => $item)
		{
			if (is_string($item)) continue;
			else if (is_float($item) && is_finite($item)) throw new TechException('invalid float value given at quote');
			else if (is_int($item)) continue;
			else if (is_numeric($item)) continue;
			else { throw new TechException('NOT int ou string value'); }
		}

		$this->items = $items;
	}

	public static function get(array $ints){
		return new self($ints);
	}

	public function toSql(Database $db): string{
		$items = array_map(function($item) use($db) {
			return $db->quote($item);
		}, $this->items);

		return implode(',', $items);
	}


    
}
