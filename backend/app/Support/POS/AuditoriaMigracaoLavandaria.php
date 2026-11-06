<?php

namespace App\Support\POS;

use App\Services\POS\Lavandaria\RegrasLavandaria;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * F0b do POS — auditoria SÓ DE LEITURA dos dados da lavandaria que vieram da migração do legado.
 *
 * Corre sobre a base do ambiente onde é executada (produção, teste, cópia local) e devolve contagens e ids (nunca nomes,
 * moradas, NIF, descrições nem valores de negócio individuais): o administrador envia o relatório sem expor dados pessoais.
 * Cada verificação tem um código estável, uma gravidade (ERRO = dado perdido ou incoerente; AVISO = a confirmar; INFO = só
 * dimensiona) e, por empresa, quantas linhas afecta. Compara o que o legado tinha (js/lavandaria.js: lav_pieces.lav_prices,
 * items.unit/size, service_prices.mode/size, números de ordem e de recibo, totais ordem × factura × recebimento) com o esquema novo.
 *
 * Não escreve nada: a ligação é posta em modo só de leitura (SET TRANSACTION READ ONLY) enquanto dura a auditoria.
 */
final class AuditoriaMigracaoLavandaria
{
    public const LIMITE_IDS = 20;

    private const MODOS = ['PECA', 'KG', 'TAMANHO'];

    /** Chaves em inglês do legado que a normalização traduz (ServicoMigracaoLavandaria). */
    private const CHAVES_ITEM = ['line_id', 'piece_id', 'piece_code', 'piece_name', 'piece_desc', 'color', 'fabric', 'unit', 'product_id', 'service_code', 'name', 'group', 'qty',
        'pieces', 'price', 'tax_rate', 'lead_days', 'entry_state', 'entry_notes', 'condition', 'declared_value', 'quote_required', 'quote_status', 'quote_amount', 'status', 'tags',
        'materials', 'started_at', 'assigned_to', 'ready_at', 'delivered_at', 'delivered_by', 'invoice_sale_id', 'size'];

    private const CHAVES_EXTRA = ['key', 'product_id', 'name', 'amount', 'tax_rate', 'invoice_sale_id', 'cancelled'];

    private const CHAVES_DOMICILIO = ['enabled', 'address', 'date', 'fee'];

    private const CHAVES_PAGAMENTO = ['pm_id', 'kind', 'name', 'amount', 'received', 'reference', 'transit_account', 'settlement_account', 'tpa_code'];

    private const CHAVES_PRECO_SERVICO = ['product_id', 'price', 'mode', 'size'];

    /** @var array<string, array{gravidade: string, descricao: string, total: int, ids: list<int|string>, por_empresa: array<int, int>}> */
    private array $achados = [];

    /** @var array<string, int|float> */
    private array $estatisticas = [];

    public function __construct(private readonly Connection $db) {}

    /** @return array<string, mixed> */
    public function auditar(?int $empresa = null): array
    {
        $this->achados = [];
        $this->estatisticas = [];
        $guarda = $this->db->transactionLevel() === 0;   // numa transacção já aberta (testes) não se mexe no modo
        if ($guarda) {
            $this->db->beginTransaction();
            $this->db->statement('SET TRANSACTION READ ONLY');
        }
        try {
            $totais = $this->totais($empresa);
            $this->pecas($empresa);
            $this->pedidos($empresa);
            $this->pagamentos($empresa);
            $this->reclamacoes($empresa);
            $this->configuracoes($empresa);
        } finally {
            if ($guarda) {
                $this->db->rollBack();
            }
        }
        ksort($this->achados);
        $contar = fn (string $g) => count(array_filter($this->achados, fn ($a) => $a['gravidade'] === $g));

        return [
            'gerado_em' => now()->toIso8601String(),
            'base_de_dados' => $this->db->getDatabaseName(),
            'filtro_empresa' => $empresa,
            'totais' => $totais,
            'estatisticas' => $this->estatisticas,
            'resumo' => ['verificacoes_com_achados' => count($this->achados), 'erros' => $contar('ERRO'), 'avisos' => $contar('AVISO'), 'info' => $contar('INFO'),
                'conclusao' => $this->conclusao($totais)],
            'verificacoes' => $this->achados,
        ];
    }

