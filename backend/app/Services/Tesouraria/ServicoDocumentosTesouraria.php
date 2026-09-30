<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\DiarioContabil;
use App\Models\DocumentoTesouraria;
use App\Models\ItemDocumentoTesouraria;
use App\Models\Terceiro;
use App\Services\Contabilidade\LocalizadorLancamentos;
use App\Services\Contabilidade\ServicoExercicios;
use App\Services\Contabilidade\ServicoLancamentos;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Services\Sistema\ServicoCambios;
use App\Services\Vendas\CalculadoraDocumento;
use App\Services\Vendas\ServicoSeries;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Pagamentos e recebimentos (saveTesouraria / integrateSelectedtreasury / unposttreasuryDocument, js/ui_tesouraria.js),
 * com as correcções:
 *   - n.º por série (PAG/REC); a referência continua texto livre (no legado era a única "chave", repetida em 670 docs);
 *   - validação completa na gravação: conta financeira de movimento das classes 43/45, sentido (pagamento = débitos
 *     líquidos; recebimento = créditos), exercício aberto, contas das linhas de movimento, e — para linhas que liquidam
 *     um documento — valor ≤ saldo em aberto (o legado permitia pagar duas vezes);
 *   - ligação EXPLÍCITA à venda / factura de fornecedor liquidada: na integração actualiza o pago e o estado da venda
 *     e o estado da factura de fornecedor (no legado nada era actualizado; o "pago" contava até documentos por integrar);
 *   - integração numa transacção, via ServicoLancamentos (diário BD para bancos, CX para caixa);
 *   - desintegrar = ESTORNO, bloqueado se o lançamento estiver reconciliado com o banco;
 *   - nunca se apaga: anula-se, com motivo.
 * Multi-moeda (moedas_tesouraria.js, ADR-034): a moeda do documento é a da conta financeira; o câmbio é o da tabela
 * na data (ou manual). Liquidar um documento em moeda estrangeira (a partir de conta na moeda ou em Kz) lança o
 * terceiro pelo VALOR HISTÓRICO em Kz — proporção do saldo em moeda — e a diferença para o valor ao câmbio do dia
 * vai a diferenças de câmbio (contas da configuração; no legado 6621/7621 fixas e invertidas).
 */
final class ServicoDocumentosTesouraria
{
    public const TIPOS = ['PAGAMENTO', 'RECEBIMENTO'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoSeries $series,
        private readonly ServicoExercicios $exercicios,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoLancamentos $lancamentos,
        private readonly LocalizadorLancamentos $localizador,
        private readonly ServicoPendentes $pendentes,
        private readonly ServicoLiquidacoes $liquidacoes,
        private readonly ServicoCambios $cambios,
        private readonly ServicoConfigTesouraria $config,
    ) {}

