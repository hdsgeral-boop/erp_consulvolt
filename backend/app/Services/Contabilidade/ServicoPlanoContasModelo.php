<?php

namespace App\Services\Contabilidade;

use App\Support\Cache\ChaveCache;
use App\Support\Cache\InvalidacaoCache;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Support\Facades\DB;

/**
 * Plano de contas modelo: a empresa mais antiga do tenant que opera a plataforma (CONSULVOLT) serve de modelo —
 * é copiado para toda a empresa nova (registo público ou criada pela plataforma/por um cliente). Cada empresa fica
 * depois com a sua própria cópia, independente: editar ou acrescentar contas numa empresa nunca afecta as outras
 * nem o modelo (ao contrário de um plano de contas partilhado, que teria de ser igual para todos).
 *
 * A leitura do modelo é de outro tenant (RLS de plano_contas/empresas — IsolamentoRls), por isso corre sob
 * ContextoTenant::executarComo() com o tenant da plataforma; a escrita na empresa nova corre já fora disso, com o
 * contexto da empresa que a criou (a WITH CHECK da RLS exige que a nova linha pertença a uma empresa desse tenant).
 */
final class ServicoPlanoContasModelo
{
    public function __construct(private readonly ContextoTenant $tenant) {}

    public function semear(int $empresaId): void
    {
        $plataformaTenantId = (int) config('erp.dominio.plataforma_tenant_id', 1);

        $linhas = $this->tenant->executarComo($plataformaTenantId, function () use ($empresaId, $plataformaTenantId) {
            // tenant_id explícito, não só o contexto RLS (regra do projecto: nunca confiar só na RLS — ver
            // ServicoUtilizadores/IsolamentoRls) — importa sobretudo em testes, onde a ligação é a do dono e ignora a RLS.
            $modelo = DB::table('empresas')->where('tenant_id', $plataformaTenantId)->whereNull('eliminado_em')->orderBy('id')->value('id');
            if ($modelo === null || (int) $modelo === $empresaId) {
                return collect();
            }

            return DB::table('plano_contas')->where('empresa_id', $modelo)->whereNull('eliminado_em')->get();
        });

        if ($linhas->isEmpty()) {
            return;
        }

        $agora = now();
        DB::table('plano_contas')->insert($linhas->map(fn ($l) => [
            'empresa_id' => $empresaId, 'codigo' => $l->codigo, 'descricao' => $l->descricao, 'tipo' => $l->tipo,
            'codigo_moeda' => $l->codigo_moeda, 'banco_nome' => $l->banco_nome, 'banco_balcao' => $l->banco_balcao,
            'banco_numero_conta' => $l->banco_numero_conta, 'banco_iban' => $l->banco_iban, 'banco_swift' => $l->banco_swift,
            'banco_titular' => $l->banco_titular, 'natureza_conta' => $l->natureza_conta,
            'criado_em' => $agora, 'atualizado_em' => $agora,
        ])->all());

        InvalidacaoCache::esquecer(ChaveCache::empresa($empresaId, 'contabilidade', 'plano_contas'));
    }

    /**
     * Backfill (ADR): semeia o plano de contas modelo em qualquer empresa, de qualquer tenant, que ainda não tenha
     * nenhuma conta própria — tipicamente empresas antigas criadas antes deste mecanismo existir (ex.: importação
     * directa, fora de ServicoGestaoEmpresas::criar()/RegistoEmpresaController). Nunca toca numa empresa que já tem
     * plano de contas (ainda que incompleto ou já personalizado) — só preenche o vazio. Percorre todos os tenants
     * (ContextoTenant::porTenant, o mesmo padrão de AvisarLicencasCommand) porque `empresas`/`plano_contas` têm RLS
     * por tenant. Devolve os ids das empresas semeadas.
     *
     * @return list<int>
     */
    public function semearEmFalta(): array
    {
        $semeadas = [];
        $this->tenant->porTenant(function () use (&$semeadas) {
            $semPlano = DB::table('empresas')
                ->whereNull('eliminado_em')
                ->whereNotIn('id', DB::table('plano_contas')->whereNull('eliminado_em')->select('empresa_id'))
                ->orderBy('id')
                ->pluck('id');

            foreach ($semPlano as $empresaId) {
                $this->semear((int) $empresaId);
                if (DB::table('plano_contas')->where('empresa_id', $empresaId)->whereNull('eliminado_em')->exists()) {
                    $semeadas[] = (int) $empresaId;
                }
            }
        });

        return $semeadas;
    }
}
