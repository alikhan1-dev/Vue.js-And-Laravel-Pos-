<?php

namespace App\Exceptions;

use Exception;

class CannotDeleteEntityWithMovementsException extends Exception
{
    public function __construct(string $entityType, string $identifier)
    {
        parent::__construct(
            "Cannot delete {$entityType}: stock movements exist. Archive or deactivate instead. Reference: {$identifier}."
        );
    }

    public function render($request)
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], 422);
        }
        return null;
    }
}
