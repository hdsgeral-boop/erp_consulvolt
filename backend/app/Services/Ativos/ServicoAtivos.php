<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AbateVendaAtivo;
use App\Models\AfetacaoAtivoProjeto;
use App\Models\AlocacaoAquisicaoAtivo;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\CentroCusto;
use App\Models\LancamentoContabil;
use App\Models\Projeto;
use App\Models\RegistoManutencaoAtivo;
use App\Models\Terceiro;
use App\Models\TransferenciaCentroCustoAtivo;
use App\Models\UnidadeNegocio;
use App\Services\Sistema\ServicoNumeracao;
use App\Support\Dados\VerificadorReferencias;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de activos (renderAssetList, showAssetForm/saveAsset, deleteAsset/deleteBulkAssets, applyBulkAssetEdit,
 * handleAssetExcelUpload, executeAssetTransfer, showAssetHistoryModal — js/ui_assets.js:69-447, 715-910, 1097-1117,
 * 2539-2743, 3393-3623) e afectação a projectos (project_asset_allocations, js/db_v2.js:560, sem ecrã no legado).
 * Regras do legado mantidas: código, descrição e categoria obrigatórios; código único na empresa; com amortizações
 * calculadas os campos de cálculo ficam bloqueados; a transferência muda o centro de custo e fica registada; a importação
 * cria as categorias em falta com 25 % e, para um código existente, só actualiza descrição e categoria (ou ignora).
 * Correcções:
 *   - a amortização acumulada é derivada (inicial + quotas contabilizadas) e recalculada em cada operação; o legado
 *     mantinha-a por somas e subtracções soltas e «corrigia-a» ao abrir o ecrã (ui_assets.js:3-37, ADR-015);
 *   - a edição em massa respeita o mesmo bloqueio da ficha (applyBulkAssetEdit mudava valor, vida e data de activos já
 *     amortizados, ui_assets.js:3566-3623);
 *   - o estado ABATIDO só se atinge pelo abate (o formulário deixava marcar ABATIDO sem registo de abate nem lançamento);
 *   - eliminar exige que o activo não tenha quotas integradas nem outros registos (transferências, manutenções, abates):
 *     o legado apagava e deixava-os órfãos; a eliminação é lógica;
 *   - valores validados (residual ≤ aquisição, amortização inicial ≤ base, vida e quota ≥ 0) e FKs verificadas;
 *   - código automático AST-NNN pela numeração da empresa quando não é indicado (o legado propunha «n.º de activos + 1»,
 *     que repetia códigos depois de eliminações, ui_assets.js:541).
 */
final class ServicoAtivos
{
    /** Campos que determinam o cálculo: bloqueados quando o activo já tem quotas calculadas. */
    public const CAMPOS_CALCULO = ['categoria_ativo_id', 'data_aquisicao', 'valor_aquisicao', 'valor_residual', 'vida_util', 'vida_util_restante', 'quota_fixa',
        'amortizacao_acumulada_inicial', 'acumulado_fim_ano'];

    public const CAMPOS_MASSA = ['descricao', 'categoria_ativo_id', 'unidade_negocio_id', 'centro_custo_id', 'fornecedor_id', 'data_aquisicao', 'vida_util',
        'vida_util_restante', 'valor_residual', 'quota_fixa', 'estado'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
        private readonly VerificadorReferencias $referencias,
        private readonly ServicoLocaisAtivos $locais,
    ) {}

    // ───────────── Ficha ─────────────

    public function guardar(array $d, ?AtivoImobilizado $a = null): AtivoImobilizado
    {
        return DB::transaction(function () use ($d, $a) {
            if ($a) {
                $a = AtivoImobilizado::query()->lockForUpdate()->findOrFail($a->id);
            }
            $d = $this->validar($d, $a);
            if ($a) {
                // activo combinado (várias linhas de aquisição): o novo valor reparte-se outra vez pelas mesmas linhas
                if (array_key_exists('valor_aquisicao', $d) && ! $this->igual($a->valor_aquisicao, $d['valor_aquisicao'])
                    && ($fk = $this->repartirAquisicaoCombinada($a, $d['valor_aquisicao'])) !== null) {
                    $a->forceFill(['lancamento_contabil_id' => $fk]);
                }
                $a->update($d);

                return $this->recalcularAcumulado($a);
            }
            if (empty($d['codigo'])) {
                $d['codigo'] = $this->proximoCodigo();
            }

            return AtivoImobilizado::create($d + ['estado' => AtivoImobilizado::ESTADO_ATIVO, 'amortizacao_acumulada' => $d['amortizacao_acumulada_inicial'] ?? '0.00']);
        });
    }

