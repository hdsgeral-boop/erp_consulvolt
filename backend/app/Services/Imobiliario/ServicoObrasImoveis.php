<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\Armazem;
use App\Models\BemFracao;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\EncomendaCompra;
use App\Models\FichaApetrechamento;
use App\Models\Fracao;
use App\Models\HistoricoOrdemImo;
use App\Models\Imovel;
use App\Models\ItemCompra;
use App\Models\LinhaRequisicaoManutencao;
use App\Models\NecessidadeOrdemImo;
use App\Models\OrdemTrabalhoImo;
use App\Models\PedidoCompra;
use App\Models\Produto;
use App\Models\RendaArrendamento;
use App\Models\RequisicaoManutencao;
use App\Models\VistoriaReserva;
use App\Services\Ativos\NumeracaoManutencao;
use App\Services\Ativos\ServicoOrdensManutencao;
use App\Services\Compras\ServicoProcessoCompras;
use App\Support\Tenancy\ContextoEmpresa;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ordens de trabalho do imóvel (imobiliario_obras.js do legado): obra, apetrechamento, manutenção, reparação e vistoria. Fluxo: ficha-tipo +
 * inventário ⇒ falta ⇒ ordem ⇒ necessidades ⇒ (stock) requisição ao armazém | (falta) pedido de compra ⇒ recepção ⇒ bens da fracção. O pedido de
 * material REUTILIZA a requisição de manutenção de Activos e o pedido de Compras (deliberação por escalões incluída): nada se duplica; a ordem
 * guarda só as ligações e a quantidade recebida LÊ-SE dessas origens (`sincronizar`), o que fecha o buraco do legado (receber à mão, sem fechar a
 * requisição nem o pedido). Correcções (P3, o legado fica como fixture em CalculadoraObras): a numeração é atómica; as acções que tocam em
 * várias tabelas são uma só transacção (o legado deixava registos órfãos); a ordem passa por aprovação antes de pedir material e de debitar
 * (par de segregação: quem aprova não recebe); só o apetrechamento põe material no inventário da fracção (o legado punha tudo e dizia que entrara
 * mesmo sem fracção); fechar tem matriz de transições e uma ordem fechada não reabre nem se apaga; a data prevista valida-se sempre; débito ao
 * inquilino idempotente (renda e ordem bloqueadas) e desfazível enquanto a renda não é documento.
 */
