<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Os itens de produtividade deixam de pertencer ao contrato: a elegibilidade de cada colaborador vive só na tabela
 * `elegibilidade_produtividade_rh` (ecrã Produtividade > Elegibilidade). Decisão do legado (2026-10-30): «os itens de
 * produtividade não devem estar associados ao contrato».
 *
 * 1) ADITIVO — a restrição de origem passa a aceitar 'CONTRATO' e cada item do JSON `contratos_trabalho.produtividade` é
 *    copiado para a elegibilidade do colaborador (preço próprio só quando > 0, origem 'CONTRATO', activo), sem duplicar o que
 *    já existe. Contratos não activos só migram se o colaborador tem um contrato activo; senão são ignorados e contados.
 * 2) DESTRUTIVO — só depois, elimina-se a coluna `produtividade` de `contratos_trabalho`.
 * As contagens (migrados, já existentes, ignorados) vão para o log e para a saída do comando. Idempotente: sem a coluna,
 * só garante a restrição. Reversível: down() repõe a coluna e reconstrói o JSON a partir das linhas origem 'CONTRATO' (no
 * contrato activo mais recente de cada colaborador) e retira essas linhas, repondo a restrição antiga.
 * Corre como dono das tabelas (as políticas RLS não se aplicam ao dono).
 */
return new class extends Migration
{
    /** @var array{migrados: int, existentes: int, ignorados: int}|null contagens da última execução de up() */
    public ?array $resultado = null;

    public function up(): void
    {
        $this->restricaoOrigem(['MANUAL', 'ENTREGAVEL', 'CONTRATO']);
        if (! Schema::hasColumn('contratos_trabalho', 'produtividade')) {
            return;
        }

        $migrados = $existentes = $ignorados = 0;
        $contratos = DB::table('contratos_trabalho')->whereNotNull('produtividade')->whereRaw("jsonb_typeof(produtividade) = 'array' AND jsonb_array_length(produtividade) > 0")
            ->get(['id', 'empresa_id', 'colaborador_id', 'estado', 'produtividade']);
        $comActivo = DB::table('contratos_trabalho')->where('estado', 'ACTIVO')->pluck('colaborador_id')->flip();
        $itens = DB::table('itens_produtividade_rh')->pluck('empresa_id', 'id');
        // os contratos não activos primeiro: o preço do contrato activo prevalece (a linha já criada não é duplicada nem alterada)
        $ordenados = $contratos->sortBy(fn ($c) => $c->estado === 'ACTIVO' ? 1 : 0)->values();
        foreach ($ordenados as $c) {
            $lista = json_decode((string) $c->produtividade, true) ?: [];
            $activo = $c->estado === 'ACTIVO';
            if (! $activo && ! isset($comActivo[$c->colaborador_id])) {
                $ignorados += count($lista);

                continue;
            }
            foreach ($lista as $x) {
                $item = (int) ($x['item_id'] ?? 0);
                if ($item <= 0 || ! isset($itens[$item]) || (int) $itens[$item] !== (int) $c->empresa_id) {
                    $ignorados++;

                    continue;
                }
                $preco = isset($x['preco_unitario']) && (float) $x['preco_unitario'] > 0 ? round((float) $x['preco_unitario'], 4) : null;
                $existe = DB::table('elegibilidade_produtividade_rh')->where('empresa_id', $c->empresa_id)->where('colaborador_id', $c->colaborador_id)->where('item_produtividade_id', $item)->first();
                if ($existe) {
                    $existentes++;
                    if ($existe->origem === 'CONTRATO' && $activo && $preco !== null) {
                        DB::table('elegibilidade_produtividade_rh')->where('id', $existe->id)->update(['preco_unitario' => $preco]);
                    }

                    continue;
                }
                DB::table('elegibilidade_produtividade_rh')->insert(['empresa_id' => $c->empresa_id, 'colaborador_id' => $c->colaborador_id, 'item_produtividade_id' => $item,
                    'ativo' => true, 'origem' => 'CONTRATO', 'preco_unitario' => $preco, 'nota' => 'Migrado do contrato #'.$c->id, 'criado_por' => 'migracao']);
                $migrados++;
            }
        }

        $msg = "produtividade_sai_do_contrato: {$migrados} item(ns) migrado(s) para a elegibilidade, {$existentes} já existia(m), {$ignorados} ignorado(s) (contrato não activo sem contrato activo do colaborador, ou item inexistente).";
        Log::info($msg);
        $this->resultado = ['migrados' => $migrados, 'existentes' => $existentes, 'ignorados' => $ignorados];
        if (($migrados + $existentes + $ignorados) > 0 && ! app()->runningUnitTests()) {
            fwrite(STDERR, '  '.$msg.PHP_EOL);
        }

        DB::statement('ALTER TABLE contratos_trabalho DROP COLUMN IF EXISTS produtividade');
    }

    public function down(): void
    {
        if (! Schema::hasColumn('contratos_trabalho', 'produtividade')) {
            DB::statement('ALTER TABLE contratos_trabalho ADD COLUMN produtividade jsonb NULL');
        }
        $linhas = DB::table('elegibilidade_produtividade_rh')->where('origem', 'CONTRATO')->orderBy('item_produtividade_id')->get();
        foreach ($linhas->groupBy(fn ($l) => $l->empresa_id.'|'.$l->colaborador_id) as $grupo) {
            $primeira = $grupo->first();
            $contrato = DB::table('contratos_trabalho')->where('empresa_id', $primeira->empresa_id)->where('colaborador_id', $primeira->colaborador_id)->where('estado', 'ACTIVO')
                ->orderByDesc('data_inicio')->orderByDesc('id')->first();
            if (! $contrato) {
                continue;
            }
            $json = $grupo->map(fn ($l) => ['item_id' => (int) $l->item_produtividade_id, 'preco_unitario' => $l->preco_unitario !== null && (float) $l->preco_unitario > 0 ? (float) $l->preco_unitario : null])->values()->all();
            DB::table('contratos_trabalho')->where('id', $contrato->id)->update(['produtividade' => json_encode($json)]);
        }
        DB::table('elegibilidade_produtividade_rh')->where('origem', 'CONTRATO')->delete();
        $this->restricaoOrigem(['MANUAL', 'ENTREGAVEL']);
    }

    /** @param  list<string>  $valores */
    private function restricaoOrigem(array $valores): void
    {
        if (! Schema::hasTable('elegibilidade_produtividade_rh')) {
            return;
        }
        $lista = implode(',', array_map(fn ($v) => "'{$v}'", $valores));
        DB::statement('ALTER TABLE elegibilidade_produtividade_rh DROP CONSTRAINT IF EXISTS ck_elegibilidade_produtividade_rh_origem');
        DB::statement("ALTER TABLE elegibilidade_produtividade_rh ADD CONSTRAINT ck_elegibilidade_produtividade_rh_origem CHECK (origem IN ({$lista}))");
    }
};
