<?php

namespace App\Tenancy;

use LogicException;

/**
 * Lançada quando se consulta ou cria um modelo de empresa sem empresa no contexto.
 * É sempre um erro de programação: a regra é falhar fechado em vez de devolver
 * dados de todas as empresas.
 */
class MissingTenantContextException extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("Sem empresa no contexto ao aceder a [{$model}].");
    }
}
