<?php

namespace YTassy\Tycms\DB\Migrations;


interface IMigration
{

    /**
     * Retoune un array sous forme [ '1.0.0', '1.0.1', '2.0.0', ...]
     */
    public function getMigrations(): array;

    /**
     * @return array Result of migration to store
     */
    public function runMigration(string $version): array;

}
