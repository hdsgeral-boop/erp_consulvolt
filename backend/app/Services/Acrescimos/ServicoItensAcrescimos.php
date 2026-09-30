<?php

namespace App\Services\Acrescimos;

use App\Exceptions\ErroNegocio;
use App\Models\ItemAcrescimoDiferimento;
use App\Models\PeriodoLancamentoAcrescimo;
use App\Support\Dados\VerificadorReferencias;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Registos de acréscimos e diferimentos (ad_items; gravarItem, eliminarItem, regularizar, terminar, desfazerPedido e
 * mapaItem, ad_dados.js:123-208 e 351-366). Paridade:
 *   - tipo ACRESCIMO|DIFERIMENTO, natureza CUSTO (conta de gasto 7) | PROVEITO (conta de rendimento 6), conta de balanço
 *     de movimento, valor > 0, período início ≤ fim, repartição DIAS (omissão) ou MESES;
 *   - diferimento exige a data do documento (lançamento inicial nesse mês, salvo `documento_em_balanco`); acréscimo tem
 *     data limite do documento real (indicada ou fim + prazo das definições);
 *   - com lançamentos contabilizados só se alteram as notas e a data limite (resposta `parcial`);
 *   - acréscimo: regularização com o documento real (ou anulação) → A_REGULARIZAR, fechado logo se não houver nada
 *     contabilizado nem por contabilizar; diferimento: término antecipado → A_TERMINAR; «desfazer pedido» volta a ACTIVO.
 * Correcções: os terceiros/dimensões são validados na empresa (controller); eliminar é recusado se o registo já teve
 * lançamentos no Diário (os estornos ficam ligados ao registo — o legado apagava o registo e as linhas); pedidos de
 * regularização/término com lock do registo.
 */
