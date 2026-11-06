<?php

namespace App\Services\POS;

use App\Models\PedidoLavandaria;
use App\Services\Migracao\LeitorBackupDexie;
use App\Services\POS\Lavandaria\ServicoCaixaLavandaria;
use App\Services\Sistema\ServicoAuditoria;
use App\Services\Vendas\CalculadoraDocumento;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Pós-carga da lavandaria (ETL): os JSON do legado (lav_orders.items/extras/pickup/delivery/history/assignments,
 * lav_payments.pos_payments, lav_pieces.service_prices) vêm com chaves em inglês; passam às chaves portuguesas lidas pelos
 * serviços da lavandaria. Idempotente (só traduz chaves conhecidas; só preenche o que falta). Também:
 *   - `valor` de cada linha/taxa = qtd × preço do legado (valorItem, js/lavandaria.js:126), quando falta;
 *   - linhas das facturas de lavandaria do legado com preço COM IVA e sem valor líquido (total_linha nulo): o valor líquido de
 *     cada linha é total / (1 + IVA), acertado ao total líquido do cabeçalho — sem isto a integração lançava o IVA em proveitos;
 *   - pago/pendente/estado das facturas das ordens recalculados a partir dos recebimentos da lavandaria (actualizarFacturas).
 * F0b (preservar dados): as opções de preço da peça (lav_prices → opcoes_preco [{modo, tamanho, preco}]), o modo/tamanho de cada
 * linha (items.unit/size → modo/tamanho), o modo/tamanho das entradas de service_prices e os contadores FR do terminal deixam de
 * se perder. `completar()` repete a tradução sobre dados JÁ migrados (modo --completar: só preenche o que falta, sem sobrescrever
 * o que foi editado no sistema novo); as opções que não vieram do legado reconstroem-se de unidade+preço (origem DERIVADA) até haver
 * backup para as ler (origem LEGADO).
 */
final class ServicoMigracaoLavandaria
{
    private const ITEM = ['line_id' => 'linha_id', 'piece_id' => 'peca_id', 'piece_code' => 'codigo_peca', 'piece_name' => 'nome_peca', 'piece_desc' => 'descricao_peca',
        'color' => 'cor', 'fabric' => 'tecido', 'unit' => 'unidade', 'product_id' => 'produto_id', 'service_code' => 'codigo_servico', 'name' => 'nome', 'group' => 'grupo',
        'qty' => 'quantidade', 'pieces' => 'numero_pecas', 'price' => 'preco', 'tax_rate' => 'taxa_imposto', 'lead_days' => 'dias_entrega', 'entry_state' => 'estado_entrada',
        'entry_notes' => 'notas_entrada', 'condition' => 'condicao', 'declared_value' => 'valor_declarado', 'quote_required' => 'requer_orcamento',
        'quote_status' => 'estado_orcamento', 'quote_amount' => 'valor_orcamento', 'quote_channel' => 'canal_orcamento', 'quote_note' => 'nota_orcamento',
        'quote_approved_at' => 'orcamento_aprovado_em', 'quote_approved_by' => 'orcamento_aprovado_por', 'quote_refused_at' => 'orcamento_recusado_em',
        'quote_refused_by' => 'orcamento_recusado_por', 'cancel_reason' => 'motivo_cancelamento', 'status' => 'estado', 'tags' => 'etiquetas', 'materials' => 'materiais',
        'started_at' => 'iniciado_em', 'assigned_to' => 'executado_por', 'ready_at' => 'pronta_em', 'delivered_at' => 'entregue_em', 'delivered_by' => 'entregue_por',
        'invoice_sale_id' => 'venda_id', 'size' => 'tamanho'];

    private const MATERIAL = ['product_id' => 'produto_id', 'name' => 'nome', 'qty' => 'quantidade', 'at' => 'em', 'by' => 'por'];

