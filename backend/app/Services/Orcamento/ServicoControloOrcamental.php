<?php

namespace App\Services\Orcamento;

use App\Exceptions\ErroNegocio;
use App\Exceptions\ErroOrcamental;
use App\Models\LinhaOrcamento;
use App\Models\LogAlertaOrcamental;
use App\Models\OrcamentoAnual;
use App\Models\PedidoExtrapolacaoOrcamento;
use App\Models\Produto;
use App\Models\RubricaOrcamental;
use App\Services\Compras\ServicoConfigCompras;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Controlo orçamental nos documentos (OrcControlo.validar e OrcPlano.verificar/compromissos/monitor,
 * js/modules/orcamento/orcamento_planeamento*.js). Paridade: só rubricas activas de CUSTO/PAGAMENTO com controlo;
 * todos os orçamentos APROVADOS do tipo e do ano cujas dimensões abrangem a linha; % = (consumido + documento) /
 * orçado (acumulado até ao mês ou ano inteiro); AVISO ≥ aviso; acima do limite, APROVACAO ou BLOQUEIO conforme o modo;
 * pedido de excesso PENDENTE → APROVADO → UTILIZADO (um pedido aprovado para o documento, de valor suficiente, deixa-o
 * passar); «aprovar no acto» para quem tem a permissão; registo de todas as ocorrências.
 * Correcções (ADR-045):
 *   - compromissos pela quantidade AINDA POR FACTURAR de cada linha da encomenda (o legado largava a encomenda inteira
 *     à primeira factura parcial) e encomendas anuladas deixam de contar (o legado nunca as anulava);
 *   - rubrica sem dotação num orçamento aprovado: aviso «sem dotação», não bloqueio (o legado dividia por zero e
 *     bloqueava qualquer gasto);
 *   - falha fechada: um erro no controlo trava o documento (o legado deixava gravar);
 *   - a permissão de aprovar excessos é verificada no servidor e ninguém aprova o seu próprio pedido;
 *   - o pedido aprovado só passa a UTILIZADO se o documento for gravado (mesma transacção); o legado consumia-o antes;
 *   - no modo AVISAR o monitor nunca mostra «excedido» (o limite não se aplica a esse modo).
 */
final class ServicoControloOrcamental
{
    private const ORDEM = ['OK' => 0, 'AVISO' => 1, 'SEM_DOTACAO' => 1, 'APROVACAO' => 2, 'BLOQUEIO' => 3];

    public function __construct(
        private readonly ServicoExecucaoOrcamental $execucao,
        private readonly ServicoConfigCompras $configCompras,
    ) {}

    // ───────────── Compromissos ─────────────

    /** Conta de custo/compra de uma linha de compra (como na contabilização das compras). */
    private function contaCompra(?int $produto): ?string
    {
        $p = $produto ? Produto::query()->withTrashed()->find($produto) : null;
        try {
            return $p?->movimenta_stock ? ($p->conta_compra ?: $this->configCompras->exigir('compras_mercadorias', ''))
                : ($p?->conta_custo ?: $this->configCompras->exigir('custos_servicos', ''));
        } catch (ErroNegocio) {
            return null;
        }
    }

