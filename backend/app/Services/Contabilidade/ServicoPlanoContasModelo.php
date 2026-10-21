<?php

namespace App\Services\Contabilidade;

use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas modelo (vindo da v6, adaptado): uma empresa nova — ou uma que ainda não tenha contas — recebe a
 * ESTRUTURA genérica do plano de uma empresa modelo, e fica com a sua própria cópia, editável sem afectar ninguém.
 *
 * Empresa modelo: `erp.plano_contas.empresa_modelo` (PLANO_CONTAS_EMPRESA_MODELO) ou, sem ela, a empresa mais antiga
 * do tenant da plataforma. Como o modelo pode ser de outro cliente, copia-se só a estrutura do PGC:
 *  - contas até 6 dígitos (as mais fundas são da empresa: bancos com o n.º de conta, clientes, fornecedores);
 *  - nunca os dados bancários (banco_*) nem descrições com números longos (n.º de conta ou IBAN no texto).
 * A leitura do modelo faz-se pela ligação do dono (`erp.saas.ligacao_dono`), que não está sujeita à RLS; a escrita na
 * empresa de destino faz-se na ligação normal, no tenant dessa empresa.
 */
final class ServicoPlanoContasModelo
{
    /** Comprimento máximo do código copiado (estrutura do PGC de Angola). */
    private const MAX_DIGITOS = 6;

    public function __construct(private readonly ContextoTenant $tenant) {}

    /** Copia a estrutura do modelo para a empresa, se ela ainda não tiver nenhuma conta. Devolve quantas copiou. */
    public function semear(int $empresaId): int
    {
        $dono = DB::connection(config('erp.saas.ligacao_dono') ?: null);
        $modelo = $this->empresaModelo($dono);
        if ($modelo === null || $modelo === $empresaId) {
            return 0;
        }
        if ($dono->table('plano_contas')->where('empresa_id', $empresaId)->whereNull('eliminado_em')->exists()) {
            return 0;
        }

        $linhas = $this->estrutura($dono, $modelo);
        if ($linhas->isEmpty()) {
            return 0;
        }

        $agora = now();
        foreach ($linhas->chunk(500) as $lote) {
            DB::table('plano_contas')->insert($lote->map(fn ($l) => [
                'empresa_id' => $empresaId, 'codigo' => $l->codigo, 'descricao' => $l->descricao, 'tipo' => $l->tipo,
                'codigo_moeda' => $l->codigo_moeda, 'natureza_conta' => $l->natureza_conta,
                'criado_em' => $agora, 'atualizado_em' => $agora,
            ])->all());
        }
        InvalidacaoCache::esquecer(ChaveCache::empresa($empresaId, 'contabilidade', 'plano_contas'));

        return $linhas->count();
    }

    /**
     * Preenche as empresas (de todos os clientes) que ainda não têm nenhuma conta; nunca toca numa que já tenha.
     *
     * @return array<int, int> empresa_id => contas copiadas
     */
    public function semearEmFalta(): array
    {
        $dono = DB::connection(config('erp.saas.ligacao_dono') ?: null);
        $semPlano = $dono->table('empresas as e')
            ->whereNull('e.eliminado_em')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('plano_contas as p')->whereColumn('p.empresa_id', 'e.id')->whereNull('p.eliminado_em'))
            ->orderBy('e.id')
            ->get(['e.id', 'e.tenant_id']);

        $feitas = [];
        foreach ($semPlano as $e) {
            $copiar = fn () => $this->semear((int) $e->id);
            $n = $e->tenant_id !== null ? $this->tenant->executarComo((int) $e->tenant_id, $copiar) : $copiar();
            if ($n > 0) {
                $feitas[(int) $e->id] = $n;
            }
        }

        return $feitas;
    }

    private function empresaModelo($dono): ?int
    {
        $configurada = config('erp.plano_contas.empresa_modelo');
        if ($configurada) {
            return $dono->table('empresas')->where('id', (int) $configurada)->whereNull('eliminado_em')->exists() ? (int) $configurada : null;
        }
        $id = $dono->table('empresas')->where('tenant_id', (int) config('erp.dominio.plataforma_tenant_id', 1))
            ->whereNull('eliminado_em')->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Estrutura genérica do modelo: só códigos curtos, sem bancos nem números de conta na descrição. */
    private function estrutura($dono, int $modelo): Collection
    {
        return $dono->table('plano_contas')->where('empresa_id', $modelo)->whereNull('eliminado_em')
            ->whereRaw('length(codigo) <= ?', [self::MAX_DIGITOS])
            ->whereNull('banco_iban')->whereNull('banco_numero_conta')->whereNull('banco_nome')
            ->whereRaw("descricao !~ '[0-9]{6,}'")
            ->orderBy('codigo')
            ->get(['codigo', 'descricao', 'tipo', 'codigo_moeda', 'natureza_conta']);
    }
}