final class ServicoItensAcrescimos
{
    public function __construct(
        private readonly ServicoDefinicoesAcrescimos $definicoes,
        private readonly VerificadorReferencias $referencias,
    ) {}

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?ItemAcrescimoDiferimento $item = null): array
    {
        $tipo = isset(CalculadoraAcrescimos::TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : null;
        $natureza = isset(CalculadoraAcrescimos::NATUREZAS[$d['natureza'] ?? '']) ? $d['natureza'] : null;
        $erros = [];
        if (! $tipo) {
            $erros['tipo'] = 'Escolha o tipo (acréscimo ou diferimento).';
        }
        if (! $natureza) {
            $erros['natureza'] = 'Escolha a natureza (gasto ou rendimento).';
        }
        $descricao = trim((string) ($d['descricao'] ?? ''));
        if (mb_strlen($descricao) < 3) {
            $erros['descricao'] = 'Escreva uma descrição.';
        }
        $valor = CalculadoraAcrescimos::dinheiro($d['valor'] ?? 0);
        if (bccomp($valor, '0', 2) <= 0) {
            $erros['valor'] = 'O valor tem de ser superior a zero.';
        }
        $ini = substr((string) ($d['data_inicio'] ?? ''), 0, 10);
        $fim = substr((string) ($d['data_fim'] ?? ''), 0, 10);
        if ($ini === '' || $fim === '') {
            $erros['data_inicio'] = 'Indique o início e o fim do período a que respeita.';
        } elseif ($fim < $ini) {
            $erros['data_fim'] = 'A data de fim é anterior à de início.';
        }
        if ($natureza && ($e = $this->definicoes->erroConta($d['conta_resultado'] ?? null, $natureza === 'CUSTO' ? 'Conta de gasto' : 'Conta de rendimento',
            $natureza === 'CUSTO' ? ['7'] : ['6']))) {
            $erros['conta_resultado'] = $e;
        }
        if ($e = $this->definicoes->erroConta($d['conta_balanco'] ?? null, 'Conta de balanço (acréscimos/diferimentos)')) {
            $erros['conta_balanco'] = $e;
        }
        $dataDoc = substr((string) ($d['data_documento'] ?? ''), 0, 10) ?: null;
        if ($tipo === 'DIFERIMENTO' && ! $dataDoc) {
            $erros['data_documento'] = 'Diferimento: indique a data do documento pago/recebido (o lançamento inicial é feito nesse mês).';
        }
        if ($erros) {
            throw new ErroNegocio(implode("\n", $erros), 'REGISTO_INVALIDO', 422, $erros);
        }
        $limite = $tipo === 'ACRESCIMO' ? (substr((string) ($d['data_limite'] ?? ''), 0, 10)
            ?: CalculadoraAcrescimos::somarDias($fim, (int) $this->definicoes->obter()['prazo_documento_dias'])) : null;
        $por = Auth::user()?->nome_utilizador;
        $reg = [
            'tipo' => $tipo, 'natureza' => $natureza, 'descricao' => $descricao, 'valor' => $valor,
            'conta_resultado' => trim((string) $d['conta_resultado']), 'conta_balanco' => trim((string) $d['conta_balanco']),
            'data_inicio' => $ini, 'data_fim' => $fim, 'reparticao' => ($d['reparticao'] ?? null) === 'MESES' ? 'MESES' : 'DIAS',
            'data_documento' => $dataDoc, 'data_limite' => $limite, 'documento_em_balanco' => $tipo === 'DIFERIMENTO' && ! empty($d['documento_em_balanco']),
            'terceiro_id' => $d['terceiro_id'] ?? null, 'unidade_negocio_id' => $d['unidade_negocio_id'] ?? null,
            'centro_custo_id' => $d['centro_custo_id'] ?? null, 'projeto_id' => $d['projeto_id'] ?? null,
            'origem' => $d['origem'] ?? null, 'notas' => trim((string) ($d['notas'] ?? '')) ?: null, 'atualizado_por' => $por,
        ];

        return DB::transaction(function () use ($item, $reg, $por) {
            if ($item) {
                $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($item->id);
                if ($this->contabilizados($item)->isNotEmpty()) {
                    // com lançamentos: só as notas e a data limite (o resto mudaria valores já contabilizados)
                    $item->update(['notas' => $reg['notas'], 'data_limite' => $reg['data_limite'], 'atualizado_por' => $por]);

                    return ['item' => $item->refresh(), 'parcial' => true];
                }
                $item->update($reg);

                return ['item' => $item->refresh(), 'parcial' => false];
            }

            return ['item' => ItemAcrescimoDiferimento::create($reg + ['estado' => 'ACTIVO', 'criado_por' => $por]), 'parcial' => false];
        });
    }

    public function eliminar(ItemAcrescimoDiferimento $item): void
    {
        DB::transaction(function () use ($item) {
            $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($item->id);
            if ($this->contabilizados($item)->isNotEmpty()) {
                throw new ErroNegocio('O registo tem lançamentos contabilizados. Descontabilize-os primeiro ou use «Anular» / «Terminar».', 'REGISTO_CONTABILIZADO', 422);
            }
            $this->referencias->exigirLivre('itens_acrescimos_diferimentos', $item->id, 'o registo', ['periodos_lancamento_acrescimos'], [],
                'Os lançamentos descontabilizados (estornos) ficam ligados ao registo: use «Anular» ou «Terminar».');
            PeriodoLancamentoAcrescimo::query()->where('item_acrescimo_diferimento_id', $item->id)->delete();
            $item->delete();
        });
    }

    /**
     * Acréscimo: associa o documento real (ou a anulação). A regularização é lançada na proposta do mês do documento.
     *
     * @param  array{fonte?: ?string, id?: mixed, doc?: ?string, data: string, valor?: mixed, anulacao?: bool, motivo?: ?string}  $doc
     */
    public function regularizar(ItemAcrescimoDiferimento $item, array $doc): ItemAcrescimoDiferimento
    {
        return DB::transaction(function () use ($item, $doc) {
            $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->tipo !== 'ACRESCIMO') {
                throw new ErroNegocio('Só acréscimos se regularizam com documento.', 'TIPO_INVALIDO', 422);
            }
            if (! in_array($item->estado, ['ACTIVO', 'A_REGULARIZAR'], true)) {
                throw new ErroNegocio('O acréscimo está «'.CalculadoraAcrescimos::ESTADOS[$item->estado].'».', 'ESTADO_INVALIDO', 422);
            }
            $data = substr((string) ($doc['data'] ?? ''), 0, 10);
            if ($data === '') {
                throw new ErroNegocio('Indique a data do documento.', 'DATA_OBRIGATORIA', 422);
            }
            $anulacao = ! empty($doc['anulacao']);
            $valor = CalculadoraAcrescimos::dinheiro($doc['valor'] ?? 0);
            if (! $anulacao && bccomp($valor, '0', 2) <= 0) {
                throw new ErroNegocio('Indique o valor (sem IVA) do documento real.', 'VALOR_OBRIGATORIO', 422);
            }
            if (! $anulacao && trim((string) ($doc['doc'] ?? '')) === '') {
                throw new ErroNegocio('Indique o número do documento.', 'DOCUMENTO_OBRIGATORIO', 422);
            }
            $regul = ['fonte' => $doc['fonte'] ?? 'MANUAL', 'id' => $doc['id'] ?? null, 'doc' => trim((string) ($doc['doc'] ?? '')), 'data' => $data,
                'valor' => $anulacao ? '0.00' : $valor, 'anulacao' => $anulacao, 'motivo' => trim((string) ($doc['motivo'] ?? '')),
                'por' => Auth::user()?->nome_utilizador, 'em' => now()->toIso8601String()];
            if (mb_strlen(json_encode($regul, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 255) {
                throw new ErroNegocio('O número do documento e o motivo são demasiado longos: abrevie-os.', 'REGULARIZACAO_LONGA', 422);
            }
            $item->update(['estado' => 'A_REGULARIZAR', 'regularizacao' => $regul]);
            // sem nada contabilizado nem por contabilizar antes do documento: fecha logo
            $feitos = $this->feitos($item);
            $pend = CalculadoraAcrescimos::pendentes($this->paraCalculo($item), CalculadoraAcrescimos::mes($data), $feitos);
            if ($feitos === [] && ! collect($pend)->contains(fn ($l) => bccomp($l['valor'], '0', 2) > 0)) {
                $item->update(['estado' => $anulacao ? 'ANULADO' : 'REGULARIZADO']);
            }

            return $item->refresh();
        });
    }

    /** Diferimento: reconhece já o saldo por reconhecer (ex.: contrato cancelado), na proposta do mês do término. */
    public function terminar(ItemAcrescimoDiferimento $item, string $data, ?string $motivo): ItemAcrescimoDiferimento
    {
        return DB::transaction(function () use ($item, $data, $motivo) {
            $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->tipo !== 'DIFERIMENTO') {
                throw new ErroNegocio('Só diferimentos terminam antecipadamente (acréscimos anulam-se ou regularizam-se).', 'TIPO_INVALIDO', 422);
            }
            if (! in_array($item->estado, ['ACTIVO', 'A_TERMINAR'], true)) {
                throw new ErroNegocio('O diferimento está «'.CalculadoraAcrescimos::ESTADOS[$item->estado].'».', 'ESTADO_INVALIDO', 422);
            }
            $data = substr($data, 0, 10);
            if ($data < $item->data_inicio->toDateString()) {
                throw new ErroNegocio('A data de término é anterior ao início do período.', 'DATA_INVALIDA', 422);
            }
            $item->update(['estado' => 'A_TERMINAR', 'termino' => ['data' => $data, 'motivo' => trim((string) $motivo), 'por' => Auth::user()?->nome_utilizador,
                'em' => now()->toIso8601String()]]);

            return $item->refresh();
        });
    }

    public function desfazerPedido(ItemAcrescimoDiferimento $item): ItemAcrescimoDiferimento
    {
        return DB::transaction(function () use ($item) {
            $item = ItemAcrescimoDiferimento::query()->lockForUpdate()->findOrFail($item->id);
            if ($item->estado === 'A_REGULARIZAR') {
                $item->update(['estado' => 'ACTIVO', 'regularizacao' => null]);
            } elseif ($item->estado === 'A_TERMINAR') {
                $item->update(['estado' => 'ACTIVO', 'termino' => null]);
            } else {
                throw new ErroNegocio('O registo não tem regularização nem término por contabilizar.', 'SEM_PEDIDO', 422);
            }

            return $item->refresh();
        });
    }

    /** Mapa de reconhecimento: quotas com o lançamento de cada uma, lançamentos, reconhecido e saldo na conta 37. */
    public function mapa(ItemAcrescimoDiferimento $item): array
    {
        $it = $this->paraCalculo($item);
        $todos = PeriodoLancamentoAcrescimo::query()->where('item_acrescimo_diferimento_id', $item->id)->orderBy('data_documento')->orderBy('id')->get();
        $feitos = $todos->where('estado', 'CONTABILIZADO');
        $reconhecido = $feitos->whereIn('tipo', ['RECONHECIMENTO', 'TERMINO'])->reduce(fn ($s, $p) => bcadd($s, (string) $p->valor, 2), '0.00');

        return [
            'item' => $item->toArray() + ['estado_rotulo' => CalculadoraAcrescimos::ESTADOS[$item->estado] ?? $item->estado],
            'quotas' => array_map(fn ($q) => $q + ['lancamento' => $feitos->first(fn ($p) => $p->tipo === 'RECONHECIMENTO' && $p->periodo === $q['periodo'])],
                CalculadoraAcrescimos::quotas($it['valor'], $it['data_inicio'], $it['data_fim'], $it['reparticao'])),
            'lancamentos' => $todos->values(),
            'reconhecido' => $reconhecido,
            'saldo_balanco' => CalculadoraAcrescimos::saldoBalanco($it, $this->feitos($item)),
        ];
    }

    /** @return Collection<int, PeriodoLancamentoAcrescimo> */
    public function contabilizados(ItemAcrescimoDiferimento $item)
    {
        return PeriodoLancamentoAcrescimo::query()->where('item_acrescimo_diferimento_id', $item->id)->where('estado', 'CONTABILIZADO')->orderBy('id')->get();
    }

    /** @return list<array{tipo: string, periodo: string, valor: string}> */
    public function feitos(ItemAcrescimoDiferimento $item): array
    {
        return $this->contabilizados($item)->map(fn ($p) => ['id' => $p->id, 'tipo' => $p->tipo, 'periodo' => $p->periodo, 'valor' => (string) $p->valor])->all();
    }

    /** Registo na forma lida pela CalculadoraAcrescimos. */
    public function paraCalculo(ItemAcrescimoDiferimento $item): array
    {
        return [
            'id' => $item->id, 'tipo' => $item->tipo, 'natureza' => $item->natureza, 'estado' => $item->estado, 'valor' => (string) $item->valor,
            'data_inicio' => $item->data_inicio?->toDateString(), 'data_fim' => $item->data_fim?->toDateString(), 'reparticao' => $item->reparticao,
            'data_documento' => $item->data_documento?->toDateString(), 'documento_em_balanco' => (bool) $item->documento_em_balanco,
            'regularizacao' => $item->regularizacao, 'termino' => $item->termino,
            'conta_resultado' => $item->conta_resultado, 'conta_balanco' => $item->conta_balanco,
        ];
    }
}
