<?php
declare(strict_types=1);
/** Additive bootstrap only. A populated legacy installation requires the separate cold command. */
class SharedObjectSchema
{
    public const TABLES=['shared_object_catalog','shared_object_collections','shared_object_quota','shared_object_usage','shared_objects','shared_object_payloads'];
    public static function columns(PDO $pdo,string $table): array {
        if(!in_array($table,self::TABLES,true))throw new InvalidArgumentException('Shared schema table');
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'
            ? $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$table."' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN)
            : array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
    }
    public static function ensure(PDO $pdo): void {
        $columns=self::columns($pdo,'shared_object_catalog');
        if($columns)return; // Includes legacy: never silently convert or create a parallel authoritative partition.
        $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);$pdo->exec(file_get_contents(__DIR__.'/../../migrations/shared-objects-'.$driver.'.sql'));
    }
}
