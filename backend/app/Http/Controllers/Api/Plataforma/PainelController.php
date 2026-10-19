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
            'ultimos_clientes' => $clientes->map(fn (array $i) => $i['tenant'])->sortByDesc('criado_em')->take(5)->values()
                ->map(fn (Tenant $c) => ['id' => $c->id, 'nome' => $c->nome, 'slug' => $c->slug, 'criado_em' => $c->criado_em])->all(),
        ], 'Painel obtido com sucesso.');
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