    /** @param  array<string, mixed>  $r */
    public static function markdown(array $r): string
    {
        $l = ['# Auditoria da migração da lavandaria', '', "Gerado em {$r['gerado_em']} · base `{$r['base_de_dados']}`".($r['filtro_empresa'] ? " · empresa {$r['filtro_empresa']}" : ''), '',
            "**Conclusão:** {$r['resumo']['conclusao']}", '', '## Totais', '', '| Tabela | Linhas |', '|---|---:|'];
        foreach ($r['totais'] as $t => $n) {
            $l[] = "| {$t} | {$n} |";
        }
        $l[] = '';
        $l[] = '## Dimensão das opções de preço (decide a urgência da F5)';
        $l[] = '';
        foreach ($r['estatisticas'] as $k => $v) {
            $l[] = "- {$k}: {$v}";
        }
        $l[] = '';
        $l[] = '## Verificações com achados';
        $l[] = '';
        if (! $r['verificacoes']) {
            $l[] = 'Nenhuma: os dados estão coerentes com o esquema novo.';
        }
        foreach ($r['verificacoes'] as $codigo => $a) {
            $l[] = "### {$codigo} [{$a['gravidade']}] — {$a['total']} linha(s)";
            $l[] = '';
            $l[] = $a['descricao'];
            $l[] = '';
            $l[] = 'Por empresa: '.implode(', ', array_map(fn ($e, $n) => "#{$e}: {$n}", array_keys($a['por_empresa']), $a['por_empresa']));
            $l[] = 'Ids (até '.self::LIMITE_IDS.'): '.implode(', ', $a['ids']).($a['total'] > count($a['ids']) ? ' …' : '');
            $l[] = '';
        }

        return implode("\n", $l)."\n";
    }

    private function achar(string $codigo, string $gravidade, string $descricao, ?int $empresa, int|string|null $id): void
    {
        $a = &$this->achados[$codigo];
        $a ??= ['gravidade' => $gravidade, 'descricao' => $descricao, 'total' => 0, 'ids' => [], 'por_empresa' => []];
        $a['total']++;
        if ($id !== null && count($a['ids']) < self::LIMITE_IDS) {
            $a['ids'][] = $id;
        }
        $e = (int) $empresa;
        $a['por_empresa'][$e] = ($a['por_empresa'][$e] ?? 0) + 1;
    }

    private function estat(string $chave, int|float $n = 1): void
    {
        $this->estatisticas[$chave] = ($this->estatisticas[$chave] ?? 0) + $n;
    }

    /** @param  Builder  $q */
    private function filtrar($q, ?int $empresa, string $coluna = 'empresa_id')
    {
        return $empresa ? $q->where($coluna, $empresa) : $q;
    }

    /** @return array<string, int> */
    private function totais(?int $empresa): array
    {
        $t = [];
        foreach (['pecas_lavandaria', 'pedidos_lavandaria', 'pagamentos_lavandaria', 'reclamacoes_lavandaria', 'configuracoes_lavandaria', 'pedidos_lavandaria_faturas'] as $tab) {
            $t[$tab] = $this->filtrar($this->db->table($tab), $empresa)->count();
        }
        $t['terminais_lavandaria'] = $this->filtrar($this->db->table('terminais_pos')->where('tipo', 'LAVANDARIA'), $empresa)->count();
        $t['servicos_lavandaria'] = $this->filtrar($this->db->table('produtos')->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS), $empresa)->count();
        $t['empresas_com_pedidos'] = $this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->distinct()->count('empresa_id');

