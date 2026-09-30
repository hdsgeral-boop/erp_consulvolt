<?php

namespace App\Services\POS\Lavandaria;

use App\Models\Terceiro;
use App\Services\Vendas\CalculadoraDocumento;
use Carbon\CarbonInterface;

/**
 * Regras puras da ordem de serviço da lavandaria (js/lavandaria.js:37-205, 1163-1170), em decimal exacto.
 * O estado da ordem deriva dos estados das linhas; os valores são preços COM IVA (como no POS).
 * Correcção face ao legado: o valor de cada linha é o valor facturável pela regra AGT (CalculadoraDocumento::calcularComIva,
 * preço unitário a 2 casas) — o legado guardava qtd × preço em vírgula flutuante e o documento podia não bater com a ordem.
 */
final class RegrasLavandaria
{
    public const ESTADOS = ['ORCAMENTO', 'RECEBIDA', 'EM_EXECUCAO', 'PRONTA', 'ENTREGA_PARCIAL', 'ENTREGUE', 'ANULADA'];

    public const GRUPOS = ['LAVANDARIA', 'ALFAIATARIA'];

    public const UNIDADES = ['PECA', 'KG'];

    public const BOM_ESTADO = 'Bom estado';

    /** Estados da peça à entrada (ESTADOS_ENTRADA, lavandaria.js:48). */
    public const ESTADOS_ENTRADA = ['Bom estado', 'Manchas', 'Rasgões ou furos', 'Desbotada / descolorada', 'Botões ou fechos em falta', 'Desgaste / pelo',
        'Encolhida / deformada', 'Outro dano'];

    /** Produtos das taxas (EXTRAS, lavandaria.js:51-56). */
    public const EXTRAS = ['URGENCIA' => ['LAV-URG', 'Taxa de urgência'], 'ARMAZEM' => ['LAV-ARM', 'Taxa de armazenagem'],
        'RECOLHA' => ['LAV-REC', 'Recolha ao domicílio'], 'ENTREGA' => ['LAV-ENT', 'Entrega ao domicílio']];

    public static function dinheiro(mixed $v): string
    {
        return number_format((float) $v, 2, '.', '');
    }

    /** Valor facturável (com IVA) de qtd × preço unitário com IVA, pela regra AGT. */
    public static function valorComIva(mixed $quantidade, mixed $preco, mixed $taxa): string
    {
        return CalculadoraDocumento::calcularComIva([['quantidade' => (string) $quantidade, 'preco_unitario' => self::dinheiro($preco),
            'taxa_imposto' => (string) $taxa]])['total_bruto'];
    }

    /** valorItem (lavandaria.js:126): linha anulada vale zero. */
    public static function valorItem(array $i): string
    {
        if (($i['estado'] ?? null) === 'ANULADA') {
            return '0.00';
        }

        return self::dinheiro($i['valor'] ?? CalculadoraDocumento::arredondar(bcmul((string) ($i['quantidade'] ?? 0), self::dinheiro($i['preco'] ?? 0), 8)));
    }

    /** estadoOrdem (lavandaria.js:131-140). */
    public static function estadoOrdem(array $itens): string
    {
        $ativos = array_values(array_filter($itens, fn ($i) => ($i['estado'] ?? null) !== 'ANULADA'));
        $estados = array_column($ativos, 'estado');
        $todos = fn (string $e) => count(array_filter($estados, fn ($x) => $x === $e)) === count($estados);

        return match (true) {
            ! $ativos => 'ANULADA',
            $todos('ENTREGUE') => 'ENTREGUE',
            in_array('ENTREGUE', $estados, true) => 'ENTREGA_PARCIAL',
            $todos('PRONTA') => 'PRONTA',
            in_array('EM_EXECUCAO', $estados, true) || in_array('PRONTA', $estados, true) => 'EM_EXECUCAO',
            in_array('ORCAMENTO', $estados, true) => 'ORCAMENTO',
            default => 'RECEBIDA',
        };
    }