    /**
     * Compromissos do ano: encomendas pela parte por facturar, facturas de fornecedor por contabilizar (exploração),
     * incluindo as feitas a partir de encomenda: o registo da factura já abate a quantidade facturada da encomenda
     * (ServicoFaturasCompra::registarDaEncomenda), pelo que não há dupla contagem e o valor não desaparece até à contabilização;
     * pagamentos pendentes (tesouraria).
     *
     * @param  array{encomenda_compra_id?: int, fatura_compra_id?: int, documento_tesouraria_id?: int}  $excluir
     * @return list<array{codigo_conta: string, valor: float, unidade_negocio_id: ?int, centro_custo_id: ?int, projeto_id: ?int, data: string}>
     */
    public function compromissos(string $tipo, int $ano, array $excluir = []): array
    {
        $saida = [];
        if ($tipo === 'EXPLORACAO') {
            $encomendas = DB::table('itens_compra as i')->join('encomendas_compra as e', 'e.id', '=', 'i.encomenda_compra_id')
                ->where('e.empresa_id', $this->empresa())->whereYear('e.data', $ano)->whereNull('i.fatura_compra_id')->where('i.tipo_documento_origem', 'ENCOMENDA')
                ->where(fn ($q) => $q->whereNull('e.estado')->orWhereNotIn('e.estado', ['ANULADA', 'CANCELADA']))
                ->when($excluir['encomenda_compra_id'] ?? null, fn ($q, $v) => $q->where('e.id', '<>', $v))
                ->get(['i.produto_id', 'i.quantidade', 'i.quantidade_faturada', 'i.total_kz', 'i.projeto_id', 'e.unidade_negocio_id', 'e.centro_custo_id', 'e.projeto_id as projeto_cab', 'e.data']);
            foreach ($encomendas as $l) {
                $q = (float) $l->quantidade;
                $porFaturar = $q > 0 ? max(0, $q - (float) $l->quantidade_faturada) / $q : 0;
                $valor = round((float) $l->total_kz * $porFaturar, 2);
                if ($valor > 0 && ($conta = $this->contaCompra($l->produto_id))) {
                    $saida[] = ['codigo_conta' => $conta, 'valor' => $valor, 'unidade_negocio_id' => $l->unidade_negocio_id, 'centro_custo_id' => $l->centro_custo_id,
                        'projeto_id' => $l->projeto_id ?? $l->projeto_cab, 'data' => substr((string) $l->data, 0, 10)];
                }
            }
            $faturas = DB::table('itens_compra as i')->join('faturas_compra as f', 'f.id', '=', 'i.fatura_compra_id')
                ->where('f.empresa_id', $this->empresa())->whereYear('f.data', $ano)->where(fn ($q) => $q->whereNull('f.contabilizado')->orWhere('f.contabilizado', false))
                ->where(fn ($q) => $q->whereNull('f.estado')->orWhere('f.estado', '<>', 'ANULADA'))
                ->when($excluir['fatura_compra_id'] ?? null, fn ($q, $v) => $q->where('f.id', '<>', $v))
                ->get(['i.produto_id', 'i.total_kz', 'i.projeto_id', 'f.unidade_negocio_id', 'f.centro_custo_id', 'f.projeto_id as projeto_cab', 'f.data']);
            foreach ($faturas as $l) {
                if ((float) $l->total_kz > 0 && ($conta = $this->contaCompra($l->produto_id))) {
                    $saida[] = ['codigo_conta' => $conta, 'valor' => (float) $l->total_kz, 'unidade_negocio_id' => $l->unidade_negocio_id, 'centro_custo_id' => $l->centro_custo_id,
                        'projeto_id' => $l->projeto_id ?? $l->projeto_cab, 'data' => substr((string) $l->data, 0, 10)];
                }
            }
        } else {
            $pag = DB::table('itens_documento_tesouraria as i')->join('documentos_tesouraria as d', 'd.id', '=', 'i.documento_tesouraria_id')
                ->where('d.empresa_id', $this->empresa())->where('d.tipo', 'PAGAMENTO')->where('d.estado', 'PENDENTE')->whereYear('d.data_documento', $ano)
                ->when($excluir['documento_tesouraria_id'] ?? null, fn ($q, $v) => $q->where('d.id', '<>', $v))
                ->get(['i.codigo_conta', 'i.tipo_dc', 'i.valor', 'i.unidade_negocio_id', 'i.centro_custo_id', 'i.projeto_id', 'd.data_documento']);
            foreach ($pag as $l) {
                $saida[] = ['codigo_conta' => (string) $l->codigo_conta, 'valor' => ($l->tipo_dc === 'D' ? 1 : -1) * (float) $l->valor, 'unidade_negocio_id' => $l->unidade_negocio_id,
                    'centro_custo_id' => $l->centro_custo_id, 'projeto_id' => $l->projeto_id, 'data' => (string) $l->data_documento];
            }
        }

        return $saida;
    }

