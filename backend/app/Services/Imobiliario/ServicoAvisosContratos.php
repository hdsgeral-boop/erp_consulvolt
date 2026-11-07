<?php

namespace App\Services\Imobiliario;

use App\Models\ContratoArrendamento;
use App\Models\RendaArrendamento;
use Illuminate\Support\Collection;

/**
 * Avisos de contratos (avisos() do legado, CT:1042-1112), calculados em lote a partir dos dados: rascunho por activar, cópia por
 * entregar na Repartição, caução por receber, subarrendamento sem título válido, termo próximo ou ultrapassado, actualização de
 * renda devida, rendas vencidas por facturar ou por pagar e mora. Só leitura; os alertas persistentes e agendados são da fase dos
 * transversais. O texto é sempre em português e nunca inclui dados de outras empresas (tudo passa pelo isolamento por empresa).
 */
final class ServicoAvisosContratos
{
    private const ORDEM = ['MORA' => 1, 'EXPIRADO' => 2, 'POR_FACTURAR' => 3, 'POR_PAGAR' => 3, 'TITULO_INACTIVO' => 4, 'ALEM_TITULO' => 4, 'CAUCAO' => 5, 'ACTUALIZACAO' => 6, 'FIM' => 7,
        'REGISTO' => 8, 'RASCUNHO' => 9];

    public function __construct(private readonly ServicoContaCorrente $contaCorrente, private readonly ServicoTaxasImobiliario $taxas) {}

    /** @return list<array{tipo: string, contrato_id: int, numero: string, texto: string}> */
    public function todos(?string $hoje = null): array
    {
        $contratos = ContratoArrendamento::query()->whereIn('estado', ContratoArrendamento::ESTADOS_VIVOS)->orderBy('id')->get();

        return $this->calcular($contratos, $hoje ?? now()->toDateString());
    }

    /** @return list<array{tipo: string, contrato_id: int, numero: string, texto: string}> */
    public function doContrato(ContratoArrendamento $c, ?string $hoje = null): array
    {
        return $this->calcular(collect([$c]), $hoje ?? now()->toDateString());
    }

