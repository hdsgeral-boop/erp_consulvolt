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
use App\Models\PlanoConta;
use App\Models\ResultadoFolhaSalarial;
use App\Services\Tesouraria\ServicoCartasPagamento;
use App\Services\Tesouraria\ServicoContasBancarias;
use App\Services\Tesouraria\ServicoDocumentosTesouraria;
use Illuminate\Support\Facades\DB;

/**
 * Pagamento dos salários.
 *   - Ordem de pagamento bancária (relatório rh_banco, js/app_v2.js:7014-7465): períodos VALIDADOS, uma linha por
 *     colaborador do grupo (Colaboradores / Avençados) com banco, IBAN e líquido — agora a partir da FOTOGRAFIA.
 *   - Pagamento (gerarPagamentoSalarios, js/modules/rh/folha_salarios.js:323-344): documento de pagamento da
 *     tesouraria (PAG, PENDENTE, ligado ao período) que debita os salários a pagar (NET_PAY_CREDIT de cada
 *     colaborador) com o n.º SAL+MMAAAA e credita o banco ao integrar. Exige o período contabilizado,
 *     e o total pago no período não pode exceder o líquido (no legado nada o impedia).
 *   - Cartas de pagamento (Fase D, ADR-071): a carta NASCE DO PAGAMENTO GRAVADO e não gera lançamentos
 *     (ServicoCartasPagamento). «Emitir carta» no RH grava primeiro o pagamento do período (conta 42/43 com IBAN) com os
 *     salários líquidos ainda sem carta e emite a carta a partir dele, na mesma transacção; um colaborador só entra numa
 *     carta activa por período; sem IBAN a carta é recusada (o legado imprimia «N/D»). As cartas antigas sem pagamento
 *     (regra anterior: carta → pagamento) ainda podem gerar o pagamento em «Gerar pagamento».
 */
final class ServicoPagamentoSalarios
{
    public const GRUPOS = ['COLABORADORES', 'AVENCADOS', 'TODOS'];

    public function __construct(
        private readonly ServicoFolhaSalarial $folha,
        private readonly ServicoDocumentosTesouraria $documentos,
        private readonly ServicoContasBancarias $contasBancarias,
        private readonly ServicoCartasPagamento $cartas,
    ) {}

    /** @return array{linhas: list<array<string, mixed>>, total: string, sem_iban: int} */
    public function ordemPagamento(PeriodoProcessamentoSalarial $p, string $grupo = 'TODOS'): array
    {
        $this->exigirValidado($p);
        $nomes = Colaborador::query()->withTrashed()->pluck('nome_completo', 'id');
        $coordenadas = CoordenadaBancariaColaborador::query()->with('banco')->get()->keyBy('colaborador_id');
        $emCarta = ItemCartaPagamento::query()->join('cartas_pagamento_bancario as c', 'c.id', '=', 'itens_carta_pagamento.carta_pagamento_bancario_id')
            ->where('c.periodo_processamento_salarial_id', $p->id)->where(fn ($q) => $q->whereNull('c.estado')->orWhere('c.estado', '<>', CartaPagamentoBancario::ANULADA))
            ->pluck('c.id', 'itens_carta_pagamento.colaborador_id');
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
     * Grava o pagamento do período (PAG, PENDENTE) com os salários líquidos ainda sem carta e emite a carta a partir dele
     * (Fase D: a carta nasce do pagamento gravado; não gera lançamentos).
     *
     * @param  array{codigo_conta_bancaria: string, data: string, nome_assinatura?: ?string, grupo?: ?string, colaboradores?: ?list<int>}  $d
     */
    public function emitirCarta(PeriodoProcessamentoSalarial $p, array $d): CartaPagamentoBancario
    {
        $this->exigirValidado($p);
        $conta = $this->contasBancarias->dados($d['codigo_conta_bancaria']);   // 42/43 de movimento (CONTA_NAO_BANCARIA)
        if (! $conta['completa']) {
            throw new ErroNegocio("A conta {$conta['codigo']} ainda não tem IBAN: preencha os dados bancários no Plano de Contas.", 'CONTA_SEM_IBAN', 422);
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
            $data = substr($d['data'], 0, 10);
            $doc = $this->criarPagamento($p, $d['codigo_conta_bancaria'], $data, $linhas->pluck('liquido', 'colaborador_id')->all(), "Pagamento de salários {$p->mes_ano}");
            $carta = $this->cartas->emitir($doc, ['beneficiarios' => $linhas->map(fn ($l) => ['colaborador_id' => $l['colaborador_id']])->all(),
                'data' => $data, 'nome_assinatura' => $d['nome_assinatura'] ?? null]);
            $carta->update(['grupo' => $grupo]);

            return $carta->refresh();
        });
    }

