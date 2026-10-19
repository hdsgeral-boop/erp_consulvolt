<?php

namespace App\Console\Commands;

use App\Services\Vendas\ServicoDocumentosImportadosSaft;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Marca os documentos migrados do sistema anterior como integrados de outro sistema (SAF-T SourceBilling «I») e grava o
 * n.º no formato SAF-T dos que não cumprem o padrão (ex.: «FR-POS/…», «FT-LAV/…», «GR LOG …»). O n.º original e os
 * campos fiscais nunca são alterados. Idempotente; --simular mostra o relatório e desfaz tudo (ROLLBACK).
 */
final class DocumentosImportadosSaftCommand extends Command
{
    protected $signature = 'erp:saft:documentos-importados
        {--empresa=* : id(s) da(s) empresa(s); por omissão, todas}
        {--sistema= : nome do sistema de origem (por omissão AGT_SISTEMA_ORIGEM_LEGADO)}
        {--simular : mostra o que seria feito e desfaz tudo (ROLLBACK)}';

    protected $description = 'Marca os documentos integrados de outro sistema (SAF-T SourceBilling I) e o n.º SAF-T dos que não cumprem o padrão';

    public function handle(ServicoDocumentosImportadosSaft $servico, ContextoEmpresa $contexto): int
    {
        $simular = (bool) $this->option('simular');
        $pedidas = array_map('intval', (array) $this->option('empresa'));
        $empresas = DB::table('empresas')->when($pedidas, fn ($q) => $q->whereIn('id', $pedidas))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $falhas = 0;
        foreach ($empresas as $empresa) {
            DB::beginTransaction();
            try {
                $r = $contexto->executarComo($empresa, fn () => $servico->marcar($simular, $this->option('sistema') ?: null));
                $simular ? DB::rollBack() : DB::commit();
            } catch (Throwable $e) {
                DB::rollBack();
                $this->error("Empresa {$empresa}: NÃO MARCADA — {$e->getMessage()}");
                $falhas++;

                continue;
            }
            if (! $r['marcados']) {
                continue;
            }
            $this->info(($simular ? '[SIMULAÇÃO] ' : '')."Empresa {$empresa}: {$r['marcados']} documento(s) integrado(s); {$r['numeros_saft']} com n.º SAF-T próprio.");
            foreach ($r['documentos'] as $d) {
                if ($d['saft'] !== $d['original']) {
                    $this->line("  · {$d['tipo']} #{$d['id']}: «{$d['original']}» → SAF-T «{$d['saft']}»");
                }
            }
            foreach ($r['conflitos'] as $c) {
                $this->warn("  ! {$c}");
            }
        }

        return $falhas ? self::FAILURE : self::SUCCESS;
    }
}
