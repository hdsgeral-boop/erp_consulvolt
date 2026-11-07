<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\RendaArrendamento;

/**
 * Subarrendamento: margem por fracção e cadeia mãe/filhos (margem e cadeia do legado, SB:167-240). A margem compara o que a empresa
 * factura aos subarrendatários com o que deve pagar aos senhorios no mesmo intervalo, não o que já entrou ou saiu de caixa; usa o
 * `total_facturar` (antes de retenção) das rendas não anuladas dos contratos tomados e subarrendados de imóveis arrendados.
 * Correcção (P3): as rendas são mensalizadas (CalculadoraMargem), para que uma renda tomada anual não distorça a margem. A cadeia
 * mostra, por contrato tomado, o facturado ao senhorio e o facturado aos filhos, com a margem do histórico todo. Só leitura.
 */
final class ServicoSubarrendamento
{
    public function __construct(private readonly ServicoContaCorrente $contaCorrente) {}

    /**
     * @return array<string, mixed>
     */
    public function margem(?string $de = null, ?string $ate = null): array
    {
        $ate ??= now()->format('Y-m');
        $de ??= CalculadoraPeriodos::somarMeses($ate, -11);
        if ($de > $ate) {
            throw new ErroNegocio('O início do intervalo é posterior ao fim.', 'IMO_INTERVALO_INVALIDO');
        }
        $contratos = ContratoArrendamento::query()->whereIn('tipo', ['TOMADA', 'SUBARRENDAMENTO'])
            ->whereIn('imovel_id', Imovel::query()->where('papel', 'ARRENDADO')->select('id'))->get(['id', 'numero', 'tipo', 'posicao', 'fracao_id', 'periodicidade', 'imovel_id'])->keyBy('id');
        $rendas = RendaArrendamento::query()->whereIn('contrato_id', $contratos->keys())->where('estado', '<>', 'ANULADA')->get(['contrato_id', 'periodo', 'total_facturar']);
        $linhas = [];
        foreach ($rendas as $r) {
            $c = $contratos[$r->contrato_id];
            $linhas[] = ['fracao_id' => (int) $c->fracao_id, 'posicao' => $c->posicao, 'periodo' => $r->periodo, 'meses' => CalculadoraPeriodos::meses($c->periodicidade),
                'total' => number_format((float) $r->total_facturar, 2, '.', ''), 'contrato' => $c->numero];
        }
        $calculo = CalculadoraMargem::calcular($linhas, $de, $ate, true);
        // tomadas sem nenhuma renda no intervalo também contam como «sem subarrendamento» se a fracção não tem subs: não aparecem nas linhas
        $fracoes = Fracao::query()->whereIn('id', array_column($calculo['linhas'], 'fracao_id'))->get(['id', 'designacao', 'imovel_id'])->keyBy('id');
        $imoveis = Imovel::query()->whereIn('id', $fracoes->pluck('imovel_id')->unique())->pluck('nome', 'id');
        $saida = array_map(function (array $l) use ($fracoes, $imoveis) {
            $f = $fracoes[$l['fracao_id']] ?? null;

            return $l + ['fracao_designacao' => $f?->designacao, 'imovel_nome' => $f ? ($imoveis[$f->imovel_id] ?? null) : null];
        }, $calculo['linhas']);

        return ['de' => $de, 'ate' => $ate, 'linhas' => $saida, 'totais' => $calculo['totais'], 'negativas' => $calculo['negativas'], 'vazias' => $calculo['vazias']];
    }

    /**
     * Cadeia de uma fracção: cada contrato tomado que a cobre, os subarrendamentos que dele dependem e a margem acumulada.
     *
     * @return list<array<string, mixed>>
     */
    public function cadeiaDaFracao(int $fracaoId): array
    {
        Fracao::query()->findOrFail($fracaoId);
        $tomadas = ContratoArrendamento::query()->where('tipo', 'TOMADA')->whereIn('id', ContratoFracao::query()->where('fracao_id', $fracaoId)->select('contrato_id'))->orderBy('inicio')->get();
        $saida = [];
        foreach ($tomadas as $mae) {
            $filhos = ContratoArrendamento::query()->where('contrato_pai_id', $mae->id)->orderBy('inicio')->get();
            $paga = $this->contaCorrente->doContrato($mae)['totais']['facturado'];
            $linhasFilhos = [];
            $recebe = '0.00';
            foreach ($filhos as $f) {
                $t = $this->contaCorrente->doContrato($f)['totais'];
                $recebe = bcadd($recebe, number_format($t['facturado'], 2, '.', ''), 2);
                $linhasFilhos[] = ['id' => $f->id, 'numero' => $f->numero, 'estado' => $f->estado, 'inicio' => $f->inicio->format('Y-m-d'), 'fim' => $f->fim?->format('Y-m-d'),
                    'facturado' => $t['facturado'], 'em_divida' => $t['em_divida']];
            }
            $saida[] = ['id' => $mae->id, 'numero' => $mae->numero, 'estado' => $mae->estado, 'inicio' => $mae->inicio->format('Y-m-d'), 'fim' => $mae->fim?->format('Y-m-d'),
                'permite_sub' => (bool) $mae->permite_sub, 'paga' => (float) $paga, 'recebe' => (float) $recebe, 'margem' => (float) bcsub($recebe, number_format($paga, 2, '.', ''), 2),
                'vivos' => $filhos->whereIn('estado', ['ACTIVO', 'SUSPENSO'])->count(), 'subarrendamentos' => $linhasFilhos];
        }

        return $saida;
    }
}