    /**
     * @param  Collection<int, ContratoArrendamento>  $contratos
     * @return list<array{tipo: string, contrato_id: int, numero: string, texto: string}>
     */
    private function calcular(Collection $contratos, string $hoje): array
    {
        if ($contratos->isEmpty()) {
            return [];
        }
        $porId = $contratos->keyBy('id');
        $pais = ContratoArrendamento::query()->whereIn('id', $contratos->pluck('contrato_pai_id')->filter()->unique())->get()->keyBy('id');
        $rendas = RendaArrendamento::query()->whereIn('contrato_id', $contratos->pluck('id'))->where('estado', '<>', 'ANULADA')->get(['id', 'contrato_id', 'posicao', 'estado', 'vencimento',
            'venda_id', 'documento_tesouraria_id', 'total_receber', 'numero_documento'])->groupBy('contrato_id');
        $facturadas = $rendas->flatten(1)->where('estado', 'FACTURADA')->values();
        $cc = $facturadas->isEmpty() ? [] : $this->contaCorrente->linhas($facturadas, $porId, $hoje);
        $prazoRegisto = (int) ($this->taxas->doAno((int) substr($hoje, 0, 4))['taxas']['prazo_registo'] ?? 10);
        $periodo = substr($hoje, 0, 7);
        $saida = [];
        $add = function (ContratoArrendamento $c, string $tipo, string $texto) use (&$saida) {
            $saida[] = ['tipo' => $tipo, 'contrato_id' => $c->id, 'numero' => $c->numero, 'texto' => $texto];
        };
        $kz = fn (float $v) => number_format($v, 2, ',', ' ').' Kz';
        foreach ($contratos as $c) {
            $n = $c->numero;
            $fim = $c->fim?->format('Y-m-d');
            if ($c->estado === 'RASCUNHO') {
                $add($c, 'RASCUNHO', "O contrato {$n} está em rascunho: active-o para gerar rendas.");
            }
            if ($c->estado !== 'ACTIVO') {
                continue;
            }
            if ($c->posicao === 'SENHORIO' && ! $c->registo_agt_data && CalculadoraPeriodos::diasEntre($c->inicio->format('Y-m-d'), $hoje) > $prazoRegisto) {
                $add($c, 'REGISTO', "O contrato {$n} não tem registada a entrega da cópia na Repartição Fiscal (prazo de {$prazoRegisto} dias a contar do início).");
            }
            if ((float) $c->caucao > (float) $c->caucao_recebida + 0.001) {
                $falta = (float) $c->caucao - (float) $c->caucao_recebida;
                $add($c, 'CAUCAO', "Contrato {$n}: falta ".($c->posicao === 'SENHORIO' ? 'receber' : 'entregar').' '.$kz($falta).' de caução.');
            }
            if ($c->tipo === 'SUBARRENDAMENTO') {
                $pai = $pais[$c->contrato_pai_id] ?? null;
                if (! $pai || $pai->estado !== 'ACTIVO') {
                    $add($c, 'TITULO_INACTIVO', "O subarrendamento {$n} está activo mas o contrato de que depende ".($pai ? "({$pai->numero}) já não está activo." : 'já não existe.'));
                } else {
                    $fimPai = ServicoRendas::fimEfectivo($pai);
                    if ($fimPai !== null && ($fim === null || $fim > $fimPai)) {
                        $add($c, 'ALEM_TITULO', "O subarrendamento {$n} vai além do contrato {$pai->numero}, que termina em {$fimPai}.");
                    }
                }
            }
            if ($fim !== null) {
                $dias = CalculadoraPeriodos::diasEntre($hoje, $fim);
                if ($dias < 0) {
                    $add($c, 'EXPIRADO', "O contrato {$n} passou o termo ({$fim}) e continua activo: renove-o ou termine-o.");
                } elseif ($dias <= ($c->aviso_dias > 0 ? $c->aviso_dias : 60)) {
                    $add($c, 'FIM', "O contrato {$n} termina em {$fim} (daqui a {$dias} dia(s))".($c->renovacao === 'AUTO' ? ', com renovação automática por '.$c->renovacao_meses.' mês(es).' : '.'));
                }
            }
            if ($c->actualizacao !== 'NAO') {
                $devida = CalculadoraActualizacao::rendaNoPeriodo(['renda' => $c->renda, 'actualizacao' => $c->actualizacao, 'actualizacao_valor' => $c->actualizacao_valor,
                    'actualizacao_meses' => $c->actualizacao_meses, 'actualizacao_base' => $c->actualizacao_base?->format('Y-m-d'), 'inicio' => $c->inicio->format('Y-m-d')], $periodo);
                if (bccomp($devida, number_format((float) $c->renda, 2, '.', ''), 2) > 0) {
                    $add($c, 'ACTUALIZACAO', "A renda do contrato {$n} deve ser actualizada para ".$kz((float) $devida).' (hoje paga-se '.$kz((float) $c->renda).').');
                }
            }
            $rs = $rendas[$c->id] ?? collect();
            $vencidas = $rs->filter(fn ($r) => in_array($r->estado, ['PREVISTA', 'APROVADA'], true) && $r->vencimento->format('Y-m-d') <= $hoje);
            if ($vencidas->isNotEmpty()) {
                $add($c, $c->posicao === 'SENHORIO' ? 'POR_FACTURAR' : 'POR_PAGAR', "Contrato {$n}: {$vencidas->count()} renda(s) vencida(s) por ".($c->posicao === 'SENHORIO' ? 'facturar.' : 'pagar.'));
            }
            $emMora = $rs->filter(fn ($r) => $r->estado === 'FACTURADA' && ($cc[$r->id]['divida'] ?? 0) > 0.009 && ($cc[$r->id]['atraso'] ?? 0) > 0);
            if ($emMora->isNotEmpty()) {
                $divida = $emMora->sum(fn ($r) => $cc[$r->id]['divida']);
                $juros = $emMora->sum(fn ($r) => $cc[$r->id]['juros']);
                $add($c, 'MORA', "Contrato {$n}: {$emMora->count()} renda(s) em atraso, ".$kz($divida).' em dívida'.($juros > 0 ? ' e '.$kz($juros).' de juros de mora.' : '.'));
            }
        }
        usort($saida, fn ($a, $b) => (self::ORDEM[$a['tipo']] ?? 99) <=> (self::ORDEM[$b['tipo']] ?? 99) ?: $a['contrato_id'] <=> $b['contrato_id']);

        return $saida;
    }
}