    /** Linhas de controlo a partir de linhas de compra (valor líquido em Kz, conta como na contabilização). */
    public function linhasCompra(iterable $itens, object $cabecalho): array
    {
        $saida = [];
        foreach ($itens as $i) {
            if ((float) $i->total_kz > 0 && ($conta = $this->contaCompra($i->produto_id))) {
                $saida[] = ['codigo_conta' => $conta, 'valor' => (float) $i->total_kz, 'unidade_negocio_id' => $cabecalho->unidade_negocio_id,
                    'centro_custo_id' => $cabecalho->centro_custo_id, 'projeto_id' => $i->projeto_id ?? $cabecalho->projeto_id];
            }
        }

        return $saida;
    }

    // ───────────── Verificação ─────────────

    /** Compromisso datado até ao mês indicado (inclusive) do ano do orçamento. */
    private static function ateAoMes(array $compromisso, int $meses): bool
    {
        return (int) substr((string) $compromisso['data'], 5, 2) <= $meses;
    }

    private static function abrange(OrcamentoAnual $o, array $l): bool
    {
        return (! $o->unidade_negocio_id || (int) ($l['unidade_negocio_id'] ?? 0) === $o->unidade_negocio_id)
            && (! $o->centro_custo_id || (int) ($l['centro_custo_id'] ?? 0) === $o->centro_custo_id)
            && (! $o->projeto_id || (int) ($l['projeto_id'] ?? 0) === $o->projeto_id);
    }

    /**
     * @param  list<array{codigo_conta: string, valor: float|string, unidade_negocio_id?: ?int, centro_custo_id?: ?int, projeto_id?: ?int}>  $linhas  (valor com sinal: + consome)
     * @param  array<string, mixed>  $excluir  documento a não contar em compromissos/realizado (é o próprio)
     * @return list<array<string, mixed>> resultado por orçamento × rubrica
     */
    public function verificar(string $tipo, string $data, array $linhas, array $excluir = []): array
    {
        $ano = (int) substr($data, 0, 4);
        $mes = (int) substr($data, 5, 2);
        $rubs = RubricaOrcamental::query()->where('tipo', $tipo)->where('ativo', true)->whereIn('natureza', ['CUSTO', 'PAGAMENTO'])->get()
            ->filter(fn ($r) => ($r->controlo['modo'] ?? 'NENHUM') !== 'NENHUM')->values();
        if ($rubs->isEmpty()) {
            return [];
        }
        $orcamentos = OrcamentoAnual::query()->where('tipo', $tipo)->where('ano', $ano)->where('estado', 'APROVADO')->get();
        if ($orcamentos->isEmpty()) {
            return [];
        }
        // linhas do documento por rubrica
        $doc = [];
        foreach ($linhas as $l) {
            $r = ServicoRubricasOrcamentais::rubricaDaConta($rubs, (string) $l['codigo_conta']);
            $r && $doc[$r->id][] = $l;
        }
        if (! $doc) {
            return [];
        }
        $this->execucao->excluirNumeroLan = $excluir['numero_lan'] ?? null;
        $compromissos = $this->compromissos($tipo, $ano, $excluir);
        $resultado = [];
        foreach ($orcamentos as $o) {
            $real = null;
            foreach ($doc as $rid => $ls) {
                $ls = array_values(array_filter($ls, fn ($l) => self::abrange($o, $l)));
                if (! $ls) {
                    continue;
                }
                $r = $rubs->firstWhere('id', $rid);
                $c = $r->controlo;
                $valor = round(array_sum(array_map(fn ($l) => (float) $l['valor'], $ls)), 2);
                if ($valor <= 0) {
                    continue;
                }
                $linha = LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->where('rubrica_orcamental_id', $rid)->first();
                $meses = ($c['base'] ?? 'ACUMULADO') === 'ANO' ? 12 : $mes;
                $orcado = $linha ? round(array_sum(array_slice(array_map('floatval', (array) $linha->valores), 0, $meses)), 2) : 0.0;
                $real ??= $this->execucao->realizado($o)['por_rubrica'];
                $realizado = round(array_sum(array_slice($real[$rid] ?? [], 0, $meses)), 2);
                // compromissos só até ao mês do documento (base ANO: o ano todo), como o legado (consumo, orcamento_planeamento.js:475)
                $comp = round(array_sum(array_map(fn ($x) => $x['valor'], array_filter($compromissos, fn ($x) => self::abrange($o, $x)
                    && self::ateAoMes($x, $meses) && ServicoRubricasOrcamentais::rubricaDaConta([$r], $x['codigo_conta'])))), 2);
                $consumido = round($realizado + $comp, 2);
                $pct = $orcado > 0 ? round(($consumido + $valor) / $orcado * 100, 2) : null;
                $estado = match (true) {
                    $orcado <= 0 => 'SEM_DOTACAO',
                    $pct > (float) $c['limite_pct'] && $c['modo'] === 'BLOQUEAR' => 'BLOQUEIO',
                    $pct > (float) $c['limite_pct'] && $c['modo'] === 'APROVACAO' => 'APROVACAO',
                    $pct >= (float) $c['aviso_pct'] => 'AVISO',
                    default => 'OK',
                };
                $resultado[] = ['orcamento_anual_id' => $o->id, 'orcamento' => $o->nome, 'rubrica_orcamental_id' => $rid, 'rubrica' => "{$r->codigo} {$r->nome}", 'modo' => $c['modo'],
                    'orcado' => $orcado, 'realizado' => $realizado, 'compromissos' => $comp, 'consumido' => $consumido, 'documento' => $valor, 'percentagem' => $pct,
                    'excesso' => $orcado > 0 ? max(0, round($consumido + $valor - $orcado * (float) $c['limite_pct'] / 100, 2)) : $valor, 'estado' => $estado];
            }
        }
        $this->execucao->excluirNumeroLan = null;

        return $resultado;
    }

