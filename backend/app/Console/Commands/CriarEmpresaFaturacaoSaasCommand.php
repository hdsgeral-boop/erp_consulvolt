<?php

namespace App\Console\Commands;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Utilizador;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Logistica\ServicoProdutos;
use App\Services\Sistema\ServicoGestaoEmpresas;
use App\Support\Tenancy\ContextoEmpresa;
use App\Support\Tenancy\ContextoTenant;
use Illuminate\Console\Command;

/**
 * Cria (idempotente) a empresa de facturação SaaS da plataforma: a empresa à qual se emitem as facturas AGT das
 * subscrições pagas pelos clientes (motor de precificação, Módulo D — ServicoFaturacaoSaas). Reaproveita 100% o
 * pipeline fiscal já existente (ServicoDocumentosVenda), em vez de inventar um documento não-certificado.
 *
 * Cria só a conta de clientes (classe 31) — a conta de proveitos/IVA do produto fica em branco: a contabilização
 * da factura (lançamentos) é sempre um passo manual explícito nesta aplicação (DocumentoVendaController::contabilizar,
 * igual para todos os módulos de vendas) e não há nenhuma conta por omissão segura a adivinhar aqui.
 */
final class CriarEmpresaFaturacaoSaasCommand extends Command
{
    private const CODIGO_CONTA_CLIENTE = '31.9.9';

    protected $signature = 'erp:saas:criar-empresa-faturacao
        {--nome= : Nome da empresa de facturação (omitido: "Consulvolt - Tecnologias, Lda")}
        {--nif= : NIF real da empresa (obrigatório na primeira execução)}';

    protected $description = 'Cria a empresa da plataforma que emite as facturas AGT das subscrições SaaS pagas';

    public function __construct(
        private readonly ContextoTenant $tenant,
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoGestaoEmpresas $empresas,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoProdutos $produtos,
        private readonly ServicoExercicios $exercicios,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $plataformaTenantId = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $nif = $this->option('nif');

        return $this->tenant->executarComo($plataformaTenantId, function () use ($plataformaTenantId, $nif) {
            $existente = $nif ? Empresa::query()->where('nif', trim($nif))->first() : null;
            if ($existente === null && (int) config('erp.faturacao_saas.empresa_id', 0) > 0) {
                $existente = Empresa::query()->find((int) config('erp.faturacao_saas.empresa_id'));
            }

            if ($existente !== null) {
                $this->info("Empresa de facturação SaaS já existe (#{$existente->id}, NIF {$existente->nif}) — nada a criar.");

                return $this->imprimirResumo($existente->id);
            }

            if (! $nif) {
                $this->error('Indique --nif=<NIF real da Consulvolt> na primeira execução.');

                return self::FAILURE;
            }

            $actor = Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->where('tenant_id', $plataformaTenantId)->first();
            if ($actor === null) {
                $this->error('Nenhum super-administrador da plataforma encontrado para criar a empresa.');

                return self::FAILURE;
            }

            $nome = $this->option('nome') ?: 'Consulvolt - Tecnologias, Lda';
            $empresa = $this->empresas->criar(['nome' => $nome, 'nif' => trim($nif)], $actor);
            $this->info("Empresa de facturação SaaS criada: #{$empresa->id} «{$empresa->nome}».");

            return $this->imprimirResumo($empresa->id);
        });
    }

    private function imprimirResumo(int $empresaId): int
    {
        return $this->contexto->executarComo($empresaId, function () use ($empresaId) {
            $conta = PlanoConta::query()->where('codigo', self::CODIGO_CONTA_CLIENTE)->first()
                ?? $this->plano->criar(['codigo' => self::CODIGO_CONTA_CLIENTE, 'descricao' => 'Clientes SaaS (Plataforma)', 'tipo' => PlanoConta::TIPO_MOVIMENTO]);

            $produto = Produto::query()->where('codigo', 'SAAS-SUBSCRICAO')->first()
                ?? $this->produtos->guardar([
                    'codigo' => 'SAAS-SUBSCRICAO', 'nome' => 'Subscrição SaaS',
                    'preco_unitario' => 0, 'taxa_imposto' => 14, 'e_servico' => true, 'movimenta_stock' => false,
                ]);

            $this->exercicios->reabrir($empresaId, (int) now()->year);

            $this->table(['Chave .env', 'Valor'], [
                ['SAAS_FATURACAO_EMPRESA_ID', $empresaId],
                ['SAAS_FATURACAO_PRODUTO_ID', $produto->id],
                ['SAAS_FATURACAO_CONTA_CLIENTE', $conta->codigo],
            ]);
            $this->warn('Coloque estas 3 chaves no .env e reinicie a aplicação para activar a emissão automática de facturas.');

            return self::SUCCESS;
        });
    }
}