    /** Normaliza e valida os dados da ficha (criação ou alteração parcial). */
    public function validar(array $d, ?AtivoImobilizado $a = null): array
    {
        unset($d['amortizacao_acumulada'], $d['lancamento_contabil_id'], $d['empresa_id']);
        // Fase F (Versão Actual): tipo BEM/EDIFICIO e localização Edifício › Andar › Porta (ui_assets.js:465-480)
        $d = $this->locais->normalizarFicha($d, $a);
        foreach (['codigo', 'descricao'] as $c) {
            if (array_key_exists($c, $d)) {
                $d[$c] = trim((string) $d[$c]);
            }
        }
        if (! $a && trim((string) ($d['descricao'] ?? '')) === '') {
            throw new ErroNegocio('Indique a descrição do bem.', 'DADOS_INVALIDOS', 422);
        }
        if ($a && array_key_exists('descricao', $d) && $d['descricao'] === '') {
            throw new ErroNegocio('A descrição não pode ficar vazia.', 'DADOS_INVALIDOS', 422);
        }
        if ($a && array_key_exists('codigo', $d) && $d['codigo'] === '') {
            throw new ErroNegocio('O código não pode ficar vazio.', 'DADOS_INVALIDOS', 422);
        }
        if (! empty($d['codigo']) && (! $a || $d['codigo'] !== $a->codigo)) {
            $existente = AtivoImobilizado::withTrashed()->where('codigo', $d['codigo'])->when($a, fn ($q) => $q->whereKeyNot($a->id))->first();
            if ($existente) {
                throw new ErroNegocio("O n.º de inventário «{$d['codigo']}» já está em uso".($existente->trashed() ? ' (activo eliminado).' : '.'), 'CODIGO_DUPLICADO', 422);
            }
        }

        // r3_activos: próprio / alheio (arrendado, cedido, de terceiros) — os alheios não têm aquisição nem amortização
        $d = $this->normalizarPropriedade($d, $a);

        if ($a && $this->temAmortizacoes($a)) {
            $mudou = array_values(array_filter(self::CAMPOS_CALCULO, fn ($c) => array_key_exists($c, $d) && ! $this->igual($a->{$c}, $d[$c])));
            if ($mudou) {
                throw new ErroNegocio("O activo {$a->codigo} já tem amortizações calculadas: reabra ou anule primeiro esses períodos para alterar ".implode(', ', $mudou).'.',
                    'ATIVO_COM_AMORTIZACOES', 422, ['campos' => $mudou]);
            }
        }
        if (array_key_exists('estado', $d) && $d['estado'] !== null) {
            if (! in_array($d['estado'], AtivoImobilizado::ESTADOS, true)) {
                throw new ErroNegocio('Estado inválido.', 'DADOS_INVALIDOS', 422);
            }
            $atual = $a?->estado ?? AtivoImobilizado::ESTADO_ATIVO;
            if ($d['estado'] !== $atual && ($d['estado'] === AtivoImobilizado::ESTADO_ABATIDO || $atual === AtivoImobilizado::ESTADO_ABATIDO)) {
                throw new ErroNegocio($atual === AtivoImobilizado::ESTADO_ABATIDO ? 'O activo está abatido: anule o abate para o reactivar.'
                    : 'Para abater o activo use Abates e vendas (regista o abate e o lançamento).', 'ESTADO_ABATE', 422);
            }
        } else {
            unset($d['estado']);
        }

        $cat = null;
        if (! $a || array_key_exists('categoria_ativo_id', $d)) {
            $cat = CategoriaAtivo::query()->find($d['categoria_ativo_id'] ?? 0)
                ?? throw new ErroNegocio('Indique uma categoria existente.', 'DADOS_INVALIDOS', 422);
        }
        foreach (['centro_custo_id' => CentroCusto::class, 'unidade_negocio_id' => UnidadeNegocio::class, 'fornecedor_id' => Terceiro::class] as $c => $modelo) {
            if (! empty($d[$c]) && ! $modelo::query()->whereKey($d[$c])->exists()) {
                throw new ErroNegocio("Registo inexistente em {$c}.", 'DADOS_INVALIDOS', 422, ['campo' => $c]);
            }
            if (array_key_exists($c, $d) && empty($d[$c])) {
                $d[$c] = null;
            }
        }

        if (! $a) {
            $d['data_aquisicao'] ??= now()->toDateString();
            $d['vida_util'] ??= $cat?->vida_util_padrao ?: 48;
            $d['vida_util_restante'] ??= $d['vida_util'];
            $d += ['valor_aquisicao' => '0.00', 'valor_residual' => '0.00', 'quota_fixa' => '0.00', 'amortizacao_acumulada_inicial' => '0.00'];
        }
        foreach (['valor_aquisicao', 'valor_residual', 'quota_fixa', 'amortizacao_acumulada_inicial'] as $c) {
            if (array_key_exists($c, $d)) {
                $d[$c] = CalculadoraAmortizacoes::d($d[$c]);
                if (bccomp($d[$c], '0', 2) < 0) {
                    throw new ErroNegocio("O campo {$c} não pode ser negativo.", 'DADOS_INVALIDOS', 422, ['campo' => $c]);
                }
            }
        }
        foreach (['vida_util', 'vida_util_restante'] as $c) {
            if (array_key_exists($c, $d) && $d[$c] !== null && (int) $d[$c] < 0) {
                throw new ErroNegocio("O campo {$c} não pode ser negativo.", 'DADOS_INVALIDOS', 422, ['campo' => $c]);
            }
        }
        if (array_key_exists('acumulado_fim_ano', $d) && $d['acumulado_fim_ano'] !== null && $d['acumulado_fim_ano'] !== ''
            && ((int) $d['acumulado_fim_ano'] < 1900 || (int) $d['acumulado_fim_ano'] > 2100)) {
            throw new ErroNegocio('O ano da amortização acumulada inicial é inválido.', 'DADOS_INVALIDOS', 422);
        }
        $aquisicao = CalculadoraAmortizacoes::d($d['valor_aquisicao'] ?? $a?->valor_aquisicao);
        $residual = CalculadoraAmortizacoes::d($d['valor_residual'] ?? $a?->valor_residual);
        $inicial = CalculadoraAmortizacoes::d($d['amortizacao_acumulada_inicial'] ?? $a?->amortizacao_acumulada_inicial);
        if (bccomp($residual, $aquisicao, 2) > 0) {
            throw new ErroNegocio('O valor residual não pode exceder o valor de aquisição.', 'DADOS_INVALIDOS', 422);
        }
        if (bccomp($inicial, bcsub($aquisicao, $residual, 2), 2) > 0) {
            throw new ErroNegocio('A amortização já efectuada não pode exceder a base amortizável (aquisição − residual).', 'DADOS_INVALIDOS', 422);
        }

        return $d;
    }