final class ServicoObrasImoveis
{
    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracaoImobiliario $numeracao,
        private readonly ServicoRendas $rendas,
    ) {}

    // ---------------------------------------------------------------- leitura

    /**
     * @param  array{estado?: ?string, tipo?: ?string, imovel_id?: ?int, fracao_id?: ?int, texto?: ?string, por_debitar?: ?bool}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $q = OrdemTrabalhoImo::query()->orderByDesc('data_pedido')->orderByDesc('id')->limit(1000);
        foreach (['estado', 'tipo'] as $c) {
            if (! empty($f[$c])) {
                $q->where($c, $f[$c]);
            }
        }
        foreach (['imovel_id', 'fracao_id'] as $c) {
            if (! empty($f[$c])) {
                $q->where($c, (int) $f[$c]);
            }
        }
        if (! empty($f['por_debitar'])) {
            $q->where('debitavel', true)->where('estado', 'CONCLUIDA')->whereNull('debitado_em');
        }
        if (! empty($f['texto'])) {
            $t = '%'.mb_strtolower(trim((string) $f['texto'])).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(numero) like ?', [$t])->orWhereRaw('lower(descricao) like ?', [$t]));
        }
        $ordens = $q->get();
        $imoveis = Imovel::query()->whereIn('id', $ordens->pluck('imovel_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');
        $fracoes = Fracao::query()->whereIn('id', $ordens->pluck('fracao_id')->filter()->unique())->pluck('designacao', 'id');
        $necessidades = NecessidadeOrdemImo::query()->whereIn('ordem_id', $ordens->pluck('id'))->selectRaw("ordem_id, count(*) filter (where estado = 'POR_PEDIR') as por_pedir, count(*) as total")
            ->groupBy('ordem_id')->get()->keyBy('ordem_id');
        $hoje = now()->toDateString();

        return $ordens->map(fn ($o) => $o->attributesToArray() + [
            'imovel_nome' => $imoveis[$o->imovel_id]->nome ?? null, 'imovel_codigo' => $imoveis[$o->imovel_id]->codigo ?? null, 'fracao_designacao' => $fracoes[$o->fracao_id] ?? null,
            'necessidades' => (int) ($necessidades[$o->id]->total ?? 0), 'por_pedir' => (int) ($necessidades[$o->id]->por_pedir ?? 0),
            'em_atraso' => $o->data_prevista !== null && $o->data_prevista->format('Y-m-d') < $hoje && ! $o->fechada(), 'aprovada' => $o->aprovada_em !== null,
            'por_debitar' => $o->debitavel && $o->estado === 'CONCLUIDA' && $o->debitado_em === null])->all();
    }

    /** @return array<string, int> */
    public function resumo(): array
    {
        $hoje = now()->toDateString();
        $r = OrdemTrabalhoImo::query()->selectRaw(
            "count(*) as total, count(*) filter (where estado = 'ABERTA') as abertas, count(*) filter (where estado = 'AGUARDA_MATERIAL') as aguarda_material, "
            ."count(*) filter (where estado = 'EM_CURSO') as em_curso, count(*) filter (where estado = 'CONCLUIDA') as concluidas, "
            ."count(*) filter (where prioridade = 'URGENTE' and estado not in ('CONCLUIDA','CANCELADA')) as urgentes, "
            ."count(*) filter (where debitavel and estado = 'CONCLUIDA' and debitado_em is null) as por_debitar, "
            ."count(*) filter (where data_prevista < ? and estado not in ('CONCLUIDA','CANCELADA')) as atrasadas, "
            ."count(*) filter (where aprovada_em is null and estado not in ('CONCLUIDA','CANCELADA')) as por_aprovar", [$hoje])->first();
        $planos = app(ServicoPlanosManutencaoImo::class)->resumo();

        return array_map('intval', $r->only(['total', 'abertas', 'aguarda_material', 'em_curso', 'concluidas', 'urgentes', 'por_debitar', 'atrasadas', 'por_aprovar']))
            + ['planos_activos' => $planos['activos'], 'planos_em_falta' => $planos['em_falta'], 'planos_por_gerar' => $planos['por_gerar']];
    }

    /** @return list<array{tipo: string, texto: string, ordem_id?: int, plano_id?: int}> */
    public function avisos(): array
    {
        $hoje = now()->toDateString();
        $av = [];
        foreach (OrdemTrabalhoImo::query()->whereNotIn('estado', OrdemTrabalhoImo::FECHADAS)->whereNotNull('data_prevista')->where('data_prevista', '<', $hoje)->orderBy('data_prevista')->limit(50)->get(['id', 'numero', 'data_prevista']) as $o) {
            $av[] = ['tipo' => 'ORDEM_ATRASADA', 'texto' => "A ordem {$o->numero} devia estar feita em {$o->data_prevista->format('d/m/Y')}.", 'ordem_id' => (int) $o->id];
        }
        foreach (OrdemTrabalhoImo::query()->whereNotIn('estado', OrdemTrabalhoImo::FECHADAS)->where('prioridade', 'URGENTE')->whereNull('aprovada_em')->orderBy('id')->limit(50)->get(['id', 'numero']) as $o) {
            $av[] = ['tipo' => 'URGENTE_SEM_APROVACAO', 'texto' => "A ordem urgente {$o->numero} ainda não foi aprovada.", 'ordem_id' => (int) $o->id];
        }
        foreach (OrdemTrabalhoImo::query()->where('debitavel', true)->where('estado', 'CONCLUIDA')->whereNull('debitado_em')->orderBy('id')->limit(50)->get(['id', 'numero']) as $o) {
            $av[] = ['tipo' => 'POR_DEBITAR', 'texto' => "A ordem {$o->numero} está concluída e por debitar ao inquilino.", 'ordem_id' => (int) $o->id];
        }

        return array_merge($av, app(ServicoPlanosManutencaoImo::class)->avisos());
    }

    /** @return array<string, mixed> a ordem com as necessidades, o custo e o trilho */
    public function ficha(OrdemTrabalhoImo $o): array
    {
        $imovel = Imovel::query()->find($o->imovel_id, ['id', 'codigo', 'nome']);
        $fracao = $o->fracao_id ? Fracao::query()->find($o->fracao_id, ['id', 'designacao']) : null;
        $linhas = $this->necessidades($o);
        $custo = $this->custoReal($o);

        return $o->attributesToArray() + [
            'imovel_nome' => $imovel?->nome, 'imovel_codigo' => $imovel?->codigo, 'fracao_designacao' => $fracao?->designacao, 'aprovada' => $o->aprovada_em !== null,
            'necessidades_lista' => $linhas, 'por_pedir' => count(array_filter($linhas, fn ($n) => $n['estado'] === 'POR_PEDIR')), 'custo_real' => $custo,
            'por_debitar' => $o->debitavel && $o->estado === 'CONCLUIDA' && $o->debitado_em === null,
            'historico' => HistoricoOrdemImo::query()->where('ordem_id', $o->id)->orderBy('id')->get(['evento', 'estado_de', 'estado_para', 'motivo', 'em'])->map->attributesToArray()->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function necessidades(OrdemTrabalhoImo $o): array
    {
        $linhas = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->orderBy('ordem')->orderBy('id')->get();
        $produtos = Produto::query()->whereIn('id', $linhas->pluck('produto_id')->filter()->unique())->get(['id', 'codigo', 'nome', 'movimenta_stock'])->keyBy('id');
        $reqs = RequisicaoManutencao::query()->whereIn('id', $linhas->pluck('requisicao_manutencao_id')->filter()->unique())->get(['id', 'numero', 'estado'])->keyBy('id');
        $pedidos = PedidoCompra::query()->whereIn('id', $linhas->pluck('pedido_compra_id')->filter()->unique())->get(['id', 'numero_pedido', 'estado'])->keyBy('id');

        return $linhas->map(function ($n) use ($produtos, $reqs, $pedidos) {
            $p = $n->produto_id ? ($produtos[$n->produto_id] ?? null) : null;

            return $n->attributesToArray() + ['nome' => $p ? trim(($p->codigo ? "{$p->codigo} — " : '').$p->nome) : ($n->descricao ?: 'Artigo'), 'de_armazem' => $p ? (bool) $p->movimenta_stock : false,
                'requisicao_numero' => $reqs[$n->requisicao_manutencao_id]->numero ?? null, 'requisicao_estado' => $reqs[$n->requisicao_manutencao_id]->estado ?? null,
                'pedido_numero' => $pedidos[$n->pedido_compra_id]->numero_pedido ?? null, 'pedido_estado' => $pedidos[$n->pedido_compra_id]->estado ?? null,
                'falta_receber' => in_array($n->estado, ['REQUISITADO', 'PEDIDO_COMPRA'], true) ? bcsub((string) $n->quantidade, (string) $n->quantidade_recebida, 3) : '0.000'];
        })->all();
    }

    /**
     * Custo real do material: o que o armazém entregou (valorizado ao custo médio, na linha da requisição) e o que as Compras receberam (quantidade
     * recebida × preço da encomenda). A estimativa é um número livre e nunca se soma a isto.
     *
     * @return array{armazem: string, compras: string, total: string}
     */
    public function custoReal(OrdemTrabalhoImo $o): array
    {
        $armazem = (string) (LinhaRequisicaoManutencao::query()->whereIn('id', NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereNotNull('linha_requisicao_id')->select('linha_requisicao_id'))->sum('custo') ?: 0);
        $compras = '0.00';
        $pedidos = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereNotNull('pedido_compra_id')->get(['pedido_compra_id', 'produto_id', 'quantidade_compra']);
        foreach ($pedidos->groupBy('pedido_compra_id') as $pedidoId => $ns) {
            $encomendas = EncomendaCompra::query()->where('pedido_compra_id', $pedidoId)->where('estado', '<>', 'ANULADO')->pluck('id');
            foreach (ItemCompra::query()->whereIn('encomenda_compra_id', $encomendas)->whereIn('produto_id', $ns->pluck('produto_id'))->get(['quantidade_recebida', 'preco_unitario']) as $it) {
                $compras = bcadd($compras, bcmul((string) ($it->quantidade_recebida ?? 0), (string) ($it->preco_unitario ?? 0), 2), 2);
            }
        }
        $armazem = number_format((float) $armazem, 2, '.', '');

        return ['armazem' => $armazem, 'compras' => $compras, 'total' => bcadd($armazem, $compras, 2)];
    }

    // ---------------------------------------------------------------- gravar / aprovar

    /**
     * Abre ou altera uma ordem. O imóvel deriva da fracção. «Quem paga» senhorio nunca é debitável. Alterar o custo, quem paga ou o débito de uma
     * ordem aprovada retira-lhe a aprovação (o valor aprovado já não é o que se vai gastar).
     *
     * @param  array<string, mixed>  $d
     */
    public function gravar(array $d, ?OrdemTrabalhoImo $existente = null): OrdemTrabalhoImo
    {
        $descricao = Normaliza::texto($d['descricao'] ?? null) ?? throw new ErroNegocio('Descreva o trabalho.', 'OBRA_DESCRICAO');

        return DB::transaction(function () use ($d, $existente, $descricao) {
            $o = $existente ? OrdemTrabalhoImo::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail() : null;
            if ($o && $o->fechada()) {
                throw new ErroNegocio('A ordem está fechada: já não se altera.', 'OBRA_FECHADA');
            }
            [$imovelId, $fracaoId] = $this->local($d, $o);
            $tipo = (string) ($d['tipo'] ?? $o?->tipo ?? 'CORRECTIVA');
            if (! isset(CalculadoraObras::TIPOS_OBRA[$tipo])) {
                throw new ErroNegocio('Tipo de ordem desconhecido.', 'OBRA_TIPO');
            }
            $prioridade = (string) ($d['prioridade'] ?? $o?->prioridade ?? 'NORMAL');
            $quem = (string) ($d['quem_paga'] ?? $o?->quem_paga ?? 'SENHORIO');
            if (! isset(CalculadoraObras::PRIORIDADES[$prioridade]) || ! isset(CalculadoraObras::QUEM_PAGA[$quem])) {
                throw new ErroNegocio('Prioridade ou quem paga desconhecido.', 'OBRA_DADOS');
            }
            $debitavel = $quem === 'SENHORIO' ? false : (array_key_exists('debitavel', $d) ? (bool) $d['debitavel'] : ($o?->debitavel ?? true));
            $pedido = Normaliza::texto($d['data_pedido'] ?? null) ?? ($o?->data_pedido?->format('Y-m-d') ?? now()->toDateString());
            $prevista = array_key_exists('data_prevista', $d) ? Normaliza::texto($d['data_prevista']) : $o?->data_prevista?->format('Y-m-d');
            if ($prevista !== null && $prevista < $pedido) {
                throw new ErroNegocio('A data prevista é anterior à do pedido.', 'OBRA_DATAS');
            }
            $custo = array_key_exists('custo_estimado', $d) ? Normaliza::r2($d['custo_estimado'], true) : $o?->custo_estimado;
            if ($custo !== null && $custo < 0) {
                throw new ErroNegocio('O custo estimado não pode ser negativo.', 'OBRA_CUSTO');
            }
            $ficha = ! empty($d['ficha_id']) ? (int) $d['ficha_id'] : $o?->ficha_id;
            if ($ficha && ! FichaApetrechamento::query()->whereKey($ficha)->exists()) {
                throw new ErroNegocio('A ficha indicada não existe.', 'OBRA_FICHA');
            }
            $campos = ['tipo' => $tipo, 'descricao' => $descricao, 'imovel_id' => $imovelId, 'fracao_id' => $fracaoId, 'prioridade' => $prioridade, 'quem_paga' => $quem, 'debitavel' => $debitavel,
                'data_pedido' => $pedido, 'data_prevista' => $prevista, 'responsavel' => Normaliza::texto($d['responsavel'] ?? $o?->responsavel), 'pedido_por' => Normaliza::texto($d['pedido_por'] ?? $o?->pedido_por),
                'custo_estimado' => $custo, 'ficha_id' => $ficha ?: null, 'notas' => array_key_exists('notas', $d) ? Normaliza::texto($d['notas']) : $o?->notas, 'atualizado_por' => Autor::nome()];
            if ($o === null) {
                $campos += ['numero' => $this->numeracao->proximoNumeroOrdem($this->contexto->obrigatorio(), $tipo), 'estado' => 'ABERTA', 'criado_por' => Autor::nome()]
                    + array_filter(['plano_id' => $d['plano_id'] ?? null, 'plano_data' => $d['plano_data'] ?? null, 'vistoria_reserva_id' => $d['vistoria_reserva_id'] ?? null]);
                $o = OrdemTrabalhoImo::create($campos)->refresh();
                $this->registar($o, 'CRIADA', null, 'ABERTA', null, ['tipo' => $tipo]);

                return $o;
            }
            $retira = $o->aprovada_em !== null && ((float) ($o->custo_estimado ?? 0) !== (float) ($custo ?? 0) || $o->quem_paga !== $quem || (bool) $o->debitavel !== $debitavel);
            if ($retira) {
                $campos += ['aprovada_em' => null, 'aprovada_por' => null];
            }
            $o->update($campos);
            $this->registar($o, 'EDITADA', $o->estado, $o->estado, $retira ? 'Aprovação retirada: mudou o custo ou quem paga.' : null);

            return $o->refresh();
        });
    }

    /** @param  array<string, mixed>  $d @return array{0: int, 1: ?int} imóvel e fracção */
    private function local(array $d, ?OrdemTrabalhoImo $o): array
    {
        $fracaoId = array_key_exists('fracao_id', $d) ? (! empty($d['fracao_id']) ? (int) $d['fracao_id'] : null) : $o?->fracao_id;
        $imovelId = ! empty($d['imovel_id']) ? (int) $d['imovel_id'] : $o?->imovel_id;
        if ($fracaoId !== null) {
            $f = Fracao::query()->find($fracaoId) ?? throw new ErroNegocio('Fracção não encontrada.', 'OBRA_FRACCAO');
            if ($imovelId !== null && (int) $f->imovel_id !== $imovelId) {
                throw new ErroNegocio('A fracção não pertence ao imóvel indicado.', 'OBRA_FRACCAO_IMOVEL');
            }

            return [(int) $f->imovel_id, $fracaoId];
        }
        if ($imovelId === null) {
            throw new ErroNegocio('A ordem tem de dizer a que imóvel ou fracção pertence.', 'OBRA_LOCAL');
        }
        if (! Imovel::query()->whereKey($imovelId)->exists()) {
            throw new ErroNegocio('Imóvel não encontrado.', 'OBRA_IMOVEL');
        }

        return [$imovelId, null];
    }

    public function aprovar(OrdemTrabalhoImo $o): OrdemTrabalhoImo
    {
        return DB::transaction(function () use ($o) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->fechada()) {
                throw new ErroNegocio('A ordem está fechada: já não se aprova.', 'OBRA_FECHADA');
            }
            if ($o->aprovada_em !== null) {
                return $o;   // repetir não muda quem aprovou nem quando
            }
            $o->update(['aprovada_em' => now(), 'aprovada_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'APROVADA', $o->estado, $o->estado, null, ['custo_estimado' => $o->custo_estimado]);

            return $o->refresh();
        });
    }

    /** Abre a ordem de apetrechamento de uma fracção pelo que falta segundo a ficha: uma necessidade por falta. */
    public function abrirApetrechamento(Fracao $f, FichaApetrechamento $ficha, ?string $prioridade = null): array
    {
        $cmp = app(ServicoApetrechamento::class)->comparar($f, $ficha);
        $faltas = array_values(array_filter($cmp['linhas'], fn ($l) => bccomp($l['falta'], '0', 3) > 0));
        if ($faltas === []) {
            throw new ErroNegocio('Não falta nada: a fracção já tem tudo o que a ficha pede.', 'OBRA_NADA_FALTA');
        }

        return DB::transaction(function () use ($f, $ficha, $faltas, $prioridade) {
            $o = $this->gravar(['tipo' => 'APETRECHAMENTO', 'fracao_id' => $f->id, 'ficha_id' => $ficha->id, 'prioridade' => $prioridade ?: 'NORMAL', 'quem_paga' => 'SENHORIO',
                'descricao' => "Apetrechar {$f->designacao} segundo a ficha «{$ficha->nome}»"]);
            foreach ($faltas as $i => $l) {
                NecessidadeOrdemImo::create(['ordem_id' => $o->id, 'produto_id' => $l['produto_id'], 'descricao' => $l['produto_id'] ? null : mb_substr((string) $l['nome'], 0, 200),
                    'quantidade' => $l['falta'], 'estado' => 'POR_PEDIR', 'ordem' => $i + 1]);
            }

            return ['ordem' => $o, 'necessidades' => count($faltas)];
        });
    }

    // ---------------------------------------------------------------- necessidades

    /**
     * Grava as necessidades por pedir (as já pedidas ou recebidas não se mexem). Artigo repetido recusa-se.
     *
     * @param  list<array<string, mixed>>  $linhas
     */
    public function gravarNecessidades(OrdemTrabalhoImo $o, array $linhas): array
    {
        return DB::transaction(function () use ($o, $linhas) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->fechada()) {
                throw new ErroNegocio('A ordem está fechada: já não tem necessidades a gravar.', 'OBRA_FECHADA');
            }
            $intocaveis = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->where('estado', '<>', 'POR_PEDIR')->get();
            $limpas = [];
            $vistos = $intocaveis->whereNotNull('produto_id')->where('estado', '<>', 'CANCELADO')->pluck('produto_id')->flip()->all();
            foreach ($linhas as $x) {
                $produto = ! empty($x['produto_id']) ? (int) $x['produto_id'] : null;
                $descricao = Normaliza::texto($x['descricao'] ?? null);
                if ($produto === null && $descricao === null) {
                    continue;
                }
                if ($produto !== null) {
                    if (! Produto::query()->whereKey($produto)->exists()) {
                        throw new ErroNegocio('Um dos artigos não existe.', 'NECESSIDADE_ARTIGO');
                    }
                    if (isset($vistos[$produto])) {
                        throw new ErroNegocio('Há um artigo repetido: some as quantidades numa só linha.', 'NECESSIDADE_REPETIDA');
                    }
                    $vistos[$produto] = true;
                }
                $q = isset($x['quantidade']) && $x['quantidade'] !== '' ? (float) $x['quantidade'] : 1.0;
                if ($q < 0.001) {
                    throw new ErroNegocio('A quantidade mínima é 0,001.', 'NECESSIDADE_QUANTIDADE');
                }
                $limpas[] = ['produto_id' => $produto, 'descricao' => $produto === null ? mb_substr((string) $descricao, 0, 200) : null, 'quantidade' => CalculadoraObras::q($q)];
            }
            NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->where('estado', 'POR_PEDIR')->delete();
            foreach ($limpas as $i => $l) {
                NecessidadeOrdemImo::create($l + ['ordem_id' => $o->id, 'estado' => 'POR_PEDIR', 'ordem' => $intocaveis->count() + $i + 1]);
            }
            $this->registar($o, 'NECESSIDADES', $o->estado, $o->estado, null, ['por_pedir' => count($limpas)]);

            return $this->necessidades($o);
        });
    }

    /**
     * Pré-visualização do pedido: para cada necessidade por pedir, quanto vai ao armazém, quanto a compras e porquê.
     *
     * @return list<array<string, mixed>>
     */
    public function planoDePedido(OrdemTrabalhoImo $o, ?int $armazemId): array
    {
        $linhas = [];
        $produtos = Produto::query()->whereIn('id', NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereNotNull('produto_id')->select('produto_id'))->get()->keyBy('id');
        foreach (NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->where('estado', 'POR_PEDIR')->orderBy('ordem')->orderBy('id')->get() as $n) {
            $p = $n->produto_id ? ($produtos[$n->produto_id] ?? null) : null;
            $deArmazem = $p !== null && (bool) $p->movimenta_stock;
            $stock = $deArmazem ? app(ServicoOrdensManutencao::class)->stockDisponivel($p, $armazemId) : null;
            $nome = $p ? trim(($p->codigo ? "{$p->codigo} — " : '').$p->nome) : ($n->descricao ?: 'Linha sem artigo');
            $linhas[] = ['necessidade_id' => (int) $n->id, 'nome' => $nome, 'produto_id' => $n->produto_id, 'quantidade' => (string) $n->quantidade, 'stock' => $stock]
                + CalculadoraObras::repartir((string) $n->quantidade, $p !== null, $deArmazem, $stock);
        }

        return $linhas;
    }

    /**
     * Pede o material: no máximo uma requisição ao armazém (a de manutenção de Activos, sem duplicar) e um pedido de compra (o circuito normal de
     * Compras, com a deliberação por escalões). Tudo numa transacção. Exige ordem aprovada.
     *
     * @return array<string, mixed>
     */
    public function pedirMaterial(OrdemTrabalhoImo $o, ?int $armazemId, ?string $requerente = null): array
    {
        return DB::transaction(function () use ($o, $armazemId, $requerente) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->fechada()) {
                throw new ErroNegocio('A ordem já está fechada.', 'OBRA_FECHADA');
            }
            if ($o->aprovada_em === null) {
                throw new ErroNegocio('A ordem ainda não foi aprovada: peça a aprovação antes de pedir material.', 'OBRA_SEM_APROVACAO');
            }
            $plano = array_values(array_filter($this->planoDePedido($o, $armazemId), fn ($l) => ! $l['bloqueada'] && (bccomp($l['para_armazem'], '0', 3) > 0 || bccomp($l['para_compra'], '0', 3) > 0)));
            if ($plano === []) {
                throw new ErroNegocio('Não há necessidades por pedir nesta ordem.', 'OBRA_SEM_NECESSIDADES');
            }
            $paraArmazem = array_values(array_filter($plano, fn ($l) => bccomp($l['para_armazem'], '0', 3) > 0));
            $paraCompra = array_values(array_filter($plano, fn ($l) => bccomp($l['para_compra'], '0', 3) > 0));
            if ($paraArmazem !== [] && (! $armazemId || ! Armazem::query()->whereKey($armazemId)->exists())) {
                throw new ErroNegocio('Escolha o armazém de onde sai o material.', 'ARMAZEM_EM_FALTA');
            }
            $ref = $this->referencia($o);
            $quem = trim((string) $requerente) ?: (Autor::nome());
            $hoje = now()->toDateString();
            $req = null;
            $pedido = null;
            $linhasReq = [];
            if ($paraArmazem !== []) {
                $req = RequisicaoManutencao::create(['numero' => app(NumeracaoManutencao::class)->proximo('RQ'), 'armazem_id' => $armazemId,
                    'requerente' => mb_substr("{$quem} (Imobiliária {$o->numero}".($ref ? " — {$ref}" : '').')', 0, 150), 'data' => $hoje, 'estado' => RequisicaoManutencao::PENDENTE, 'criado_por' => Autor::nome()]);
                foreach ($paraArmazem as $l) {
                    $linhasReq[$l['necessidade_id']] = LinhaRequisicaoManutencao::create(['requisicao_manutencao_id' => $req->id, 'produto_id' => $l['produto_id'], 'descricao' => mb_substr($l['nome'], 0, 500),
                        'quantidade' => $l['para_armazem'], 'quantidade_entregue' => 0, 'estado' => 'PENDENTE', 'custo' => 0])->id;
                }
            }
            $linhasCompra = [];
            if ($paraCompra !== []) {
                $pedido = app(ServicoProcessoCompras::class)->criarPedido([
                    'nome_requerente' => mb_substr("{$quem} (Imobiliária {$o->numero})", 0, 255), 'data' => $hoje,
                    'descricao' => mb_substr((CalculadoraObras::TIPOS_OBRA[$o->tipo]['rot'] ?? 'Ordem')." {$o->numero}".($ref ? " — {$ref}" : ''), 0, 255),
                    'data_entrega' => $o->data_prevista?->format('Y-m-d') ?? $hoje,
                    'observacoes' => "Gerado pela ordem {$o->numero} da Gestão Imobiliária.\n".$o->descricao,
                    'linhas' => array_map(fn ($l) => ['produto_id' => $l['produto_id'], 'quantidade' => $l['para_compra'], 'descricao' => $l['nome']], $paraCompra)]);
                $itens = ItemCompra::query()->where('pedido_compra_id', $pedido->id)->where('tipo_documento_origem', 'PEDIDO')->orderBy('id')->get();
                foreach ($paraCompra as $i => $l) {
                    $linhasCompra[$l['necessidade_id']] = $itens[$i]->id ?? null;
                }
            }
            foreach ($plano as $l) {
                $n = NecessidadeOrdemImo::query()->whereKey($l['necessidade_id'])->lockForUpdate()->firstOrFail();
                $comArmazem = bccomp($l['para_armazem'], '0', 3) > 0;
                $comCompra = bccomp($l['para_compra'], '0', 3) > 0;
                $n->update(['estado' => $comCompra ? 'PEDIDO_COMPRA' : 'REQUISITADO', 'quantidade_armazem' => $l['para_armazem'], 'quantidade_compra' => $l['para_compra'],
                    'requisicao_manutencao_id' => $comArmazem ? $req?->id : null, 'linha_requisicao_id' => $comArmazem ? ($linhasReq[$n->id] ?? null) : null,
                    'pedido_compra_id' => $comCompra ? $pedido?->id : null, 'linha_compra_id' => $comCompra ? ($linhasCompra[$n->id] ?? null) : null]);
            }
            $estadoAntes = $o->estado;
            if (in_array($o->estado, ['ABERTA', 'EM_CURSO'], true)) {
                $o->update(['estado' => 'AGUARDA_MATERIAL', 'atualizado_por' => Autor::nome()]);
            }
            $this->registar($o, 'MATERIAL_PEDIDO', $estadoAntes, $o->estado, null, ['armazem' => count($paraArmazem), 'compra' => count($paraCompra), 'requisicao' => $req?->numero, 'pedido' => $pedido?->numero_pedido]);

            return ['requisicao_id' => $req?->id, 'requisicao_numero' => $req?->numero, 'pedido_compra_id' => $pedido?->id, 'pedido_compra_numero' => $pedido?->numero_pedido,
                'armazem' => count($paraArmazem), 'compra' => count($paraCompra), 'bloqueadas' => count($this->planoDePedido($o, $armazemId))];
        });
    }

    // ---------------------------------------------------------------- recepção ligada

    /**
     * Actualiza as quantidades recebidas a partir das origens (entregas do armazém e recepções de Compras), cancela a parte cujo pedido ou
     * requisição foi anulado, põe no inventário da fracção o que chegou numa ordem de apetrechamento (só a diferença: repetir não duplica) e
     * passa a ordem de «aguarda material» a «em curso» quando tudo chegou. Idempotente.
     *
     * @return array{recebido: string, bens_criados: int, necessidades: int}
     */
    public function sincronizar(OrdemTrabalhoImo $o): array
    {
        return DB::transaction(function () use ($o) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            $recebido = '0.000';
            $bens = 0;
            $linhas = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereIn('estado', ['REQUISITADO', 'PEDIDO_COMPRA', 'RECEBIDO'])->lockForUpdate()->orderBy('id')->get();
            $reqs = RequisicaoManutencao::query()->whereIn('id', $linhas->pluck('requisicao_manutencao_id')->filter()->unique())->get(['id', 'estado'])->keyBy('id');
            $linhasReq = LinhaRequisicaoManutencao::query()->whereIn('id', $linhas->pluck('linha_requisicao_id')->filter()->unique())->get(['id', 'quantidade_entregue'])->keyBy('id');
            $pedidos = PedidoCompra::query()->whereIn('id', $linhas->pluck('pedido_compra_id')->filter()->unique())->get(['id', 'estado'])->keyBy('id');
            foreach ($linhas as $n) {
                $armazem = (string) $n->quantidade_armazem;
                $compra = (string) $n->quantidade_compra;
                $mudou = [];
                if ($n->requisicao_manutencao_id && ($reqs[$n->requisicao_manutencao_id]->estado ?? null) === RequisicaoManutencao::CANCELADA) {
                    $armazem = '0.000';
                    $mudou += ['quantidade_armazem' => '0.000', 'requisicao_manutencao_id' => null, 'linha_requisicao_id' => null];
                }
                if ($n->pedido_compra_id && ($pedidos[$n->pedido_compra_id]->estado ?? null) === 'ANULADO') {
                    $compra = '0.000';
                    $mudou += ['quantidade_compra' => '0.000', 'pedido_compra_id' => null, 'linha_compra_id' => null];
                }
                $entregue = $n->linha_requisicao_id && isset($linhasReq[$n->linha_requisicao_id]) ? CalculadoraObras::q($linhasReq[$n->linha_requisicao_id]->quantidade_entregue) : '0.000';
                $recebidaCompra = $this->recebidoDeCompras($n);
                $recebida = CalculadoraObras::recebida((string) $n->quantidade, $armazem, $entregue, $compra, $recebidaCompra);
                $semParte = bccomp($armazem, '0', 3) <= 0 && bccomp($compra, '0', 3) <= 0;
                $estado = bccomp($recebida, (string) $n->quantidade, 3) >= 0 ? 'RECEBIDO' : ($semParte ? 'POR_PEDIR' : (bccomp($compra, '0', 3) > 0 ? 'PEDIDO_COMPRA' : 'REQUISITADO'));
                if ($estado !== $n->estado) {
                    $mudou += ['estado' => $estado];
                }
                if (bccomp($recebida, (string) $n->quantidade_recebida, 3) !== 0) {
                    $mudou += ['quantidade_recebida' => $recebida];
                }
                $recebido = bcadd($recebido, $recebida, 3);
                $delta = bcsub($recebida, (string) $n->quantidade_apetrechada, 3);
                if ($o->tipo === 'APETRECHAMENTO' && $o->fracao_id && bccomp($delta, '0', 3) > 0) {
                    $produto = $n->produto_id ? Produto::query()->find($n->produto_id, ['id', 'nome']) : null;
                    BemFracao::create(['fracao_id' => $o->fracao_id, 'produto_id' => $n->produto_id, 'descricao' => $n->produto_id ? null : ($n->descricao ?: 'Material recebido'), 'quantidade' => $delta,
                        'estado' => 'NOVO', 'origem' => bccomp($compra, '0', 3) > 0 ? 'COMPRA' : 'ARMAZEM', 'data' => now()->toDateString(), 'necessidade_id' => $n->id,
                        'notas' => mb_substr("Recebido na ordem {$o->numero}".($produto ? " ({$produto->nome})" : ''), 0, 300), 'criado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]);
                    $mudou += ['quantidade_apetrechada' => $recebida];
                    $bens++;
                }
                if ($mudou !== []) {
                    $n->update($mudou);
                }
            }
            if ($o->estado === 'AGUARDA_MATERIAL') {
                $abertas = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereIn('estado', ['POR_PEDIR', 'REQUISITADO', 'PEDIDO_COMPRA'])->exists();
                if (! $abertas) {
                    $o->update(['estado' => 'EM_CURSO']);
                    $this->registar($o, 'EM_CURSO', 'AGUARDA_MATERIAL', 'EM_CURSO', 'Chegou todo o material.');
                }
            }
            if ($bens > 0) {
                $this->registar($o, 'RECEBIDO', $o->estado, $o->estado, null, ['bens' => $bens]);
            }

            return ['recebido' => $recebido, 'bens_criados' => $bens, 'necessidades' => $linhas->count()];
        });
    }

    /** O que as Compras receberam desta linha: as encomendas do pedido (não anuladas), do mesmo artigo. */
    private function recebidoDeCompras(NecessidadeOrdemImo $n): string
    {
        if (! $n->pedido_compra_id || ! $n->produto_id) {
            return '0.000';
        }
        $encomendas = EncomendaCompra::query()->where('pedido_compra_id', $n->pedido_compra_id)->where('estado', '<>', 'ANULADO')->pluck('id');
        $soma = ItemCompra::query()->whereIn('encomenda_compra_id', $encomendas)->where('produto_id', $n->produto_id)->sum('quantidade_recebida');

        return CalculadoraObras::q($soma);
    }

    /** Sincroniza todas as ordens abertas com material pedido (tarefa agendada); devolve quantas e as falhas. @return array{ordens: int, bens: int} */
    public function sincronizarAbertas(): array
    {
        $r = ['ordens' => 0, 'bens' => 0];
        $ids = OrdemTrabalhoImo::query()->whereNotIn('estado', OrdemTrabalhoImo::FECHADAS)->whereIn('id', NecessidadeOrdemImo::query()->whereIn('estado', ['REQUISITADO', 'PEDIDO_COMPRA'])->select('ordem_id'))->pluck('id');
        foreach ($ids as $id) {
            $x = $this->sincronizar(OrdemTrabalhoImo::query()->findOrFail($id));
            $r['ordens']++;
            $r['bens'] += $x['bens_criados'];
        }

        return $r;
    }

    // ---------------------------------------------------------------- estados

    public function iniciar(OrdemTrabalhoImo $o): OrdemTrabalhoImo
    {
        return DB::transaction(function () use ($o) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->estado !== 'ABERTA') {
                throw new ErroNegocio($o->fechada() ? 'A ordem já está fechada.' : 'Só uma ordem aberta se começa: as restantes já estão em andamento.', 'OBRA_ESTADO');
            }
            $o->update(['estado' => 'EM_CURSO', 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'INICIADA', 'ABERTA', 'EM_CURSO', null);

            return $o->refresh();
        });
    }

    /**
     * Conclui. Com necessidades ainda por receber recusa, a menos que se peça para as cancelar (como a confirmação do legado, agora no servidor);
     * as por pedir não bloqueiam (como no legado) e ficam canceladas.
     */
    public function concluir(OrdemTrabalhoImo $o, bool $cancelarPendentes = false, ?string $data = null): OrdemTrabalhoImo
    {
        $this->sincronizar($o);

        return DB::transaction(function () use ($o, $cancelarPendentes, $data) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->fechada()) {
                throw new ErroNegocio('A ordem já está fechada.', 'OBRA_FECHADA');
            }
            $hoje = now()->toDateString();
            $data = Normaliza::texto($data) ?? $hoje;
            if ($data > $hoje || $data < $o->data_pedido->format('Y-m-d')) {
                throw new ErroNegocio('A data de conclusão não pode ser futura nem anterior ao pedido.', 'OBRA_DATAS');
            }
            $pendentes = NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereIn('estado', ['REQUISITADO', 'PEDIDO_COMPRA'])->lockForUpdate()->get();
            if ($pendentes->isNotEmpty() && ! $cancelarPendentes) {
                throw new ErroNegocio("A ordem tem {$pendentes->count()} necessidade(s) por receber (requisição ou pedido de compra).", 'OBRA_PENDENTES', 422, ['pendentes' => $pendentes->count()]);
            }
            NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereIn('estado', ['POR_PEDIR', 'REQUISITADO', 'PEDIDO_COMPRA'])->update(['estado' => 'CANCELADO']);
            $de = $o->estado;
            $o->update(['estado' => 'CONCLUIDA', 'data_conclusao' => $data, 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'CONCLUIDA', $de, 'CONCLUIDA', $pendentes->isNotEmpty() ? 'Concluída com necessidades por receber, canceladas.' : null, ['pendentes_canceladas' => $pendentes->count()]);

            return $o->refresh();
        });
    }

    public function cancelar(OrdemTrabalhoImo $o, string $motivo): OrdemTrabalhoImo
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Diga porque cancela a ordem.', 'OBRA_MOTIVO');

        return DB::transaction(function () use ($o, $motivo) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->fechada()) {
                throw new ErroNegocio('A ordem já está fechada.', 'OBRA_FECHADA');
            }
            if ($o->debitado_em !== null) {
                throw new ErroNegocio('A ordem já foi debitada ao inquilino: desfaça o débito antes de a cancelar.', 'OBRA_DEBITADA');
            }
            NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->where('estado', 'POR_PEDIR')->update(['estado' => 'CANCELADO']);
            $de = $o->estado;
            $o->update(['estado' => 'CANCELADA', 'motivo_cancelamento' => mb_substr($motivo, 0, 300), 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'CANCELADA', $de, 'CANCELADA', $motivo);

            return $o->refresh();
        });
    }

    public function eliminar(OrdemTrabalhoImo $o): void
    {
        DB::transaction(function () use ($o) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if (NecessidadeOrdemImo::query()->where('ordem_id', $o->id)->whereIn('estado', ['REQUISITADO', 'PEDIDO_COMPRA', 'RECEBIDO'])->exists()) {
                throw new ErroNegocio('A ordem já pediu material ao armazém ou a compras. Cancele-a em vez de a apagar.', 'OBRA_COM_MATERIAL');
            }
            if ($o->estado === 'CONCLUIDA' || $o->debitado_em !== null) {
                throw new ErroNegocio('Uma ordem concluída ou debitada não se apaga: é o registo do que foi feito.', 'OBRA_CONCLUIDA');
            }
            $o->delete();
        });
    }

    // ---------------------------------------------------------------- débito ao inquilino

    /**
     * Debita ao inquilino (ligação à F3): acrescenta o custo como encargo extraordinário numa renda ainda por facturar do contrato que cobre a
     * fracção. Exige ordem concluída, aprovada, debitável e ainda não debitada; repetir devolve o débito existente (idempotente). O valor por
     * omissão é o custo real do material (ou, sem ele, a estimativa), repartido a meio se «partilhado».
     *
     * @param  array{renda_id?: ?int, valor?: float|string|null, designacao?: ?string}  $d
     * @return array<string, mixed>
     */
    public function debitar(OrdemTrabalhoImo $o, array $d = []): array
    {
        return DB::transaction(function () use ($o, $d) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->debitado_em !== null) {
                $r = RendaArrendamento::query()->find($o->debitado_renda_id);

                return ['ordem' => $o, 'renda' => $r, 'valor' => $o->debitado_valor, 'repetido' => true];
            }
            if ($o->estado !== 'CONCLUIDA') {
                throw new ErroNegocio('Só se debita uma ordem concluída.', 'OBRA_DEBITO_ESTADO');
            }
            if (! $o->debitavel || $o->quem_paga === 'SENHORIO') {
                throw new ErroNegocio('Esta ordem não é debitável ao inquilino: o custo é do senhorio.', 'OBRA_NAO_DEBITAVEL');
            }
            if ($o->aprovada_em === null) {
                throw new ErroNegocio('A ordem não foi aprovada: aprove-a antes de debitar o inquilino.', 'OBRA_SEM_APROVACAO');
            }
            $valor = $d['valor'] ?? null;
            if ($valor === null || $valor === '') {
                $real = $this->custoReal($o)['total'];
                $base = bccomp($real, '0', 2) > 0 ? $real : number_format((float) ($o->custo_estimado ?? 0), 2, '.', '');
                $valor = $o->quem_paga === 'PARTILHADO' ? bcdiv(bcadd($base, '0.005', 3), '2', 2) : $base;
            }
            if (! is_numeric($valor) || (float) $valor <= 0) {
                throw new ErroNegocio('A ordem não tem custo a debitar: indique o valor.', 'OBRA_DEBITO_VALOR');
            }
            $valor = number_format((float) $valor, 2, '.', '');
            $renda = $this->rendaParaDebito($o, ! empty($d['renda_id']) ? (int) $d['renda_id'] : null);
            $origem = 'OBRA:'.$o->id;
            $renda = RendaArrendamento::query()->whereKey($renda->id)->lockForUpdate()->firstOrFail();
            if (! collect($renda->extras ?? [])->contains(fn ($e) => ($e['origem'] ?? null) === $origem)) {
                $renda = $this->rendas->adicionarExtra($renda, Normaliza::texto($d['designacao'] ?? null) ?? mb_substr("Obra {$o->numero}: {$o->descricao}", 0, 150), $valor, $origem);
            }
            $o->update(['debitado_em' => now(), 'debitado_renda_id' => $renda->id, 'debitado_valor' => $valor, 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'DEBITADA', 'CONCLUIDA', 'CONCLUIDA', null, ['renda_id' => $renda->id, 'periodo' => $renda->periodo, 'valor' => $valor]);

            return ['ordem' => $o->refresh(), 'renda' => $renda->refresh(), 'valor' => (float) $valor, 'repetido' => false];
        });
    }

    /** Desfaz o débito enquanto a renda não é documento: retira o encargo da renda e liberta a ordem para voltar a ser debitada. */
    public function desfazerDebito(OrdemTrabalhoImo $o): OrdemTrabalhoImo
    {
        return DB::transaction(function () use ($o) {
            $o = OrdemTrabalhoImo::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
            if ($o->debitado_em === null) {
                return $o;
            }
            $renda = RendaArrendamento::query()->findOrFail($o->debitado_renda_id);
            $this->rendas->removerExtra($renda, 'OBRA:'.$o->id);
            $o->update(['debitado_em' => null, 'debitado_renda_id' => null, 'debitado_valor' => null, 'atualizado_por' => Autor::nome()]);
            $this->registar($o, 'DEBITO_DESFEITO', 'CONCLUIDA', 'CONCLUIDA', null, ['renda_id' => $renda->id]);

            return $o->refresh();
        });
    }

    /** A renda por facturar do contrato do inquilino que cobre a fracção da ordem (a do período corrente ou a seguinte; ou a indicada). */
    private function rendaParaDebito(OrdemTrabalhoImo $o, ?int $rendaId): RendaArrendamento
    {
        $contratos = $this->contratosDoLocal($o);
        if ($rendaId !== null) {
            $r = RendaArrendamento::query()->find($rendaId) ?? throw new ErroNegocio('Renda não encontrada.', 'OBRA_RENDA');
            if (! $contratos->contains((int) $r->contrato_id)) {
                throw new ErroNegocio('A renda indicada não é do inquilino desta fracção.', 'OBRA_RENDA_OUTRO_CONTRATO');
            }

            return $r;
        }
        if ($contratos->isEmpty()) {
            throw new ErroNegocio($o->fracao_id ? 'A fracção não tem contrato de arrendamento activo: não há inquilino a debitar.' : 'A ordem é do imóvel todo: indique a renda do inquilino a debitar.', 'OBRA_SEM_CONTRATO');
        }
        $atual = now()->format('Y-m');
        $rendas = RendaArrendamento::query()->whereIn('contrato_id', $contratos)->where('posicao', 'SENHORIO')->whereIn('estado', ['PREVISTA', 'APROVADA'])->orderBy('periodo')->get();
        $r = $rendas->first(fn ($x) => $x->periodo >= $atual) ?? $rendas->last();
        if (! $r) {
            throw new ErroNegocio('Não há renda por facturar deste inquilino: gere a renda do próximo período e volte a debitar.', 'OBRA_SEM_RENDA');
        }

        return $r;
    }

    /** @return Collection<int, int> ids dos contratos activos do lado senhorio que cobrem a fracção da ordem (ou o imóvel, se a ordem é do imóvel todo, apenas com renda indicada) */
    private function contratosDoLocal(OrdemTrabalhoImo $o): Collection
    {
        if ($o->fracao_id) {
            $ids = ContratoFracao::query()->where('fracao_id', $o->fracao_id)->where('posicao', 'SENHORIO')->pluck('contrato_id');
        } else {
            $ids = ContratoArrendamento::query()->where('imovel_id', $o->imovel_id)->where('posicao', 'SENHORIO')->pluck('id');
        }

        return ContratoArrendamento::query()->whereIn('id', $ids)->where('posicao', 'SENHORIO')->where('estado', 'ACTIVO')->pluck('id')->map(fn ($i) => (int) $i);
    }

    // ---------------------------------------------------------------- danos de reservas

    /**
     * Abre a ordem de reparação dos danos de uma reserva a partir da vistoria de saída. Idempotente (uma ordem por vistoria). A reserva por uma
     * fracção com contrato em vigor e sem caução retida fica a debitar ao inquilino; se parte do custo já foi retida da caução (F4), a ordem fica
     * por conta do senhorio para não cobrar duas vezes (a diferença decide-se à mão).
     *
     * @return array{ordem: OrdemTrabalhoImo, nova: bool}
     */
    public function deVistoria(VistoriaReserva $v): array
    {
        return DB::transaction(function () use ($v) {
            $v = VistoriaReserva::query()->whereKey($v->id)->lockForUpdate()->firstOrFail();
            $existente = OrdemTrabalhoImo::query()->where('vistoria_reserva_id', $v->id)->first();
            if ($existente) {
                return ['ordem' => $existente, 'nova' => false];
            }
            if ($v->quando !== 'DEPOIS' || (! in_array($v->estado, ['DANIFICADO', 'EM_FALTA'], true) && (float) ($v->custo_danos ?? 0) <= 0)) {
                throw new ErroNegocio('Só a vistoria de saída com danos dá ordem de obra.', 'OBRA_VISTORIA_SEM_DANOS');
            }
            $r = DB::table('reservas_espaco')->where('id', $v->reserva_id)->first(['id', 'espaco_id', 'imovel_id', 'fracao_id', 'contrato_id', 'data', 'caucao_retida']);
            if (! $r) {
                throw new ErroNegocio('Reserva não encontrada.', 'OBRA_RESERVA');
            }
            $espaco = DB::table('espacos_comuns')->where('id', $r->espaco_id)->value('nome');
            $custo = (float) ($v->custo_danos ?? 0);
            $debitavel = $r->contrato_id !== null && (float) $r->caucao_retida <= 0;
            $o = $this->gravar(['tipo' => 'CORRECTIVA', 'imovel_id' => (int) $r->imovel_id, 'fracao_id' => null, 'prioridade' => 'NORMAL', 'quem_paga' => $debitavel ? 'INQUILINO' : 'SENHORIO',
                'debitavel' => $debitavel, 'custo_estimado' => $custo > 0 ? $custo : null, 'pedido_por' => 'Vistoria da reserva',
                'descricao' => "Reparar os danos da reserva de {$espaco} em ".CarbonImmutable::parse((string) $r->data)->format('d/m/Y').' (vistoria de saída)'.($v->observacoes ? ": {$v->observacoes}" : ''),
                'notas' => $debitavel ? null : ((float) $r->caucao_retida > 0 ? 'Parte do custo já foi retida da caução da reserva: não se debita outra vez.' : 'Reserva interna ou sem contrato: custo do senhorio.')]);
            $o->update(['vistoria_reserva_id' => $v->id]);
            $this->registar($o, 'DE_VISTORIA', null, 'ABERTA', null, ['vistoria_id' => $v->id, 'reserva_id' => $r->id]);

            return ['ordem' => $o->refresh(), 'nova' => true];
        });
    }

    // ---------------------------------------------------------------- apoio

    private function referencia(OrdemTrabalhoImo $o): string
    {
        $im = Imovel::query()->find($o->imovel_id, ['codigo']);
        $fr = $o->fracao_id ? Fracao::query()->find($o->fracao_id, ['designacao']) : null;

        return trim(($im?->codigo ?? '').($fr ? " · {$fr->designacao}" : ''));
    }

    /** @param  array<string, mixed>|null  $dados */
    public function registar(OrdemTrabalhoImo $o, string $evento, ?string $de, ?string $para, ?string $motivo, ?array $dados = null): void
    {
        try {
            HistoricoOrdemImo::create(['ordem_id' => $o->id, 'evento' => $evento, 'estado_de' => $de, 'estado_para' => $para, 'motivo' => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
                'dados' => $dados, 'utilizador_id' => Autor::id()]);
        } catch (UniqueConstraintViolationException) {
            // o trilho nunca bloqueia a acção
        }
    }
}