    /**
     * Controlo no momento de gravar um documento (dentro da transacção do documento). Lê do pedido HTTP as opções
     * `orcamento.motivo` e `orcamento.aprovar_excesso` («aprovar no acto»).
     *
     * @param  array{origem: string, documento: string, data: string}  $doc
     */
    public function avaliar(string $tipo, array $doc, array $linhas, array $excluir = []): array
    {
        try {
            $res = $this->verificar($tipo, $doc['data'], $linhas, $excluir);
        } catch (ErroNegocio $e) {
            throw $e;
        } catch (\Throwable $e) {   // falha fechada (o legado deixava gravar)
            throw new ErroNegocio('Não foi possível validar o orçamento: o documento não foi gravado. '.$e->getMessage(), 'ORCAMENTO_INDISPONIVEL', 422);
        }
        $chave = "{$doc['origem']}|{$doc['documento']}";
        $alertas = array_map(fn ($r) => $r + ['origem' => $doc['origem'], 'documento' => $doc['documento']], array_values(array_filter($res, fn ($r) => $r['estado'] !== 'OK')));
        $bloqueios = array_values(array_filter($alertas, fn ($a) => $a['estado'] === 'BLOQUEIO'));
        if ($bloqueios) {
            throw new ErroOrcamental('Orçamento esgotado: '.implode('; ', array_map(fn ($a) => "{$a['rubrica']} ({$a['orcamento']}) a {$a['percentagem']} %", $bloqueios)).'.',
                'ORCAMENTO_BLOQUEADO', ['alertas' => $alertas], $bloqueios, 'BLOQUEADO');
        }
        $opcoes = (array) (request()?->input('orcamento') ?? []);
        foreach (array_filter($alertas, fn ($a) => $a['estado'] === 'APROVACAO') as $a) {
            $pedido = PedidoExtrapolacaoOrcamento::query()->where('chave_documento', $chave)->where('rubrica_orcamental_id', $a['rubrica_orcamental_id'])
                ->where('orcamento_anual_id', $a['orcamento_anual_id'])->where('estado', 'APROVADO')->where('valor', '>=', $a['documento'] - 0.005)->lockForUpdate()->first();
            if ($pedido) {
                $pedido->update(['estado' => 'UTILIZADO', 'utilizado_em' => now()]);   // mesma transacção: só fica utilizado se o documento gravar
                $this->registar($a, 'APROVADO_PREVIAMENTE');

                continue;
            }
            if (! empty($opcoes['aprovar_excesso']) && Gate::any(['orc_aprovar_excesso'])) {
                if (mb_strlen(trim((string) ($opcoes['motivo'] ?? ''))) < 5) {
                    throw new ErroNegocio('Indique o motivo do excesso (mínimo 5 caracteres).', 'MOTIVO_EM_FALTA', 422);
                }
                $this->criarPedido($a, $chave, $doc, (string) $opcoes['motivo'], 'UTILIZADO', true);
                $this->registar($a, 'APROVOU_NO_ACTO');

                continue;
            }
            throw new ErroOrcamental("O documento excede o orçamento de {$a['rubrica']} ({$a['percentagem']} %): peça a aprovação do excesso.", 'ORCAMENTO_EXIGE_APROVACAO',
                // o suficiente para o frontend pedir a aprovação a partir do próprio documento (POST /orcamento/pedidos-excesso)
                ['alertas' => $alertas, 'chave_documento' => $chave, 'tipo' => $tipo, 'origem' => $doc['origem'], 'documento' => $doc['documento'],
                    'data' => $doc['data'], 'linhas' => array_slice(array_values($linhas), 0, 500)], [$a], 'SEM_APROVACAO');
        }
        foreach (array_filter($alertas, fn ($a) => in_array($a['estado'], ['AVISO', 'SEM_DOTACAO'], true)) as $a) {
            $this->registar($a, 'CONTINUOU');
        }

        return $alertas;
    }