    /**
     * Propriedade do bem/edifício (r3_activos). PROPRIO (por omissão, e todos os existentes) mantém as regras de aquisição e
     * amortização. ALHEIO (arrendado, cedido, de terceiros): sem amortização (residual, quota fixa, vida e amortização inicial
     * a zero), sem ligação a lançamentos de aquisição; o valor e a data ficam opcionais (valor de referência / entrada ao
     * serviço — por omissão o início do contrato). Passar de próprio a alheio só sem quotas, sem lançamento de aquisição,
     * sem linhas de aquisição combinadas e sem abates. Os dados do proprietário/contrato só se guardam nos alheios.
     */
    private function normalizarPropriedade(array $d, ?AtivoImobilizado $a): array
    {
        if (array_key_exists('propriedade', $d) && $d['propriedade'] !== null && ! in_array($d['propriedade'], AtivoImobilizado::PROPRIEDADES, true)) {
            throw new ErroNegocio('Propriedade inválida (PROPRIO ou ALHEIO).', 'DADOS_INVALIDOS', 422, ['campo' => 'propriedade']);
        }
        $atual = $a ? ($a->propriedade ?: AtivoImobilizado::PROPRIO) : null;
        $prop = ($d['propriedade'] ?? null) ?: ($atual ?? AtivoImobilizado::PROPRIO);
        $mudou = $a && $prop !== $atual;
        if (! $a || array_key_exists('propriedade', $d)) {
            $d['propriedade'] = $prop;
        }
        $alheios = array_values(array_diff(AtivoImobilizado::CAMPOS_PROPRIEDADE, ['propriedade']));

        if ($prop === AtivoImobilizado::PROPRIO) {
            // os dados do contrato não se aplicam a um bem próprio
            foreach ($alheios as $c) {
                if (! $a || $mudou) {
                    $d[$c] = null;
                } else {
                    unset($d[$c]);
                }
            }

            return $d;
        }

        if ($mudou) {
            $motivos = array_values(array_filter([
                $this->temAmortizacoes($a) ? 'tem amortizações calculadas' : null,
                $a->lancamento_contabil_id || AlocacaoAquisicaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->exists() ? 'está ligado a um lançamento de aquisição' : null,
                AbateVendaAtivo::query()->where('ativo_imobilizado_id', $a->id)->exists() ? 'tem abates registados' : null,
            ]));
            if ($motivos) {
                throw new ErroNegocio("O activo {$a->codigo} não pode passar a alheio: ".implode(' e ', $motivos).'.', 'PROPRIEDADE_BLOQUEADA', 422, ['motivos' => $motivos]);
            }
        }
        if (array_key_exists('regime_alheio', $d) && $d['regime_alheio'] !== null && $d['regime_alheio'] !== ''
            && ! in_array($d['regime_alheio'], AtivoImobilizado::REGIMES_ALHEIO, true)) {
            throw new ErroNegocio('Regime inválido (ARRENDADO, CEDIDO, TERCEIROS ou OUTRO).', 'DADOS_INVALIDOS', 422, ['campo' => 'regime_alheio']);
        }
        foreach (['regime_alheio', 'proprietario_nome', 'contrato_referencia', 'propriedade_notas'] as $c) {
            if (array_key_exists($c, $d)) {
                $d[$c] = trim((string) $d[$c]) ?: null;
            }
        }
        if (! empty($d['proprietario_terceiro_id']) && ! Terceiro::query()->whereKey($d['proprietario_terceiro_id'])->exists()) {
            throw new ErroNegocio('Proprietário inexistente.', 'DADOS_INVALIDOS', 422, ['campo' => 'proprietario_terceiro_id']);
        }
        if (array_key_exists('proprietario_terceiro_id', $d) && empty($d['proprietario_terceiro_id'])) {
            $d['proprietario_terceiro_id'] = null;
        }
        foreach (['contrato_inicio', 'contrato_fim'] as $c) {
            if (array_key_exists($c, $d)) {
                $d[$c] = $this->data($d[$c]);
            }
        }
        $inicio = array_key_exists('contrato_inicio', $d) ? $d['contrato_inicio'] : $a?->contrato_inicio?->toDateString();
        $fim = array_key_exists('contrato_fim', $d) ? $d['contrato_fim'] : $a?->contrato_fim?->toDateString();
        if ($inicio && $fim && $fim < $inicio) {
            throw new ErroNegocio('O fim do contrato é anterior ao início.', 'DADOS_INVALIDOS', 422, ['campo' => 'contrato_fim']);
        }
        if (array_key_exists('renda_mensal', $d)) {
            $d['renda_mensal'] = $d['renda_mensal'] === null || $d['renda_mensal'] === '' ? null : CalculadoraAmortizacoes::d($d['renda_mensal']);
            if ($d['renda_mensal'] !== null && bccomp($d['renda_mensal'], '0', 2) < 0) {
                throw new ErroNegocio('A renda não pode ser negativa.', 'DADOS_INVALIDOS', 422, ['campo' => 'renda_mensal']);
            }
        }
        // sem amortização: os campos de cálculo ficam a zero (o servidor ignora o que vier)
        $d = array_merge($d, ['valor_residual' => '0.00', 'quota_fixa' => '0.00', 'amortizacao_acumulada_inicial' => '0.00', 'acumulado_fim_ano' => null,
            'vida_util' => 0, 'vida_util_restante' => 0]);
        if (! $a || $mudou) {
            $d['valor_aquisicao'] = isset($d['valor_aquisicao']) && $d['valor_aquisicao'] !== '' ? $d['valor_aquisicao'] : '0.00';
            $d['data_aquisicao'] = ($d['data_aquisicao'] ?? null) ?: ($inicio ?? now()->toDateString());
        } elseif (array_key_exists('data_aquisicao', $d) && empty($d['data_aquisicao'])) {
            unset($d['data_aquisicao']);
        }

        return $d;
    }

