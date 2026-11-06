<?php

namespace App\Rules;

use App\Support\Angola\NifAngolano as ValidadorNif;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * NIF angolano (empresa de 10 dígitos, pessoa singular com o formato do BI, 9 dígitos). Valida o valor TAL COMO
 * chega (sem maiúsculas nem separadores): chame `normalizarNoPedido` antes de validar para o que se grava ser sempre a
 * forma normalizada. Ver App\Support\Angola\NifAngolano. Com `$permitirConsumidorFinal` aceita também o 999999999.
 */
final class NifAngolano implements ValidationRule
{
    /**
     * @param  ?string  $inalterado  NIF já gravado: se o pedido o repete tal e qual, aceita-se (um registo antigo, de antes desta
     *                               regra, não fica bloqueado para editar o resto; só um NIF NOVO ou alterado é validado)
     */
    public function __construct(private readonly bool $permitirConsumidorFinal = false, private readonly ?string $inalterado = null) {}

    /** Põe o NIF do pedido (chave com pontos, ex.: «empresa.nif») na forma normalizada: sem espaços/pontos/hífenes e em maiúsculas. */
    public static function normalizarNoPedido(Request $r, string $chave = 'nif'): void
    {
        $v = $r->input($chave);
        if (is_string($v)) {
            $dados = $r->all();
            Arr::set($dados, $chave, ValidadorNif::normalizar($v) ?: null);
            $r->merge($dados);
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (is_string($value) && $this->inalterado !== null && ValidadorNif::normalizar($value) === ValidadorNif::normalizar($this->inalterado)) {
            return;
        }
        if (! is_string($value) || ! ValidadorNif::valido($value, $this->permitirConsumidorFinal)) {
            $fail(ValidadorNif::MENSAGEM);
        }
    }
}
