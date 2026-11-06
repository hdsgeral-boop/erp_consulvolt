<?php

namespace App\Console\Commands;

use App\Support\POS\AuditoriaMigracaoLavandaria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * F0b do POS: auditoria SÓ DE LEITURA dos dados da lavandaria migrados do legado (ver AuditoriaMigracaoLavandaria).
 * Escreve um relatório JSON e Markdown (contagens e ids, sem dados pessoais) para o administrador enviar. Não altera dados.
 * Sai com 1 se houver verificações com gravidade ERRO (útil em verificações de deploy).
 */
final class AuditarMigracaoLavandaria extends Command
{
    protected $signature = 'erp:lavandaria:auditar-migracao
        {--empresa= : Só esta empresa (id)}
        {--saida= : Pasta dos relatórios (por omissão storage/app/private/auditorias)}
        {--json : Imprime o JSON em vez do resumo}
        {--database= : Ligação a usar (por omissão a do dono da base, para ler todas as empresas apesar da RLS)}';

    protected $description = 'Audita (só leitura) a migração da lavandaria: opções de preço, modos/tamanhos, totais ordem × factura × recebimento e números duplicados';

    public function handle(): int
    {
        $ligacao = $this->option('database') ?: config('erp.saas.ligacao_dono');
        $r = (new AuditoriaMigracaoLavandaria(DB::connection($ligacao ?: null)))->auditar($this->option('empresa') ? (int) $this->option('empresa') : null);

        $pasta = $this->option('saida') ?: storage_path('app/private/auditorias');
        File::ensureDirectoryExists($pasta);
        $base = $pasta.DIRECTORY_SEPARATOR.'lavandaria_migracao_'.now()->format('Ymd_His');
        file_put_contents($base.'.json', json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        file_put_contents($base.'.md', AuditoriaMigracaoLavandaria::markdown($r));

        if ($this->option('json')) {
            $this->line(json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(['Tabela', 'Linhas'], collect($r['totais'])->map(fn ($n, $t) => [$t, $n])->values()->all());
            $this->line('Opções de preço: '.json_encode($r['estatisticas'], JSON_UNESCAPED_UNICODE));
            $this->table(['Verificação', 'Gravidade', 'Linhas'], collect($r['verificacoes'])->map(fn ($a, $c) => [$c, $a['gravidade'], $a['total']])->values()->all());
            $this->line($r['resumo']['conclusao']);
        }
        $this->info("Relatórios: {$base}.json e {$base}.md");

        return $r['resumo']['erros'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
