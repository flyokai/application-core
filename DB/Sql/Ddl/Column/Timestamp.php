<?php

namespace Flyokai\ApplicationCore\DB\Sql\Ddl\Column;

class Timestamp extends \Laminas\Db\Sql\Ddl\Column\Timestamp
{
    /**
     * @return array
     */
    public function getExpressionData()
    {
        $spec = $this->specification;
        $options = $this->getOptions();

        $params   = [];
        $params[] = $this->name;
        $params[] = $this->type;

        $types = [self::TYPE_IDENTIFIER, self::TYPE_LITERAL];

        if (! $this->isNullable) {
            $spec .= ' NOT NULL';
        }

        if ($this->default !== null) {
            $spec    .= ' DEFAULT %s';
            $params[] = $this->default;
            if (isset($options['default_type'])) {
                $types[]  = $options['default_type'];
            } else {
                $types[]  = self::TYPE_VALUE;
            }
        }

        if (isset($options['on_update'])) {
            $spec    .= ' %s';
            $params[] = 'ON UPDATE CURRENT_TIMESTAMP';
            $types[]  = self::TYPE_LITERAL;
        }

        $data = [
            [
                $spec,
                $params,
                $types,
            ],
        ];

        foreach ($this->constraints as $constraint) {
            $data[] = ' ';
            $data   = array_merge($data, $constraint->getExpressionData());
        }

        return $data;
    }
}
