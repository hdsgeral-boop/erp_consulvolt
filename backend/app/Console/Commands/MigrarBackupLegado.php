<?php

namespace App\Console\Commands;

use App\Services\Logistica\ServicoMigracaoStock;
use App\Services\Migracao\ServicoMigracaoLegado;
use App\Services\POS\ServicoMigracaoPOS;
use App\Services\RH\ServicoFolhaSalarial;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * php artisan erp:migrar-backup-legado {caminho} [--simular] [--substituir]
 *
 * Migra o backup Dexie do ERP legado para o PostgreSQL (ver ServicoMigracaoLegado).
 * --simular    executa tudo e desfaz no fim (relatório completo, base intacta)
 * --substituir apaga TODOS os dados de negócio antes de migrar (exige confirmação interactiva ou --force)
 */
final class MigrarBackupLegado extends Command
{
    protected $signature = 'erp:migrar-backup-legado
        {caminho : Caminho do ficheiro JSON exportado pelo Dexie}
        {--simular : Executa e valida tudo mas desfaz no fim (ROLLBACK)}
        {--substituir : Apaga todos os dados de negócio existentes antes de migrar}
        {--force : Não pedir confirmação (uso não interactivo)}';

    protected $description = 'Migra o backup Dexie do ERP legado (JSON) para o PostgreSQL';

    public function handle(): int
    {
        $caminho = $this->argument('caminho');
        $simular = (bool) $this->option('simular');
        $substituir = (bool) $this->option('substituir');

        if ($substituir && ! $simular && ! $this->option('force')
            && ! $this->confirm('ATENÇÃO: --substituir apaga TODOS os dados de negócio da base de destino. Continuar?')) {
            $this->warn('Cancelado.');

            return self::FAILURE;
        }

        $this->info(($simular ? '[SIMULAÇÃO] ' : '')."A migrar {$caminho}");
        $memoria = memory_get_peak_usage(true);

        try {
            $servico = new ServicoMigracaoLegado($caminho, fn (string $m) => $this->line($m));
            $relatorio = $servico->executar($simular, $substituir, get_current_user() ?: 'consola');
        } catch (Throwable $e) {
            $this->error('Migração FALHADA — nenhuma alteração foi gravada (ROLLBACK).');
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ficheiro = "migracao/relatorio_{$relatorio['execucao']}.json";
        Storage::disk('local')->put($ficheiro, json_encode($relatorio, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->apresentar($relatorio);
        $this->newLine();
        $this->line(sprintf('Relatório completo: storage/app/private/%s · memória máxima: %.0f MB · duração: %s s',
            $ficheiro, max($memoria, memory_get_peak_usage(true)) / 1048576, $relatorio['duracao_segundos']));

        $problemas = $relatorio['resumo']['fks_com_orfaos'] + $relatorio['resumo']['tabelas_com_divergencia_de_contagem'];
        if ($problemas > 0) {
            $this->error('Há divergências de contagem ou órfãos: ver relatório.');

            return self::FAILURE;
        }
        $this->info($simular ? 'Simulação concluída sem erros (base de dados intacta).' : 'Migração concluída e gravada (COMMIT).');

        if (! $simular) {
            // Salários (ADR-036): o legado não guardava resultados; fotografa os períodos encerrados em modo LEGADO,
            // reconstruídos com a variante que reproduz o diário, e confere-os com os lançamentos SAL.
            $contexto = app(ContextoEmpresa::class);
            $total = ['periodos' => 0, 'confere' => 0, 'difere' => 0];
            foreach (DB::table('periodos_processamento_salarial')->distinct()->pluck('empresa_id') as $empresa) {
                $r = $contexto->executarComo((int) $empresa, fn () => DB::transaction(fn () => app(ServicoFolhaSalarial::class)->fotografarLegado()));
                foreach ($total as $k => $v) {
                    $total[$k] = $v + $r[$k];
                }
            }
            $this->info("Salários: {$total['periodos']} períodos fotografados; contabilizados que conferem com o diário: {$total['confere']}, com diferença: {$total['difere']} (ver Sistema › Validações).");

            // Stock (ADR-042): saldos por armazém do legado como verdade, acerto do histórico e custo médio inicial
            $st = ['sem_tipo' => 0, 'acertos' => 0, 'totais_corrigidos' => 0, 'com_custo' => 0, 'sem_custo' => 0];
            foreach (DB::table('produtos')->where('movimenta_stock', true)->distinct()->pluck('empresa_id') as $empresa) {
                $r = $contexto->executarComo((int) $empresa, fn () => DB::transaction(fn () => app(ServicoMigracaoStock::class)->acertar()));
                foreach ($st as $k => $v) {
                    $st[$k] = $v + $r[$k];
                }
            }
            $this->info("Stock: {$st['sem_tipo']} saída(s) de guia sem tipo classificada(s); {$st['acertos']} acerto(s) de saldo inicial; {$st['totais_corrigidos']} total(is) de produto alinhado(s); "
                ."custo médio inicial em {$st['com_custo']} produto(s), {$st['sem_custo']} sem custo conhecido (ver Sistema › Validações).");

            // POS: chaves dos JSON do legado (meios, totais do Z, talões TPA, deliberações, pagamentos) em português
            $pos = DB::transaction(fn () => app(ServicoMigracaoPOS::class)->normalizar());
            $this->info("POS: JSON normalizados em {$pos['terminais']} terminal(is), {$pos['sessoes']} sessão(ões) e {$pos['vendas']} venda(s).");
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $r */
    private function apresentar(array $r): void
    {
        $this->newLine();
        $this->table(['Indicador', 'Valor'], collect($r['resumo'])->map(fn ($v, $k) => [str_replace('_', ' ', $k), is_int($v) ? number_format($v, 0, ',', ' ') : $v])->values()->all());

        $divergentes = array_filter($r['contagens'], fn ($c) => ! $c['confere']);
        if ($divergentes) {
            $this->warn('Tabelas com divergência de contagem:');
            $this->table(['Legado', 'Destino', 'Lidas', 'Migradas', 'Quarentena'],
                collect($divergentes)->map(fn ($c, $t) => [$t, $c['destino'], $c['lidas'], $c['migradas'], $c['quarentena']])->values()->all());
        }
        if ($r['orfaos']) {
            $this->warn('FKs com órfãos:');
            $this->table(['FK', 'Órfãos'], collect($r['orfaos'])->map(fn ($n, $fk) => [$fk, $n])->values()->all());
        }

        $this->line('<comment>Equilíbrio contabilístico (D − C) por empresa:</comment>');
        $this->table(['Empresa', 'Linhas', 'Débito', 'Crédito', 'D − C', 'Arredond.', 'D − C legado', ''], collect($r['equilibrio_contabilistico'])->map(fn ($e) => [
            "#{$e['empresa_id']} ".mb_strimwidth($e['empresa'], 0, 38, '…'), number_format($e['linhas'], 0, ',', ' '),
            number_format($e['debito'], 2, ',', ' '), number_format($e['credito'], 2, ',', ' '), number_format($e['diferenca'], 2, ',', ' '),
            number_format($e['efeito_arredondamento'], 4, ',', ' '), number_format($e['diferenca_no_legado'], 4, ',', ' '),
            $e['equilibrada'] ? 'OK' : 'DESEQUILIBRADA',
        ])->all());

        $this->line('<comment>Ocorrências (tabela | regra | gravidade):</comment>');
        $this->table(['Ocorrência', 'Total'], collect($r['ocorrencias'])->map(fn ($n, $k) => [$k, $n])->values()->all());
    }
}
