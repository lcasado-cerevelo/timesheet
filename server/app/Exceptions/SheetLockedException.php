<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;

/** Una hoja enviada o aprobada no se cambia. Se responde 423 (bloqueada). */
class SheetLockedException extends \DomainException
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? __('Esta hoja ya se envió y no se puede cambiar.'));
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => 'sheet_locked'], 423);
    }
}