    /**
     * totaisOrdem (lavandaria.js:142-149).
     *
     * @return array{servicos: string, extras: string, total: string, facturado: string, pago: string, saldo: string, por_facturar: string, saldo_facturado: string}
     */
    public static function totais(array $itens, array $extras, string $facturado, string $pago): array
    {
        $servicos = array_reduce($itens, fn ($s, $i) => bcadd($s, self::valorItem($i), 2), '0.00');
        $ext = array_reduce(array_filter($extras, fn ($e) => empty($e['cancelado'])), fn ($s, $e) => bcadd($s, self::dinheiro($e['valor'] ?? 0), 2), '0.00');
        $total = bcadd($servicos, $ext, 2);

        return ['servicos' => $servicos, 'extras' => $ext, 'total' => $total, 'facturado' => $facturado, 'pago' => $pago, 'saldo' => bcsub($total, $pago, 2),
            'por_facturar' => bcsub($total, $facturado, 2), 'saldo_facturado' => bcsub($facturado, $pago, 2)];
    }

    /** itemFacturavel (lavandaria.js:127): por facturar, não anulada e sem orçamento por aprovar. */
    public static function facturavel(array $i): bool
    {
        return empty($i['venda_id']) && ($i['estado'] ?? null) !== 'ANULADA' && ! (! empty($i['requer_orcamento']) && ($i['estado_orcamento'] ?? null) !== 'APROVADO');
    }

    /**
     * linhasPorFacturar (lavandaria.js:193-204): linhas facturáveis (opcionalmente só as indicadas) e todas as taxas por facturar.
     *
     * @param  list<int>|null  $linhasIds
     * @return list<array{ref: string, id: int|string, produto_id: int, descricao: string, quantidade: string, preco: string, total: string}>
     */
    public static function linhasPorFacturar(array $itens, array $extras, ?array $linhasIds = null): array
    {
        $saida = [];
        foreach ($itens as $i) {
            if (! self::facturavel($i) || ($linhasIds !== null && ! in_array((int) $i['linha_id'], $linhasIds, true))) {
                continue;
            }
            $peca = self::descricaoPeca($i);
            $saida[] = ['ref' => 'item', 'id' => (int) $i['linha_id'], 'produto_id' => (int) ($i['produto_id'] ?? 0), 'descricao' => trim(($i['nome'] ?? '').($peca ? " · {$peca}" : '')),
                'quantidade' => (string) $i['quantidade'], 'preco' => self::dinheiro($i['preco']), 'total' => self::valorItem($i)];
        }
        foreach ($extras as $e) {
            if (! empty($e['venda_id']) || ! empty($e['cancelado'])) {
                continue;
            }
            $saida[] = ['ref' => 'extra', 'id' => $e['id'], 'produto_id' => (int) ($e['produto_id'] ?? 0), 'descricao' => $e['nome'] ?? '', 'quantidade' => '1',
                'preco' => self::dinheiro($e['valor']), 'total' => self::dinheiro($e['valor'])];
        }

        return array_values(array_filter($saida, fn ($l) => bccomp($l['total'], '0', 2) > 0));
    }

    public static function soma(array $linhas, string $campo = 'total'): string
    {
        return array_reduce($linhas, fn ($s, $l) => bcadd($s, self::dinheiro($l[$campo] ?? 0), 2), '0.00');
    }

    /** descricaoPeca (lavandaria.js:128). */
    public static function descricaoPeca(array $i): string
    {
        if (! empty($i['descricao_peca'])) {
            return (string) $i['descricao_peca'];
        }
        $cor = implode(', ', array_filter([$i['cor'] ?? null, $i['tecido'] ?? null]));

        return implode(' · ', array_filter([$i['nome_peca'] ?? null, $cor]));
    }

    /** estadoEntradaTexto (lavandaria.js:129). */
    public static function estadoEntradaTexto(array $i): string
    {
        if (! empty($i['estado_entrada'])) {
            return $i['estado_entrada'].(! empty($i['notas_entrada']) ? " — {$i['notas_entrada']}" : '');
        }

        return (string) ($i['condicao'] ?? '');
    }

    /** N.º de etiquetas da linha: por kg, o n.º de peças; por peça, a quantidade (lavandaria.js:560, 654). */
    public static function etiquetas(array $i): int
    {
        return max(1, (int) round((float) (($i['unidade'] ?? 'PECA') === 'KG' ? ($i['numero_pecas'] ?? 1) : ($i['quantidade'] ?? 1))));
    }

    /** Dias completos desde uma data (diasDesde, lavandaria.js:31). */
    public static function diasDesde(?string $iso, CarbonInterface $agora): int
    {
        return $iso ? (int) floor(($agora->getTimestamp() - strtotime($iso)) / 86400) : 0;
    }

