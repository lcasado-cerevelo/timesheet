<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** Una hoja aprobada no se cambia. Se responde 423 (bloqueada). */
class SheetLockedException extends \DomainException
{
    public function __construct(string $message = 'Esta hoja ya fue aprobada y no se puede cambiar.')
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'sheet_locked'], 423);
    }
}