    /** @param  array<string, mixed>  $d  tipo, data_documento, conta_financeira, descricao, referencia?, taxa_cambio?, linhas[] */
    public function gravar(array $d, ?DocumentoTesouraria $doc = null): DocumentoTesouraria
    {
        $empresa = $this->contexto->obrigatorio();
        if ($doc && $doc->estado !== 'PENDENTE') {
            throw new ErroNegocio("Um documento {$doc->estado} não pode ser alterado.", 'DOCUMENTO_NAO_EDITAVEL', 422);
        }
        $tipo = $d['tipo'];
        $data = substr($d['data_documento'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);
        $moeda = $this->moedaDocumento($d['conta_financeira'], $data, isset($d['taxa_cambio']) ? (string) $d['taxa_cambio'] : null);
        [$linhas, $total, $totalMoeda] = $this->validarLinhas($tipo, $d['linhas'], $doc?->id, $data, $moeda);

        return DB::transaction(function () use ($d, $doc, $tipo, $data, $linhas, $total, $totalMoeda, $moeda, $empresa) {
            $dados = ['tipo' => $tipo, 'data_documento' => $data, 'conta_financeira' => $d['conta_financeira'], 'descricao' => $d['descricao'],
                'referencia' => $d['referencia'] ?? null, 'valor_total' => $total, 'estado' => 'PENDENTE', 'projeto_id' => $d['projeto_id'] ?? null,
                'codigo_moeda' => $moeda['codigo'], 'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id'],
                'taxa_cambio_manual' => $moeda['manual'], 'valor_total_moeda' => $moeda['estrangeira'] ? $totalMoeda : null];
            if ($doc) {
                $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
                if ($doc->estado !== 'PENDENTE') {
                    throw new ErroNegocio("Um documento {$doc->estado} não pode ser alterado.", 'DOCUMENTO_NAO_EDITAVEL', 422);
                }
                if ($doc->tipo !== $tipo) {
                    throw new ErroNegocio('O tipo do documento não se altera.', 'TIPO_NAO_EDITAVEL', 422);
                }
                $doc->update($dados);
                ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->delete();
            } else {
                $reserva = $this->series->reservar($empresa, $tipo === 'PAGAMENTO' ? 'PAG' : 'REC', $data, false);
                $doc = DocumentoTesouraria::create($dados + ['numero_documento' => $reserva['numero_documento'], 'importado' => false]);
            }
            foreach ($linhas as $l) {
                ItemDocumentoTesouraria::create($l + ['documento_tesouraria_id' => $doc->id]);
            }

            return $doc->refresh();
        });
    }

