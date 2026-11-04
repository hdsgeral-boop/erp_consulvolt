<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\PlanoLicenca;
use App\Models\PlanoLicencaVersao;
use App\Models\Tenant;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ModulosPlano;
use Illuminate\Support\Facades\DB;

/**
 * Versões dos planos: rascunho, edição, publicação. Uma versão publicada nunca se altera: para mudar, cria-se um
 * rascunho (cópia da versão indicada ou da em vigor). Publicar copia preço e limites para o plano (usados na cobrança
 * e nos limites) e descontinua a versão anterior. Os módulos ficam registados na versão (catálogo em config/erp.php).
 */
final class ServicoVersoesPlano
{
    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    public function emVigor(PlanoLicenca $plano): ?PlanoLicencaVersao
    {
        return PlanoLicencaVersao::query()->where('plano_id', $plano->id)->where('estado', PlanoLicencaVersao::PUBLICADA)->orderByDesc('numero')->first();
    }

    public function clientesDoPlano(PlanoLicenca $plano): int
    {
        return Tenant::query()->where('plano', $plano->codigo)->count();
    }

    public function temVersaoPublicada(PlanoLicenca $plano): bool
    {
        return $this->emVigor($plano) !== null;
    }

    /**
     * Cria o plano e a sua PRIMEIRA versão em rascunho. O preço e os limites só passam a valer ao publicar a versão;
     * até lá o plano fica INACTIVO (não é escolhível nem renovável), mesmo que se peça activo.
     *
     * @param  array<string, mixed>  $d
     */
    public function criarPlano(array $d): PlanoLicenca
    {
        return DB::transaction(function () use ($d) {
            $plano = PlanoLicenca::query()->create([
                'codigo' => $d['codigo'], 'nome' => $d['nome'], 'descricao' => $d['descricao'] ?? null,
                'preco_mensal' => $d['preco_mensal'], 'moeda' => $d['moeda'],
                'max_empresas' => $d['max_empresas'] ?? null, 'max_utilizadores' => $d['max_utilizadores'] ?? null,
                'dias_teste' => $d['dias_teste'] ?? null,
                'ativo' => false, 'ordem' => $d['ordem'] ?? 0,
            ]);
            PlanoLicencaVersao::query()->create([
                'plano_id' => $plano->id, 'numero' => 1, 'estado' => PlanoLicencaVersao::RASCUNHO,
                'preco_mensal' => $d['preco_mensal'], 'moeda' => $d['moeda'],
                'max_empresas' => $d['max_empresas'] ?? null, 'max_utilizadores' => $d['max_utilizadores'] ?? null,
                'dias_teste' => $d['dias_teste'] ?? null,
                'modulos' => array_values(array_unique($d['modulos'] ?? [])),
            ]);
            $this->auditoria->registar('Plataforma', 'Criou plano', "Plano {$plano->codigo} criado (versão 1 em rascunho).", 'planos_licenca', $plano->id, null, ['codigo' => $plano->codigo, 'nome' => $plano->nome]);

            return $plano->refresh();
        });
    }

    /**
     * Altera nome, descrição, estado e ordem (o código nunca muda; preço e limites só por versão).
     * Activar exige uma versão publicada; desactivar um plano em uso exige `confirmar_desativacao` (os clientes
     * mantêm o plano, mas ele deixa de poder ser escolhido/renovado nas empresas).
     *
     * @param  array<string, mixed>  $d
     */
    public function atualizarPlano(PlanoLicenca $plano, array $d): PlanoLicenca
    {
        return DB::transaction(function () use ($plano, $d) {
            $plano = PlanoLicenca::query()->lockForUpdate()->findOrFail($plano->id);
            if (($d['ativo'] ?? null) === true && ! $plano->ativo && ! $this->temVersaoPublicada($plano)) {
                throw new ErroNegocio('Publique uma versão antes de activar o plano.', 'PLANO_SEM_VERSAO_PUBLICADA', 422);
            }
            if (($d['ativo'] ?? null) === false && $plano->ativo) {
                $emUso = $this->clientesDoPlano($plano);
                if ($emUso > 0 && empty($d['confirmar_desativacao'])) {
                    throw new ErroNegocio("O plano está atribuído a {$emUso} cliente(s). Desactivar impede novas escolhas e renovações, mas não retira o plano aos clientes. Confirme para continuar.", 'PLANO_EM_USO', 422);
                }
            }
            $campos = ['nome', 'descricao', 'ativo', 'ordem'];
            $antes = $plano->only($campos);
            $plano->fill(array_intersect_key($d, array_flip($campos)))->save();
            $this->auditoria->registar('Plataforma', 'Atualizou plano', "Plano {$plano->codigo} actualizado.", 'planos_licenca', $plano->id, $antes, $plano->only($campos));

            return $plano->refresh();
        });
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
                'dias_teste' => $origem ? $origem->dias_teste : $plano->dias_teste,
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
        $versao->update(array_intersect_key($d, array_flip(['preco_mensal', 'moeda', 'max_empresas', 'max_utilizadores', 'dias_teste', 'modulos'])));

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
                'dias_teste' => $versao->dias_teste,
            ]);
            // Os módulos em vigor mudaram: ModulosPlano tem o mapa código do plano => módulos em cache (CACHE.md regra 3).
            ModulosPlano::invalidar();

            return $versao->refresh();
        });
    }

    /** @return array<string, mixed> */
    public function apresentar(PlanoLicencaVersao $v): array
    {
        return [
            'id' => $v->id, 'plano_id' => $v->plano_id, 'numero' => $v->numero, 'estado' => $v->estado,
            'preco_mensal' => (float) $v->preco_mensal, 'moeda' => $v->moeda,
            'max_empresas' => $v->max_empresas, 'max_utilizadores' => $v->max_utilizadores, 'dias_teste' => $v->dias_teste,
            'modulos' => $v->modulos ?? [], 'publicada_em' => $v->publicada_em?->toIso8601String(),
        ];
    }
}
