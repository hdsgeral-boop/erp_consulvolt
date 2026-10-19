<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use Illuminate\Support\Facades\DB;

/**
 * Versões dos planos: rascunho, edição, publicação. Uma versão publicada nunca se altera: para mudar, cria-se um
 * rascunho (cópia da versão indicada ou da em vigor). Publicar copia preço e limites para o plano (usados na cobrança
 * e nos limites) e descontinua a versão anterior. Os módulos ficam registados na versão (catálogo em config/erp.php).
 */
final class ServicoVersoesPlano
{
    public function emVigor(PlanoLicenca $plano): ?PlanoLicencaVersao
    {
        return PlanoLicencaVersao::query()->where('plano_id', $plano->id)->where('estado', PlanoLicencaVersao::PUBLICADA)->orderByDesc('numero')->first();
    }

    public function criarRascunho(PlanoLicenca $plano, ?int $copiarDe = null): PlanoLicencaVersao
    {
        $origem = $copiarDe ? PlanoLicencaVersao::query()->where('plano_id', $plano->id)->findOrFail($copiarDe) : $this->emVigor($plano);

        return DB::transaction(function () use ($plano, $origem) {
            $numero = (int) PlanoLicencaVersao::query()->where('plano_id', $plano->id)->max('numero') + 1;

            return PlanoLicencaVersao::query()->create([
                'plano_id' => $plano->id, 'numero' => $numero, 'estado' => PlanoLicencaVersao::RASCUNHO,
                'preco_mensal' => $origem?->preco_mensal ?? $plano->preco_mensal, 'moeda' => $origem?->moeda ?? $plano->moeda,
                'max_empresas' => $origem ? $origem->max_empresas : $plano->max_empresas,
                'max_utilizadores' => $origem ? $origem->max_utilizadores : $plano->max_utilizadores,
                'modulos' => $origem?->modulos ?? [],
            ])->refresh();
        });
    }

    /** @param  array<string, mixed>  $d */
    public function atualizarRascunho(PlanoLicencaVersao $versao, array $d): PlanoLicencaVersao
    {
        if ($versao->estado !== PlanoLicencaVersao::RASCUNHO) {
            throw new ErroNegocio('Uma versão publicada não se altera. Crie um rascunho novo a partir dela.', 'VERSAO_PUBLICADA', 422);
        }
        $versao->update(array_intersect_key($d, array_flip(['preco_mensal', 'moeda', 'max_empresas', 'max_utilizadores', 'modulos'])));

        return $versao->refresh();
    }

    public function publicar(PlanoLicencaVersao $versao): PlanoLicencaVersao
    {
        if ($versao->estado !== PlanoLicencaVersao::RASCUNHO) {
            throw new ErroNegocio('Só um rascunho pode ser publicado.', 'VERSAO_NAO_RASCUNHO', 422);
        }

        return DB::transaction(function () use ($versao) {
            PlanoLicencaVersao::query()->where('plano_id', $versao->plano_id)->where('estado', PlanoLicencaVersao::PUBLICADA)
                ->update(['estado' => PlanoLicencaVersao::DESCONTINUADA]);
            $versao->update(['estado' => PlanoLicencaVersao::PUBLICADA, 'publicada_em' => now()]);
            PlanoLicenca::query()->whereKey($versao->plano_id)->update([
                'preco_mensal' => $versao->preco_mensal, 'moeda' => $versao->moeda,
                'max_empresas' => $versao->max_empresas, 'max_utilizadores' => $versao->max_utilizadores,
            ]);

            return $versao->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function apresentar(PlanoLicencaVersao $v): array
    {
        return [
            'id' => $v->id, 'plano_id' => $v->plano_id, 'numero' => $v->numero, 'estado' => $v->estado,
            'preco_mensal' => (float) $v->preco_mensal, 'moeda' => $v->moeda,
            'max_empresas' => $v->max_empresas, 'max_utilizadores' => $v->max_utilizadores,
            'modulos' => $v->modulos ?? [], 'publicada_em' => $v->publicada_em?->toIso8601String(),
        ];
    }
}