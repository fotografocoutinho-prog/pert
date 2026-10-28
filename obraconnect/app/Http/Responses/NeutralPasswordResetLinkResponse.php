<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;

/**
 * Para emails desconhecidos responde como se o envio tivesse corrido bem,
 * para não revelar que contas existem na plataforma.
 */
class NeutralPasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse
{
    public function __construct(private readonly string $status) {}

    public function toResponse($request): JsonResponse
    {
        if ($this->status === Password::INVALID_USER) {
            return new JsonResponse(['message' => trans(Password::RESET_LINK_SENT)], 200);
        }

        throw ValidationException::withMessages(['email' => [trans($this->status)]]);
    }
}