    // ───────────── Pedidos de excesso ─────────────

    /** Pedido de aprovação do excesso de um documento que ainda não foi gravado. */
    public function pedirExcesso(string $tipo, array $doc, array $linhas, string $motivo): array
    {
        $chave = "{$doc['origem']}|{$doc['documento']}";

        return DB::transaction(function () use ($tipo, $doc, $linhas, $motivo, $chave) {
            $pedidos = [];
            foreach (array_filter($this->verificar($tipo, $doc['data'], $linhas), fn ($r) => $r['estado'] === 'APROVACAO') as $a) {
                $a += ['origem' => $doc['origem'], 'documento' => $doc['documento']];
                $pedidos[] = $this->criarPedido($a, $chave, $doc, $motivo, 'PENDENTE', false);
                $this->registar($a, 'PEDIU_APROVACAO');
            }
            if (! $pedidos) {
                throw new ErroNegocio('O documento não precisa de aprovação de excesso.', 'SEM_EXCESSO', 422);
            }

            return $pedidos;
        });
    }

    public function decidirPedido(PedidoExtrapolacaoOrcamento $p, string $decisao, ?string $nota): PedidoExtrapolacaoOrcamento
    {
        if ($p->estado !== 'PENDENTE') {
            throw new ErroNegocio("O pedido está {$p->estado}.", 'ESTADO_INVALIDO', 422);
        }
        if ($p->pedido_por && $p->pedido_por === Auth::user()?->nome_utilizador) {
            throw new ErroNegocio('Não pode decidir o seu próprio pedido de excesso.', 'AUTO_APROVACAO', 403);
        }
        if ($decisao === 'REJEITADO' && mb_strlen(trim((string) $nota)) < 3) {
            throw new ErroNegocio('Indique o motivo da rejeição.', 'NOTA_EM_FALTA', 422);
        }
        $p->update(['estado' => $decisao, 'decidido_por' => Auth::user()?->nome_utilizador, 'decidido_em' => now(), 'nota_decisao' => $nota]);

        return $p->refresh();
    }

    private function criarPedido(array $a, string $chave, array $doc, string $motivo, string $estado, bool $auto): PedidoExtrapolacaoOrcamento
    {
        $user = Auth::user()?->nome_utilizador;
        $dados = ['estado' => $estado, 'rubrica_orcamental_id' => $a['rubrica_orcamental_id'], 'orcamento_anual_id' => $a['orcamento_anual_id'], 'origem' => $doc['origem'],
            'documento' => $doc['documento'], 'chave_documento' => $chave, 'data_documento' => $doc['data'], 'valor' => $a['documento'], 'valor_orcado' => $a['orcado'],
            'valor_consumido' => $a['consumido'], 'percentagem' => $a['percentagem'], 'valor_excesso' => $a['excesso'], 'motivo' => $motivo, 'pedido_por' => $user, 'pedido_em' => now(),
            'autoaprovado' => $auto] + ($auto ? ['decidido_por' => $user, 'decidido_em' => now(), 'utilizado_em' => now(), 'nota_decisao' => 'Aprovado no acto'] : []);
        $existente = PedidoExtrapolacaoOrcamento::query()->where('chave_documento', $chave)->where('rubrica_orcamental_id', $a['rubrica_orcamental_id'])
            ->where('orcamento_anual_id', $a['orcamento_anual_id'])->where('estado', 'PENDENTE')->first();
        // Segurança (Fase 6): um pedido pendente de outro utilizador não é reescrito (valor, motivo e autor) por um novo pedido.
        if ($existente && ! $auto && $existente->pedido_por !== null && $existente->pedido_por !== $user) {
            throw new ErroNegocio("Já existe um pedido de excesso pendente deste documento, feito por {$existente->pedido_por}.", 'PEDIDO_DE_OUTRO_UTILIZADOR', 409);
        }
        $existente ? $existente->update($dados) : $existente = PedidoExtrapolacaoOrcamento::create($dados);

        return $existente->refresh();
    }

