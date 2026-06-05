<?php

namespace Flyokai\DbSchema\Introspection;

use Flyokai\DbSchema\Model\ColumnModel;

/**
 * Canonicalizes both declared and live-MySQL column descriptions so the differ
 * can compare them with value equality. The declarative side already produces
 * canonical tokens (see SchemaDiscovery); this class exists mainly to reconcile
 * the two defaults and to canonicalize the live side.
 */
class MysqlTypeNormalizer
{
    /**
     * Fold length fields that MySQL ignores for a given type.
     * Return a new ColumnModel suitable for structural equality checks.
     */
    public function canonicalize(ColumnModel $c): ColumnModel
    {
        $type = strtolower($c->type);
        $length = $c->length;
        $precision = $c->precision;
        $scale = $c->scale;

        // Integer display widths: MySQL 8 drops them for most integer types.
        // We normalize both sides to null length so they compare cleanly.
        if (in_array($type, ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true)) {
            if ($type !== 'tinyint' || $length !== 1) {
                $length = null;
            }
        }

        // TEXT/BLOB families don't carry a length.
        if (in_array($type, ['text', 'tinytext', 'mediumtext', 'longtext', 'blob', 'tinyblob', 'mediumblob', 'longblob', 'json'], true)) {
            $length = null;
            $precision = null;
            $scale = null;
        }

        // DATETIME/DATE/TIME/TIMESTAMP: drop length; fractional seconds precision sits in $precision.
        if (in_array($type, ['datetime', 'date', 'time', 'timestamp'], true)) {
            $length = null;
            // precision stays
        }

        // Decimal: both sides use precision+scale. Default precision to 10,0 if unset (MySQL default).
        if ($type === 'decimal' || $type === 'numeric') {
            $type = 'decimal';
            $precision = $precision ?? 10;
            $scale = $scale ?? 0;
            $length = null;
        }

        $default = $c->default;
        $hasDefault = $c->hasDefault;
        if ($hasDefault) {
            $default = $this->canonicalizeDefault($default, $type);
        }

        $defaultExpression = $c->defaultExpression;
        if ($defaultExpression !== null) {
            // CURRENT_TIMESTAMP() and CURRENT_TIMESTAMP are the same to MySQL; drop parens.
            $defaultExpression = strtoupper(str_replace(['()', ' '], ['', ''], $defaultExpression));
        }
        $onUpdateExpression = $c->onUpdateExpression;
        if ($onUpdateExpression !== null) {
            $onUpdateExpression = strtoupper(str_replace(['()', ' '], ['', ''], $onUpdateExpression));
        }

        return new ColumnModel(
            name: $c->name,
            type: $type,
            length: $length,
            precision: $precision,
            scale: $scale,
            unsigned: $c->unsigned,
            nullable: $c->nullable,
            hasDefault: $hasDefault,
            default: $default,
            defaultExpression: $defaultExpression,
            onUpdateExpression: $onUpdateExpression,
            autoIncrement: $c->autoIncrement,
            comment: $c->comment,
            collation: $c->collation,
            enumValues: $c->enumValues,
            renamedFrom: null,
        );
    }

    private function canonicalizeDefault(mixed $default, string $type): mixed
    {
        if ($default === null) return null;
        if (in_array($type, ['int', 'bigint', 'mediumint', 'smallint', 'tinyint'], true)) {
            return (string)(int)$default;
        }
        if (in_array($type, ['decimal', 'float', 'double'], true)) {
            return (string)(float)$default;
        }
        if ($type === 'boolean') {
            return $default ? '1' : '0';
        }
        return (string)$default;
    }

    /**
     * Map MySQL-reported DATA_TYPE (from INFORMATION_SCHEMA.COLUMNS) to our canonical token.
     */
    public function mapDataType(string $mysqlType): string
    {
        $t = strtolower($mysqlType);
        return match ($t) {
            'int', 'integer' => 'int',
            'tinyint' => 'tinyint',
            'smallint' => 'smallint',
            'mediumint' => 'mediumint',
            'bigint' => 'bigint',
            'varchar' => 'varchar',
            'char' => 'char',
            'text' => 'text',
            'tinytext' => 'tinytext',
            'mediumtext' => 'mediumtext',
            'longtext' => 'longtext',
            'json' => 'json',
            'date' => 'date',
            'datetime' => 'datetime',
            'time' => 'time',
            'timestamp' => 'timestamp',
            'decimal', 'numeric' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'enum' => 'enum',
            'set' => 'set',
            'binary' => 'binary',
            'varbinary' => 'varbinary',
            'blob' => 'blob',
            'tinyblob' => 'tinyblob',
            'mediumblob' => 'mediumblob',
            'longblob' => 'longblob',
            default => $t,
        };
    }
}
