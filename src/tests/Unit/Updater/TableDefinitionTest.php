<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Tycms\Updater\Lib\MariaDbBackup\TableDefinition;

require_once __DIR__ . '/../../../updater/Lib/MariaDbBackup/TableDefinition.php';

final class TableDefinitionTest extends TestCase
{
    public function testConstraintsAreSeparatedWithoutSplittingExpressionsOrStrings(): void
    {
        $sql = <<<'SQL'
CREATE TABLE `ex``ample` (
  `id` int NOT NULL AUTO_INCREMENT,
  `value` varchar(30) DEFAULT 'CHECK (a,b)' CHECK (length(`value`) > 0),
  `other` int DEFAULT (coalesce(1,2)),
  PRIMARY KEY (`id`),
  UNIQUE KEY `un` (`value`),
  CONSTRAINT `fk``x` FOREIGN KEY (`other`) REFERENCES `parent` (`id`),
  CONSTRAINT `positive` CHECK ((`other` > 0) AND (`other` < 20))
) ENGINE=InnoDB
SQL;
        $result = TableDefinition::split($sql, 'ex`ample');
        self::assertStringContainsString("DEFAULT 'CHECK (a,b)'", $result['create']);
        self::assertStringContainsString('DEFAULT (coalesce(1,2))', $result['create']);
        self::assertStringContainsString('PRIMARY KEY (`id`)', $result['create']);
        self::assertStringContainsString('UNIQUE KEY `un`', $result['create']);
        self::assertStringNotContainsString('FOREIGN KEY', $result['create']);
        self::assertCount(3, $result['constraints']);
        self::assertStringContainsString('ALTER TABLE `ex``ample` ADD CONSTRAINT `value` CHECK', $result['constraints'][0]);
        self::assertStringContainsString('CONSTRAINT `fk``x` FOREIGN KEY', $result['constraints'][1]);
    }

    public function testIncompleteDefinitionIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        TableDefinition::split('CREATE TABLE `x` (`a` INT', 'x');
    }
}
