<?php

namespace App\Http\Controllers\Api\Plataforma;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Plataforma\ServicoClientes;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/** Painel da plataforma: resumo e relatórios CSV. Acesso: middleware `plataforma`. */
final class PainelController extends Controller
{
    public function __construct(private readonly ServicoClientes $clientes) {}

    public function dashboard(): JsonResponse
    {
        $clientes = $this->clientes->listar();
        $empresas = $this->clientes->empresas();
        $hoje = now()->toDateString();

        return RespostaApi::sucesso([
            'clientes' => [
                'total' => $clientes->count(),
                'ativos' => $clientes->filter(fn (array $i) => $i['tenant']->estaAtivo())->count(),
                'suspensos' => $clientes->filter(fn (array $i) => ! $i['tenant']->estaAtivo())->count(),
            ],
            'empresas' => [
                'total' => $empresas->count(),
                'ativas' => $empresas->where('estado', 'ATIVO')->count(),
                'em_teste' => $empresas->where('licenca_origem', 'TESTE')->count(),
                'licenca_expirada' => $empresas->filter(fn (array $e) => $e['licenca_fim'] !== null && $e['licenca_fim'] < $hoje)->count(),
            ],
            'utilizadores' => (int) DB::table('utilizadores')->count(),
            'indicadores' => $this->indicadores($clientes->map(fn (array $i) => $i['tenant']), $empresas),
            'ultimos_clientes' => $clientes->map(fn (array $i) => $i['tenant'])->sortByDesc('criado_em')->take(5)->values()
                ->map(fn (Tenant $c) => ['id' => $c->id, 'nome' => $c->nome, 'slug' => $c->slug, 'criado_em' => $c->criado_em])->all(),
        ], 'Painel obtido com sucesso.');
    }

