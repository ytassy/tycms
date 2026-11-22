<?php

namespace YTassy\Tycms\Tools;


class ArrayTools {

	public static function filterFields(array|object $array, array $fields): array
	{
		$result = [];

		if (is_object($array)) $array = get_object_vars($array);
		
		foreach ($fields as $field)
		{
			if (array_key_exists($field, $array))
			{
				$result[$field] = $array[$field];
			}
		}

		return $result;
	}

	public static function indexArray(array $array, string|array $field, bool $multiple = false, ?string $finalSingleField = null)
	{
		$res = [];

		if (is_string($field))
		{
			if ($finalSingleField === null)
			{
				foreach($array as $item)
				{
					$val = is_array($item) ? $item[$field] : $item->$field;

					if ($multiple) $res[$val][] = $item;
					else $res[$val] = $item;
				}

				return $res;
			}

			foreach($array as $item)
			{
				$val = is_array($item) ? $item[$field] : $item->$field;
				$final = is_array($item) ? $item[$finalSingleField] : $item->$finalSingleField;

				if ($multiple) $res[$val][] = $final;
				else $res[$val] = $final;
			}

			return $res;
		}

		if ($finalSingleField === null)
		{
			foreach($array as $item)
			{
				$target =& $res;

				foreach ($field as $key)
				{
					$val = is_array($item) ? $item[$key] : $item->$key;
					$target =& $target[$val];
				}

				if ($multiple) $target[] = $item;
				else $target = $item;

				unset($target);
			}

			return $res;
		}

		foreach($array as $item)
		{
			$target =& $res;

			foreach ($field as $key)
			{
				$val = is_array($item) ? $item[$key] : $item->$key;
				$target =& $target[$val];
			}

			$final = is_array($item) ? $item[$finalSingleField] : $item->$finalSingleField;

			if ($multiple) $target[] = $final;
			else $target = $final;

			unset($target);
		}

		return $res;
	}


}