    /**
     * Taxa de armazenagem das peças prontas não levantadas (taxaArmazenagem, lavandaria.js:1163-1170):
     * % do valor do serviço por cada dia além do prazo de levantamento.
     *
     * @return array{valor: string, detalhe: list<array{linha_id: int, nome: string, dias: int, valor: string}>}
     */
    public static function taxaArmazenagem(array $itens, array $config, CarbonInterface $agora): array
    {
        if (empty($config['taxa_armazenagem_ativa'])) {
            return ['valor' => '0.00', 'detalhe' => []];
        }
        $detalhe = [];
        foreach ($itens as $i) {
            $dias = self::diasDesde($i['pronta_em'] ?? null, $agora) - (int) $config['dias_armazenagem_gratis'];
            if ($dias > 0) {
                $valor = CalculadoraDocumento::arredondar(bcmul(bcdiv(bcmul(self::valorItem($i), (string) $config['percentagem_armazenagem_dia'], 8), '100', 8), (string) $dias, 8));
                $detalhe[] = ['linha_id' => (int) $i['linha_id'], 'nome' => trim(($i['nome_peca'] ?? '').' '.$i['nome']), 'dias' => $dias, 'valor' => $valor];
            }
        }

        return ['valor' => self::soma($detalhe, 'valor'), 'detalhe' => $detalhe];
    }

    /** ehConsumidorFinal (lavandaria.js:65): sem cliente, NIF 99999999(9) ou nome «Consumidor Final». */
    public static function consumidorFinal(?Terceiro $c): bool
    {
        return ! $c || preg_match('/^9{8,9}$/', preg_replace('/\D/', '', (string) $c->nif)) === 1 || preg_match('/consumidor\s*final/iu', (string) $c->nome) === 1;
    }

    /** Diferença em dias, com uma casa decimal (difDias, lavandaria.js:160). */
    public static function difDias(?string $de, ?string $ate): ?float
    {
        return $de && $ate ? max(0, round((strtotime($ate) - strtotime($de)) / 8640) / 10) : null;
    }

    /**
     * Indicadores da ordem (indicadoresOrdem, lavandaria.js:163-182).
     *
     * @return array<string, mixed>
     */
    public static function indicadores(array $o, CarbonInterface $agora): array
    {
        $itens = array_values(array_filter($o['itens'] ?? [], fn ($i) => ($i['estado'] ?? null) !== 'ANULADA'));
        $concluida = $itens && ! array_filter($itens, fn ($i) => ! in_array($i['estado'], ['PRONTA', 'ENTREGUE'], true));
        $max = fn (array $l) => ($l = array_filter($l)) ? max(array_map(fn ($x) => date('c', strtotime($x)), $l)) : null;
        $prontaEm = $concluida ? $max(array_column($itens, 'pronta_em')) : null;
        $entregueEm = ($o['estado'] ?? null) === 'ENTREGUE' ? ($o['entregue_em'] ?? $max(array_column($itens, 'entregue_em'))) : null;
        $agoraIso = $agora->toIso8601String();
        $referencia = $prontaEm ?? $agoraIso;
        $atraso = ! empty($o['data_prometida']) && ($o['estado'] ?? null) !== 'ANULADA'
            ? max(0, (int) round((strtotime(substr($referencia, 0, 10)) - strtotime(substr((string) $o['data_prometida'], 0, 10))) / 86400)) : 0;
        $situacao = match (true) {
            ($o['estado'] ?? null) === 'ANULADA' => 'Anulada',
            (bool) $prontaEm => $atraso > 0 ? "Concluída com {$atraso}d de atraso" : 'Concluída no prazo',
            default => $atraso > 0 ? "Atrasada {$atraso}d" : 'Dentro do prazo',
        };
        $atribuida = $o['atribuido_em'] ?? null;

        return ['responsavel' => $o['nome_atribuido'] ?? null, 'atribuida_em' => $atribuida, 'pronta_em' => $prontaEm, 'entregue_em' => $entregueEm,
            'dias_aberta' => self::difDias($o['recebido_em'] ?? null, $entregueEm ?? $agoraIso),
            'execucao' => $prontaEm ? self::difDias($atribuida && strtotime($atribuida) < strtotime($prontaEm) ? $atribuida : ($o['recebido_em'] ?? null), $prontaEm) : null,
            'espera_levantamento' => $prontaEm ? self::difDias($prontaEm, $entregueEm ?? $agoraIso) : null,
            'atraso' => $atraso, 'no_prazo' => $prontaEm ? $atraso === 0 : null, 'situacao' => $situacao];
    }
}
