<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\EtiquetaProjeto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de etiquetas dos projectos (por empresa, com cor) — modal «Etiquetas e sinalizações dos projectos».
 * Apagar uma etiqueta do catálogo retira-a de todos os projectos que a tinham (cascata na tabela de associação).
 */
final class ServicoEtiquetasProjeto
{
    public function listar(): Collection
    {
        return EtiquetaProjeto::query()->orderBy('nome')->get();
    }

    public function guardar(array $d, ?EtiquetaProjeto $e = null): EtiquetaProjeto
    {
        return DB::transaction(function () use ($d, $e) {
            if ($e) {
                $e = EtiquetaProjeto::query()->lockForUpdate()->findOrFail($e->id);
            }
            $nome = trim((string) ($d['nome'] ?? $e?->nome ?? ''));
            if ($nome === '') {
                throw new ErroNegocio('Indique o nome da etiqueta.', 'VALIDACAO', 422);
            }
            if (EtiquetaProjeto::query()->whereRaw('lower(nome) = lower(?)', [$nome])->when($e, fn ($q) => $q->whereKeyNot($e->id))->exists()) {
                throw new ErroNegocio("Já existe uma etiqueta «{$nome}».", 'ETIQUETA_DUPLICADA', 422);
            }
            $dados = ['nome' => $nome, 'cor' => $d['cor'] ?? $e?->cor ?? '#2563eb'];
            $e ? $e->update($dados) : $e = EtiquetaProjeto::create($dados);

            return $e->refresh();
        });
    }

    public function eliminar(EtiquetaProjeto $e): void
    {
        $e->delete();
    }
}
