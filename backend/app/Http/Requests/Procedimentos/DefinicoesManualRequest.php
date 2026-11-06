<?php

namespace App\Http\Requests\Procedimentos;

use App\Http\Requests\POS\PedidoPOS;
use App\Services\Procedimentos\ServicoManualProcedimentos;

/**
 * Definições do Manual de Procedimentos. Em PUT grava (permissão proc_editar); em POST …/recolha é só leitura do que ainda
 * não foi gravado (permissão proc_manual_view) e todos os campos são opcionais.
 */
final class DefinicoesManualRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return $this->isMethod('PUT') ? ['proc_editar'] : ['proc_manual_view'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $r = [
            'titulo' => ['nullable', 'string', 'max:200'],
            'versao' => ['nullable', 'string', 'max:20'],
            'data_documento' => ['nullable', 'date_format:Y-m-d'],
            'aprovado_em' => ['nullable', 'date_format:Y-m-d'],
            'elaborado_por' => ['nullable', 'string', 'max:150'],
            'aprovado_por' => ['nullable', 'string', 'max:150'],
            'processos_excluidos' => ['nullable', 'array', 'max:200'],
            'processos_excluidos.*' => ['string', 'max:40'],
        ];
        foreach (ServicoManualProcedimentos::OPCOES as $k) {
            $r[$k] = ['nullable', 'boolean'];
        }
        foreach (array_keys(ServicoManualProcedimentos::TEXTOS) as $k) {
            $r[$k] = ['nullable', 'string', 'max:20000'];
        }

        return $r;
    }
}