    /**
     * Activo inventariado em conjunto a partir de várias linhas de aquisição (ServicoAquisicoesAtivos::combinar): ao mudar o
     * valor de aquisição, refaz a repartição pelas mesmas linhas e pela mesma ordem (data, id), sem exceder o que cada linha
     * ainda tem por cobrir (descontado o que outros activos já cobrem). Se não couber, recusa sem alterar nada. Corre dentro
     * da transacção de guardar(), com as linhas bloqueadas.
     *
     * @return int|null primeira linha coberta (nova FK do activo) ou null quando o activo não é combinado
     */
    private function repartirAquisicaoCombinada(AtivoImobilizado $a, string $valor): ?int
    {
        $atuais = AlocacaoAquisicaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->lockForUpdate()->get()->keyBy('lancamento_contabil_id');
        if ($atuais->isEmpty()) {
            return null;
        }
        if (bccomp($valor, '0', 2) <= 0) {
            throw new ErroNegocio("O activo {$a->codigo} foi inventariado a partir de várias linhas de aquisição: o valor de aquisição tem de ser maior que zero.",
                'DADOS_INVALIDOS', 422, ['campo' => 'valor_aquisicao']);
        }
        $ids = $atuais->keys()->map(fn ($id) => (int) $id)->all();
        $linhas = LancamentoContabil::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()
            ->sortBy([fn ($x, $y) => [$x->data_documento?->toDateString(), $x->id] <=> [$y->data_documento?->toDateString(), $y->id]])->values();
        $alocado = app(ServicoAquisicoesAtivos::class)->alocadoPorLinha($ids);
        $fila = [];
        $disponivel = '0.00';
        foreach ($linhas as $l) {
            $outros = bcsub($alocado[$l->id]['v'] ?? '0.00', CalculadoraAmortizacoes::d($atuais[$l->id]?->valor), 2);
            $restante = bcsub(CalculadoraAmortizacoes::d($l->valor), $outros, 2);
            $restante = bccomp($restante, '0', 2) > 0 ? $restante : '0.00';
            $fila[] = ['linha' => $l, 'restante' => $restante];
            $disponivel = bcadd($disponivel, $restante, 2);
        }
        if (bccomp($valor, $disponivel, 2) > 0) {
            $detalhe = array_map(fn ($f) => ['id' => $f['linha']->id, 'documento' => $f['linha']->numero_documento, 'disponivel' => $f['restante']], $fila);
            throw new ErroNegocio("O valor de aquisição ({$valor}) excede o que as ".count($fila)." linhas de aquisição combinadas no activo {$a->codigo} ainda podem cobrir ({$disponivel}): "
                .implode('; ', array_map(fn ($x) => ($x['documento'] ?: "linha {$x['id']}").' '.$x['disponivel'], $detalhe)).'. Indique um valor até '.$disponivel.'.',
                'EXCEDE_AQUISICAO', 422, ['disponivel' => $disponivel, 'linhas' => $detalhe]);
        }
        $falta = $valor;
        $fk = null;
        foreach ($fila as $f) {
            $id = $f['linha']->id;
            $parte = bccomp($falta, $f['restante'], 2) <= 0 ? $falta : $f['restante'];
            $existente = $atuais[$id] ?? null;
            if (bccomp($parte, '0', 2) > 0) {
                $existente ? $existente->update(['valor' => $parte]) : AlocacaoAquisicaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'lancamento_contabil_id' => $id, 'valor' => $parte]);
                $fk ??= $id;
                $falta = bcsub($falta, $parte, 2);
            } else {
                $existente?->delete();
            }
        }

        return $fk;
    }

    public function eliminar(AtivoImobilizado $a): void
    {
        $this->eliminarVarios([$a->id]);
    }

    /** Eliminação (unitária ou em massa): tudo ou nada; as quotas em rascunho saem com o activo. */
    public function eliminarVarios(array $ids): int
    {
        return DB::transaction(function () use ($ids) {
            $ativos = AtivoImobilizado::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($ativos->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Há activos seleccionados que não existem.', 'DADOS_INVALIDOS', 422);
            }
            $integrados = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ids)->where('contabilizado', true)->distinct()->pluck('ativo_imobilizado_id');
            if ($integrados->isNotEmpty()) {
                throw new ErroNegocio('Há activos com amortizações integradas na contabilidade: reabra primeiro esses períodos.', 'ATIVO_COM_AMORTIZACOES', 422,
                    ['ativos' => $ativos->whereIn('id', $integrados)->pluck('codigo')->values()->all()]);
            }
            foreach ($ativos as $a) {
                $this->referencias->exigirLivre('ativos_imobilizados', $a->id, "o activo {$a->codigo}", ['amortizacoes_ativos', 'alocacoes_aquisicao_ativos']);
            }
            AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ids)->where('contabilizado', false)->delete();
            foreach ($ativos as $a) {
                $a->delete();
            }

            return $ativos->count();
        });
    }

    /** Edição em massa (applyBulkAssetEdit): os mesmos bloqueios da ficha, tudo ou nada. */
    public function editarVarios(array $ids, array $campos): int
    {
        $campos = array_intersect_key($campos, array_flip(self::CAMPOS_MASSA));
        if (! $campos) {
            throw new ErroNegocio('Seleccione pelo menos um campo para actualizar.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($ids, $campos) {
            $ativos = AtivoImobilizado::query()->whereIn('id', $ids)->lockForUpdate()->get();
            if ($ativos->count() !== count(array_unique($ids))) {
                throw new ErroNegocio('Há activos seleccionados que não existem.', 'DADOS_INVALIDOS', 422);
            }
            $erros = [];
            foreach ($ativos as $a) {
                try {
                    $a->update($this->validar($campos, $a));
                } catch (ErroNegocio $e) {
                    $erros[$a->codigo] = $e->getMessage();
                }
            }
            if ($erros) {
                throw new ErroNegocio('Nenhum activo foi alterado: '.count($erros).' não aceita(m) a alteração.', 'EDICAO_MASSA_RECUSADA', 422, ['ativos' => $erros]);
            }

            return $ativos->count();
        });
    }

    /**
     * Importação (handleAssetExcelUpload, ui_assets.js:751-910). Cada linha: codigo, descricao, valor_aquisicao, categoria (nome),
     * vida_util, anos_amortizados, amortizacao_acumulada, ano_amortizacao_acumulada, data_aquisicao.
     * Código já existente: IGNORAR (mantém) ou ACTUALIZAR (só descrição e categoria, como o legado). Com $simular devolve a contagem.
     *
     * @return array{novos: int, existentes: list<string>, repetidos: int, importados: int, actualizados: int, ignorados: int, categorias_criadas: list<string>}
     */
    public function importar(array $linhas, string $decisao, bool $simular = false): array
    {
        $r = ['novos' => 0, 'existentes' => [], 'repetidos' => 0, 'importados' => 0, 'actualizados' => 0, 'ignorados' => 0, 'categorias_criadas' => []];
        $existentes = AtivoImobilizado::withTrashed()->get(['id', 'codigo', 'descricao', 'eliminado_em'])->keyBy(fn ($a) => mb_strtolower(trim((string) $a->codigo)));
        $vistos = [];
        $validas = [];
        foreach ($linhas as $i => $l) {
            $codigo = trim((string) ($l['codigo'] ?? ''));
            $descricao = trim((string) ($l['descricao'] ?? ''));
            if ($codigo === '' || $descricao === '') {
                continue;
            }
            $k = mb_strtolower($codigo);
            if (isset($vistos[$k])) {
                $r['repetidos']++;

                continue;
            }
            $vistos[$k] = true;
            if (isset($existentes[$k])) {
                $r['existentes'][] = "{$existentes[$k]->codigo} — {$existentes[$k]->descricao}";
            } else {
                $r['novos']++;
            }
            $validas[] = ['linha' => $i + 1, 'codigo' => $codigo, 'descricao' => $descricao] + $l;
        }
        if ($simular) {
            return $r;
        }

        DB::transaction(function () use ($validas, $decisao, $existentes, &$r) {
            $cats = CategoriaAtivo::query()->get()->keyBy(fn ($c) => mb_strtolower(trim((string) $c->nome)));
            foreach ($validas as $l) {
                $nomeCat = trim((string) ($l['categoria'] ?? '')) ?: 'Sem Categoria';
                $cat = $cats[mb_strtolower($nomeCat)] ?? null;
                if (! $cat) {
                    $cat = CategoriaAtivo::create(['nome' => $nomeCat, 'taxa_anual' => 25]);   // como o legado (ui_assets.js:829-836)
                    $cats[mb_strtolower($nomeCat)] = $cat;
                    $r['categorias_criadas'][] = $nomeCat;
                }
                $existente = $existentes[mb_strtolower($l['codigo'])] ?? null;
                if ($existente) {
                    if ($decisao === 'ACTUALIZAR' && ! $existente->trashed()) {
                        AtivoImobilizado::query()->whereKey($existente->id)->update(['descricao' => $l['descricao'], 'categoria_ativo_id' => $cat->id, 'atualizado_em' => now()]);
                        $r['actualizados']++;
                    } else {
                        $r['ignorados']++;
                    }

                    continue;
                }
                $vida = (int) ($l['vida_util'] ?? 0) ?: 48;
                $anos = (float) ($l['anos_amortizados'] ?? 0);
                try {
                    $this->guardar([
                        'codigo' => $l['codigo'], 'descricao' => $l['descricao'], 'categoria_ativo_id' => $cat->id,
                        'data_aquisicao' => $this->dataImportada($l['data_aquisicao'] ?? null),
                        'valor_aquisicao' => (float) ($l['valor_aquisicao'] ?? 0), 'vida_util' => $vida,
                        'vida_util_restante' => max(1, (int) round($vida - $anos * 12)),
                        'amortizacao_acumulada_inicial' => (float) ($l['amortizacao_acumulada'] ?? 0),
                        'acumulado_fim_ano' => ! empty($l['ano_amortizacao_acumulada']) ? (int) $l['ano_amortizacao_acumulada'] : null,
                    ]);
                } catch (ErroNegocio $e) {
                    throw new ErroNegocio("Linha {$l['linha']} ({$l['codigo']}): ".$e->getMessage(), $e->codigo, 422, ['linha' => $l['linha']] + $e->detalhes);
                }
                $r['importados']++;
            }
        });

        return $r;
    }

    // ───────────── Amortização acumulada ─────────────

    /** Recalcula a amortização acumulada (inicial + Σ quotas contabilizadas). */
    public function recalcularAcumulado(AtivoImobilizado $a): AtivoImobilizado
    {
        $soma = (string) (AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->where('contabilizado', true)->sum('valor') ?: '0');
        $acumulado = bcadd(CalculadoraAmortizacoes::d($a->amortizacao_acumulada_inicial), $soma, 2);
        if (! $this->igual($a->amortizacao_acumulada, $acumulado)) {
            $a->update(['amortizacao_acumulada' => $acumulado]);
        }

        return $a->refresh();
    }

    public function temAmortizacoes(AtivoImobilizado $a): bool
    {
        return AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->exists();
    }

    // ───────────── Transferências e afectações ─────────────

    /** Transferência de centro de custo (executeAssetTransfer, ui_assets.js:2581-2607), agora numa transacção. */
    public function transferir(AtivoImobilizado $a, int $destino, string $data, ?int $projetoId = null): TransferenciaCentroCustoAtivo
    {
        return DB::transaction(function () use ($a, $destino, $data, $projetoId) {
            $a = AtivoImobilizado::query()->lockForUpdate()->findOrFail($a->id);
            if ($a->estado === AtivoImobilizado::ESTADO_ABATIDO) {
                throw new ErroNegocio("O activo {$a->codigo} está abatido.", 'ATIVO_ABATIDO', 422);
            }
            CentroCusto::query()->findOr($destino, fn () => throw new ErroNegocio('Centro de custo de destino inexistente.', 'DADOS_INVALIDOS', 422));
            if ((int) $a->centro_custo_id === $destino) {
                throw new ErroNegocio('O novo centro de custo deve ser diferente do actual.', 'MESMO_CENTRO_CUSTO', 422);
            }
            $projeto = $projetoId ? Projeto::query()->findOr($projetoId, fn () => throw new ErroNegocio('Projecto inexistente.', 'DADOS_INVALIDOS', 422)) : null;
            $t = TransferenciaCentroCustoAtivo::create(['ativo_imobilizado_id' => $a->id, 'centro_custo_origem_id' => $a->centro_custo_id,
                'centro_custo_destino_id' => $destino, 'data' => $data, 'projeto_id' => $projeto?->id, 'codigo_projeto' => $projeto?->codigo]);
            $a->update(['centro_custo_id' => $destino]);

            return $t;
        });
    }

    public function guardarAfetacao(array $d, ?AfetacaoAtivoProjeto $f = null): AfetacaoAtivoProjeto
    {
        return DB::transaction(function () use ($d, $f) {
            $d += $f ? $f->only(['projeto_id', 'ativo_imobilizado_id', 'data_inicio', 'data_fim']) : [];
            $d['data_inicio'] = $this->data($d['data_inicio'] ?? null);
            $d['data_fim'] = $this->data($d['data_fim'] ?? null);
            $a = AtivoImobilizado::query()->lockForUpdate()->find($d['ativo_imobilizado_id'] ?? 0)
                ?? throw new ErroNegocio('Activo inexistente.', 'DADOS_INVALIDOS', 422);
            Projeto::query()->findOr($d['projeto_id'] ?? 0, fn () => throw new ErroNegocio('Projecto inexistente.', 'DADOS_INVALIDOS', 422));
            if (! $d['data_inicio']) {
                throw new ErroNegocio('Indique a data de início da afectação.', 'DADOS_INVALIDOS', 422);
            }
            if ($d['data_fim'] && $d['data_fim'] < $d['data_inicio']) {
                throw new ErroNegocio('A data de fim é anterior à de início.', 'DADOS_INVALIDOS', 422);
            }
            if (! $f && $a->estado === AtivoImobilizado::ESTADO_ABATIDO) {
                throw new ErroNegocio("O activo {$a->codigo} está abatido.", 'ATIVO_ABATIDO', 422);
            }
            $sobreposta = AfetacaoAtivoProjeto::query()->where('ativo_imobilizado_id', $a->id)->when($f, fn ($q) => $q->whereKeyNot($f->id))
                ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $d['data_inicio']))
                ->when($d['data_fim'], fn ($q) => $q->where('data_inicio', '<=', $d['data_fim']))->with('projeto:id,codigo,nome')->first();
            if ($sobreposta) {
                throw new ErroNegocio("O activo {$a->codigo} já está afecto ao projecto ".($sobreposta->projeto?->codigo ?? "#{$sobreposta->projeto_id}").' nesse intervalo.',
                    'AFETACAO_SOBREPOSTA', 422, ['afetacao_id' => $sobreposta->id]);
            }
            $campos = array_intersect_key($d, array_flip(['projeto_id', 'ativo_imobilizado_id', 'data_inicio', 'data_fim']));
            $f ? $f->update($campos) : $f = AfetacaoAtivoProjeto::create($campos);

            return $f->refresh();
        });
    }

    // ───────────── Consulta ─────────────

    /** Ficha com histórico (showAssetHistoryModal): origem contabilística, transferências, manutenções, amortizações, abate e afectações. */
    public function ficha(AtivoImobilizado $a): array
    {
        $origem = $a->lancamento_contabil_id ? LancamentoContabil::query()->with('diario:id,codigo,nome')->find($a->lancamento_contabil_id) : null;
        $campos = ['id', 'numero_lan', 'numero_documento', 'data_documento', 'codigo_conta', 'valor', 'descricao', 'terceiro_id'];
        // todas as linhas de aquisição que o activo cobre, com o valor de cada uma (combinado: as alocações; senão: a FK)
        $alocacoes = AlocacaoAquisicaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->pluck('valor', 'lancamento_contabil_id');
        $origens = $alocacoes->isNotEmpty()
            ? LancamentoContabil::query()->with('diario:id,codigo,nome')->whereKey($alocacoes->keys()->all())->get()
                ->sortBy([fn ($x, $y) => [$x->data_documento?->toDateString(), $x->id] <=> [$y->data_documento?->toDateString(), $y->id]])
                ->map(fn ($l) => $l->only($campos) + ['diario' => $l->diario?->only(['codigo', 'nome']), 'valor_alocado' => CalculadoraAmortizacoes::d($alocacoes[$l->id])])->values()->all()
            : ($origem ? [$origem->only($campos) + ['diario' => $origem->diario?->only(['codigo', 'nome']), 'valor_alocado' => CalculadoraAmortizacoes::d($a->valor_aquisicao)]] : []);

        return $a->load(['categoriaAtivo', 'centroCusto:id,codigo,descricao', 'unidadeNegocio:id,codigo,nome', 'fornecedor:id,nome', 'proprietario:id,nome', 'edificio:id,codigo,descricao', 'andar:id,numero,nome', 'porta:id,numero,nome'])->toArray() + [
            'valor_liquido' => bcsub(CalculadoraAmortizacoes::d($a->valor_aquisicao), CalculadoraAmortizacoes::d($a->amortizacao_acumulada), 2),
            'bloqueado' => $this->temAmortizacoes($a),
            'origem' => $origem ? $origem->only($campos) + ['diario' => $origem->diario?->only(['codigo', 'nome'])] : null,
            'origens' => $origens,
            'combinado' => $alocacoes->isNotEmpty(),
            'transferencias' => TransferenciaCentroCustoAtivo::query()->where('ativo_imobilizado_id', $a->id)->with(['centroCustoOrigem:id,codigo,descricao', 'centroCustoDestino:id,codigo,descricao'])
                ->orderByDesc('data')->orderByDesc('id')->get(),
            'manutencoes' => RegistoManutencaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->orderByDesc('data')->orderByDesc('id')->get(),
            'amortizacoes' => AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->get()->sortBy(fn ($x) => CalculadoraAmortizacoes::ordem((string) $x->periodo_codigo))->values(),
            'abates' => AbateVendaAtivo::query()->where('ativo_imobilizado_id', $a->id)->orderByDesc('data')->get(),
            'afetacoes' => AfetacaoAtivoProjeto::query()->where('ativo_imobilizado_id', $a->id)->with('projeto:id,codigo,nome')->orderByDesc('data_inicio')->get(),
        ];
    }

    /** Acrescenta a cada activo a amortização acumulada, o valor líquido e o bloqueio da edição. */
    public function enriquecer(Collection $ativos): Collection
    {
        $comQuotas = AmortizacaoAtivo::query()->whereIn('ativo_imobilizado_id', $ativos->pluck('id'))->distinct()->pluck('ativo_imobilizado_id')->flip();

        return $ativos->map(fn (AtivoImobilizado $a) => $a->toArray() + [
            'valor_liquido' => bcsub(CalculadoraAmortizacoes::d($a->valor_aquisicao), CalculadoraAmortizacoes::d($a->amortizacao_acumulada), 2),
            'taxa' => (int) $a->vida_util > 0 ? bcdiv('1200', (string) $a->vida_util, 2) : null,
            'bloqueado' => isset($comQuotas[$a->id]),
        ]);
    }

    // ───────────── Auxiliares ─────────────

    private function proximoCodigo(): string
    {
        $empresa = $this->contexto->obrigatorio();
        do {
            $n = $this->numeracao->proximo($empresa, 'ativos:codigo', fn () => (int) DB::table('ativos_imobilizados')->where('empresa_id', $empresa)
                ->whereRaw("codigo ~ '^AST-[0-9]{1,5}$'")->selectRaw('MAX(substring(codigo from 5)::int) AS m')->value('m'));
            $codigo = 'AST-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT);
        } while (AtivoImobilizado::withTrashed()->where('codigo', $codigo)->exists());

        return $codigo;
    }

    private function dataImportada(mixed $v): string
    {
        if (is_numeric($v) && (float) $v > 0) {   // série do Excel (dias desde 1899-12-30)
            return now()->setDate(1899, 12, 30)->addDays((int) $v)->toDateString();
        }

        return $this->data($v) ?? now()->toDateString();
    }

    private function data(mixed $v): ?string
    {
        $v = trim((string) $v);
        if ($v === '') {
            return null;
        }
        $t = strtotime($v);
        if ($t === false) {
            throw new ErroNegocio("Data inválida: {$v}.", 'DADOS_INVALIDOS', 422);
        }

        return date('Y-m-d', $t);
    }

    private function igual(mixed $atual, mixed $novo): bool
    {
        if ($atual instanceof \DateTimeInterface) {
            $atual = $atual->format('Y-m-d');
        }
        if (is_numeric($atual) || is_numeric($novo)) {   // NULL e '' valem 0 (o legado deixava campos numéricos por preencher)
            return bccomp(is_numeric($atual) ? (string) $atual : '0', is_numeric($novo) ? (string) $novo : '0', 2) === 0;
        }

        return (string) ($atual ?? '') === (string) ($novo ?? '');
    }
}
