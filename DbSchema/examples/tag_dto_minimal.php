<?php
/**
 * db-schema example — the simplest possible #[Table] DTO + standalone discovery.
 *
 * This script discovers schemas without booting the full framework. Run from project root:
 *   php vendor/flyokai/db-schema/examples/tag_dto_minimal.php
 */

require __DIR__ . '/../../../../vendor/autoload.php';

use Flyokai\DataMate\Dto;
use Flyokai\DataMate\DtoTrait;
use Flyokai\DataMate\Solid;
use Flyokai\DbSchema\Attribute\Column;
use Flyokai\DbSchema\Attribute\PrimaryKey;
use Flyokai\DbSchema\Attribute\Table;
use Flyokai\DbSchema\Attribute\UniqueKey;
use Flyokai\DbSchema\Discovery\SchemaDiscovery;

#[Table(name: 'demo_widget', alias: 'widget')]
#[UniqueKey('idx_widget_sku', ['sku'])]
final class WidgetSolid implements Dto, Solid
{
    use DtoTrait;

    public function __construct(
        #[PrimaryKey(autoIncrement: true)] public readonly int    $widgetId,
        #[Column(length: 64)]              public readonly string $sku,
        #[Column(length: 255)]             public readonly string $name,
                                           public readonly float  $price,
        #[Column(defaultExpression: 'CURRENT_TIMESTAMP')]
                                           public readonly \DateTimeImmutable $created = new \DateTimeImmutable(),
    ) {}
}

// Standalone discovery without Registry::modules() — feed the class directly.
$discovery = new SchemaDiscovery();
$model     = $discovery->discoverFromClasses([WidgetSolid::class]);

foreach ($model->tables as $name => $table) {
    echo "\n=== Table {$name} (alias: {$table->alias}) ===\n";

    foreach ($table->columns as $col) {
        $line  = "  {$col->name}: {$col->type}";
        $line .= $col->length    ? "({$col->length})"          : '';
        $line .= $col->nullable  ? ' NULL'                      : ' NOT NULL';
        $line .= $col->autoIncrement       ? ' AUTO_INCREMENT'  : '';
        $line .= $col->unsigned            ? ' UNSIGNED'        : '';
        $line .= $col->defaultExpression   ? " DEFAULT {$col->defaultExpression}" : '';
        echo $line . "\n";
    }

    foreach ($table->primaryKey?->columns ?? [] as $c) {
        echo "  PRIMARY KEY: {$c}\n";
    }
    foreach ($table->uniqueKeys as $uk) {
        echo "  UNIQUE KEY {$uk->name}: " . implode(',', $uk->columns) . "\n";
    }
}

echo "\nNext step: run `bin/flyok-setup upgrade` to apply this against your live DB.\n";
