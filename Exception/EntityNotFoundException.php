<?php

namespace Flyokai\ApplicationCore\Exception;

class EntityNotFoundException extends \InvalidArgumentException
{
    public static function fromEntity(string $entityType, int|string|array $entityId): self
    {
        $entityId = is_array($entityId) ? var_export($entityId, true) : $entityId;
        return new self(sprintf(
            'Entity "%s" with id "%s" not found.',
            $entityType, $entityId
        ));
    }
}
