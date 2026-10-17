<?php

namespace App\Console\Commands;

use App\Services\Logistica\ServicoConversaoContasCategorias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ronda 3 — converte as contas contabilísticas dos produtos para categorias (ServicoConversaoContasCategorias). Separado da
 * migração de esquema (que é só aditiva); idempotente (um produto já convertido fica registado e não volta a ser tratado);
 * --simular mostra o resultado e desfaz tudo (ROLLBACK). O relatório (categorias actualizadas/criadas, produtos associados,
 * avisos de contas inexistentes/totalizadoras) é impresso, gravado no disco local (relatorios/conversao_contas_categorias) e,
 * fora da simulação, fica em registos_conversao_contas_produtos. As contas gravadas nos produtos não são apagadas.
 */
final class ConverterContasProdutosCategorias extends Command
{
    protected $signature = 'erp:produtos:converter-contas-categorias
        {--empresa=* : id(s) da(s) empresa(s); por omissão, todas}
        {--simular : mostra o que seria feito e desfaz tudo (ROLLBACK)}';

    protected $description = 'Passa as contas contabilísticas dos produtos para as categorias (cria categorias por combinação de contas) e associa cada produto';

    public function handle(ServicoConversaoContasCategorias $servico, ContextoEmpresa $contexto): int
    {
        $simular = (bool) $this->option('simular');
        $pedidas = array_map('intval', (array) $this->option('empresa'));
        $empresas = DB::table('empresas')->when($pedidas, fn ($q) => $q->whereIn('id', $pedidas))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (! $empresas) {
            $this->error('Nenhuma empresa encontrada.');

            return self::FAILURE;
        }
        $falhas = 0;
        foreach ($empresas as $empresa) {
            DB::beginTransaction();
            try {
                $r = $contexto->executarComo($empresa, fn () => $servico->converter('consola'.($simular ? ' (simulação)' : '')));
                $simular ? DB::rollBack() : DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error("Empresa {$empresa}: NÃO CONVERTIDA — {$e->getMessage()}");
                $falhas++;

                continue;
            }
            $this->relatar($r, $simular);
        }

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, mixed>  $r */
    private function relatar(array $r, bool $simular): void
    {
        $this->info(($simular ? '[SIMULAÇÃO] ' : '')."Empresa {$r['empresa_id']}: {$r['produtos_analisados']} produto(s) analisado(s), {$r['ja_convertidos']} já convertido(s) antes, "
            ."{$r['produtos_movidos']} mudado(s) de categoria.");
        $contas = fn (array $c) => implode(' ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys(array_filter($c)), array_filter($c))) ?: '(sem contas: usa as contas por omissão)';
        foreach ($r['categorias_actualizadas'] as $c) {
            $this->line("  · categoria «{$c['nome']}» recebe as contas: ".$contas($c['contas']));
        }
        foreach ($r['categorias_criadas'] as $c) {
            $this->line("  + categoria criada «{$c['nome']}»".($c['origem'] ? " (de «{$c['origem']}»)" : '').': '.$contas($c['contas']));
        }
        foreach ($r['avisos'] as $a) {
            $this->warn("  ! {$a}");
        }
        $ficheiro = sprintf('relatorios/conversao_contas_categorias/empresa_%d_%s%s.json', $r['empresa_id'], now()->format('Ymd_His'), $simular ? '_simulacao' : '');
        Storage::disk('local')->put($ficheiro, json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->line('  Relatório: '.Storage::disk('local')->path($ficheiro).($simular ? ' — nada foi gravado (ROLLBACK).' : ''));
    }
}