    /**
     * Folha de pagamento por caixa (numerário): para os colaboradores SEM coordenadas bancárias, grava o pagamento do
     * período (PAG, PENDENTE, conta de caixa — classe 45) e devolve a lista para imprimir (nome, líquido e linha de
     * assinatura) — ao contrário da carta bancária, não há IBAN nem beneficiário da `CartaPagamentoBancario`.
     *
     * @param  array{codigo_conta_caixa: string, data: string, grupo?: ?string, colaboradores?: ?list<int>}  $d
     * @return array{documento_tesouraria: DocumentoTesouraria, linhas: list<array<string, mixed>>}
     */
    public function emitirFolhaCaixa(PeriodoProcessamentoSalarial $p, array $d): array
    {
        $this->exigirValidado($p);
        $this->contaCaixa($d['codigo_conta_caixa']);
        $grupo = $d['grupo'] ?? 'TODOS';

        return DB::transaction(function () use ($p, $d, $grupo) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["cartas_salarios:{$p->id}"]);
            $linhas = collect($this->ordemPagamento($p, $grupo)['linhas'])
                ->when(! empty($d['colaboradores']), fn ($c) => $c->whereIn('colaborador_id', $d['colaboradores']))
                ->filter(fn ($l) => $l['iban'] === null)->values();
            if (! empty($d['colaboradores']) && $linhas->count() !== count(array_unique($d['colaboradores']))) {
                throw new ErroNegocio('Há colaboradores sem salário líquido neste período, fora do grupo, ou com coordenadas bancárias (esses recebem por carta bancária).', 'COLABORADOR_INVALIDO', 422);
            }
            if ($linhas->isEmpty()) {
                throw new ErroNegocio('Não há colaboradores sem coordenadas bancárias com salário líquido neste período (e grupo).', 'SEM_SALARIOS_A_PAGAR', 422);
            }
            $data = substr($d['data'], 0, 10);
            $doc = $this->criarPagamento($p, $d['codigo_conta_caixa'], $data, $linhas->pluck('liquido', 'colaborador_id')->all(), "Pagamento de salários {$p->mes_ano} (folha de caixa)");

            return ['documento_tesouraria' => $doc, 'linhas' => $linhas->map(fn ($l) => ['colaborador_id' => $l['colaborador_id'], 'nome' => $l['nome'], 'liquido' => $l['liquido']])->all()];
        });
    }

    /** Conta de caixa (classe 45) de movimento — numerário, sem IBAN (ao contrário das 42/43 das cartas bancárias). */
    private function contaCaixa(string $codigo): PlanoConta
    {
        $conta = PlanoConta::query()->where('codigo', $codigo)->first()
            ?? throw new ErroNegocio("A conta {$codigo} não existe no plano de contas.", 'CONTA_INEXISTENTE', 404, ['codigo_conta' => $codigo]);
        if (! $conta->eMovimento() || ! str_starts_with($conta->codigo, '45')) {
            throw new ErroNegocio("A conta {$codigo} não é uma conta de caixa de movimento (classe 45).", 'CONTA_NAO_CAIXA', 422, ['codigo_conta' => $codigo]);
        }

        return $conta;
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

    /** Cartas antigas (sem pagamento): gera o pagamento (PAG, PENDENTE) na tesouraria; a integração faz-se na Tesouraria. */
    public function gerarPagamento(CartaPagamentoBancario $c): DocumentoTesouraria
    {
        return DB::transaction(function () use ($c) {
            $c = CartaPagamentoBancario::query()->lockForUpdate()->findOrFail($c->id);
            $this->exigirSemPagamento($c);
            $p = PeriodoProcessamentoSalarial::query()->findOrFail($c->periodo_processamento_salarial_id);
            $montantes = ItemCartaPagamento::query()->where('carta_pagamento_bancario_id', $c->id)->pluck('montante', 'colaborador_id')->all();
            $doc = $this->criarPagamento($p, (string) $c->codigo_conta_bancaria, $c->data->toDateString(), $montantes, "Pagamento de salários {$p->mes_ano} (carta #{$c->id})");
            $c->update(['documento_tesouraria_id' => $doc->id]);

            return $doc->refresh();
        });
    }

    /**
     * Documento de pagamento (PAG, PENDENTE, ligado ao período): D salários a pagar (NET_PAY_CREDIT) por colaborador.
     *
     * @param  array<int, string>  $montantes  colaborador_id → líquido a pagar
     */
    private function criarPagamento(PeriodoProcessamentoSalarial $p, string $conta, string $data, array $montantes, string $descricao): DocumentoTesouraria
    {
        $p = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($p->id);
        if (! $p->contabilizado) {
            throw new ErroNegocio("O processamento de {$p->mes_ano} ainda não está contabilizado: não há salários a pagar no diário.", 'PERIODO_NAO_CONTABILIZADO', 422);
        }
        $total = array_reduce($montantes, fn ($s, $v) => bcadd($s, (string) $v, 2), '0.00');
        $pago = (string) DocumentoTesouraria::query()->where('periodo_processamento_salarial_id', $p->id)->where('tipo', 'PAGAMENTO')->where('estado', '<>', 'ANULADO')->sum('valor_total');
        $liquido = (string) ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->where('liquido', '>', 0)->sum('liquido');
        if (bccomp(bcadd($pago, $total, 2), $liquido, 2) > 0) {
            throw new ErroNegocio("O pagamento excede os salários líquidos de {$p->mes_ano} (líquido {$liquido}; já pago {$pago}).", 'PAGAMENTO_EXCEDE_LIQUIDO', 422);
        }

        [$mes, $ano] = explode('/', $p->mes_ano);
        $sistema = MapeamentoContabilSistemaRH::query()->get();
        $resultados = ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $p->id)->get()->keyBy('colaborador_id');
        $porConta = [];
        foreach ($montantes as $colaborador => $valor) {
            $r = $resultados[$colaborador];
            $contaSalarios = $this->folha->contaSistema($sistema, 'NET_PAY_CREDIT', $r->tipo_organizacao_id, (bool) $r->avencado)
                ?? throw new ErroNegocio('Falta o mapeamento NET_PAY_CREDIT (salários a pagar).', 'MAPEAMENTO_EM_FALTA', 422);
            $porConta[$contaSalarios] = bcadd($porConta[$contaSalarios] ?? '0.00', (string) $valor, 2);
        }
        $doc = $this->documentos->gravar(['tipo' => 'PAGAMENTO', 'data_documento' => $data, 'conta_financeira' => $conta,
            'descricao' => $descricao, 'referencia' => "SAL{$mes}{$ano}",
            'linhas' => array_map(fn ($c, $v) => ['codigo_conta' => (string) $c, 'tipo_dc' => 'D', 'valor' => $v, 'numero_documento' => "SAL{$mes}{$ano}",
                'descricao' => "Salários a pagar {$p->mes_ano}"], array_keys($porConta), $porConta)]);
        if ($doc->codigo_moeda !== 'AOA') {
            throw new ErroNegocio('Os salários são processados em Kz: a conta bancária da carta tem de ser em Kz.', 'MOEDA_INVALIDA', 422);
        }
        $doc->update(['periodo_processamento_salarial_id' => $p->id]);

        return $doc->refresh();
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
