<?php

namespace App\Services\Sistema;

use App\Models\Utilizador;
use Normalizer;

/**
 * Avaliação de permissões com a semântica do legado (js/permissoes.js:591-643), sobre o catálogo
 * extraído do próprio legado (CatalogoPermissoes):
 *
 *   total()        = papel SUPER_ADMINISTRADOR  ou  permissoes.all === true
 *   tem(k)         = total() ou permissoes[norm(k)] === true
 *                    ou (k ∈ {rh_portal_view, rh_portal_usar} e utilizador ligado a um colaborador na empresa activa)
 *   pode(tarefa)   = tem(tarefa) ou alguma tarefa agrupada pela chave antiga (LEGADO)
 *   podeVer(ecrã)  = ecrã visível: tem("<ecrã>_view") ou alguma tarefa do ecrã ou algum ecrã-filho visível
 *                    (vistas do legado sem ecrã: LEGADO_VISTA)
 *
 * Todos os perfis estão no formato v2: os antigos foram convertidos na migração com o converterAntigo do
 * próprio legado (ferramentas/levantamento/converter_perfis_antigos.mjs).
 */
final class ServicoPermissoes
{
    private const PORTAL_AUTOMATICO = ['rh_portal_view', 'rh_portal_usar'];

    /** @var array<string, bool> memória por pedido: "utilizador|empresa" => ligado a colaborador */
    private array $ligacoes = [];

    public function total(Utilizador $utilizador): bool
    {
        return $utilizador->eSuperAdministrador() || ($this->permissoes($utilizador)['all'] ?? null) === true;
    }

    /**
     * Administrador para efeitos de aprovação (papelAdmin, js/manutencao.js:21): papel SUPER_ADMINISTRADOR ou
     * ADMINISTRADOR, ou perfil de acesso total (no legado, o perfil n.º 1 "Super Administrador").
     */
    public function administrador(Utilizador $utilizador): bool
    {
        return in_array($utilizador->papel, [Utilizador::PAPEL_SUPER_ADMINISTRADOR, Utilizador::PAPEL_ADMINISTRADOR], true) || $this->total($utilizador);
    }

    public function tem(Utilizador $utilizador, string $chave, ?int $empresaId = null): bool
    {
        if ($this->total($utilizador)) {
            return true;
        }
        $k = self::norm($chave);
        if (($this->permissoes($utilizador)[$k] ?? null) === true) {
            return true;
        }

        return in_array($k, self::PORTAL_AUTOMATICO, true) && $empresaId !== null && $this->ligadoAColaborador($utilizador, $empresaId);
    }

    /** Tarefa (acção de negócio) — equivalente a window.can / permTem do legado. */
    public function pode(Utilizador $utilizador, string $tarefa, ?int $empresaId = null): bool
    {
        if ($this->tem($utilizador, $tarefa, $empresaId)) {
            return true;
        }
        foreach (CatalogoPermissoes::LEGADO[self::norm($tarefa)] ?? [] as $agrupada) {
            if ($this->tem($utilizador, $agrupada, $empresaId)) {
                return true;
            }
        }

        return false;
    }

    /** Consulta de um ecrã/vista — equivalente a window.hasView (formato v2) do legado. */
    public function podeVer(Utilizador $utilizador, string $vista, ?int $empresaId = null): bool
    {
        if ($vista === 'welcome' || $this->total($utilizador)) {
            return true;
        }
        $k = self::norm($vista);
        $ecra = CatalogoPermissoes::ecraDaVista($k);
        if ($ecra !== null) {
            return $this->ecraVisivel($utilizador, $ecra, $empresaId);
        }
        if (isset(CatalogoPermissoes::LEGADO_VISTA[$k])) {
            foreach (CatalogoPermissoes::LEGADO_VISTA[$k] as $v) {
                if ($this->podeVer($utilizador, $v, $empresaId)) {
                    return true;
                }
            }

            return false;
        }

        return $this->tem($utilizador, $k, $empresaId) || $this->tem($utilizador, "{$k}_view", $empresaId);
    }

    /**
     * Ability do Gate: "<ecrã>_view" é uma consulta de ecrã; qualquer outra chave é uma tarefa.
     */
    public function autoriza(Utilizador $utilizador, string $habilidade, ?int $empresaId = null): bool
    {
        $k = self::norm($habilidade);
        if (str_ends_with($k, '_view') && CatalogoPermissoes::ecraDaVista(substr($k, 0, -5)) !== null) {
            return $this->podeVer($utilizador, substr($k, 0, -5), $empresaId);
        }

        return $this->pode($utilizador, $k, $empresaId);
    }

    public function formatoV2(Utilizador $utilizador): bool
    {
        return ($this->permissoes($utilizador)['_v2'] ?? null) === true;
    }

    /**
     * Permissões efectivas para o frontend (chaves activas; `*` = acesso total).
     *
     * @return list<string>
     */
    public function efectivas(Utilizador $utilizador, ?int $empresaId = null): array
    {
        if ($this->total($utilizador)) {
            return ['*'];
        }
        $chaves = array_keys(array_filter($this->permissoes($utilizador), fn ($v, $k) => $v === true && $k !== '_v2', ARRAY_FILTER_USE_BOTH));
        if ($empresaId !== null && $this->ligadoAColaborador($utilizador, $empresaId)) {
            $chaves = array_merge($chaves, self::PORTAL_AUTOMATICO);
        }
        $chaves = array_values(array_unique(array_map(self::norm(...), $chaves)));
        sort($chaves);

        return $chaves;
    }

    public static function norm(string $chave): string
    {
        $decomposta = Normalizer::normalize($chave, Normalizer::FORM_D) ?: $chave;

        return trim(mb_strtolower(preg_replace('/\p{Mn}+/u', '', $decomposta)));
    }

    /** @param  array<string, mixed>  $ecra */
    private function ecraVisivel(Utilizador $utilizador, array $ecra, ?int $empresaId): bool
    {
        if ($this->tem($utilizador, "{$ecra['id']}_view", $empresaId)) {
            return true;
        }
        foreach ($ecra['tarefas'] as $t) {
            if ($this->tem($utilizador, $t['chave'], $empresaId)) {
                return true;
            }
        }
        foreach (CatalogoPermissoes::filhos($ecra['id']) as $filho) {
            if ($this->ecraVisivel($utilizador, $filho, $empresaId)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function permissoes(Utilizador $utilizador): array
    {
        return $utilizador->perfil?->permissoes ?? [];
    }

    private function ligadoAColaborador(Utilizador $utilizador, int $empresaId): bool
    {
        return $this->ligacoes["{$utilizador->getKey()}|{$empresaId}"] ??= $utilizador->empresas()
            ->where('empresas.id', $empresaId)
            ->whereNotNull('utilizador_empresa.colaborador_id')
            ->exists();
    }
}
