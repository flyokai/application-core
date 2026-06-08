<?php

namespace Flyokai\DbSchema\Apply;

use Flyokai\ApplicationCore\DB\Sql\Ddl\Column as FlyoCol;
use Flyokai\DbSchema\Model\ColumnModel;
use Laminas\Db\Sql\Ddl\Column as LamCol;
use Laminas\Db\Sql\Ddl\Column\ColumnInterface;

/**
 * Maps our canonical ColumnModel to a Laminas Ddl Column instance. Uses Flyokai
 * custom Ddl classes (Json, LongText, MediumText, SmallInt, Timestamp) when the
 * base Laminas fork doesn't cover the type.
 */
class ColumnFactory
{
    public function build(ColumnModel $c): ColumnInterface
    {
        $options = $this->buildOptions($c);
        $nullable = $c->nullable;

        // Choose the positional $default argument for the Laminas Column constructors.
        // Timestamp columns accept a raw SQL expression here (e.g. CURRENT_TIMESTAMP) when
        // options['default_type'] === 'expression'. For all other types we pass the literal
        // default value or null.
        if ($c->defaultExpression !== null) {
            $default = $c->defaultExpression;
        } elseif ($c->hasDefault) {
            $default = $c->default;
        } else {
            $default = null;
        }

        return match ($c->type) {
            'int' => new LamCol\Integer($c->name, $nullable, $default, $options),
            'bigint' => new LamCol\BigInteger($c->name, $nullable, $default, $options),
            'smallint' => new FlyoCol\SmallInt($c->name, $nullable, $default, $options),
            'tinyint' => $this->buildTinyInt($c, $options),
            'mediumint' => $this->unsupported('mediumint'),
            'varchar' => new LamCol\Varchar($c->name, $c->length ?? 255, $nullable, $default, $options),
            'char' => new LamCol\Char($c->name, $c->length ?? 1, $nullable, $default, $options),
            'text' => new LamCol\Text($c->name, $c->length, $nullable, $default, $options),
            'tinytext' => $this->unsupported('tinytext'),
            'mediumtext' => new FlyoCol\MediumText($c->name, $c->length, $nullable, $default, $options),
            'longtext' => new FlyoCol\LongText($c->name, $c->length, $nullable, $default, $options),
            'json' => new FlyoCol\Json($c->name, null, $nullable, $default, $options),
            'date' => new LamCol\Date($c->name, $nullable, $default, $options),
            'datetime' => new LamCol\Datetime($c->name, $nullable, $default, $options),
            'time' => new LamCol\Time($c->name, $nullable, $default, $options),
            'timestamp' => new FlyoCol\Timestamp($c->name, $nullable, $default, $options),
            'decimal' => new LamCol\Decimal($c->name, $c->precision ?? 10, $c->scale ?? 0, $nullable, $default, $options),
            'float' => new LamCol\Floating($c->name, $c->precision, $c->scale, $nullable, $default, $options),
            'double' => new LamCol\Floating($c->name, $c->precision, $c->scale, $nullable, $default, $options),
            'boolean' => new LamCol\Boolean($c->name, $nullable, $default, $options),
            'binary' => new LamCol\Binary($c->name, $c->length, $nullable, $default, $options),
            'varbinary' => new LamCol\Varbinary($c->name, $c->length ?? 255, $nullable, $default, $options),
            'blob' => new LamCol\Blob($c->name, $c->length, $nullable, $default, $options),
            'enum' => $this->unsupported('enum — phase 1 does not emit ENUM; use VARCHAR-backed BackedEnum instead'),
            default => $this->unsupported($c->type),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOptions(ColumnModel $c): array
    {
        $options = [];
        if ($c->autoIncrement) {
            $options['autoincrement'] = true;
        }
        if ($c->unsigned) {
            $options['unsigned'] = true;
        }
        if ($c->length !== null && $c->type !== 'varchar' && $c->type !== 'char') {
            $options['length'] = $c->length;
        }
        if ($c->comment !== null && $c->comment !== '') {
            $options['comment'] = $c->comment;
        }
        if ($c->collation !== null && $c->collation !== '') {
            $options['collation'] = $c->collation;
        }
        if ($c->defaultExpression !== null) {
            // Flyokai's Timestamp column reads default_type=expression from options; the
            // expression itself is passed as the positional $default constructor arg.
            $options['default_type'] = 'expression';
        }
        if ($c->onUpdateExpression !== null) {
            // Flyokai's Timestamp column emits "ON UPDATE CURRENT_TIMESTAMP" when
            // this option is set. MySQL only supports CURRENT_TIMESTAMP here, so the
            // option is a boolean flag — the expression text is ignored.
            $options['on_update'] = true;
        }
        return $options;
    }

    private function buildTinyInt(ColumnModel $c, array $options): ColumnInterface
    {
        // TINYINT(1) is Laminas Boolean's wire format. For anything else, we
        // don't have a dedicated Tinyint class and fall back to Boolean with a
        // warning in options['length'] (applier ignores it). Phase 1 scope.
        return new LamCol\Boolean($c->name, $c->nullable, $c->hasDefault ? $c->default : null, $options);
    }

    private function unsupported(string $type): never
    {
        throw new \LogicException(sprintf(
            'SchemaApplier: column type "%s" is not supported yet — add a custom Ddl column class or use #[Column(type: "...")] to pick a supported type',
            $type
        ));
    }
}
