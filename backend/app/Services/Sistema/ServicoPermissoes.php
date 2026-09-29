<?php

namespace App\Services\Sistema;

use App\Models\Utilizador;
use Normalizer;

/**
 * Avaliação de permissões com a mesma semântica do legado (js/permissoes.js:591-599):
 *
 *   total()  = papel SUPER_ADMINISTRADOR  ou  permissoes.all === true
 *   tem(k)   = total()  ou  permissoes[norm(k)] === true
 *              ou (k ∈ {rh_portal_view, rh_portal_usar} e o utilizador está ligado a um colaborador na empresa activa)
 *   norm(k)  = sem acentos, minúsculas, sem espaços nas pontas
 *
 * O catálogo de ecrãs/tarefas (116 ecrãs, ≈195 tarefas, mapas LEGADO/LEGADO_VISTA de js/permissoes.js)
 * é acrescentado com os módulos (Fase 4), sobre este núcleo.
 */
final class ServicoPermissoes
{
    private const PORTAL_AUTOMATICO = ['rh_portal_view', 'rh_portal_usar'];

    public function total(Utilizador $utilizador): bool
    {
        return $utilizador->eSuperAdministrador() || ($this->permissoes($utilizador)['all'] ?? null) === true;
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

        return in_array($k, self::PORTAL_AUTOMATICO, true)
            && $empresaId !== null
            && $this->ligadoAColaborador($utilizador, $empresaId);
    }

    /** Perfil no formato v2 (só vale o que está marcado). */
    public function formatoV2(Utilizador $utilizador): bool
    {
        return ($this->permissoes($utilizador)['_v2'] ?? null) === true;
    }

    /**
     * Permissões efectivas para o frontend (lista de chaves activas; `*` = acesso total).
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

    /** @return array<string, mixed> */
    private function permissoes(Utilizador $utilizador): array
    {
        return $utilizador->perfil?->permissoes ?? [];
    }

    private function ligadoAColaborador(Utilizador $utilizador, int $empresaId): bool
    {
        return $utilizador->empresas()
            ->where('empresas.id', $empresaId)
            ->whereNotNull('utilizador_empresa.colaborador_id')
            ->exists();
    }
}