    /**
     * Indicadores e séries do painel (consola da plataforma, pedido de 2026-10-22): receita mensal recorrente estimada
     * (planos dos clientes activos), receita recebida e clientes novos por mês (12 meses), clientes por plano, utilizadores
     * activos (últimos 30 dias), acessos por dia, licenças a expirar e tickets. Leituras agregadas de todos os clientes
     * pela ligação do dono (sem RLS) — só contagens e somas, nunca dados de negócio.
     *
     * @param  \Illuminate\Support\Collection<int, Tenant>  $tenants
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $empresas
     * @return array<string, mixed>
     */
    private function indicadores($tenants, $empresas): array
    {
        $db = DB::connection(config('erp.saas.ligacao_dono') ?: null);
        $plataforma = (int) config('erp.dominio.plataforma_tenant_id', 1);
        $meses = collect(range(11, 0))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->values();
        $precos = $db->table('planos_licenca')->pluck('preco_mensal', 'codigo')->map(fn ($v) => (float) $v);
        $nomesPlanos = $db->table('planos_licenca')->pluck('nome', 'codigo');
        $clientes = $tenants->filter(fn (Tenant $t) => (int) $t->id !== $plataforma);

        $mrr = $clientes->filter(fn (Tenant $t) => $t->estaAtivo() && $t->plano)->sum(fn (Tenant $t) => $precos[$t->plano] ?? 0);
        $porPlano = $clientes->groupBy(fn (Tenant $t) => $t->plano ?: '—')->map->count();

        $novos = $clientes->groupBy(fn (Tenant $t) => $t->criado_em?->format('Y-m'))->map->count();
        $receita = $db->table('subscricoes_empresa')->where('estado', 'PAGO')->where('pago_em', '>=', now()->startOfMonth()->subMonths(11))
            ->selectRaw("to_char(pago_em, 'YYYY-MM') AS mes, COALESCE(SUM(valor), 0) AS total")->groupBy('mes')->pluck('total', 'mes');
        $acessos = $db->table('utilizadores')->whereNull('eliminado_em')->where('ultimo_acesso_em', '>=', now()->subDays(29)->startOfDay())
            ->selectRaw("to_char(ultimo_acesso_em, 'YYYY-MM-DD') AS dia, COUNT(*) AS total")->groupBy('dia')->pluck('total', 'dia');
        $dias = collect(range(29, 0))->map(fn ($i) => now()->subDays($i)->format('Y-m-d'))->values();
        $hoje = now()->toDateString();
        $limite = now()->addDays(30)->toDateString();
        $nomesClientes = $tenants->pluck('nome', 'id');
        $tickets = rescue(fn () => $db->table('tickets_suporte')->selectRaw('estado, COUNT(*) AS total')->groupBy('estado')->pluck('total', 'estado'), collect(), report: false);

        return [
            'mrr' => round($mrr, 2),
            'moeda' => 'AOA',
            'utilizadores_ativos_30d' => (int) $acessos->sum(),
            'tickets_abertos' => (int) (($tickets['ABERTO'] ?? 0) + ($tickets['EM_CURSO'] ?? 0)),
            'meses' => $meses->map(fn ($m) => \Illuminate\Support\Carbon::createFromFormat('Y-m', $m)->locale('pt')->isoFormat('MMM YY'))->all(),
            'clientes_novos_mes' => $meses->map(fn ($m) => (int) ($novos[$m] ?? 0))->all(),
            'receita_mes' => $meses->map(fn ($m) => round((float) ($receita[$m] ?? 0), 2))->all(),
            'dias' => $dias->map(fn ($d) => substr($d, 8, 2).'/'.substr($d, 5, 2))->all(),
            'acessos_dia' => $dias->map(fn ($d) => (int) ($acessos[$d] ?? 0))->all(),
            'por_plano' => $porPlano->map(fn ($n, $codigo) => ['plano' => $nomesPlanos[$codigo] ?? 'Sem plano', 'clientes' => $n])->values()->all(),
            'tickets' => collect(['ABERTO', 'EM_CURSO', 'RESOLVIDO', 'FECHADO'])->mapWithKeys(fn ($e) => [$e => (int) ($tickets[$e] ?? 0)])->all(),
            'licencas_a_expirar' => $empresas->filter(fn (array $e) => $e['licenca_fim'] !== null && $e['licenca_fim'] >= $hoje && $e['licenca_fim'] <= $limite)
                ->sortBy('licenca_fim')->take(10)->map(fn (array $e) => ['empresa' => $e['nome'], 'cliente' => $nomesClientes[$e['tenant_id']] ?? null,
                    'licenca_fim' => $e['licenca_fim'], 'origem' => $e['licenca_origem']])->values()->all(),
        ];
    }

    public function relatorioClientes(): Response
    {
        $linhas = $this->clientes->listar()->map(fn (array $i) => [
            $i['tenant']->id, $i['tenant']->nome, $i['tenant']->slug, $i['tenant']->estado, $i['empresas'], $i['utilizadores'],
        ])->all();

        return $this->csv('clientes.csv', ['id', 'nome', 'slug', 'estado', 'empresas', 'utilizadores'], $linhas);
    }

    public function relatorioEmpresas(): Response
    {
        $linhas = $this->clientes->empresas()->map(fn (array $e) => [
            $e['id'], $e['cliente'], $e['nome'], $e['nif'], $e['estado'], $e['licenca_origem'], $e['licenca_fim'], $e['criado_em'],
        ])->all();

        return $this->csv('empresas.csv', ['id', 'cliente', 'nome', 'nif', 'estado', 'licenca', 'licenca_fim', 'criado_em'], $linhas);
    }

    /**
     * CSV para Excel (com BOM). Células que começam por = + - @ levam apóstrofo (injecção de fórmulas).
     *
     * @param  list<string>  $cabecalho
     * @param  list<array<int, mixed>>  $linhas
     */
    private function csv(string $nome, array $cabecalho, array $linhas): Response
    {
        $saida = fopen('php://temp', 'r+');
        fputcsv($saida, $cabecalho, ';');
        foreach ($linhas as $linha) {
            fputcsv($saida, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $linha), ';');
        }
        rewind($saida);
        $conteudo = "\xEF\xBB\xBF".stream_get_contents($saida);
        fclose($saida);

        return response($conteudo, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$nome}\""]);
    }
}
