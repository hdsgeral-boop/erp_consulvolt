<?php

namespace App\Http\Requests\POS;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Base dos pedidos do POS (F0): a permissão do catálogo do legado é verificada ANTES da validação, como o `exigir()` do
 * controlador fazia — um utilizador sem permissão continua a receber 403 (SEM_PERMISSAO) e não 422. As regras e mensagens são
 * as que estavam no controlador; as respostas JSON não mudam (tests/Support/ContratoPOS).
 */
abstract class PedidoPOS extends FormRequest
{
    /**
     * Pelo menos uma destas permissões é exigida (vazio = o controlador decide, p.ex. conforme o conteúdo do pedido).
     *
     * @return list<string>
     */
    protected function permissoes(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        $p = $this->permissoes();
        if ($p !== [] && ! Gate::any($p)) {
            throw new AccessDeniedHttpException;
        }

        return true;
    }
}
