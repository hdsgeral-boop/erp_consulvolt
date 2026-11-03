<?php

namespace Tests\Feature\Concerns;

use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\Empresa;
use App\Models\EntregavelTarefaUnidade;
use App\Models\InfotipoSalarial;
use App\Models\PeriodoFeriasEmpresa;
use App\Models\TarefaUnidadeOrganica;
use App\Models\TipoOrganizacaoRH;
use App\Models\UnidadeNegocio;
use App\Models\UnidadeOrganica;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;

/**
 * Cenário comum da Fase K (ADR-079). Hoje é 2026-03-10. Árvore: Direcção (Director) › Departamento (Chefe) ‹ Ana, Rui, Suplente, Chefe.
 * Entregável «Relatório» (dono Ana, meta 2, prazo dia 5 do mês seguinte, aviso e multa de 5000) EM ATRASO desde 2026-03-05 (período Fevereiro).
 * A Ana tem contrato com 300000 de salário base. Rubricas: Salário Base (vencimento) e Multas (desconto).
 */
trait CenarioFaseK
{
    protected Empresa $empresa;

    /** @var array<string, int> */
    protected array $ids = [];

    /** @var array<string, array<string, mixed>> */
    protected array $s = [];

    protected function prepararCenarioK(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->empresa = $this->criarEmpresa();
        $this->em(function () {
            $org = TipoOrganizacaoRH::create(['nome' => 'Colaboradores'])->id;
            $mk = fn (string $n, string $nif) => Colaborador::create(['nome_completo' => $n, 'nif' => $nif, 'estado' => 'ACTIVO', 'tipo_organizacao_id' => $org, 'dias_uteis_mes' => 22, 'data_admissao' => '2020-01-06'])->id;
            $this->ids = ['ana' => $mk('Ana', '1001'), 'rui' => $mk('Rui', '1002'), 'chefe' => $mk('Chefe', '1003'), 'director' => $mk('Director', '1004'), 'suplente' => $mk('Suplente', '1005'),
                'sem_utilizador' => $mk('Sem Utilizador', '1006')];
            $dir = UnidadeOrganica::create(['nome' => 'Direcção', 'codigo' => 'DIR', 'tipo' => 'DIRECCAO', 'colaborador_responsavel_id' => $this->ids['director'], 'ativo' => true, 'ordem' => 10]);
            $dep = UnidadeOrganica::create(['nome' => 'Departamento', 'codigo' => 'DEP', 'tipo' => 'DEPARTAMENTO', 'unidade_organica_pai_id' => $dir->id, 'colaborador_responsavel_id' => $this->ids['chefe'], 'ativo' => true, 'ordem' => 10]);
            Colaborador::query()->whereKey([$this->ids['ana'], $this->ids['rui'], $this->ids['chefe'], $this->ids['suplente']])->update(['unidade_organica_id' => $dep->id]);
            Colaborador::query()->whereKey($this->ids['director'])->update(['unidade_organica_id' => $dir->id]);
            $t = TarefaUnidadeOrganica::create(['unidade_organica_id' => $dep->id, 'nome' => 'Relatórios', 'periodicidade' => 'MENSAL', 'ativo' => true]);
            $e = EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => 'Relatório', 'metrica' => 'QUANTIDADE', 'unidade' => 'un', 'meta' => 2, 'prazo_dia' => 5, 'prazo_mes' => 2,
                'colaborador_id' => $this->ids['ana'], 'alerta' => true, 'multa_valor' => 5000, 'multa_descricao' => 'Multa por atraso do relatório', 'ativo' => true]);
            $base = InfotipoSalarial::create(['tipo' => 'VENCIMENTO', 'nome' => 'Salário Base', 'sujeito_inss' => true, 'irt' => 'true'])->id;
            $multa = InfotipoSalarial::create(['tipo' => 'DESCONTO', 'nome' => 'Multas', 'sujeito_inss' => false, 'irt' => 'false'])->id;
            ContratoTrabalho::create(['colaborador_id' => $this->ids['ana'], 'estado' => 'ACTIVO', 'dias_contrato_mes' => 22, 'horas_por_dia' => 8, 'data_inicio' => '2020-01-06',
                'remuneracoes' => [['infotipo_id' => $base, 'valor_mes' => 300000]],
                'horario' => ['entrada' => '08:00', 'saida' => '17:00', 'periodos' => 1, 'intervalo_min' => 60, 'tolerancia_min' => 10, 'dias' => [1, 2, 3, 4, 5]]]);
            foreach ([2026, 2027] as $ano) {
                PeriodoFeriasEmpresa::create(['ano' => $ano, 'data_inicio' => "{$ano}-01-01", 'data_fim' => "{$ano}-12-31"]);
            }
            $un = UnidadeNegocio::create(['codigo' => 'UN1', 'nome' => 'Negócio'])->id;
            $this->ids += ['dir' => $dir->id, 'dep' => $dep->id, 'tarefa' => $t->id, 'entregavel' => $e->id, 'base' => $base, 'multa' => $multa, 'un' => $un];
        });
        $this->s = [
            'ana' => $this->sessao(['rh_portal_usar', 'mt_assuntos_view', 'mt_criar'], $this->ids['ana']),
            'rui' => $this->sessao(['rh_portal_usar'], $this->ids['rui']),
            'chefe' => $this->sessao(['rh_portal_usar', 'mt_assuntos_view', 'est_estrutura_view'], $this->ids['chefe']),
            'director' => $this->sessao(['rh_portal_usar', 'mt_assuntos_view', 'est_estrutura_view'], $this->ids['director']),
            'suplente' => $this->sessao(['rh_portal_usar', 'rh_portal_aprovar', 'mt_assuntos_view', 'est_estrutura_view'], $this->ids['suplente']),
            'rh' => $this->sessao(['rh_portal_aprovar', 'rh_portal_gestao_view', 'est_estrutura_view', 'est_multas_gerir', 'est_delegar', 'est_editar', 'rh_emp_lancar', 'calcular_view', 'calcular_lancar', 'rh_lanc_del']),
        ];
    }

    protected function em(\Closure $f): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($this->empresa->id, $f);
    }

    /** @return array<string, mixed> cabeçalhos autenticados de um novo utilizador com o perfil e o colaborador indicados */
    protected function sessao(array $permissoes, ?int $colaborador = null): array
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($this->empresa->id, ['colaborador_id' => $colaborador]);

        return $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }
}
