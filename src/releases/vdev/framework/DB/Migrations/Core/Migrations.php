<?php


namespace YTassy\Tycms\DB\Migrations\Core;

use YTassy\Tycms\DB\Migrations\IMigration;

class Migrations implements IMigration
{
    private $migrations = [
        //'1.0.0' => [ Mig_1_0_0::class],
        //'1.0.2' => [ Mig_1_0_2::class ] 
    ];

	public function getMigrations(): array
	{
		return array_keys($this->migrations);
	}

	public function runMigration(string $version): array
	{
        $res = [];

        $classes = $this->migrations[$version];

        foreach($classes as $class)
        {
            $mig = new $class($this);
            $res[] = $mig ->run();
        }
        
        return $res;
	}
}
