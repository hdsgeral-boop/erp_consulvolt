<?php

namespace App\Services\Projetos;

use App\Exceptions\ErroNegocio;
use App\Models\ItemCompra;
use App\Models\LinhaRequisicaoProjeto;
use App\Models\Produto;
use App\Models\Projeto;
use App\Models\RequisicaoMaterialProjeto;
use App\Models\StockArmazem;
use App\Models\TarefaProjeto;
use App\Services\Compras\ServicoProcessoCompras;
use Illuminate\Support\Facades\DB;

/**
 * Requisições de material da obra (showAddRequisitionModal / saveRequisition / viewRequisition,
 * js/ui_projects.js:2160-2457).
 * Mantém do legado:
 *   - requerente, data, entrega desejada e linhas (tarefa opcional, artigo, quantidade), estado PENDENTE;
 *   - o que é serviço ou não tem stock suficiente (soma de todos os armazéns) vai a Compras num pedido de compra do
 *     projecto (requerente «<nome> (Obra: <código>)»), linha COMPRAS; o resto fica STOCK;
 *   - a linha guarda a rubrica MATERIAIS e a descrição do artigo.
 * Correcções:
 *   - tudo numa transacção (o legado gravava a requisição, o pedido e as linhas em passos soltos);
 *   - o pedido passa pelo circuito de Compras (numeração PC, deliberação — ServicoProcessoCompras::criarPedido);
 *   - projecto encerrado não aceita requisições; artigos, quantidades e tarefas validados.
 */
final class ServicoRequisicoesProjetos
{
    public function __construct(
        private readonly ServicoProjetos $projetos,
        private readonly ServicoProcessoCompras $compras,
    ) {}

    public function listar(Projeto $p): array
    {
        $reqs = RequisicaoMaterialProjeto::query()->where('projeto_id', $p->id)->orderByDesc('id')->get();
        $linhas = LinhaRequisicaoProjeto::query()->whereIn('requisicao_material_projeto_id', $reqs->pluck('id'))->orderBy('id')->get()->groupBy('requisicao_material_projeto_id');

        return $reqs->map(fn ($r) => $r->toArray() + ['linhas' => ($linhas[$r->id] ?? collect())->values()->all()])->all();
    }

    /**
     * @param  array{nome_requerente: string, data: string, data_prevista?: ?string, linhas: list<array{produto_id: int, quantidade: mixed, tarefa_projeto_id?: ?int}>}  $d
     * @return array{requisicao: RequisicaoMaterialProjeto, pedido_compra_id: ?int}
     */
    public function criar(Projeto $p, array $d): array
    {
        $this->projetos->exigirAberto($p);
        $requerente = trim((string) ($d['nome_requerente'] ?? ''));
        if ($requerente === '' || empty($d['data'])) {
            throw new ErroNegocio('Preencha o requerente e a data.', 'DADOS_EM_FALTA', 422);
        }
        $linhas = array_values(array_filter($d['linhas'] ?? [], fn ($l) => ! empty($l['produto_id'])));
        if (! $linhas) {
            throw new ErroNegocio('Adicione pelo menos uma linha à requisição.', 'SEM_LINHAS', 422);
        }
        $produtos = Produto::query()->whereIn('id', array_column($linhas, 'produto_id'))->get()->keyBy('id');
        $tarefas = TarefaProjeto::query()->where('projeto_id', $p->id)->pluck('id')->flip();
        $stock = StockArmazem::query()->whereIn('produto_id', $produtos->keys())->groupBy('produto_id')->selectRaw('produto_id, SUM(quantidade_stock) AS q')->pluck('q', 'produto_id');
        $compra = $interno = [];
        foreach ($linhas as $n => $l) {
            $pr = $produtos[$l['produto_id']] ?? throw new ErroNegocio('Linha '.($n + 1).': artigo inexistente.', 'PRODUTO_INEXISTENTE', 422);
            if ((float) $l['quantidade'] <= 0) {
                throw new ErroNegocio('Linha '.($n + 1).': a quantidade tem de ser positiva.', 'QUANTIDADE_INVALIDA', 422);
            }
            $tarefa = $l['tarefa_projeto_id'] ?? null;
            if ($tarefa && ! isset($tarefas[$tarefa])) {
                throw new ErroNegocio('Linha '.($n + 1).': a tarefa não pertence ao projecto.', 'TAREFA_INVALIDA', 422);
            }
            $x = ['produto' => $pr, 'quantidade' => (string) $l['quantidade'], 'tarefa' => $tarefa ?: null];
            if (! $pr->movimenta_stock || (float) ($stock[$pr->id] ?? 0) < (float) $l['quantidade']) {
                $compra[] = $x;
            } else {
                $interno[] = $x;
            }
        }

        return DB::transaction(function () use ($p, $d, $requerente, $compra, $interno) {
            $req = RequisicaoMaterialProjeto::create(['projeto_id' => $p->id, 'nome_requerente' => $requerente, 'data' => $d['data'],
                'data_prevista' => $d['data_prevista'] ?? $d['data'], 'estado' => 'PENDENTE']);
            $pedidoId = null;
            if ($compra) {
                $pedido = $this->compras->criarPedido(['nome_requerente' => mb_substr("{$requerente} (Obra: {$p->codigo})", 0, 255), 'data' => $d['data'],
                    'data_entrega' => $d['data_prevista'] ?? null, 'projeto_id' => $p->id,
                    'descricao' => "Requisição de material REQ-{$req->id} do projecto {$p->codigo}",
                    'linhas' => array_map(fn ($x) => ['produto_id' => $x['produto']->id, 'quantidade' => $x['quantidade']], $compra)]);
                $pedidoId = $pedido->id;
                // a tarefa de cada linha (criarPedido ainda não a recebe — gancho descrito no ADR-052)
                foreach (ItemCompra::query()->where('pedido_compra_id', $pedido->id)->orderBy('id')->get()->values() as $i => $item) {
                    if ($compra[$i]['tarefa'] ?? null) {
                        $item->update(['tarefa_projeto_id' => $compra[$i]['tarefa']]);
                    }
                }
            }
            foreach ([['COMPRAS', $compra], ['STOCK', $interno]] as [$estado, $lista]) {
                foreach ($lista as $x) {
                    LinhaRequisicaoProjeto::create(['requisicao_material_projeto_id' => $req->id, 'tarefa_projeto_id' => $x['tarefa'], 'rubrica' => 'MATERIAIS',
                        'descricao' => $x['produto']->nome, 'quantidade' => $x['quantidade'], 'estado' => $estado]);
                }
            }
            $this->projetos->registar($p->id, 'Requisição de material', "REQ-{$req->id}: ".count($compra).' a compras, '.count($interno).' de stock');

            return ['requisicao' => $req->refresh(), 'pedido_compra_id' => $pedidoId];
        });
    }
}
