<?php

namespace Tests\Feature;

use App\Models\Concerns\PertenceEmpresa;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Contrato do esquema: a base de dados e os models correspondem a database/legado/esquema.json
 * (gerado a partir do dicionário DE/PARA). Qualquer desvio entre migrations, models e contrato falha aqui.
 */
final class EsquemaContratoTest extends TestCase
{
    /** @return array<string, mixed> */
    private function contrato(): array
    {
        return json_decode(file_get_contents(database_path('legado/esquema.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Família do tipo PostgreSQL (information_schema) esperada para o tipo do contrato. */
    private function familia(string $tipo): string
    {
        return match (true) {
            $tipo === 'bigint' => 'bigint',
            $tipo === 'integer' => 'integer',
            str_starts_with($tipo, 'numeric') => 'numeric',
            str_starts_with($tipo, 'varchar') => 'character varying',
            $tipo === 'text' => 'text',
            $tipo === 'jsonb' => 'jsonb',
            $tipo === 'boolean' => 'boolean',
            $tipo === 'date' => 'date',
            $tipo === 'timestamptz' => 'timestamp with time zone',
        };
    }

    #[Test]
    public function todas_as_tabelas_da_matriz_existem_com_as_colunas_e_tipos_do_contrato(): void
    {
        $reais = collect(DB::select("SELECT table_name, column_name, data_type, is_nullable, numeric_precision, numeric_scale, character_maximum_length
                                     FROM information_schema.columns WHERE table_schema = 'public'"))
            ->groupBy('table_name')->map(fn ($c) => $c->keyBy('column_name'));

        $falhas = [];
        foreach ($this->contrato()['tabelas'] as $t) {
            $colunas = $reais->get($t['tabela']);
            if (! $colunas) {
                $falhas[] = "tabela em falta: {$t['tabela']}";

                continue;
            }
            foreach ($t['colunas'] as $c) {
                $real = $colunas->get($c['coluna']);
                if (! $real) {
                    $falhas[] = "coluna em falta: {$t['tabela']}.{$c['coluna']}";

                    continue;
                }
                if ($real->data_type !== $this->familia($c['tipo'])) {
                    $falhas[] = "tipo diferente: {$t['tabela']}.{$c['coluna']} ({$real->data_type} ≠ {$c['tipo']})";
                }
                if (preg_match('/^numeric\((\d+),(\d+)\)$/', $c['tipo'], $m) && ((int) $real->numeric_precision !== (int) $m[1] || (int) $real->numeric_scale !== (int) $m[2])) {
                    $falhas[] = "precisão diferente: {$t['tabela']}.{$c['coluna']}";
                }
                if ($c['nulo'] !== ($real->is_nullable === 'YES') && $c['coluna'] !== 'id') {
                    $falhas[] = "nulidade diferente: {$t['tabela']}.{$c['coluna']}";
                }
            }
        }

        $this->assertSame([], $falhas);
        $this->assertGreaterThanOrEqual(150, count($this->contrato()['tabelas']));
    }

    #[Test]
    public function todas_as_chaves_estrangeiras_do_contrato_existem_e_sao_adiaveis(): void
    {
        $fks = collect(DB::select(<<<'SQL'
            SELECT c.conrelid::regclass::text AS tabela, a.attname AS coluna, c.confrelid::regclass::text AS alvo,
                   c.condeferrable AS adiavel, c.confdeltype AS ao_apagar
            FROM pg_constraint c
            JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
            WHERE c.contype = 'f'
        SQL))->keyBy(fn ($f) => "{$f->tabela}.{$f->coluna}");

        $falhas = [];
        foreach ($this->contrato()['tabelas'] as $t) {
            foreach ($t['colunas'] as $c) {
                if (! $c['fk'] || ($t['tabela'] === 'logs_auditoria')) {
                    continue;
                }
                $fk = $fks->get("{$t['tabela']}.{$c['coluna']}");
                if (! $fk) {
                    $falhas[] = "FK em falta: {$t['tabela']}.{$c['coluna']} -> {$c['fk']['tabela']}";

                    continue;
                }
                if ($fk->alvo !== $c['fk']['tabela']) {
                    $falhas[] = "FK com alvo errado: {$t['tabela']}.{$c['coluna']}";
                }
                if (! $fk->adiavel) {
                    $falhas[] = "FK não adiável: {$t['tabela']}.{$c['coluna']}";
                }
                if (($fk->ao_apagar === 'c') !== $c['fk']['cascata']) {
                    $falhas[] = "ON DELETE diferente do contrato: {$t['tabela']}.{$c['coluna']}";
                }
            }
        }

        $this->assertSame([], $falhas);
    }

    #[Test]
    public function chaves_unicas_do_contrato_existem(): void
    {
        $indices = collect(DB::select("SELECT tablename, indexdef FROM pg_indexes WHERE schemaname = 'public' AND indexdef LIKE 'CREATE UNIQUE%'"));

        $falhas = [];
        foreach ($this->contrato()['tabelas'] as $t) {
            foreach ($t['unicos'] as $u) {
                $esperado = '('.implode(', ', $u['colunas']).')';
                $existe = $indices->contains(fn ($i) => $i->tablename === $t['tabela'] && str_contains($i->indexdef, $esperado));
                if (! $existe) {
                    $falhas[] = "único em falta: {$t['tabela']}{$esperado}";
                }
            }
        }

        $this->assertSame([], $falhas);
    }

    #[Test]
    public function cada_model_aponta_para_a_sua_tabela_e_as_relacoes_resolvem(): void
    {
        $falhas = [];
        foreach ($this->contrato()['tabelas'] as $t) {
            if (! $t['model']) {
                continue;
            }
            $classe = "App\\Models\\{$t['model']}";
            if (! class_exists($classe)) {
                $falhas[] = "model em falta: {$classe}";

                continue;
            }
            /** @var Model $modelo */
            $modelo = new $classe;
            if ($modelo->getTable() !== $t['tabela']) {
                $falhas[] = "tabela errada em {$classe}";
            }
            $colunas = array_column($t['colunas'], 'coluna');
            foreach (array_diff($modelo->getFillable(), $colunas) as $extra) {
                $falhas[] = "fillable sem coluna: {$classe}::{$extra}";
            }
            foreach ((new ReflectionClass("App\\Models\\Base\\{$t['model']}Base"))->getMethods(ReflectionMethod::IS_PUBLIC) as $metodo) {
                $retorno = $metodo->getReturnType()?->getName();
                if ($metodo->class !== "App\\Models\\Base\\{$t['model']}Base" || ! $retorno || ! is_subclass_of($retorno, Relation::class)) {
                    continue;
                }
                $relacao = $modelo->{$metodo->getName()}();
                if (! $relacao->getRelated() instanceof Model) {
                    $falhas[] = "relação inválida: {$classe}::{$metodo->getName()}";
                }
            }
        }

        $this->assertSame([], $falhas);
    }

    #[Test]
    public function tabelas_de_empresa_isolam_por_empresa_e_as_globais_nao(): void
    {
        $falhas = [];
        foreach ($this->contrato()['tabelas'] as $t) {
            if (! $t['model']) {
                continue;
            }
            $usa = in_array(PertenceEmpresa::class, class_uses_recursive("App\\Models\\{$t['model']}"), true);
            if ($usa === $t['global']) {
                $falhas[] = "{$t['model']}: ".($t['global'] ? 'global não deve isolar por empresa' : 'falta isolamento por empresa');
            }
        }

        $this->assertSame([], $falhas);
    }
}
