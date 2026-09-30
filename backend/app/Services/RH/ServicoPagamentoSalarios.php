<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\CartaPagamentoBancario;
use App\Models\Colaborador;
use App\Models\CoordenadaBancariaColaborador;
use App\Models\DocumentoTesouraria;
use App\Models\ItemCartaPagamento;
use App\Models\MapeamentoContabilSistemaRH;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\ResultadoFolhaSalarial;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pagamento dos salários.
 *   - Ordem de pagamento bancária (relatório rh_banco, js/app_v2.js:7014-7465): períodos VALIDADOS, uma linha por
 *     colaborador do grupo (Colaboradores / Avençados) com banco, IBAN e líquido — agora a partir da FOTOGRAFIA.
 *   - Cartas de pagamento (payment_letters/payment_letter_items: tabelas que o legado criou e NUNCA usou): a ordem
 *     passa a poder ser GRAVADA para uma conta bancária, com o IBAN e o valor fixados no momento da emissão; um
 *     colaborador só entra numa carta por período; sem IBAN a carta é recusada (o legado imprimia «N/D»).
 *   - Pagamento (gerarPagamentoSalarios, js/modules/rh/folha_salarios.js:323-344): documento de pagamento da
 *     tesouraria (PAG, PENDENTE, ligado ao período) que debita os salários a pagar (NET_PAY_CREDIT de cada
 *     colaborador) com o n.º SAL+MMAAAA e credita o banco da carta ao integrar. Exige o período contabilizado,
 *     e o total pago no período não pode exceder o líquido (no legado nada o impedia).
 */
final class ServicoPagamentoSalarios
{
    public const GRUPOS = ['COLABORADORES', 'AVENCADOS', 'TODOS'];

    public function __construct(
        private readonly ServicoFolhaSalarial $folha,
        private readonly ServicoDocumentosTesouraria $documentos,
        private readonly ServicoPlanoContas $plano,
    ) {}

    /** @return array{linhas: list<array<string, mixed>>, total: string, sem_iban: int} */
    public function ordemPagamento(PeriodoProcessamentoSalarial $p, string $grupo = 'TODOS'): array
    {
        $this->exigirValidado($p);
        $nomes = Colaborador::query()->withTrashed()->pluck('nome_completo', 'id');
        $coordenadas = CoordenadaBancariaColaborador::query()->with('banco')->get()->keyBy('colaborador_id');
        $emCarta = ItemCartaPagamento::query()->join('cartas_pagamento_bancario as c', 'c.id', '=', 'itens_carta_pagamento.carta_pagamento_bancario_id')
            ->where('c.periodo_processamento_salarial_id', $p->id)->pluck('c.id', 'itens_carta_pagamento.colaborador_id');
        $linhas = [];
        $total = '0.00';
        $semIban = 0;
        foreach (ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->orderBy('colaborador_id')->get() as $r) {
            if (bccomp((string) $r->liquido, '0', 2) <= 0 || ($grupo === 'COLABORADORES' && $r->avencado) || ($grupo === 'AVENCADOS' && ! $r->avencado)) {
                continue;
            }
            $cb = $coordenadas[$r->colaborador_id] ?? null;
            $semIban += $cb ? 0 : 1;
            $total = bcadd($total, (string) $r->liquido, 2);
            $linhas[] = ['colaborador_id' => $r->colaborador_id, 'nome' => $nomes[$r->colaborador_id] ?? null, 'avencado' => (bool) $r->avencado,
                // IBAN normalizado: NIB migrado (21 dígitos) → AO06
                'banco' => $cb?->banco?->nome, 'iban' => $cb ? ServicoColaboradores::normalizarIban((string) $cb->iban) : null,
                'liquido' => number_format((float) $r->liquido, 2, '.', ''),
                'carta_pagamento_id' => $emCarta[$r->colaborador_id] ?? null];
        }

        return ['linhas' => $linhas, 'total' => $total, 'sem_iban' => $semIban];
    }