    public function anular(DocumentoTesouraria $doc, string $motivo): DocumentoTesouraria
    {
        return DB::transaction(function () use ($doc, $motivo) {
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->estado !== 'PENDENTE') {
                throw new ErroNegocio($doc->estado === 'INTEGRADO' ? 'O documento está integrado: desintegre-o primeiro (estorno).' : 'O documento já está anulado.',
                    'DOCUMENTO_NAO_ANULAVEL', 422);
            }
            $doc->update(['estado' => 'ANULADO', 'anulado_em' => now(), 'motivo_anulacao' => $motivo]);

            return $doc;
        });
    }

    public function integrar(DocumentoTesouraria $doc): DocumentoTesouraria
    {
        return DB::transaction(function () use ($doc) {
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->estado !== 'PENDENTE') {
                throw new ErroNegocio("O documento está {$doc->estado}.", 'DOCUMENTO_NAO_INTEGRAVEL', 422);
            }
            $data = $doc->data_documento->toDateString();
            $moeda = $this->moedaDocumento($doc->conta_financeira, $data, $doc->taxa_cambio_manual ? (string) $doc->taxa_cambio : null);
            // revalida e recalcula (o saldo pode ter mudado desde a gravação: outro documento integrado entretanto)
            $entrada = ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->orderBy('id')->get()
                ->map(fn ($i) => $i->only(['codigo_conta', 'tipo_dc', 'terceiro_id', 'numero_documento', 'descricao', 'venda_id', 'fatura_compra_id',
                    'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'data_documento_original', 'nota_demonstracao_id', 'nota_fluxo_caixa_id'])
                    + ['valor' => $i->valor_introduzido ?? $i->valor])->all();
            [$itens, $total, $totalMoeda] = $this->validarLinhas($doc->tipo, $entrada, $doc->id, $data, $moeda);
            ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->delete();
            foreach ($itens as $l) {
                ItemDocumentoTesouraria::create($l + ['documento_tesouraria_id' => $doc->id]);
            }

            $numero = $doc->numero_documento ?: ($doc->referencia ?: "TES-{$doc->id}");
            $linhas = [[
                'codigo_conta' => $doc->conta_financeira, 'tipo_dc' => $doc->tipo === 'PAGAMENTO' ? 'C' : 'D', 'valor' => $total,
                'descricao' => mb_substr((string) $doc->descricao, 0, 1000), 'projeto_id' => $doc->projeto_id,
            ] + ($moeda['estrangeira'] ? ['codigo_moeda' => $moeda['codigo'], 'valor_moeda' => $totalMoeda, 'taxa_cambio' => $moeda['taxa']] : [])];
            foreach ($itens as $i) {
                $linhas[] = ['codigo_conta' => $i['codigo_conta'], 'tipo_dc' => $i['tipo_dc'], 'valor' => $i['valor'], 'terceiro_id' => $i['terceiro_id'] ?? null,
                    'descricao' => mb_substr((string) (($i['descricao'] ?? null) ?: $doc->descricao), 0, 1000), 'unidade_negocio_id' => $i['unidade_negocio_id'] ?? null,
                    'centro_custo_id' => $i['centro_custo_id'] ?? null, 'projeto_id' => ($i['projeto_id'] ?? null) ?? $doc->projeto_id,
                    'nota_demonstracao_id' => $i['nota_demonstracao_id'] ?? null, 'nota_fluxo_caixa_id' => $i['nota_fluxo_caixa_id'] ?? null,
                    // a contrapartida fica com o n.º do documento que liquida (é a chave dos pendentes)
                    'numero_documento' => ($i['numero_documento'] ?? null) ?: null,
                    'codigo_moeda' => $i['codigo_moeda'] ?? null, 'valor_moeda' => $i['valor_moeda'] ?? null, 'taxa_cambio' => $i['taxa_cambio'] ?? null];
                // diferença de câmbio: valor ao câmbio do documento − valor histórico do terceiro
                $diferenca = bcsub((string) ($i['valor_kz_documento'] ?? $i['valor']), (string) $i['valor'], 2);
                if (bccomp($diferenca, '0', 2) !== 0) {
                    $lado = bccomp($diferenca, '0', 2) > 0 ? $i['tipo_dc'] : ($i['tipo_dc'] === 'D' ? 'C' : 'D');
                    $conta = $lado === 'C' ? $this->config->exigir('diferencas_cambio_favoraveis', 'Há uma diferença de câmbio favorável a lançar.')
                        : $this->config->exigir('diferencas_cambio_desfavoraveis', 'Há uma diferença de câmbio desfavorável a lançar.');
                    $linhas[] = ['codigo_conta' => $conta, 'tipo_dc' => $lado, 'valor' => ltrim($diferenca, '-'), 'terceiro_id' => $i['terceiro_id'] ?? null,
                        'descricao' => mb_substr('Diferença de câmbio — '.($i['numero_documento'] ?? ''), 0, 1000)];
                }
            }
            $criadas = $this->lancamentos->criar([
                'diario_id' => $this->diario($doc->conta_financeira)->id, 'data_documento' => $data,
                'numero_documento' => $numero, 'referencia' => $doc->referencia, 'descricao' => mb_substr((string) $doc->descricao, 0, 1000),
                'tipo_origem' => 'TESOURARIA', 'linhas' => $linhas,
            ]);
            $doc->update(['estado' => 'INTEGRADO', 'numero_lan_contabilizacao' => $criadas->first()->numero_lan, 'integrado_em' => now(),
                'integrado_por' => Auth::user()?->nome_utilizador, 'valor_total' => $total, 'valor_total_moeda' => $moeda['estrangeira'] ? $totalMoeda : null,
                'taxa_cambio' => $moeda['estrangeira'] ? $moeda['taxa'] : null, 'taxa_cambio_id' => $moeda['taxa_id']]);
            $this->actualizarLigados(ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->get(), +1);

            return $doc;
        });
    }

    public function desintegrar(DocumentoTesouraria $doc, string $motivo): DocumentoTesouraria
    {
        return DB::transaction(function () use ($doc, $motivo) {
            $doc = DocumentoTesouraria::query()->lockForUpdate()->findOrFail($doc->id);
            if ($doc->estado !== 'INTEGRADO') {
                throw new ErroNegocio('O documento não está integrado.', 'DOCUMENTO_NAO_INTEGRADO', 422);
            }
            // legado: sem n.º de lançamento guardado; a linha do banco/caixa tem doc_number = referência ou TES-<id>
            $numero = $doc->numero_documento ?: ($doc->referencia ?: "TES-{$doc->id}");
            $linha = $this->localizador->localizar($doc->numero_lan_contabilizacao, $numero, $doc->conta_financeira, $doc->tipo === 'PAGAMENTO' ? 'C' : 'D');
            $this->lancamentos->estornar($linha, $motivo);   // recusa se reconciliado com o banco ou ligado a activos
            $doc->update(['estado' => 'PENDENTE', 'numero_lan_contabilizacao' => null, 'integrado_em' => null, 'integrado_por' => null]);
            $this->actualizarLigados(ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->get(), -1);

            return $doc;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $linhas  valores na MOEDA DO DOCUMENTO
     * @param  array{codigo: string, taxa: string, taxa_id: ?int, manual: bool, estrangeira: bool}  $moeda
     * @return array{0: list<array<string, mixed>>, 1: string, 2: string} linhas normalizadas, total em Kz, total na moeda do documento
     */
    private function validarLinhas(string $tipo, array $linhas, ?int $docId, string $data, array $moeda): array
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de documento inválido.', 'TIPO_INVALIDO', 422);
        }
        if (! $linhas) {
            throw new ErroNegocio('O documento tem de ter pelo menos uma linha.', 'SEM_LINHAS', 422);
        }
        $d = $c = $dm = $cm = '0.00';
        $saida = [];
        $porDocumento = [];
        foreach (array_values($linhas) as $n => $l) {
            $onde = 'Linha '.($n + 1).':';
            $valor = number_format((float) $l['valor'], 2, '.', '');
            if (bccomp($valor, '0', 2) <= 0 || ! in_array($l['tipo_dc'], ['D', 'C'], true)) {
                throw new ErroNegocio("{$onde} valor e sentido (D/C) inválidos.", 'LINHA_INVALIDA', 422);
            }
            try {
                $this->plano->contaDeMovimento((string) $l['codigo_conta']);
            } catch (ErroNegocio $e) {
                throw new ErroNegocio("{$onde} {$e->getMessage()}", $e->codigo, 422);
            }
            $kzDoc = $moeda['estrangeira'] ? CalculadoraDocumento::arredondar(bcmul($valor, $moeda['taxa'], 8)) : $valor;
            $l['tipo_dc'] === 'D' ? $d = bcadd($d, $kzDoc, 2) : $c = bcadd($c, $kzDoc, 2);
            $l['tipo_dc'] === 'D' ? $dm = bcadd($dm, $valor, 2) : $cm = bcadd($cm, $valor, 2);
            $l = array_intersect_key($l, array_flip(['codigo_conta', 'tipo_dc', 'terceiro_id', 'numero_documento', 'descricao', 'venda_id', 'fatura_compra_id',
                'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'data_documento_original', 'nota_demonstracao_id', 'nota_fluxo_caixa_id']))
                + ['valor' => $kzDoc, 'valor_introduzido' => $valor, 'valor_kz_documento' => $kzDoc, 'codigo_moeda' => null, 'valor_moeda' => null, 'taxa_cambio' => null,
                    'cambial_moeda_documento' => null, 'cambial_saldo_moeda' => null, 'cambial_saldo_kz' => null];
            if ($moeda['estrangeira']) {
                $l = ['codigo_moeda' => $moeda['codigo'], 'valor_moeda' => $valor, 'taxa_cambio' => $moeda['taxa']] + $l;
            }
            $this->validarLigacao($l, $onde);

            $aberto = ! empty($l['numero_documento']) && ! empty($l['terceiro_id'])
                ? $this->pendentes->saldo((int) $l['terceiro_id'], (string) $l['codigo_conta'], (string) $l['numero_documento'], $docId) : null;
            if ($aberto && $aberto['codigo_moeda'] !== 'AOA' && bccomp((string) $aberto['saldo_moeda'], '0', 2) > 0) {
                $l = $this->liquidacaoEmMoeda($l, $aberto, $moeda, $kzDoc, $valor, $data, $onde);
            } elseif (! empty($l['numero_documento']) && ! empty($l['terceiro_id'])) {
                $k = "{$l['terceiro_id']}|{$l['codigo_conta']}|{$l['numero_documento']}";
                $porDocumento[$k] = ($porDocumento[$k] ?? []) + ['aberto' => $aberto, 'numero' => (string) $l['numero_documento'], 'D' => '0.00', 'C' => '0.00', 'onde' => $onde];
                $porDocumento[$k][$l['tipo_dc']] = bcadd($porDocumento[$k][$l['tipo_dc']], $kzDoc, 2);
            }
            $saida[] = $l;
        }
        $liquido = $tipo === 'PAGAMENTO' ? bcsub($d, $c, 2) : bcsub($c, $d, 2);
        $liquidoMoeda = $tipo === 'PAGAMENTO' ? bcsub($dm, $cm, 2) : bcsub($cm, $dm, 2);
        if (bccomp($liquido, '0', 2) <= 0) {
            throw new ErroNegocio($tipo === 'PAGAMENTO' ? 'Num pagamento os débitos das linhas têm de exceder os créditos.'
                : 'Num recebimento os créditos das linhas têm de exceder os débitos.', 'SENTIDO_INVALIDO', 422, ['debito' => $d, 'credito' => $c]);
        }
        // documentos em Kz: não liquidar mais do que o saldo em aberto
        foreach ($porDocumento as $p) {
            $aberto = $p['aberto'];
            if (! $aberto) {
                continue;   // sem documento em aberto com esse n.º: é um adiantamento ou movimento avulso
            }
            $aLiquidar = $aberto['liquidar_a'] === 'C' ? bcsub($p['C'], $p['D'], 2) : bcsub($p['D'], $p['C'], 2);
            if (bccomp($aLiquidar, bcadd($aberto['saldo'], '0.01', 2), 2) > 0) {
                throw new ErroNegocio("{$p['onde']} liquida {$aLiquidar} do documento {$p['numero']}, mas o saldo em aberto é {$aberto['saldo']}.",
                    'VALOR_SUPERIOR_EM_ABERTO', 422, ['numero_documento' => $p['numero'], 'saldo' => $aberto['saldo']]);
            }
        }

        return [$saida, $liquido, $liquidoMoeda];
    }

    /**
     * Linha que liquida um documento em moeda estrangeira: quantidade em moeda liquidada, valor histórico em Kz
     * (proporção do saldo em Kz face ao saldo em moeda) e a diferença para o valor ao câmbio do documento.
     */
    private function liquidacaoEmMoeda(array $l, array $aberto, array $moeda, string $kzDoc, string $valor, string $data, string $onde): array
    {
        $moedaItem = $aberto['codigo_moeda'];
        if ($moeda['estrangeira'] && $moeda['codigo'] !== $moedaItem) {
            throw new ErroNegocio("{$onde} o documento {$l['numero_documento']} é em {$moedaItem} e a conta em {$moeda['codigo']}: use uma conta em {$moedaItem} ou em Kz.",
                'MOEDAS_DIFERENTES', 422);
        }
        if ($moeda['estrangeira']) {
            [$moedaLiq, $taxa] = [$valor, $moeda['taxa']];
        } else {
            $c = $this->cambios->obter($this->contexto->obrigatorio(), $moedaItem, $data)
                ?? throw new ErroNegocio("{$onde} não há câmbio de {$moedaItem} até {$data} para converter o pagamento em Kz.", 'CAMBIO_EM_FALTA', 422);
            $taxa = $c['taxa'];
            $moedaLiq = CalculadoraDocumento::arredondar(bcdiv($kzDoc, $taxa, 8));
        }
        $saldoMoeda = (string) $aberto['saldo_moeda'];
        if (bccomp($moedaLiq, bcadd($saldoMoeda, '0.01', 2), 2) > 0) {
            throw new ErroNegocio("{$onde} liquida {$moedaLiq} {$moedaItem} do documento {$l['numero_documento']}, mas o saldo em aberto é {$saldoMoeda} {$moedaItem}.",
                'VALOR_SUPERIOR_EM_ABERTO', 422, ['saldo_moeda' => $saldoMoeda]);
        }
        $total = bccomp(ltrim(bcsub($moedaLiq, $saldoMoeda, 2), '-'), '0.005', 3) <= 0;
        $historico = $total ? (string) $aberto['saldo'] : CalculadoraDocumento::arredondar(bcdiv(bcmul($moedaLiq, (string) $aberto['saldo'], 8), $saldoMoeda, 8));

        return array_merge($l, ['valor' => $historico, 'valor_kz_documento' => $kzDoc, 'codigo_moeda' => $moedaItem, 'valor_moeda' => $moedaLiq, 'taxa_cambio' => $taxa,
            'cambial_moeda_documento' => $moedaItem, 'cambial_saldo_moeda' => $saldoMoeda, 'cambial_saldo_kz' => (string) $aberto['saldo']]);
    }

    private function validarLigacao(array &$l, string $onde): void
    {
        $numero = $this->liquidacoes->validarLigacao($l['venda_id'] ?? null, $l['fatura_compra_id'] ?? null, isset($l['terceiro_id']) ? (int) $l['terceiro_id'] : null, $onde);
        if ($numero !== null) {
            $l['numero_documento'] ??= $numero;
        }
        if (! empty($l['terceiro_id']) && ! Terceiro::query()->whereKey($l['terceiro_id'])->exists()) {
            throw new ErroNegocio("{$onde} terceiro inexistente.", 'TERCEIRO_INEXISTENTE', 422);
        }
    }

    private function actualizarLigados($itens, int $sinal): void
    {
        $this->liquidacoes->aplicar($itens->map(fn ($i) => $i->only(['venda_id', 'fatura_compra_id', 'tipo_dc', 'valor']))->all(), $sinal);
    }

    /** @return array{codigo: string, taxa: string, taxa_id: ?int, manual: bool, estrangeira: bool} */
    private function moedaDocumento(string $conta, string $data, ?string $manual): array
    {
        $c = $this->plano->contaDeMovimento($conta);
        if (! preg_match('/^4[35]/', $conta)) {
            throw new ErroNegocio('A conta financeira tem de ser de bancos (43) ou de caixa (45).', 'CONTA_FINANCEIRA_INVALIDA', 422);
        }
        $codigo = ($c['codigo_moeda'] ?? null) ?: ServicoCambios::BASE;
        if ($codigo === ServicoCambios::BASE) {
            return ['codigo' => $codigo, 'taxa' => '1', 'taxa_id' => null, 'manual' => false, 'estrangeira' => false];
        }
        if ($manual !== null && (float) $manual > 0) {
            return ['codigo' => $codigo, 'taxa' => number_format((float) $manual, 6, '.', ''), 'taxa_id' => null, 'manual' => true, 'estrangeira' => true];
        }
        $t = $this->cambios->obter($this->contexto->obrigatorio(), $codigo, $data)
            ?? throw new ErroNegocio("A conta {$conta} é em {$codigo} e não há câmbio registado até {$data}. Registe-o ou indique-o manualmente.", 'CAMBIO_EM_FALTA', 422);

        return ['codigo' => $codigo, 'taxa' => $t['taxa'], 'taxa_id' => $t['id'], 'manual' => false, 'estrangeira' => true];
    }

    private function diario(string $conta): DiarioContabil
    {
        return str_starts_with($conta, '45') ? $this->localizador->diario('CX', 'Caixa') : $this->localizador->diario('BD', 'Bancos');
    }
}
