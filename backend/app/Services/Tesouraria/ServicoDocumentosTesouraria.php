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
 *   - desintegrar = ESTORNO, bloqueado se o lançamento estiver reconciliado com o banco (o legado apagava as linhas
 *     por texto de referência e o bloqueio por reconciliação nunca disparava);
 *   - nunca se apaga: anula-se, com motivo (o legado apagava até documentos integrados, sem transacção).
 * Multi-moeda, reconciliação bancária e caixa: Tesouraria parte 2.
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
    ) {}

    /** @param  array<string, mixed>  $d  tipo, data_documento, conta_financeira, descricao, referencia?, linhas[] */
    public function gravar(array $d, ?DocumentoTesouraria $doc = null): DocumentoTesouraria
    {
        $empresa = $this->contexto->obrigatorio();
        if ($doc && $doc->estado !== 'PENDENTE') {
            throw new ErroNegocio("Um documento {$doc->estado} não pode ser alterado.", 'DOCUMENTO_NAO_EDITAVEL', 422);
        }
        $tipo = $d['tipo'];
        $data = substr($d['data_documento'], 0, 10);
        $this->exercicios->exigirAberto($empresa, $data);
        $this->exigirContaFinanceira($d['conta_financeira']);
        [$linhas, $total] = $this->validarLinhas($tipo, $d['linhas'], $doc?->id);

        return DB::transaction(function () use ($d, $doc, $tipo, $data, $linhas, $total, $empresa) {
            $dados = ['tipo' => $tipo, 'data_documento' => $data, 'conta_financeira' => $d['conta_financeira'], 'descricao' => $d['descricao'],
                'referencia' => $d['referencia'] ?? null, 'valor_total' => $total, 'estado' => 'PENDENTE', 'codigo_moeda' => 'AOA',
                'projeto_id' => $d['projeto_id'] ?? null];
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
            $itens = ItemDocumentoTesouraria::query()->where('documento_tesouraria_id', $doc->id)->orderBy('id')->get();
            // revalida (o saldo pode ter mudado desde a gravação: outro documento integrado entretanto)
            [, $total] = $this->validarLinhas($doc->tipo, $itens->map(fn ($i) => $i->only(['codigo_conta', 'tipo_dc', 'valor', 'terceiro_id', 'numero_documento',
                'descricao', 'venda_id', 'fatura_compra_id', 'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'data_documento_original']))->all(), $doc->id);
            $this->exigirContaFinanceira($doc->conta_financeira);

            $numero = $doc->numero_documento ?: ($doc->referencia ?: "TES-{$doc->id}");
            $linhas = [[
                'codigo_conta' => $doc->conta_financeira, 'tipo_dc' => $doc->tipo === 'PAGAMENTO' ? 'C' : 'D', 'valor' => $total,
                'descricao' => mb_substr((string) $doc->descricao, 0, 1000), 'projeto_id' => $doc->projeto_id,
            ]];
            foreach ($itens as $i) {
                $linhas[] = ['codigo_conta' => $i->codigo_conta, 'tipo_dc' => $i->tipo_dc, 'valor' => $i->valor, 'terceiro_id' => $i->terceiro_id,
                    'descricao' => mb_substr((string) ($i->descricao ?: $doc->descricao), 0, 1000), 'unidade_negocio_id' => $i->unidade_negocio_id,
                    'centro_custo_id' => $i->centro_custo_id, 'projeto_id' => $i->projeto_id ?? $doc->projeto_id,
                    'nota_demonstracao_id' => $i->nota_demonstracao_id, 'nota_fluxo_caixa_id' => $i->nota_fluxo_caixa_id,
                    // a contrapartida fica com o n.º do documento que liquida (é a chave dos pendentes)
                    'numero_documento' => $i->numero_documento ?: null];
            }
            $criadas = $this->lancamentos->criar([
                'diario_id' => $this->diario($doc->conta_financeira)->id, 'data_documento' => $doc->data_documento->toDateString(),
                'numero_documento' => $numero, 'referencia' => $doc->referencia, 'descricao' => mb_substr((string) $doc->descricao, 0, 1000),
                'tipo_origem' => 'TESOURARIA', 'linhas' => $linhas,
            ]);
            $doc->update(['estado' => 'INTEGRADO', 'numero_lan_contabilizacao' => $criadas->first()->numero_lan, 'integrado_em' => now(),
                'integrado_por' => Auth::user()?->nome_utilizador, 'valor_total' => $total]);
            $this->actualizarLigados($itens, +1);

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
     * @param  list<array<string, mixed>>  $linhas
     * @return array{0: list<array<string, mixed>>, 1: string} linhas normalizadas e valor total
     */
    private function validarLinhas(string $tipo, array $linhas, ?int $docId): array
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            throw new ErroNegocio('Tipo de documento inválido.', 'TIPO_INVALIDO', 422);
        }
        if (! $linhas) {
            throw new ErroNegocio('O documento tem de ter pelo menos uma linha.', 'SEM_LINHAS', 422);
        }
        $d = $c = '0.00';
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
            $l['tipo_dc'] === 'D' ? $d = bcadd($d, $valor, 2) : $c = bcadd($c, $valor, 2);
            $l = array_intersect_key($l, array_flip(['codigo_conta', 'tipo_dc', 'terceiro_id', 'numero_documento', 'descricao', 'venda_id', 'fatura_compra_id',
                'unidade_negocio_id', 'centro_custo_id', 'projeto_id', 'data_documento_original', 'nota_demonstracao_id', 'nota_fluxo_caixa_id'])) + ['valor' => $valor];
            $this->validarLigacao($l, $onde);
            if (! empty($l['numero_documento']) && ! empty($l['terceiro_id'])) {
                $k = "{$l['terceiro_id']}|{$l['codigo_conta']}|{$l['numero_documento']}";
                $porDocumento[$k] = ($porDocumento[$k] ?? []) + ['terceiro_id' => (int) $l['terceiro_id'], 'conta' => (string) $l['codigo_conta'],
                    'numero' => (string) $l['numero_documento'], 'D' => '0.00', 'C' => '0.00', 'onde' => $onde];
                $porDocumento[$k][$l['tipo_dc']] = bcadd($porDocumento[$k][$l['tipo_dc']], $valor, 2);
            }
            $saida[] = $l;
        }
        $liquido = $tipo === 'PAGAMENTO' ? bcsub($d, $c, 2) : bcsub($c, $d, 2);
        if (bccomp($liquido, '0', 2) <= 0) {
            throw new ErroNegocio($tipo === 'PAGAMENTO' ? 'Num pagamento os débitos das linhas têm de exceder os créditos.'
                : 'Num recebimento os créditos das linhas têm de exceder os débitos.', 'SENTIDO_INVALIDO', 422, ['debito' => $d, 'credito' => $c]);
        }
        // não liquidar mais do que o saldo em aberto de cada documento
        foreach ($porDocumento as $p) {
            $aberto = $this->pendentes->saldo($p['terceiro_id'], $p['conta'], $p['numero'], $docId);
            if (! $aberto) {
                continue;   // sem documento em aberto com esse n.º: é um adiantamento ou movimento avulso
            }
            $aLiquidar = $aberto['liquidar_a'] === 'C' ? bcsub($p['C'], $p['D'], 2) : bcsub($p['D'], $p['C'], 2);
            if (bccomp($aLiquidar, bcadd($aberto['saldo'], '0.01', 2), 2) > 0) {
                throw new ErroNegocio("{$p['onde']} liquida {$aLiquidar} do documento {$p['numero']}, mas o saldo em aberto é {$aberto['saldo']}.",
                    'VALOR_SUPERIOR_EM_ABERTO', 422, ['numero_documento' => $p['numero'], 'saldo' => $aberto['saldo']]);
            }
        }

        return [$saida, $liquido];
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

    private function exigirContaFinanceira(string $conta): void
    {
        $c = $this->plano->contaDeMovimento($conta);
        if (! preg_match('/^4[35]/', $conta)) {
            throw new ErroNegocio('A conta financeira tem de ser de bancos (43) ou de caixa (45).', 'CONTA_FINANCEIRA_INVALIDA', 422);
        }
        if (($c['codigo_moeda'] ?? null) && $c['codigo_moeda'] !== 'AOA') {
            throw new ErroNegocio("A conta {$conta} é em {$c['codigo_moeda']}: os documentos em moeda estrangeira chegam com a Tesouraria parte 2.", 'MOEDA_NAO_SUPORTADA', 422);
        }
    }

    private function diario(string $conta): DiarioContabil
    {
        return str_starts_with($conta, '45') ? $this->localizador->diario('CX', 'Caixa') : $this->localizador->diario('BD', 'Bancos');
    }

    private static function tolerancia(): string
    {
        return ServicoPendentes::TOLERANCIA;
    }
}