    /**
     * @param  array{codigo_conta_bancaria: string, data: string, nome_assinatura?: ?string, grupo?: ?string, colaboradores?: ?list<int>}  $d
     */
    public function emitirCarta(PeriodoProcessamentoSalarial $p, array $d): CartaPagamentoBancario
    {
        $this->exigirValidado($p);
        $this->plano->contaDeMovimento($d['codigo_conta_bancaria']);
        if (! str_starts_with($d['codigo_conta_bancaria'], '43')) {
            throw new ErroNegocio('A carta de pagamento é emitida sobre uma conta bancária (43).', 'CONTA_NAO_BANCARIA', 422);
        }
        $grupo = $d['grupo'] ?? 'TODOS';

        return DB::transaction(function () use ($p, $d, $grupo) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["cartas_salarios:{$p->id}"]);
            $linhas = collect($this->ordemPagamento($p, $grupo)['linhas'])
                ->when(! empty($d['colaboradores']), fn ($c) => $c->whereIn('colaborador_id', $d['colaboradores']))
                ->filter(fn ($l) => $l['carta_pagamento_id'] === null)->values();
            if (! empty($d['colaboradores']) && $linhas->count() !== count(array_unique($d['colaboradores']))) {
                throw new ErroNegocio('Há colaboradores sem salário líquido neste período, fora do grupo ou já incluídos noutra carta.', 'COLABORADOR_INVALIDO', 422);
            }
            if ($linhas->isEmpty()) {
                throw new ErroNegocio('Não há salários por incluir em carta neste período (e grupo).', 'SEM_SALARIOS_A_PAGAR', 422);
            }
            $semIban = $linhas->whereNull('iban')->pluck('nome')->all();
            if ($semIban) {
                throw new ErroNegocio('Colaboradores sem IBAN: '.implode(', ', $semIban).'. Registe as coordenadas bancárias antes de emitir a carta.',
                    'COLABORADOR_SEM_IBAN', 422, ['colaboradores' => $semIban]);
            }
            $carta = CartaPagamentoBancario::create(['mes_ano' => $p->mes_ano, 'periodo_processamento_salarial_id' => $p->id, 'grupo' => $grupo,
                'codigo_conta_bancaria' => $d['codigo_conta_bancaria'], 'nome_assinatura' => $d['nome_assinatura'] ?? null, 'data' => substr($d['data'], 0, 10),
                'montante_total' => $linhas->reduce(fn ($s, $l) => bcadd($s, $l['liquido'], 2), '0.00'), 'criado_por' => Auth::user()?->nome_utilizador]);
            foreach ($linhas as $l) {
                ItemCartaPagamento::create(['carta_pagamento_bancario_id' => $carta->id, 'colaborador_id' => $l['colaborador_id'], 'montante' => $l['liquido'], 'iban' => $l['iban']]);
            }

            return $carta->refresh();
        });
    }

    public function carta(CartaPagamentoBancario $c): array
    {
        $nomes = Colaborador::query()->withTrashed()->pluck('nome_completo', 'id');

        return $c->toArray() + [
            'itens' => ItemCartaPagamento::query()->where('carta_pagamento_bancario_id', $c->id)->orderBy('id')->get()
                ->map(fn ($i) => $i->toArray() + ['nome' => $nomes[$i->colaborador_id] ?? null])->all(),
            'documento_tesouraria' => $c->documento_tesouraria_id ? DocumentoTesouraria::query()->find($c->documento_tesouraria_id)?->only(['id', 'numero_documento', 'estado', 'valor_total']) : null,
        ];
    }

    public function eliminarCarta(CartaPagamentoBancario $c): void
    {
        DB::transaction(function () use ($c) {
            $c = CartaPagamentoBancario::query()->lockForUpdate()->findOrFail($c->id);
            $this->exigirSemPagamento($c);
            ItemCartaPagamento::query()->where('carta_pagamento_bancario_id', $c->id)->delete();
            $c->delete();
        });
    }

    /** Gera o pagamento (PAG, PENDENTE) na tesouraria; a integração no diário faz-se na Tesouraria. */
    public function gerarPagamento(CartaPagamentoBancario $c): DocumentoTesouraria
    {
        return DB::transaction(function () use ($c) {
            $c = CartaPagamentoBancario::query()->lockForUpdate()->findOrFail($c->id);
            $this->exigirSemPagamento($c);
            $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($c->periodo_processamento_salarial_id);
            if (! $p->contabilizado) {
                throw new ErroNegocio("O processamento de {$p->mes_ano} ainda não está contabilizado: não há salários a pagar no diário.", 'PERIODO_NAO_CONTABILIZADO', 422);
            }
            $pago = (string) DocumentoTesouraria::query()->where('periodo_processamento_salarial_id', $p->id)->where('tipo', 'PAGAMENTO')->where('estado', '<>', 'ANULADO')->sum('valor_total');
            $liquido = (string) ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('liquido', '>', 0)->sum('liquido');
            if (bccomp(bcadd($pago, (string) $c->montante_total, 2), $liquido, 2) > 0) {
                throw new ErroNegocio("O pagamento excede os salários líquidos de {$p->mes_ano} (líquido {$liquido}; já pago {$pago}).", 'PAGAMENTO_EXCEDE_LIQUIDO', 422);
            }

            [$mes, $ano] = explode('/', $p->mes_ano);
            $sistema = MapeamentoContabilSistemaRH::query()->get();
            $resultados = ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->get()->keyBy('colaborador_id');
            $porConta = [];
            foreach (ItemCartaPagamento::query()->where('carta_pagamento_bancario_id', $c->id)->get() as $i) {
                $r = $resultados[$i->colaborador_id];
                $conta = $this->folha->contaSistema($sistema, 'NET_PAY_CREDIT', $r->tipo_organizacao_id, (bool) $r->avencado)
                    ?? throw new ErroNegocio('Falta o mapeamento NET_PAY_CREDIT (salários a pagar).', 'MAPEAMENTO_EM_FALTA', 422);
                $porConta[$conta] = bcadd($porConta[$conta] ?? '0.00', (string) $i->montante, 2);
            }
            $doc = $this->documentos->gravar(['tipo' => 'PAGAMENTO', 'data_documento' => $c->data->toDateString(), 'conta_financeira' => $c->codigo_conta_bancaria,
                'descricao' => "Pagamento de salários {$p->mes_ano} (carta #{$c->id})", 'referencia' => "SAL{$mes}{$ano}",
                'linhas' => array_map(fn ($conta, $v) => ['codigo_conta' => (string) $conta, 'tipo_dc' => 'D', 'valor' => $v, 'numero_documento' => "SAL{$mes}{$ano}",
                    'descricao' => "Salários a pagar {$p->mes_ano}"], array_keys($porConta), $porConta)]);
            if ($doc->codigo_moeda !== 'AOA') {
                throw new ErroNegocio('Os salários são processados em Kz: a conta bancária da carta tem de ser em Kz.', 'MOEDA_INVALIDA', 422);
            }
            $doc->update(['periodo_processamento_salarial_id' => $p->id]);
            $c->update(['documento_tesouraria_id' => $doc->id]);

            return $doc->refresh();
        });
    }

    private function exigirValidado(PeriodoProcessamentoSalarial $p): void
    {
        if ($p->estado !== 'VALIDADO') {
            throw new ErroNegocio("O processamento de {$p->mes_ano} não está validado.", 'PERIODO_NAO_VALIDADO', 422);
        }
    }

    private function exigirSemPagamento(CartaPagamentoBancario $c): void
    {
        $doc = $c->documento_tesouraria_id ? DocumentoTesouraria::query()->find($c->documento_tesouraria_id) : null;
        if ($doc && $doc->estado !== 'ANULADO') {
            throw new ErroNegocio("A carta já tem o pagamento {$doc->numero_documento} ({$doc->estado}). Anule-o na Tesouraria primeiro.", 'CARTA_COM_PAGAMENTO', 422);
        }
    }
}
