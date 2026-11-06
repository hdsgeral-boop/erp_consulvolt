<?php

namespace App\Http\Requests\Procedimentos;

use App\Http\Requests\POS\PedidoPOS;

/**
 * Gravação (PUT) de um processo do Manual de Procedimentos. As regras de negócio com mensagem própria (título, pelo menos
 * uma etapa, cruzamentos) ficam no serviço; aqui só os limites de tamanho e de forma. Permissão proc_editar, verificada
 * antes da validação (403 e não 422).
 */
final class GravarProcedimentoRequest extends PedidoPOS
{
    protected function permissoes(): array
    {
        return ['proc_editar'];
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'titulo' => ['nullable', 'string', 'max:200'],
            'objetivo' => ['nullable', 'string', 'max:5000'],
            'intervenientes' => ['nullable', 'array', 'max:30'],
            'intervenientes.*' => ['nullable', 'string', 'max:120'],
            'etapas' => ['nullable', 'array', 'max:100'],
            'etapas.*.nome' => ['nullable', 'string', 'max:200'],
            'etapas.*.quem' => ['nullable', 'string', 'max:200'],
            'etapas.*.descricao' => ['nullable', 'string', 'max:5000'],
            'etapas.*.controlos' => ['nullable', 'string', 'max:5000'],
            'etapas.*.resultado' => ['nullable', 'string', 'max:5000'],
            'cruza' => ['nullable', 'array', 'max:100'],
            'cruza.*.com' => ['nullable', 'string', 'max:40'],
            'cruza.*.onde' => ['nullable', 'string', 'max:200'],
            'cruza.*.o_que' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