    private const EXTRA = ['key' => 'chave', 'product_id' => 'produto_id', 'name' => 'nome', 'amount' => 'valor', 'tax_rate' => 'taxa_imposto', 'invoice_sale_id' => 'venda_id',
        'cancelled' => 'cancelado'];

    private const DOMICILIO = ['enabled' => 'ativa', 'address' => 'morada', 'date' => 'data', 'fee' => 'taxa'];

    private const HISTORICO = ['at' => 'em', 'by' => 'por', 'text' => 'texto'];

    private const ATRIBUICAO = ['employee_id' => 'colaborador_id', 'name' => 'nome', 'assigned_at' => 'atribuido_em', 'at' => 'em', 'by' => 'por', 'note' => 'nota'];

    private const PRECO_SERVICO = ['product_id' => 'produto_id', 'price' => 'preco', 'mode' => 'modo', 'size' => 'tamanho'];

    private const OPCAO = ['mode' => 'modo', 'size' => 'tamanho', 'price' => 'preco'];

    private const MODOS = ['PECA', 'KG', 'TAMANHO'];

    private const PAGAMENTO = ['pm_id' => 'meio_id', 'kind' => 'tipo', 'name' => 'nome', 'amount' => 'valor', 'received' => 'recebido', 'reference' => 'referencia',
        'transit_account' => 'conta_transitoria', 'settlement_account' => 'conta_liquidacao', 'tpa_code' => 'codigo_tpa'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoCaixaLavandaria $caixa,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return array{pedidos: int, pagamentos: int, pecas: int, linhas_faturas: int, faturas: int} */
    public function normalizar(): array
    {
        $r = ['pedidos' => 0, 'pagamentos' => 0, 'pecas' => 0, 'linhas_faturas' => 0, 'faturas' => 0];
        $lista = fn (?string $j, array $mapa) => array_map(fn ($x) => self::traduzir($x, $mapa), json_decode((string) $j, true) ?: []);
        foreach (DB::table('pedidos_lavandaria')->orderBy('id')->get(['id', 'itens', 'extras', 'recolha', 'entrega', 'historico_alteracoes', 'atribuicoes']) as $p) {
            $itens = array_map(fn ($i) => self::itemTraduzido($i), json_decode((string) $p->itens, true) ?: []);
            $extras = array_map(function ($e) {
                $e = self::traduzir($e, self::EXTRA);
                $e['valor'] = number_format((float) ($e['valor'] ?? 0), 2, '.', '');

                return $e;
            }, json_decode((string) $p->extras, true) ?: []);
            $objeto = fn (?string $j) => $j === null ? null : json_encode(self::traduzir(json_decode($j, true) ?: [], self::DOMICILIO));
            DB::table('pedidos_lavandaria')->where('id', $p->id)->update(['itens' => json_encode($itens), 'extras' => json_encode($extras),
                'recolha' => $objeto($p->recolha), 'entrega' => $objeto($p->entrega),
                'historico_alteracoes' => json_encode($lista($p->historico_alteracoes, self::HISTORICO)), 'atribuicoes' => json_encode($lista($p->atribuicoes, self::ATRIBUICAO))]);
            $r['pedidos']++;
        }
        foreach (DB::table('pagamentos_lavandaria')->whereNotNull('pos_pagamentos')->get(['id', 'pos_pagamentos']) as $p) {
            DB::table('pagamentos_lavandaria')->where('id', $p->id)->update(['pos_pagamentos' => json_encode($lista($p->pos_pagamentos, self::PAGAMENTO))]);
            $r['pagamentos']++;
        }
        foreach (DB::table('pecas_lavandaria')->get(['id', 'unidade', 'preco', 'precos_servico', 'opcoes_preco', 'opcoes_preco_origem']) as $p) {
            $plano = self::planoPeca($p, null);
            if ($plano['update']) {
                DB::table('pecas_lavandaria')->where('id', $p->id)->update($plano['update']);
            }
            $r['pecas']++;
        }
        $r['linhas_faturas'] = $this->valorLiquidoLinhasLegado();
        foreach (DB::table('pedidos_lavandaria')->orderBy('id')->get(['id', 'empresa_id']) as $p) {
            $this->contexto->executarComo((int) $p->empresa_id, fn () => $this->caixa->actualizarFaturas(PedidoLavandaria::query()->findOrFail($p->id)));
            $r['faturas']++;
        }

        return $r;
    }

    /**
     * Modo --completar: preenche, nas ordens, peças e terminais JÁ migrados, só o que falta (idempotente; nunca sobrescreve o que
     * foi editado no sistema novo). Sem `$legado` (backup indisponível) só traduz o que está na base e reconstrói as opções de preço
     * de unidade+preço (origem DERIVADA); com `$legado` (ver legadoDoBackup) lê lav_prices e lav_fr_counters do backup.
     *
     * @param  array{pecas?: array<int, array{empresa: ?int, codigo: ?string, lav_prices: mixed}>, terminais?: array<int, array{empresa: ?int, codigo: ?string, fr: mixed}>}|null  $legado
     * @return array{pecas_com_opcoes_legado: int, pecas_com_opcoes_derivadas: int, pecas_precos_servico_traduzidos: int, pedidos_itens_traduzidos: int, terminais_contadores_fr: int, sem_alteracao: int, simulacao: bool, erros: list<array{tabela: string, id: int|string|null, motivo: string}>}
     */
    public function completar(?array $legado = null, bool $simular = false): array
    {
        $r = ['pecas_com_opcoes_legado' => 0, 'pecas_com_opcoes_derivadas' => 0, 'pecas_precos_servico_traduzidos' => 0, 'pedidos_itens_traduzidos' => 0,
            'terminais_contadores_fr' => 0, 'sem_alteracao' => 0, 'simulacao' => $simular, 'erros' => []];
        $erro = function (string $tabela, int|string|null $id, string $motivo) use (&$r): void {
            $r['erros'][] = ['tabela' => $tabela, 'id' => $id, 'motivo' => $motivo];
        };
        DB::beginTransaction();
        try {
            foreach (DB::table('pecas_lavandaria')->orderBy('id')->get(['id', 'empresa_id', 'codigo', 'unidade', 'preco', 'precos_servico', 'opcoes_preco', 'opcoes_preco_origem']) as $p) {
                $leg = $legado['pecas'][$p->id] ?? null;
                if ($leg !== null && (($leg['empresa'] !== null && $leg['empresa'] !== (int) $p->empresa_id) || ($leg['codigo'] !== null && trim((string) $leg['codigo']) !== trim((string) $p->codigo)))) {
                    $erro('pecas_lavandaria', $p->id, 'O backup tem outra empresa/código para este id: lav_prices não aplicado.');
                    $leg = null;
                }
                $plano = self::planoPeca($p, $leg === null ? null : ['lav_prices' => $leg['lav_prices']]);
                foreach ($plano['erros'] as $e) {
                    $erro('pecas_lavandaria', $p->id, $e);
                }
                if (! $plano['update']) {
                    $r['sem_alteracao']++;

                    continue;
                }
                DB::table('pecas_lavandaria')->where('id', $p->id)->update($plano['update']);
                $r['pecas_com_opcoes_legado'] += ($plano['update']['opcoes_preco_origem'] ?? null) === 'LEGADO' ? 1 : 0;
                $r['pecas_com_opcoes_derivadas'] += ($plano['update']['opcoes_preco_origem'] ?? null) === 'DERIVADA' ? 1 : 0;
                $r['pecas_precos_servico_traduzidos'] += array_key_exists('precos_servico', $plano['update']) ? 1 : 0;
                $this->auditar((int) $p->empresa_id, 'pecas_lavandaria', (int) $p->id, array_keys($plano['update']));
            }
            foreach (DB::table('pedidos_lavandaria')->orderBy('id')->get(['id', 'empresa_id', 'itens']) as $p) {
                $itens = json_decode((string) $p->itens, true);
                if (! is_array($itens)) {
                    $erro('pedidos_lavandaria', $p->id, 'Itens ilegíveis (JSON inválido): ordem não tocada.');

                    continue;
                }
                if (! array_filter($itens, fn ($i) => is_array($i) && array_intersect_key($i, self::ITEM))) {
                    $r['sem_alteracao']++;   // sem chaves do legado por traduzir (ordem do sistema novo ou já completada)

                    continue;
                }
                DB::table('pedidos_lavandaria')->where('id', $p->id)->update(['itens' => json_encode(array_map(fn ($i) => self::itemTraduzido($i), $itens))]);
                $r['pedidos_itens_traduzidos']++;
                $this->auditar((int) $p->empresa_id, 'pedidos_lavandaria', (int) $p->id, ['itens']);
            }
            foreach ($legado['terminais'] ?? [] as $id => $t) {
                $linha = DB::table('terminais_pos')->where('id', $id)->first(['id', 'empresa_id', 'codigo', 'lavandaria_contadores_fr']);
                $contadores = is_array($t['fr']) ? array_filter($t['fr'], fn ($n) => is_numeric($n)) : [];
                if (! $linha || ! $contadores || $linha->lavandaria_contadores_fr !== null) {
                    continue;
                }
                if (($t['empresa'] !== null && $t['empresa'] !== (int) $linha->empresa_id) || ($t['codigo'] !== null && trim((string) $t['codigo']) !== trim((string) $linha->codigo))) {
                    $erro('terminais_pos', $id, 'O backup tem outra empresa/código para este id: lav_fr_counters não aplicado.');

                    continue;
                }
                DB::table('terminais_pos')->where('id', $id)->update(['lavandaria_contadores_fr' => json_encode($contadores)]);
                $r['terminais_contadores_fr']++;
                $this->auditar((int) $linha->empresa_id, 'terminais_pos', (int) $id, ['lavandaria_contadores_fr']);
            }
            $simular ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $r;
    }

    /**
     * Lê do backup Dexie (streaming) o que a carga original não gravou: lav_pieces.lav_prices e pos_terminals.lav_fr_counters.
     *
     * @return array{pecas: array<int, array{empresa: ?int, codigo: ?string, lav_prices: mixed}>, terminais: array<int, array{empresa: ?int, codigo: ?string, fr: mixed}>}
     */
    public static function legadoDoBackup(string $caminho): array
    {
        $leitor = new LeitorBackupDexie($caminho);
        $leitor->validarFormato();
        $saida = ['pecas' => [], 'terminais' => []];
        foreach ($leitor->linhas($leitor->tabelas()) as [$tabela, $l]) {
            if ($tabela === 'lav_pieces' && isset($l['id'])) {
                $saida['pecas'][(int) $l['id']] = ['empresa' => isset($l['lav_company_id']) ? (int) $l['lav_company_id'] : null, 'codigo' => $l['code'] ?? null, 'lav_prices' => $l['lav_prices'] ?? null];
            } elseif ($tabela === 'pos_terminals' && isset($l['id'])) {
                $saida['terminais'][(int) $l['id']] = ['empresa' => isset($l['pos_company_id']) ? (int) $l['pos_company_id'] : null, 'codigo' => $l['code'] ?? null, 'fr' => $l['lav_fr_counters'] ?? null];
            }
        }

        return $saida;
    }

    /** @param  list<string>  $campos */
    private function auditar(int $empresa, string $tabela, int $id, array $campos): void
    {
        $this->contexto->executarComo($empresa, fn () => $this->auditoria->registar('POS', 'CORRECAO_MIGRACAO', 'Lavandaria F0b: campos do legado completados ('.implode(', ', $campos).')',
            $tabela, $id, null, ['campos' => $campos], $empresa, null, 'sistema (completar migração da lavandaria)'));
    }

    /** Linha de ordem: chaves em português, `valor` (qtd × preço) se faltar, e modo/tamanho (items.unit/size) preservados. */
    private static function itemTraduzido(mixed $i): mixed
    {
        if (! is_array($i)) {
            return $i;
        }
        $tinhaTamanho = array_key_exists('size', $i);
        $i = self::traduzir($i, self::ITEM);
        $i['materiais'] = array_map(fn ($m) => self::traduzir($m, self::MATERIAL), $i['materiais'] ?? []);
        $i['unidade'] ??= 'PECA';
        if ($tinhaTamanho) {   // linha do legado: o modo de preço é a unidade (PECA|KG|TAMANHO) e o tamanho o nome da opção
            $i['modo'] ??= in_array($i['unidade'], self::MODOS, true) ? $i['unidade'] : 'PECA';
        }
        if (! array_key_exists('valor', $i)) {   // valorItem do legado: qtd × preço
            $i['valor'] = CalculadoraDocumento::arredondar(bcmul(number_format((float) ($i['quantidade'] ?? 0), 3, '.', ''), number_format((float) ($i['preco'] ?? 0), 2, '.', ''), 8));
        }

        return $i;
    }

    /**
     * Colunas a actualizar numa peça (só o que falta): service_prices com modo/tamanho em português e opcoes_preco.
     *
     * @param  array{lav_prices: mixed}|null  $legado  lav_prices lidos do backup (null = sem backup)
     * @return array{update: array<string, mixed>, erros: list<string>}
     */
    private static function planoPeca(object $p, ?array $legado): array
    {
        $upd = [];
        $erros = [];
        if ($p->precos_servico !== null) {
            $antes = json_decode((string) $p->precos_servico, true);
            $depois = array_map(fn ($x) => self::traduzir($x, self::PRECO_SERVICO), is_array($antes) ? $antes : []);
            if (is_array($antes) && $depois !== $antes) {
                $upd['precos_servico'] = json_encode($depois);
            }
        }
        $actual = $p->opcoes_preco !== null ? json_decode((string) $p->opcoes_preco, true) : null;
        $origem = $p->opcoes_preco_origem;
        $lav = $legado['lav_prices'] ?? null;
        if ($lav !== null && ! is_array($lav)) {
            $erros[] = 'lav_prices com formato inesperado (não é uma lista): ignorado.';
            $lav = null;
        }
        $doBackup = null;
        if ($lav) {
            [$opcoes, $descartadas] = self::opcoesDoLegado($lav);
            if ($descartadas) {
                $erros[] = "{$descartadas} opção(ões) de preço do legado sem nome de tamanho ou inválida(s) descartada(s) (o legado também as ignorava).";
            }
            $doBackup = $opcoes ?: null;
        }
        $derivada = fn () => [['modo' => $p->unidade === 'KG' ? 'KG' : 'PECA', 'tamanho' => '', 'preco' => number_format((float) $p->preco, 2, '.', '')]];   // opcoesPeca do legado sem lav_prices
        if (empty($actual)) {
            $upd += $doBackup ? ['opcoes_preco' => json_encode($doBackup), 'opcoes_preco_origem' => 'LEGADO'] : ['opcoes_preco' => json_encode($derivada()), 'opcoes_preco_origem' => 'DERIVADA'];
        } elseif ($origem === null) {   // carga do ETL: lav_prices em bruto (mode/size/price)
            [$opcoes] = self::opcoesDoLegado($actual);
            $upd += ['opcoes_preco' => json_encode($opcoes ?: $derivada()), 'opcoes_preco_origem' => $opcoes ? 'LEGADO' : 'DERIVADA'];
        } elseif ($origem === 'DERIVADA' && $doBackup) {   // a reconstrução é nossa: o backup (verdade do legado) substitui-a
            $upd += ['opcoes_preco' => json_encode($doBackup), 'opcoes_preco_origem' => 'LEGADO'];
        }
        if (isset($upd['opcoes_preco']) && $actual && json_decode($upd['opcoes_preco'], true) == $actual && $upd['opcoes_preco_origem'] === $origem) {
            unset($upd['opcoes_preco'], $upd['opcoes_preco_origem']);   // nada de novo
        }

        return ['update' => $upd, 'erros' => $erros];
    }

    /**
     * normalizarOpcao + filtro de opcoesPeca (js/lavandaria.js:143-148): modo PECA|KG|TAMANHO (omissão PECA), tamanho só no modo TAMANHO,
     * opções TAMANHO sem nome ignoradas. Aceita chaves do legado (mode/size/price) ou já em português.
     *
     * @return array{0: list<array{modo: string, tamanho: string, preco: string}>, 1: int} opções e n.º de descartadas
     */
    private static function opcoesDoLegado(array $lista): array
    {
        $saida = [];
        $descartadas = 0;
        foreach ($lista as $o) {
            if (! is_array($o)) {
                $descartadas++;

                continue;
            }
            $o = self::traduzir($o, self::OPCAO);
            $modo = in_array($o['modo'] ?? null, self::MODOS, true) ? $o['modo'] : 'PECA';
            $tamanho = $modo === 'TAMANHO' ? trim((string) ($o['tamanho'] ?? '')) : '';
            if ($modo === 'TAMANHO' && $tamanho === '') {
                $descartadas++;

                continue;
            }
            $saida[] = ['modo' => $modo, 'tamanho' => $tamanho, 'preco' => number_format((float) ($o['preco'] ?? 0), 2, '.', '')];
        }

        return [$saida, $descartadas];
    }

    /** Valor líquido das linhas das facturas de lavandaria e das vendas POS do legado (preço com IVA, total_linha nulo), acertado ao cabeçalho. */
    private function valorLiquidoLinhasLegado(): int
    {
        $n = 0;
        $vendas = DB::table('vendas')->where(fn ($q) => $q->whereNotNull('pedido_lavandaria_id')->orWhereNotNull('sessao_pos_id')->orWhereNotNull('sessao_pos_legado_codigo'))
            ->whereIn('tipo_documento', ['FT', 'FR'])
            ->whereExists(fn ($q) => $q->from('itens_venda as i')->whereColumn('i.venda_id', 'vendas.id')->whereNull('i.total_linha'))->get(['id', 'total_liquido']);
        foreach ($vendas as $v) {
            $itens = DB::table('itens_venda')->where('venda_id', $v->id)->orderBy('id')->get(['id', 'total', 'taxa_imposto', 'total_linha', 'fe_selado']);
            if ($itens->contains(fn ($i) => $i->total_linha !== null || $i->fe_selado)) {
                continue;   // documento já tratado ou selado: não se mexe
            }
            $bases = $itens->map(fn ($i) => CalculadoraDocumento::arredondar(bcdiv((string) $i->total, bcadd('1', bcdiv((string) $i->taxa_imposto, '100', 8), 8), 8)))->all();
            if ($v->total_liquido !== null && $bases) {   // o arredondamento acerta-se na última linha, para bater com o total líquido do documento
                $dif = bcsub((string) $v->total_liquido, array_reduce($bases, fn ($s, $b) => bcadd($s, $b, 2), '0.00'), 2);
                $bases[count($bases) - 1] = bcadd($bases[count($bases) - 1], $dif, 2);
            }
            foreach ($itens->values() as $k => $i) {
                DB::table('itens_venda')->where('id', $i->id)->update(['total_linha' => $bases[$k]]);
                $n++;
            }
        }

        return $n;
    }

    /** Traduz as chaves conhecidas; as já traduzidas ou desconhecidas ficam como estão (idempotente). */
    private static function traduzir(mixed $x, array $mapa): mixed
    {
        if (! is_array($x)) {
            return $x;
        }
        $saida = [];
        foreach ($x as $k => $v) {
            $saida[$mapa[$k] ?? $k] = $v;
        }

        return $saida;
    }
}