    private function registar(array $a, string $acao): void
    {
        LogAlertaOrcamental::create(['em' => now(), 'por' => Auth::user()?->nome_utilizador, 'origem' => $a['origem'], 'documento' => $a['documento'],
            'rubrica_orcamental_id' => $a['rubrica_orcamental_id'], 'orcamento_anual_id' => $a['orcamento_anual_id'], 'percentagem' => $a['percentagem'],
            'estado' => $a['estado'], 'acao' => $acao, 'valor' => $a['documento']]);
    }

    // ───────────── Monitor ─────────────

    /** Consumo (realizado + compromissos) face ao orçado de cada rubrica dos orçamentos aprovados do ano. */
    public function monitor(int $ano, int $mes): array
    {
        $saida = [];
        foreach (OrcamentoAnual::query()->where('ano', $ano)->where('estado', 'APROVADO')->get() as $o) {
            $real = $this->execucao->realizado($o)['por_rubrica'];
            $comp = $this->compromissos($o->tipo, $ano);
            $rubs = RubricaOrcamental::query()->where('tipo', $o->tipo)->whereIn('natureza', ['CUSTO', 'PAGAMENTO'])->get();
            foreach (LinhaOrcamento::query()->where('orcamento_anual_id', $o->id)->get() as $l) {
                $r = $rubs->firstWhere('id', $l->rubrica_orcamental_id);
                if (! $r) {
                    continue;
                }
                $c = $r->controlo ?? ['modo' => 'NENHUM', 'aviso_pct' => 90, 'limite_pct' => 100, 'base' => 'ACUMULADO'];
                $meses = ($c['base'] ?? 'ACUMULADO') === 'ANO' ? 12 : $mes;
                $orcado = round(array_sum(array_slice(array_map('floatval', (array) $l->valores), 0, $meses)), 2);
                $cp = round(array_sum(array_map(fn ($x) => $x['valor'], array_filter($comp, fn ($x) => self::abrange($o, $x) && self::ateAoMes($x, $meses)
                    && ServicoRubricasOrcamentais::rubricaDaConta([$r], $x['codigo_conta'])))), 2);
                $consumido = round(array_sum(array_slice($real[$r->id] ?? [], 0, $meses)) + $cp, 2);
                $pct = $orcado > 0 ? round($consumido / $orcado * 100, 2) : null;
                $modo = $c['modo'] ?? 'NENHUM';
                $estado = match (true) {
                    $pct === null => 'SEM_DOTACAO',
                    in_array($modo, ['APROVACAO', 'BLOQUEAR'], true) && $pct > (float) $c['limite_pct'] => 'EXCEDIDO',
                    $pct >= (float) ($c['aviso_pct'] ?? 90) => 'AVISO',   // no modo AVISAR nunca «excedido»
                    default => 'OK',
                };
                $saida[] = ['orcamento_anual_id' => $o->id, 'orcamento' => $o->nome, 'rubrica_orcamental_id' => $r->id, 'rubrica' => "{$r->codigo} {$r->nome}", 'modo' => $modo,
                    'orcado' => $orcado, 'compromissos' => $cp, 'consumido' => $consumido, 'disponivel' => round($orcado - $consumido, 2), 'percentagem' => $pct, 'estado' => $estado];
            }
        }

        return $saida;
    }

    private function empresa(): int
    {
        return app(ContextoEmpresa::class)->obrigatorio();
    }
}