        return $t;
    }

    /** @param  array<string, int>  $totais */
    private function conclusao(array $totais): string
    {
        if (array_sum($totais) === 0) {
            return 'SEM DADOS DE LAVANDARIA visíveis para esta ligação (se se esperavam dados, a ligação não vê as empresas: usar a ligação do dono da base, --database=).';
        }
        $erros = array_filter($this->achados, fn ($a) => $a['gravidade'] === 'ERRO');

        return $erros ? count($erros).' verificação(ões) com ERRO: ver abaixo; correr erp:lavandaria:completar-migracao --simular antes de corrigir.'
            : 'Sem erros: dados migrados coerentes com o esquema novo (ver avisos).';
    }

    private function pecas(?int $empresa): void
    {
        $servicos = $this->filtrar($this->db->table('produtos')->whereIn('lavandaria_grupo', RegrasLavandaria::GRUPOS), $empresa)->get(['id', 'empresa_id'])->groupBy('empresa_id')
            ->map(fn ($l) => $l->pluck('id')->flip()->all())->all();
        $codigos = [];
        $q = $this->filtrar($this->db->table('pecas_lavandaria'), $empresa)->orderBy('id');
        foreach ($q->cursor() as $p) {
            $e = (int) $p->empresa_id;
            $opcoes = $p->opcoes_preco !== null ? (json_decode((string) $p->opcoes_preco, true) ?: []) : [];
            $servPrecos = $p->precos_servico !== null ? json_decode((string) $p->precos_servico, true) : [];
            $servPrecos = is_array($servPrecos) ? $servPrecos : [];
            $codigos[$e][trim((string) $p->codigo)][] = $p->id;

            if (! in_array($p->unidade, ['PECA', 'KG'], true)) {
                $this->achar('P01_peca_unidade_invalida', 'ERRO', 'Unidade de venda da peça fora de PECA/KG (o legado admitia TAMANHO): o modo de preço ficou mal traduzido.', $e, $p->id);
            }
            if (! $opcoes) {
                $this->achar('P02_peca_sem_opcoes_preco', 'AVISO', 'Peça sem opcoes_preco: correr erp:lavandaria:completar-migracao (com o backup do legado, para ler lav_prices).', $e, $p->id);
            } elseif ($p->opcoes_preco_origem === 'DERIVADA') {
                $this->achar('P03_peca_opcoes_derivadas', 'AVISO', 'Opções reconstruídas de unidade+preço (não vieram de lav_prices): confirmar com o backup do legado.', $e, $p->id);
            }
            // indícios de que a peça tinha várias opções no legado (service_prices com modo/tamanho), mas sem lav_prices lido do legado
            $modos = [];
            foreach ($servPrecos as $x) {
                $m = $x['modo'] ?? $x['mode'] ?? null;
                if ($m !== null) {
                    $modos[$m.'|'.($x['tamanho'] ?? $x['size'] ?? '')] = true;
                }
                if (array_intersect(self::CHAVES_PRECO_SERVICO, array_keys((array) $x)) && (isset($x['product_id']) || isset($x['price']) || isset($x['mode']) || isset($x['size']))) {
                    $this->achar('P04_precos_servico_por_traduzir', 'ERRO', 'Entradas de precos_servico ainda com chaves do legado (product_id/price/mode/size).', $e, $p->id);
                }
                if (isset($x['produto_id']) && empty($servicos[$e][(int) $x['produto_id']])) {
                    $this->achar('P05_precos_servico_servico_inexistente', 'AVISO', 'Preço por serviço de um produto que não é serviço de lavandaria desta empresa.', $e, $p->id);
                }
            }
            $origemLegado = $p->opcoes_preco_origem === 'LEGADO' || $p->opcoes_preco_origem === 'NOVO';
            if (! $origemLegado && (count($modos) > 1 || array_filter(array_keys($modos), fn ($k) => ! str_starts_with($k, 'PECA|') && $k !== '|'))) {
                $this->achar('P06_peca_provavel_perda_de_opcoes', 'ERRO', 'service_prices indicam várias opções (Peça/Kg/Tamanho) mas lav_prices não foi lido do legado: ler do backup.', $e, $p->id);
            }
            if (bccomp((string) ($p->preco ?? 0), '0', 2) <= 0 && ! array_filter($opcoes, fn ($o) => (float) ($o['preco'] ?? 0) > 0) && ! array_filter($servPrecos, fn ($x) => (float) ($x['preco'] ?? $x['price'] ?? 0) > 0)) {
                $this->achar('P07_peca_sem_preco_algum', 'AVISO', 'Peça sem nenhum preço (só serve para serviços com orçamento; igual ao legado «por definir»).', $e, $p->id);
            }
            // dimensão (decide a urgência da F5)
            $this->estat('pecas_total');
            if (count($opcoes) > 1) {
                $this->estat('pecas_com_mais_de_uma_opcao');
            }
            if (array_filter($opcoes, fn ($o) => ($o['modo'] ?? null) === 'TAMANHO')) {
                $this->estat('pecas_com_opcao_tamanho');
            }
            if (array_filter($opcoes, fn ($o) => ($o['modo'] ?? null) === 'KG')) {
                $this->estat('pecas_com_opcao_kg');
            }
            if ($p->opcoes_preco_origem === 'LEGADO') {
                $this->estat('pecas_opcoes_lidas_do_legado');
            }
            if (array_filter($servPrecos, fn ($x) => isset($x['modo']) || isset($x['mode']))) {
                $this->estat('pecas_com_preco_por_servico_e_opcao');
            }
        }
        foreach ($codigos as $e => $porCodigo) {
            foreach ($porCodigo as $codigo => $ids) {
                if ($codigo !== '' && count($ids) > 1) {
                    foreach ($ids as $id) {
                        $this->achar('P08_peca_codigo_duplicado', 'ERRO', 'Código de peça repetido na mesma empresa.', $e, $id);
                    }
                }
            }
        }
    }

    private function pedidos(?int $empresa): void
    {
        $numeros = [];
        $etiquetas = [];
        $faturasPorPedido = $this->filtrar($this->db->table('pedidos_lavandaria_faturas'), $empresa)->selectRaw('pedido_lavandaria_id, count(*) as n')->groupBy('pedido_lavandaria_id')->pluck('n', 'pedido_lavandaria_id')->all();
        $facturado = $this->filtrar($this->db->table('vendas')->whereNotNull('pedido_lavandaria_id')->whereIn('tipo_documento', ['FT', 'FR'])->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO')), $empresa)
            ->selectRaw('pedido_lavandaria_id, sum(total_bruto) as t')->groupBy('pedido_lavandaria_id')->pluck('t', 'pedido_lavandaria_id')->all();
        $pivot = $this->filtrar($this->db->table('pedidos_lavandaria_faturas as f')->join('vendas as v', 'v.id', '=', 'f.venda_id')->whereIn('v.tipo_documento', ['FT', 'FR'])
            ->where(fn ($q) => $q->whereNull('v.estado')->orWhere('v.estado', '<>', 'ANULADO')), $empresa, 'f.empresa_id')
            ->selectRaw('f.pedido_lavandaria_id, sum(v.total_bruto) as t')->groupBy('f.pedido_lavandaria_id')->pluck('t', 'f.pedido_lavandaria_id')->all();
        $pago = $this->filtrar($this->db->table('pagamentos_lavandaria')->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO')), $empresa)
            ->selectRaw('pedido_lavandaria_id, sum(montante) as t')->groupBy('pedido_lavandaria_id')->pluck('t', 'pedido_lavandaria_id')->all();

        $this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->orderBy('id')->chunkById(500, function ($lote) use (&$numeros, &$etiquetas, $faturasPorPedido, $facturado, $pivot, $pago) {
            foreach ($lote as $p) {
                $e = (int) $p->empresa_id;
                $id = (int) $p->id;
                $numero = trim((string) $p->numero_encomenda);
                if ($numero === '') {
                    $this->achar('O01_pedido_sem_numero', 'ERRO', 'Ordem sem número (OS/…).', $e, $id);
                } else {
                    $numeros[$e][$numero][] = $id;
                }
                if (! in_array($p->estado, RegrasLavandaria::ESTADOS, true)) {
                    $this->achar('O02_pedido_estado_invalido', 'ERRO', 'Estado da ordem vazio ou fora da lista.', $e, $id);
                }
                if ($p->modo_faturacao !== null && ! in_array($p->modo_faturacao, ['RECEPCAO', 'ENTREGA'], true)) {
                    $this->achar('O03_pedido_modo_faturacao_invalido', 'ERRO', 'modo_faturacao fora de RECEPCAO/ENTREGA.', $e, $id);
                }
                foreach (['cliente_id' => 'cliente', 'terminal_pos_id' => 'terminal', 'recebido_em' => 'data de recepção', 'data_prometida' => 'data prometida'] as $col => $nome) {
                    if ($p->{$col} === null) {
                        $this->achar('O04_pedido_sem_'.$col, 'AVISO', "Ordem sem {$nome}.", $e, $id);
                    }
                }
                $itens = json_decode((string) $p->itens, true);
                $extras = json_decode((string) $p->extras, true) ?: [];
                if (! is_array($itens) || ! $itens) {
                    $this->achar('O05_pedido_sem_itens', 'ERRO', 'Ordem sem linhas (itens vazio ou JSON inválido).', $e, $id);
                    $itens = [];
                }
                $this->itens($e, $id, $itens, $etiquetas);
                if (is_array($extras) && array_filter($extras, fn ($x) => is_array($x) && array_intersect(self::CHAVES_EXTRA, array_keys($x)) && (isset($x['amount']) || isset($x['key']) || isset($x['name'])))) {
                    $this->achar('O06_extras_por_traduzir', 'ERRO', 'Taxas/extras da ordem com chaves do legado (key/amount/name).', $e, $id);
                }
                foreach (['recolha' => $p->recolha, 'entrega' => $p->entrega] as $campo => $j) {
                    $d = $j !== null ? json_decode((string) $j, true) : null;
                    if (is_array($d) && array_intersect(array_keys($d), ['enabled', 'address', 'fee'])) {
                        $this->achar('O07_domicilio_por_traduzir', 'ERRO', 'Recolha/entrega ao domicílio com chaves do legado (enabled/address/fee).', $e, $id);
                    }
                }
                $hist = json_decode((string) $p->historico_alteracoes, true);
                if (is_array($hist) && array_filter($hist, fn ($x) => is_array($x) && isset($x['text']))) {
                    $this->achar('O08_historico_por_traduzir', 'AVISO', 'Histórico da ordem com chaves do legado (at/by/text).', $e, $id);
                }
                $linhasValidas = array_values(array_filter($itens, 'is_array'));
                if ($linhasValidas && in_array($p->estado, RegrasLavandaria::ESTADOS, true) && RegrasLavandaria::estadoOrdem($linhasValidas) !== $p->estado) {
                    $this->achar('O09_pedido_estado_incoerente_com_linhas', 'AVISO', 'O estado da ordem não corresponde ao que resulta do estado das linhas.', $e, $id);
                }
                // faturas_ids_legado × pivot
                $ids = $p->faturas_ids_legado !== null ? (json_decode((string) $p->faturas_ids_legado, true) ?: []) : [];
                if (is_array($ids) && $ids && ($faturasPorPedido[$id] ?? 0) < count($ids)) {
                    $this->achar('O10_faturas_do_legado_sem_ligacao', 'ERRO', 'A ordem tinha mais facturas no legado (invoice_ids) do que as ligadas em pedidos_lavandaria_faturas.', $e, $id);
                }
                // totais ordem × factura × recebimento
                $totais = RegrasLavandaria::totais($itens, is_array($extras) ? $extras : [], '0.00', '0.00');
                $total = $totais['total'];
                $pagoOrd = number_format((float) ($pago[$id] ?? 0), 2, '.', '');
                $fact = number_format((float) max((float) ($facturado[$id] ?? 0), (float) ($pivot[$id] ?? 0)), 2, '.', '');
                if ($p->estado !== 'ANULADA') {
                    if (bccomp($pagoOrd, bcadd($total, '0.01', 2), 2) > 0) {
                        $this->achar('C01_pago_maior_que_total_da_ordem', 'ERRO', 'Soma dos recebimentos não anulados superior ao total da ordem (linhas + taxas).', $e, $id);
                    }
                    if (bccomp($fact, bcadd($total, '0.01', 2), 2) > 0) {
                        $this->achar('C02_facturado_maior_que_total_da_ordem', 'ERRO', 'Soma das facturas não anuladas superior ao total da ordem (linhas + taxas).', $e, $id);
                    }
                    if (bccomp($pagoOrd, bcadd($fact, '0.01', 2), 2) > 0 && bccomp($fact, '0', 2) > 0 && $p->modo_faturacao === 'RECEPCAO') {
                        $this->achar('C03_pago_maior_que_facturado_em_ordem_facturada', 'AVISO', 'Recebido acima do facturado numa ordem com facturação na recepção.', $e, $id);
                    }
                } elseif (bccomp($pagoOrd, '0', 2) > 0) {
                    $this->achar('C04_ordem_anulada_com_recebimentos', 'ERRO', 'Ordem anulada com recebimentos não anulados.', $e, $id);
                }
            }
        }, 'id');

        foreach ($numeros as $e => $porNumero) {
            foreach ($porNumero as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $this->achar('O11_numero_de_ordem_duplicado', 'ERRO', 'Número de ordem repetido na mesma empresa.', $e, $id);
                    }
                }
            }
        }
        foreach ($etiquetas as $e => $porTag) {
            foreach ($porTag as $ids) {
                if (count(array_unique($ids)) > 1) {
                    foreach (array_unique($ids) as $id) {
                        $this->achar('O12_etiqueta_duplicada_entre_ordens', 'AVISO', 'A mesma etiqueta de peça aparece em ordens diferentes da empresa.', $e, $id);
                    }
                }
            }
        }
    }

    /**
     * @param  list<mixed>  $itens
     * @param  array<int, array<string, list<int>>>  $etiquetas
     */
    private function itens(int $e, int $id, array $itens, array &$etiquetas): void
    {
        foreach ($itens as $i) {
            if (! is_array($i)) {
                $this->achar('O13_linha_ilegivel', 'ERRO', 'Linha da ordem que não é um objecto JSON.', $e, $id);

                continue;
            }
            $this->estat('linhas_total');
            if (array_intersect(self::CHAVES_ITEM, array_keys($i)) && (isset($i['qty']) || isset($i['price']) || isset($i['name']) || isset($i['piece_id']))) {
                $this->achar('O14_linhas_por_traduzir', 'ERRO', 'Linhas da ordem com chaves do legado (qty/price/name…): correr erp:lavandaria:completar-migracao.', $e, $id);
            }
            if (array_key_exists('size', $i)) {
                $this->achar('O15_linha_tamanho_por_traduzir', 'ERRO', 'Linha com o tamanho do legado (size) por traduzir para modo/tamanho.', $e, $id);
            }
            $unidade = $i['unidade'] ?? $i['unit'] ?? null;
            if (! empty($i['tamanho']) || ($i['modo'] ?? null) === 'TAMANHO' || $unidade === 'TAMANHO') {
                $this->estat('linhas_com_tamanho');
            }
            if ($unidade !== null && ! in_array($unidade, ['PECA', 'KG'], true) && in_array($unidade, self::MODOS, true)) {
                $this->achar('O16_linha_unidade_tamanho', 'AVISO', 'Linha cuja unidade é TAMANHO (o sistema novo só conhece PECA/KG): o tamanho vai em tamanho/modo.', $e, $id);
            }
            if (($i['estado'] ?? $i['status'] ?? null) === 'ANULADA') {
                continue;
            }
            $qtd = (float) ($i['quantidade'] ?? $i['qty'] ?? 0);
            $preco = (float) ($i['preco'] ?? $i['price'] ?? 0);
            $orcamento = ! empty($i['requer_orcamento']) || ! empty($i['quote_required']);
            if ($qtd <= 0 || ($preco <= 0 && ! $orcamento)) {
                $this->achar('O17_linha_sem_quantidade_ou_preco', 'ERRO', 'Linha activa sem quantidade ou sem preço (e sem orçamento).', $e, $id);
            }
            if (isset($i['valor']) && abs((float) $i['valor'] - round($qtd * $preco, 2)) > 0.02 && ! $orcamento) {
                $this->achar('O18_linha_valor_diferente_de_qtd_x_preco', 'AVISO', 'valor da linha diferente de quantidade × preço (acima de 0,02): confirmar o arredondamento AGT.', $e, $id);
            }
            foreach ((array) ($i['etiquetas'] ?? $i['tags'] ?? []) as $tag) {
                if (is_string($tag) && $tag !== '') {
                    $etiquetas[$e][$tag][] = $id;
                }
            }
        }
    }

    private function pagamentos(?int $empresa): void
    {
        $recibos = [];
        $pedidos = $this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->pluck('empresa_id', 'id')->all();
        $this->filtrar($this->db->table('pagamentos_lavandaria'), $empresa)->orderBy('id')->chunkById(500, function ($lote) use (&$recibos, $pedidos) {
            foreach ($lote as $p) {
                $e = (int) $p->empresa_id;
                $id = (int) $p->id;
                $numero = trim((string) $p->numero_recibo);
                if ($numero === '') {
                    $this->achar('G01_pagamento_sem_numero_de_recibo', 'AVISO', 'Recebimento sem número de recibo.', $e, $id);
                } else {
                    $recibos[$e][$numero][] = $id;
                }
                if ($p->pedido_lavandaria_id === null || ! isset($pedidos[$p->pedido_lavandaria_id]) || (int) $pedidos[$p->pedido_lavandaria_id] !== $e) {
                    $this->achar('G02_pagamento_sem_ordem_valida', 'ERRO', 'Recebimento sem ordem, com ordem inexistente ou de outra empresa.', $e, $id);
                }
                if ($p->estado !== null && ! in_array($p->estado, ['REGISTADO', 'ANULADO'], true)) {
                    $this->achar('G03_pagamento_estado_invalido', 'ERRO', 'Estado do recebimento fora de REGISTADO/ANULADO.', $e, $id);
                }
                if ($p->natureza_registo !== null && ! in_array($p->natureza_registo, ['ADIANTAMENTO', 'PAGAMENTO', 'FR'], true)) {
                    $this->achar('G04_pagamento_natureza_invalida', 'ERRO', 'natureza_registo fora de ADIANTAMENTO/PAGAMENTO/FR.', $e, $id);
                }
                $linhas = $p->pos_pagamentos !== null ? json_decode((string) $p->pos_pagamentos, true) : null;
                if (is_array($linhas) && $linhas) {
                    if (array_filter($linhas, fn ($x) => is_array($x) && array_intersect(self::CHAVES_PAGAMENTO, array_keys($x)) && (isset($x['pm_id']) || isset($x['amount']) || isset($x['kind'])))) {
                        $this->achar('G05_meios_de_pagamento_por_traduzir', 'ERRO', 'pos_pagamentos com chaves do legado (pm_id/kind/amount).', $e, $id);
                    }
                    $soma = array_reduce($linhas, fn ($s, $x) => $s + (float) (is_array($x) ? ($x['valor'] ?? $x['amount'] ?? 0) : 0), 0.0);
                    if (abs($soma - (float) $p->montante) > 0.01) {
                        $this->achar('G06_montante_diferente_da_soma_dos_meios', 'AVISO', 'montante do recebimento diferente da soma dos meios de pagamento.', $e, $id);
                    }
                } elseif (bccomp((string) ($p->montante ?? 0), '0', 2) > 0) {
                    $this->achar('G07_pagamento_sem_meios_de_pagamento', 'AVISO', 'Recebimento com montante e sem meios de pagamento detalhados.', $e, $id);
                }
            }
        }, 'id');
        foreach ($recibos as $e => $porNumero) {
            foreach ($porNumero as $ids) {
                if (count($ids) > 1) {
                    foreach ($ids as $id) {
                        $this->achar('G08_numero_de_recibo_duplicado', 'ERRO', 'Número de recibo repetido na mesma empresa.', $e, $id);
                    }
                }
            }
        }
    }

    private function reclamacoes(?int $empresa): void
    {
        $pedidos = $this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->pluck('empresa_id', 'id')->all();
        foreach ($this->filtrar($this->db->table('reclamacoes_lavandaria'), $empresa)->orderBy('id')->cursor() as $r) {
            $e = (int) $r->empresa_id;
            if ($r->estado !== null && ! in_array($r->estado, ['AGUARDA_COMPROVATIVO', 'COMPROVADO', 'APROVADA', 'RECUSADA', 'PAGA'], true)) {
                $this->achar('R01_reclamacao_estado_invalido', 'ERRO', 'Estado da reclamação fora da lista.', $e, $r->id);
            }
            if ($r->pedido_lavandaria_id === null || ! isset($pedidos[$r->pedido_lavandaria_id])) {
                $this->achar('R02_reclamacao_sem_ordem_valida', 'ERRO', 'Reclamação sem ordem ou com ordem inexistente.', $e, $r->id);
            }
            if ($r->estado === 'PAGA' && $r->documento_tesouraria_id === null) {
                $this->achar('R03_reclamacao_paga_sem_documento_de_tesouraria', 'AVISO', 'Reclamação paga sem documento de tesouraria.', $e, $r->id);
            }
        }
    }

    private function configuracoes(?int $empresa): void
    {
        $comPedidos = $this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->distinct()->pluck('empresa_id')->all();
        $configs = $this->filtrar($this->db->table('configuracoes_lavandaria'), $empresa)->selectRaw('empresa_id, count(*) as n, min(id) as id')->groupBy('empresa_id')->get()->keyBy('empresa_id');
        foreach ($comPedidos as $e) {
            if (! $configs->has($e)) {
                $this->achar('T01_empresa_com_ordens_sem_definicoes_de_lavandaria', 'AVISO', 'Empresa com ordens e sem configuracoes_lavandaria (valem os valores por omissão: confirmar taxas e contas).', (int) $e, null);
            }
        }
        foreach ($configs as $e => $c) {
            if ($c->n > 1) {
                $this->achar('T02_definicoes_de_lavandaria_duplicadas', 'ERRO', 'Mais de uma configuração de lavandaria para a mesma empresa.', (int) $e, (int) $c->id);
            }
        }
        $maximos = [];
        foreach ($this->filtrar($this->db->table('pedidos_lavandaria'), $empresa)->whereNotNull('terminal_pos_id')->select('terminal_pos_id', 'numero_encomenda')->cursor() as $p) {
            if (preg_match('#^OS/[^/]+/(\d{4})/(\d+)$#', (string) $p->numero_encomenda, $m)) {
                $k = $p->terminal_pos_id.'|'.$m[1];
                $maximos[$k] = max($maximos[$k] ?? 0, (int) $m[2]);
            }
        }
        foreach ($this->filtrar($this->db->table('terminais_pos')->where('tipo', 'LAVANDARIA'), $empresa)->orderBy('id')->cursor() as $t) {
            $os = $t->lavandaria_contadores_os !== null ? (json_decode((string) $t->lavandaria_contadores_os, true) ?: []) : [];
            foreach ($maximos as $k => $max) {
                [$terminal, $ano] = explode('|', $k);
                if ((int) $terminal === (int) $t->id && (int) ($os[$ano] ?? 0) < $max) {
                    $this->achar('T03_contador_de_ordens_atras_do_maximo_usado', 'INFO', 'O contador OS do terminal está atrás do maior número usado no ano (a numeração nova usa o máximo dos dois: sem risco, só informativo).', (int) $t->empresa_id, (int) $t->id);
                }
            }
            if ($t->lavandaria_contadores_fr === null && $t->lavandaria_contadores_os !== null) {
                $this->achar('T04_terminal_sem_contador_fr_do_legado', 'INFO', 'Terminal com contadores da lavandaria do legado mas sem lav_fr_counters (correr completar-migracao com o backup).', (int) $t->empresa_id, (int) $t->id);
            }
        }
    }
}
