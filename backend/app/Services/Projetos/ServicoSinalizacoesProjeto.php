<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\SinalizacaoProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de sinalizações dos projectos (por empresa, com cor) — modal «Etiquetas e sinalizações dos projectos».
 * Apagar uma sinalização do catálogo retira-a de todos os projectos que a tinham (cascata na tabela de associação).
 * Assinalar/desassinalar um projecto concreto é responsabilidade de ServicoProjetos (guarda motivo, quem e quando).
 */
final class ServicoSinalizacoesProjeto
{
    public function listar(): Collection
    {
        return SinalizacaoProjeto::query()->orderBy('nome')->get();
    }

    public function guardar(array $d, ?SinalizacaoProjeto $s = null): SinalizacaoProjeto
    {
        return DB::transaction(function () use ($d, $s) {
            if ($s) {
                $s = SinalizacaoProjeto::query()->lockForUpdate()->findOrFail($s->id);
            }
            $nome = trim((string) ($d['nome'] ?? $s?->nome ?? ''));
            if ($nome === '') {
                throw new ErroNegocio('Indique o nome da sinalização.', 'VALIDACAO', 422);
            }
            if (SinalizacaoProjeto::query()->whereRaw('lower(nome) = lower(?)', [$nome])->when($s, fn ($q) => $q->whereKeyNot($s->id))->exists()) {
                throw new ErroNegocio("Já existe uma sinalização «{$nome}».", 'SINALIZACAO_DUPLICADA', 422);
            }
            $dados = ['nome' => $nome, 'cor' => $d['cor'] ?? $s?->cor ?? '#dc2626'];
            $s ? $s->update($dados) : $s = SinalizacaoProjeto::create($dados);

            return $s->refresh();
        });
    }

    public function eliminar(SinalizacaoProjeto $s): void
    {
        $s->delete();
    }
}
