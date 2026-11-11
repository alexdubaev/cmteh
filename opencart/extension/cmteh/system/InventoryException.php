<?php
declare(strict_types=1);
namespace Cmteh;

final class InventoryException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message, public readonly int $status = 422)
    {
        parent::__construct($message, $status);
    }
}
