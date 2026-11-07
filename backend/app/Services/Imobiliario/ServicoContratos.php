<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\ContratoArrendamento;
use App\Models\ContratoEncargo;
use App\Models\ContratoFracao;
use App\Models\Fracao;
use App\Models\HistoricoContratoImo;
use App\Models\Imovel;
use App\Models\MovimentoCaucaoImo;
use App\Models\RendaArrendamento;
use App\Models\Terceiro;
use App\Services\Contabilidade\ServicoPlanoContas;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contratos de arrendamento (gravarContrato, activarContrato, terminarContrato, eliminarContrato e actualizar renda do legado).
 * O contrato prende-se sempre a fracções; só há um contrato vivo por fracção e lado nas mesmas datas (conflitos verificados com as
 * fracções bloqueadas, sem exigir `btree_gist`). Máquina de estados: RASCUNHO → ACTIVO ⇄ SUSPENSO → TERMINADO | DENUNCIADO, com
 * trilho em `historico_contrato_imo`. Activar marca as fracções «arrendada» (lado senhorio) e terminar liberta-as e anula as rendas
 * futuras; subarrendamentos dependem do contrato tomado e caducam com ele (cascata confirmada). Correcções (P3): suspender e
 * renovar passam a existir, o legado deixava SUSPENSO órfão e a renovação só avisava; terminar só parte de activo ou suspenso; a
 * renda do período de saída é proporcional; editar um contrato em vigor não muda tipo, âmbito, fracções, outra parte nem início; a
 * actualização manual da renda salta as rendas já documentadas em vez de rebentar a meio. A caução não se grava aqui (ledger em
 * ServicoCaucao) e quem a movimenta não termina o contrato (par de segregação do catálogo).
 */
final class ServicoContratos
{
    /** Fracções de estacionamento destas afectações nunca entram em contrato (visita, carga e descarga). */
    private const PARK_NAO_ARRENDAVEL = ['VISITA', 'CARGA_DESCARGA'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracaoImobiliario $numeracao,
        private readonly ServicoFracoes $fracoes,
        private readonly ServicoPlanoContas $plano,
        private readonly ServicoRendas $rendas,
        private readonly ServicoContaCorrente $contaCorrente,
        private readonly ServicoTaxasImobiliario $taxas,
        private readonly ServicoCaucao $caucao,
        private readonly ServicoAvisosContratos $avisos,
    ) {}

    // ---------------------------------------------------------------- leitura

    /**
     * @param  array{texto?: ?string, estado?: ?string, posicao?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $q = ContratoArrendamento::query()->orderByDesc('inicio')->orderByDesc('id')->limit(1000);
        if (! empty($f['estado'])) {
            $q->where('estado', $f['estado']);
        }
        if (! empty($f['posicao'])) {
            $q->where('posicao', $f['posicao']);
        }
        $texto = trim((string) ($f['texto'] ?? ''));
        if ($texto !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($texto)).'%';
            $q->where(fn ($w) => $w->whereRaw('lower(numero) like ?', [$like])
                ->orWhereIn('terceiro_id', Terceiro::query()->whereRaw('lower(nome) like ?', [$like])->select('id'))
                ->orWhereIn('imovel_id', Imovel::query()->whereRaw('lower(nome) like ? or lower(codigo) like ?', [$like, $like])->select('id'))
                ->orWhereIn('id', ContratoFracao::query()->whereIn('fracao_id', Fracao::query()->whereRaw('lower(designacao) like ?', [$like])->select('id'))->select('contrato_id')));
        }

        return $this->enriquecer($q->get());
    }

    /**
     * @param  Collection<int, ContratoArrendamento>  $contratos
     * @return list<array<string, mixed>>
     */
    private function enriquecer(Collection $contratos): array
    {
        if ($contratos->isEmpty()) {
            return [];
        }
        $terceiros = Terceiro::query()->whereIn('id', $contratos->pluck('terceiro_id')->unique())->pluck('nome', 'id');
        $imoveis = Imovel::query()->whereIn('id', $contratos->pluck('imovel_id')->unique())->get(['id', 'codigo', 'nome'])->keyBy('id');
        $ligacoes = ContratoFracao::query()->whereIn('contrato_id', $contratos->pluck('id'))->get(['contrato_id', 'fracao_id']);
        $designacoes = Fracao::query()->whereIn('id', $ligacoes->pluck('fracao_id')->unique())->pluck('designacao', 'id');
        $porContrato = $ligacoes->groupBy('contrato_id')->map(fn ($l) => $l->map(fn ($x) => $designacoes[$x->fracao_id] ?? '?')->values()->all());
        $pais = ContratoArrendamento::query()->whereIn('id', $contratos->pluck('contrato_pai_id')->filter()->unique())->pluck('numero', 'id');

        return $contratos->map(fn (ContratoArrendamento $c) => $c->attributesToArray() + [
            'terceiro_nome' => $terceiros[$c->terceiro_id] ?? null, 'imovel_codigo' => $imoveis[$c->imovel_id]->codigo ?? null, 'imovel_nome' => $imoveis[$c->imovel_id]->nome ?? null,
            'fracoes' => $porContrato[$c->id] ?? [], 'contrato_pai_numero' => $c->contrato_pai_id ? ($pais[$c->contrato_pai_id] ?? null) : null,
            'caucao_em_poder' => round((float) $c->caucao_recebida - (float) $c->caucao_devolvida - (float) $c->caucao_retida, 2),
        ])->values()->all();
    }

    /** @return array<string, mixed> o contrato com tudo o que o detalhe mostra */
    public function ficha(ContratoArrendamento $c): array
    {
        $base = $this->enriquecer(collect([$c]))[0];
        $encargos = ContratoEncargo::query()->where('contrato_id', $c->id)->orderBy('ordem')->get();
        $ligacoes = ContratoFracao::query()->where('contrato_id', $c->id)->pluck('fracao_id');
        $fracoes = Fracao::query()->whereIn('id', $ligacoes)->orderBy('ordem')->get(['id', 'codigo', 'designacao', 'estado', 'area_util', 'renda_referencia']);
        $terceiro = Terceiro::query()->find($c->terceiro_id, ['id', 'nome', 'nif']);
        $imovel = Imovel::query()->find($c->imovel_id, ['id', 'codigo', 'nome', 'papel', 'morada']);
        $historico = HistoricoContratoImo::query()->where('contrato_id', $c->id)->orderByDesc('em')->orderByDesc('id')->limit(200)->get();
        $filhos = ContratoArrendamento::query()->where('contrato_pai_id', $c->id)->orderBy('id')->get(['id', 'numero', 'estado', 'inicio', 'fim']);
        $periodo = now()->format('Y-m');
        $dados = ['renda' => $c->renda, 'actualizacao' => $c->actualizacao, 'actualizacao_valor' => $c->actualizacao_valor, 'actualizacao_meses' => $c->actualizacao_meses,
            'actualizacao_base' => $c->actualizacao_base?->format('Y-m-d'), 'inicio' => $c->inicio->format('Y-m-d')];
        $rendaHoje = CalculadoraActualizacao::rendaNoPeriodo($dados, $periodo);
        $fiscal = $this->rendas->montar($c, $encargos, $periodo);

        return $base + [
            'encargos' => $encargos->map(fn ($e) => $e->only(['id', 'designacao', 'valor', 'incluido_na_renda', 'origem', 'fracao_id']))->all(),
            'fracoes_detalhe' => $fracoes->map(fn ($f) => $f->only(['id', 'codigo', 'designacao', 'estado', 'area_util', 'renda_referencia']))->all(),
            'terceiro' => $terceiro?->only(['id', 'nome', 'nif']), 'imovel' => $imovel?->only(['id', 'codigo', 'nome', 'papel', 'morada']),
            'historico' => $historico->map(fn ($h) => $h->only(['id', 'evento', 'estado_de', 'estado_para', 'motivo', 'dados', 'utilizador_id', 'em']))->all(),
            'filhos' => $filhos->map(fn ($f) => $f->only(['id', 'numero', 'estado']))->all(),
            'renda_no_periodo' => (float) $rendaHoje, 'conta_corrente' => $this->contaCorrente->doContrato($c)['totais'],
            'fiscalidade' => ['periodo' => $periodo, 'base' => (float) $fiscal['base'], 'ip_valor' => (float) $fiscal['ip_valor'], 'ip_retido' => $fiscal['ip_retido'], 'selo_pct' => (float) $fiscal['selo_pct'],
                'selo_valor' => (float) $fiscal['selo_valor'], 'iva_taxa' => (float) $fiscal['iva_taxa'], 'iva_valor' => (float) $fiscal['iva_valor'],
                'total_facturar' => (float) $fiscal['total_facturar'], 'total_receber' => (float) $fiscal['total_receber'], 'ano' => (int) substr($periodo, 0, 4),
                'ip_efectivo' => $this->taxas->doAno((int) substr($periodo, 0, 4))['ip_efectivo']],
            'caucao_ledger' => $this->caucao->listar($c), 'avisos' => $this->avisos->doContrato($c),
        ];
    }

    /** @return array<string, mixed> os indicadores do módulo (contratos, rendas do mês, caução, dívida) */
    public function resumo(?string $hoje = null): array
    {
        $hoje ??= now()->toDateString();
        $periodo = substr($hoje, 0, 7);
        $contratos = ContratoArrendamento::query()->get(['id', 'posicao', 'estado', 'renda', 'periodicidade', 'caucao_recebida', 'caucao_devolvida', 'caucao_retida', 'fim', 'aviso_dias', 'mora_mensal']);
        $mensal = ['SENHORIO' => '0', 'INQUILINO' => '0'];
        $emPoder = ['SENHORIO' => '0', 'INQUILINO' => '0'];
        $aExpirar = 0;
        foreach ($contratos as $c) {
            $emPoder[$c->posicao] = bcadd($emPoder[$c->posicao], bcsub(bcsub(number_format((float) $c->caucao_recebida, 2, '.', ''), number_format((float) $c->caucao_devolvida, 2, '.', ''), 2), number_format((float) $c->caucao_retida, 2, '.', ''), 2), 2);
            if ($c->estado === 'ACTIVO') {
                $mensal[$c->posicao] = bcadd($mensal[$c->posicao], bcdiv(number_format((float) $c->renda, 2, '.', ''), (string) CalculadoraPeriodos::meses($c->periodicidade), 8), 8);
                if ($c->fim && CalculadoraPeriodos::diasEntre($hoje, $c->fim->format('Y-m-d')) >= 0 && CalculadoraPeriodos::diasEntre($hoje, $c->fim->format('Y-m-d')) <= max(1, (int) $c->aviso_dias ?: 60)) {
                    $aExpirar++;
                }
            }
        }
        $rendasDoMes = RendaArrendamento::query()->where('estado', '<>', 'ANULADA')->where('periodo', $periodo)->get(['posicao', 'estado', 'total_facturar', 'total_receber']);
        $soma = fn (string $posicao, ?string $estado, string $campo) => round($rendasDoMes->where('posicao', $posicao)->when($estado, fn ($x) => $x->where('estado', $estado))->sum($campo), 2);
        $vencidas = RendaArrendamento::query()->whereIn('estado', ['PREVISTA', 'APROVADA'])->where('vencimento', '<=', $hoje)
            ->whereIn('contrato_id', ContratoArrendamento::query()->where('estado', 'ACTIVO')->select('id'))->get(['posicao']);
        $facturadas = RendaArrendamento::query()->where('estado', 'FACTURADA')->where('vencimento', '<', $hoje)->get();
        $cc = $this->contaCorrente->linhas($facturadas, $contratos->keyBy('id'), $hoje);
        $emDivida = '0.00';
        $emAtraso = 0;
        $juros = '0.00';
        foreach ($cc as $l) {
            if ($l['divida'] > 0.009 && $l['atraso'] > 0) {
                $emAtraso++;
                $emDivida = bcadd($emDivida, number_format($l['divida'], 2, '.', ''), 2);
                $juros = bcadd($juros, number_format($l['juros'], 2, '.', ''), 2);
            }
        }
        $r2 = fn (string $v) => round((float) $v, 2);

        return ['contratos' => $contratos->count(), 'activos' => $contratos->where('estado', 'ACTIVO')->count(), 'renda_mensal' => $r2($mensal['SENHORIO']), 'renda_mensal_paga' => $r2($mensal['INQUILINO']),
            'margem_mensal' => $r2(bcsub($mensal['SENHORIO'], $mensal['INQUILINO'], 8)), 'caucoes' => $r2($emPoder['SENHORIO']), 'caucoes_entregues' => $r2($emPoder['INQUILINO']),
            'periodo' => $periodo, 'mes_previsto' => $soma('SENHORIO', null, 'total_facturar'), 'mes_facturado' => $soma('SENHORIO', 'FACTURADA', 'total_facturar'),
            'mes_a_pagar' => $soma('INQUILINO', null, 'total_receber'), 'mes_pago' => $soma('INQUILINO', 'PAGA', 'total_receber'),
            'por_facturar' => $vencidas->where('posicao', 'SENHORIO')->count(), 'por_pagar' => $vencidas->where('posicao', 'INQUILINO')->count(),
            'em_divida' => $r2($emDivida), 'rendas_em_atraso' => $emAtraso, 'juros_mora' => $r2($juros), 'a_expirar' => $aExpirar];
    }

    // ---------------------------------------------------------------- gravar

    /**
     * Cria ou edita um contrato (em rascunho cria-se sempre; um contrato em vigor não muda tipo, âmbito, fracções, outra parte nem início).
     *
     * @param  array<string, mixed>  $d
     */
    public function gravar(array $d, ?ContratoArrendamento $existente = null): ContratoArrendamento
    {
        return DB::transaction(function () use ($d, $existente) {
            $c = $existente ? ContratoArrendamento::query()->lockForUpdate()->findOrFail($existente->id) : null;
            if ($c && in_array($c->estado, ['TERMINADO', 'DENUNCIADO'], true)) {
                throw new ErroNegocio('Um contrato terminado não se edita: faça um novo.', 'IMO_CONTRATO_ENCERRADO');
            }
            $tipo = isset(CatalogoContratos::TIPOS[$d['tipo'] ?? '']) ? $d['tipo'] : 'ARRENDAMENTO';
            $posicao = $tipo === 'TOMADA' ? 'INQUILINO' : 'SENHORIO';
            $ambito = isset(CatalogoContratos::AMBITOS[$d['ambito'] ?? '']) ? $d['ambito'] : 'FRACCAO';
            [$imovel, $fracoes] = $this->resolverFracoes($d, $ambito);
            $terceiro = $this->resolverTerceiro($d, $posicao, $imovel);
            $inicio = Normaliza::texto($d['inicio'] ?? null) ?? throw new ErroNegocio('Indique a data de início.', 'IMO_INICIO');
            $fim = Normaliza::texto($d['fim'] ?? null);
            if ($fim !== null && $fim <= $inicio) {
                throw new ErroNegocio('A data de fim tem de ser posterior ao início.', 'IMO_FIM_ANTES_INICIO');
            }
            $renda = Normaliza::r2($d['renda'] ?? null);
            if ($renda <= 0) {
                throw new ErroNegocio('A renda tem de ser maior do que zero.', 'IMO_RENDA_ZERO');
            }
            $caucao = Normaliza::r2($d['caucao'] ?? null);
            if ($caucao > CatalogoContratos::CAUCAO_MAX_RENDAS * $renda + 0.001) {
                throw new ErroNegocio('A caução não pode passar de seis rendas.', 'IMO_CAUCAO_MAXIMA');
            }
            if ($c && $caucao + 0.001 < (float) $c->caucao_recebida) {
                throw new ErroNegocio('A caução acordada não pode ser inferior à já recebida ('.number_format((float) $c->caucao_recebida, 2, ',', ' ').' Kz).', 'IMO_CAUCAO_RECEBIDA');
            }
            $actualizacao = isset(CatalogoContratos::ACTUALIZACOES[$d['actualizacao'] ?? '']) ? $d['actualizacao'] : 'NAO';
            $actValor = (float) ($d['actualizacao_valor'] ?? 0);
            if ($actualizacao === 'PERC' && ($actValor <= 0 || $actValor > 100)) {
                throw new ErroNegocio('A percentagem de actualização tem de estar entre 0 e 100.', 'IMO_ACTUALIZACAO_PERC');
            }
            if ($actualizacao !== 'NAO' && $actValor <= 0) {
                throw new ErroNegocio('Indique o valor da actualização.', 'IMO_ACTUALIZACAO_VALOR');
            }
            $mora = array_key_exists('mora_mensal', $d) && $d['mora_mensal'] !== null && $d['mora_mensal'] !== '' ? (float) $d['mora_mensal'] : null;
            if ($mora !== null && ($mora < 0 || $mora > 100)) {
                throw new ErroNegocio('A taxa de mora mensal tem de estar entre 0 e 100.', 'IMO_MORA');
            }
            $pai = $this->resolverPai($d, $tipo, $imovel, $fracoes, $inicio, $fim);
            $contas = $this->resolverContas($d, $tipo, $imovel);
            if ($c && $c->estado !== 'RASCUNHO') {
                $mudou = $c->tipo !== $tipo || $c->ambito !== $ambito || $c->terceiro_id !== (int) $terceiro->id || $c->inicio->format('Y-m-d') !== $inicio
                    || $c->contrato_pai_id !== $pai?->id || $this->idsDasFracoes($c)->all() !== $fracoes->pluck('id')->sort()->values()->all();
                if ($mudou) {
                    throw new ErroNegocio('Num contrato em vigor não se mudam o tipo, o âmbito, as fracções, a outra parte nem o início: termine-o e faça um novo.', 'IMO_CONTRATO_EM_VIGOR');
                }
            }
            $this->exigirSemConflitos($fracoes, $posicao, $inicio, $fim, $c?->id);
            $dia = min(max(1, Normaliza::inteiro($d['dia_vencimento'] ?? null) ?: 1), 28);
            $dados = [
                'tipo' => $tipo, 'posicao' => $posicao, 'ambito' => $ambito, 'imovel_id' => $imovel->id, 'fracao_id' => $fracoes->first()->id, 'terceiro_id' => $terceiro->id,
                'contrato_pai_id' => $pai?->id, 'permite_sub' => $tipo === 'TOMADA' && ! empty($d['permite_sub']),
                'finalidade_fiscal' => isset(CatalogoContratos::FINALIDADES_FISCAIS[$d['finalidade_fiscal'] ?? '']) ? $d['finalidade_fiscal'] : 'HABITACAO',
                'inicio' => $inicio, 'fim' => $fim, 'periodicidade' => isset(CatalogoContratos::PERIODICIDADES[$d['periodicidade'] ?? '']) ? $d['periodicidade'] : 'MENSAL',
                'antecipada' => ! array_key_exists('antecipada', $d) || filter_var($d['antecipada'], FILTER_VALIDATE_BOOLEAN), 'dia_vencimento' => $dia, 'renda' => $renda,
                'pro_rata' => ! array_key_exists('pro_rata', $d) || filter_var($d['pro_rata'], FILTER_VALIDATE_BOOLEAN),
                'actualizacao' => $actualizacao, 'actualizacao_valor' => $actualizacao === 'NAO' ? 0 : $actValor, 'actualizacao_meses' => max(1, Normaliza::inteiro($d['actualizacao_meses'] ?? null) ?: 12),
                'caucao' => $caucao, 'retencao_ip' => ! array_key_exists('retencao_ip', $d) || filter_var($d['retencao_ip'], FILTER_VALIDATE_BOOLEAN),
                'isencao_ip' => isset(CatalogoContratos::ISENCOES[$d['isencao_ip'] ?? '']) ? $d['isencao_ip'] : 'NAO',
                'selo_encargo' => isset(CatalogoContratos::SELO_ENCARGO[$d['selo_encargo'] ?? '']) ? $d['selo_encargo'] : 'SENHORIO',
                'doc_tipo' => isset(CatalogoContratos::DOC_TIPOS[$d['doc_tipo'] ?? '']) ? $d['doc_tipo'] : 'FT',
                'renovacao' => isset(CatalogoContratos::RENOVACOES[$d['renovacao'] ?? '']) ? $d['renovacao'] : 'NAO', 'renovacao_meses' => max(1, Normaliza::inteiro($d['renovacao_meses'] ?? null) ?: 12),
                'aviso_dias' => max(0, Normaliza::inteiro($d['aviso_dias'] ?? null)), 'mora_mensal' => $mora, 'registo_agt_data' => $posicao === 'SENHORIO' ? Normaliza::texto($d['registo_agt_data'] ?? null) : null,
                'outra_parte_morada' => Normaliza::texto($d['outra_parte_morada'] ?? null), 'outra_parte_doc' => Normaliza::texto($d['outra_parte_doc'] ?? null),
                'outra_parte_contacto' => Normaliza::texto($d['outra_parte_contacto'] ?? null), 'notas' => Normaliza::texto($d['notas'] ?? null),
            ] + $contas;
            if ($c) {
                $c->update($dados + ['atualizado_por' => Autor::nome()]);
            } else {
                $c = ContratoArrendamento::create($dados + ['numero' => $this->numeracao->proximoNumeroContrato($this->contexto->obrigatorio(), $tipo), 'estado' => 'RASCUNHO',
                    'criado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]);
            }
            $this->gravarFracoes($c, $fracoes, $posicao, $inicio, $fim);
            $this->gravarEncargos($c, $d['encargos'] ?? [], $fracoes);
            $this->registar($c, $existente ? 'EDITADO' : 'CRIADO', null, $existente ? $c->estado : 'RASCUNHO', null);

            return $c->refresh();
        });
    }

    /** @param  array<string, mixed>  $d  @return array{0: Imovel, 1: Collection<int, Fracao>} as fracções bloqueadas, por id */
    private function resolverFracoes(array $d, string $ambito): array
    {
        if ($ambito === 'FRACCAO') {
            $id = Normaliza::inteiro($d['fracao_id'] ?? null);
            if ($id <= 0) {
                throw new ErroNegocio('Escolha a fracção. O contrato prende-se sempre a uma fracção, nunca ao imóvel.', 'IMO_FRACCAO_OBRIGATORIA');
            }
            $f = Fracao::query()->lockForUpdate()->find($id) ?? throw new ErroNegocio('Fracção não encontrada.', 'IMO_FRACCAO_INEXISTENTE', 404);
            $this->exigirArrendavel($f);

            return [Imovel::query()->findOrFail($f->imovel_id), collect([$f])];
        }
        $imovelId = Normaliza::inteiro($d['imovel_id'] ?? null);
        if ($imovelId <= 0) {
            throw new ErroNegocio('Escolha o imóvel do contrato.', 'IMO_IMOVEL_OBRIGATORIO');
        }
        $imovel = Imovel::query()->find($imovelId) ?? throw new ErroNegocio('Imóvel não encontrado.', 'IMO_IMOVEL_INEXISTENTE', 404);
        $todas = Fracao::query()->where('imovel_id', $imovel->id)->orderBy('id')->lockForUpdate()->get();
        if ($ambito === 'IMOVEL') {
            $lista = $todas->filter(fn ($f) => $this->arrendavel($f))->values();
            if ($lista->isEmpty()) {
                throw new ErroNegocio('O imóvel não tem fracções registadas. Crie-as (ou a fracção «Única») antes de o arrendar por inteiro.', 'IMO_IMOVEL_SEM_FRACCOES');
            }

            return [$imovel, $lista];
        }
        $ids = array_values(array_unique(array_map('intval', (array) ($d['fracoes_ids'] ?? []))));
        $escolhidas = $todas->whereIn('id', $ids)->values();
        if ($escolhidas->count() !== count($ids)) {
            throw new ErroNegocio('Há fracções escolhidas que não pertencem a este imóvel: um contrato não atravessa prédios.', 'IMO_FRACCOES_OUTRO_IMOVEL');
        }
        $escolhidas->each(fn ($f) => $this->exigirArrendavel($f));
        if ($escolhidas->count() < 2) {
            throw new ErroNegocio('Escolha pelo menos duas fracções, ou mude o âmbito para uma fracção só.', 'IMO_FRACCOES_POUCAS');
        }

        return [$imovel, $escolhidas];
    }

    private function arrendavel(Fracao $f): bool
    {
        return ! ($f->natureza === 'ESTACIONAMENTO' && in_array($f->park_afectacao, self::PARK_NAO_ARRENDAVEL, true));
    }

    private function exigirArrendavel(Fracao $f): void
    {
        if (! $this->arrendavel($f)) {
            throw new ErroNegocio("A fracção {$f->designacao} é uma vaga de visita ou de carga e descarga: não se arrenda.", 'IMO_FRACCAO_NAO_ARRENDAVEL');
        }
    }

    /** @param  array<string, mixed>  $d */
    private function resolverTerceiro(array $d, string $posicao, Imovel $imovel): Terceiro
    {
        $id = Normaliza::inteiro($d['terceiro_id'] ?? null);
        $falta = $posicao === 'SENHORIO' ? 'Indique o inquilino.' : 'Indique o senhorio a quem a empresa paga.';
        if ($id <= 0) {
            throw new ErroNegocio($falta, 'IMO_TERCEIRO_OBRIGATORIO');
        }
        $t = Terceiro::query()->find($id) ?? throw new ErroNegocio($falta, 'IMO_TERCEIRO_OBRIGATORIO');
        if ($posicao === 'SENHORIO' && $imovel->proprietario_terceiro_id !== null && (int) $imovel->proprietario_terceiro_id === (int) $t->id) {
            throw new ErroNegocio('O inquilino não pode ser o proprietário do imóvel.', 'IMO_INQUILINO_PROPRIETARIO');
        }

        return $t;
    }

    /**
     * O contrato tomado de que um subarrendamento depende, com as regras do legado (autorização, fracções cobertas, datas).
     *
     * @param  Collection<int, Fracao>  $fracoes
     */
    private function resolverPai(array $d, string $tipo, Imovel $imovel, Collection $fracoes, string $inicio, ?string $fim): ?ContratoArrendamento
    {
        if ($tipo === 'ARRENDAMENTO' && $imovel->papel === 'MEDIACAO') {
            throw new ErroNegocio('Um imóvel em mediação não se arrenda em nome da empresa — registe-o como próprio ou arrendado.', 'IMO_IMOVEL_MEDIACAO');
        }
        if ($tipo === 'TOMADA' && $imovel->papel !== 'ARRENDADO') {
            throw new ErroNegocio('Um contrato tomado é o que torna o imóvel arrendado: ponha o papel do imóvel em «Arrendado».', 'IMO_IMOVEL_PAPEL');
        }
        if ($tipo !== 'SUBARRENDAMENTO') {
            return null;
        }
        if ($imovel->papel !== 'ARRENDADO') {
            throw new ErroNegocio('Só se subarrenda o que a empresa arrendou: o imóvel tem de ter o papel «Arrendado».', 'IMO_IMOVEL_PAPEL');
        }
        $tomadas = ContratoArrendamento::query()->where('tipo', 'TOMADA')->whereIn('estado', ContratoArrendamento::ESTADOS_VIVOS)
            ->whereIn('id', ContratoFracao::query()->where('fracao_id', $fracoes->first()->id)->where('posicao', 'INQUILINO')->select('contrato_id'))->orderBy('id')->get();
        if ($tomadas->isEmpty()) {
            throw new ErroNegocio('A fracção não tem contrato tomado: registe primeiro o arrendamento em que a empresa é inquilina.', 'IMO_SEM_TOMADA');
        }
        $paiId = Normaliza::inteiro($d['contrato_pai_id'] ?? null);
        $pai = $paiId > 0 ? $tomadas->firstWhere('id', $paiId) : ($tomadas->count() === 1 ? $tomadas->first() : null);
        if (! $pai) {
            throw new ErroNegocio('Escolha o contrato tomado de que este subarrendamento depende.', 'IMO_PAI_OBRIGATORIO');
        }
        if (! $pai->permite_sub) {
            throw new ErroNegocio("O contrato {$pai->numero} não autoriza subarrendar. Sem autorização escrita do senhorio, o subarrendamento é causa de resolução.", 'IMO_PAI_SEM_AUTORIZACAO');
        }
        $cobertas = ContratoFracao::query()->where('contrato_id', $pai->id)->pluck('fracao_id')->all();
        $naoCobertas = $fracoes->filter(fn ($f) => ! in_array($f->id, $cobertas, true));
        if ($naoCobertas->isNotEmpty()) {
            throw new ErroNegocio("O contrato {$pai->numero} não abrange ".$naoCobertas->pluck('designacao')->implode(', ').': a empresa não pode subarrendar o que não tomou.', 'IMO_FRACCOES_NAO_COBERTAS');
        }
        if ($inicio < $pai->inicio->format('Y-m-d')) {
            throw new ErroNegocio("O subarrendamento não pode começar antes do contrato {$pai->numero}, que só produz efeitos a partir de {$pai->inicio->format('Y-m-d')}.", 'IMO_SUB_ANTES_PAI');
        }
        $fimPai = ServicoRendas::fimEfectivo($pai);
        if ($fimPai !== null && ($fim === null || $fim > $fimPai)) {
            throw new ErroNegocio("O subarrendamento caduca com o contrato {$pai->numero}, que termina em {$fimPai}: não pode ir além dessa data nem ficar sem termo.", 'IMO_SUB_ALEM_PAI');
        }

        return $pai;
    }

    /** @param  array<string, mixed>  $d  @return array<string, ?string> as contas do contrato tomado (vazias nos outros) */
    private function resolverContas(array $d, string $tipo, Imovel $imovel): array
    {
        $vazio = ['conta_custo' => null, 'conta_retencao' => null, 'conta_financeira' => null];
        if ($tipo !== 'TOMADA') {
            return $vazio;
        }
        $custo = Normaliza::texto($d['conta_custo'] ?? null) ?? throw new ErroNegocio('Indique a conta de custo da renda (o ERP não pré-preenche contas).', 'IMO_CONTA_CUSTO');
        $retem = ! array_key_exists('retencao_ip', $d) || filter_var($d['retencao_ip'], FILTER_VALIDATE_BOOLEAN);
        $ret = Normaliza::texto($d['conta_retencao'] ?? null);
        if ($retem && $ret === null) {
            throw new ErroNegocio('A empresa retém o Imposto Predial ao senhorio: indique a conta onde a retenção fica a aguardar entrega à AGT.', 'IMO_CONTA_RETENCAO');
        }
        $fin = Normaliza::texto($d['conta_financeira'] ?? null);
        foreach ([$custo, $ret, $fin] as $conta) {
            if ($conta !== null) {
                $this->plano->contaDeMovimento($conta);
            }
        }

        return ['conta_custo' => $custo, 'conta_retencao' => $retem ? $ret : null, 'conta_financeira' => $fin];
    }

    /** @param  Collection<int, Fracao>  $fracoes */
    private function exigirSemConflitos(Collection $fracoes, string $posicao, string $inicio, ?string $fim, ?int $excluir): void
    {
        $linhas = DB::table('contrato_fracoes as cf')->join('contratos_arrendamento as c', 'c.id', '=', 'cf.contrato_id')->join('fracoes as f', 'f.id', '=', 'cf.fracao_id')
            ->where('cf.empresa_id', $this->contexto->obrigatorio())->whereIn('cf.fracao_id', $fracoes->pluck('id'))->where('cf.posicao', $posicao)
            ->whereIn('c.estado', ContratoArrendamento::ESTADOS_VIVOS)->where('c.inicio', '<=', $fim ?? '9999-12-31')
            ->whereRaw("coalesce(c.fim_real, c.fim, date '9999-12-31') >= ?", [$inicio])->when($excluir, fn ($q) => $q->where('c.id', '<>', $excluir))
            ->orderBy('c.id')->get(['c.id', 'c.numero', 'c.estado', 'c.inicio', 'c.fim', 'f.designacao']);
        if ($linhas->isEmpty()) {
            return;
        }
        $c = $linhas->first();
        $nomes = $linhas->where('id', $c->id)->pluck('designacao')->implode(', ');
        $plural = $linhas->where('id', $c->id)->count() > 1;
        throw new ErroNegocio(($plural ? 'As fracções ' : 'A fracção ')."{$nomes} ".($plural ? 'estão' : 'está')." no contrato {$c->numero} (".CatalogoContratos::ESTADOS_CONTRATO[$c->estado].") de {$c->inicio} a ".($c->fim ?? 'sem termo').'.',
            'IMO_CONFLITO_DATAS', 422, ['contrato' => $c->numero]);
    }

    /** @param  Collection<int, Fracao>  $fracoes */
    private function gravarFracoes(ContratoArrendamento $c, Collection $fracoes, string $posicao, string $inicio, ?string $fim): void
    {
        ContratoFracao::query()->where('contrato_id', $c->id)->delete();
        foreach ($fracoes as $f) {
            ContratoFracao::create(['contrato_id' => $c->id, 'fracao_id' => $f->id, 'posicao' => $posicao, 'inicio' => $inicio, 'fim' => $fim]);
        }
    }

    /** @param  array<int, mixed>  $lista  @param  Collection<int, Fracao>  $fracoes */
    private function gravarEncargos(ContratoArrendamento $c, array $lista, Collection $fracoes): void
    {
        ContratoEncargo::query()->where('contrato_id', $c->id)->delete();
        $ordem = 0;
        foreach ($lista as $e) {
            $designacao = Normaliza::texto($e['designacao'] ?? null);
            $valor = Normaliza::r2($e['valor'] ?? null);
            $incluido = filter_var($e['incluido_na_renda'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($designacao === null || ($valor <= 0 && ! $incluido)) {
                continue;
            }
            $fracaoId = Normaliza::inteiro($e['fracao_id'] ?? null, true);
            ContratoEncargo::create(['contrato_id' => $c->id, 'designacao' => mb_substr($designacao, 0, 150), 'valor' => max(0, $valor), 'incluido_na_renda' => $incluido,
                'origem' => ($e['origem'] ?? 'MANUAL') === 'PARQUE' ? 'PARQUE' : 'MANUAL', 'fracao_id' => $fracaoId && $fracoes->contains('id', $fracaoId) ? $fracaoId : null, 'ordem' => $ordem++]);
        }
    }

    /** @return Collection<int, int> */
    private function idsDasFracoes(ContratoArrendamento $c): Collection
    {
        return ContratoFracao::query()->where('contrato_id', $c->id)->pluck('fracao_id')->map(fn ($i) => (int) $i)->sort()->values();
    }

    /** @param  array<string, mixed>|null  $dados */
    private function registar(ContratoArrendamento $c, string $evento, ?string $de, ?string $para, ?string $motivo, ?array $dados = null): void
    {
        HistoricoContratoImo::create(['contrato_id' => $c->id, 'evento' => $evento, 'estado_de' => $de, 'estado_para' => $para, 'motivo' => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
            'dados' => $dados, 'utilizador_id' => Autor::id()]);
    }

    // ---------------------------------------------------------------- estados

    /** Activa um contrato em rascunho (ou reactiva um suspenso): marca as fracções «arrendada» (lado senhorio) e passa a gerar rendas. */
    public function activar(ContratoArrendamento $contrato): ContratoArrendamento
    {
        return DB::transaction(function () use ($contrato) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if (in_array($c->estado, ['TERMINADO', 'DENUNCIADO'], true)) {
                throw new ErroNegocio('Um contrato terminado não se reactiva — faça um novo.', 'IMO_CONTRATO_ENCERRADO');
            }
            if ($c->estado === 'ACTIVO') {
                throw new ErroNegocio('O contrato já está activo.', 'IMO_CONTRATO_JA_ACTIVO');
            }
            if ($c->contrato_pai_id) {
                $pai = ContratoArrendamento::query()->find($c->contrato_pai_id) ?? throw new ErroNegocio('O contrato tomado de que este depende já não existe.', 'IMO_PAI_INEXISTENTE');
                if ($pai->estado !== 'ACTIVO') {
                    throw new ErroNegocio("O contrato {$pai->numero}, de que este subarrendamento depende, está em «".CatalogoContratos::ESTADOS_CONTRATO[$pai->estado].'». Active-o primeiro.', 'IMO_PAI_NAO_ACTIVO');
                }
            }
            $fracoes = Fracao::query()->whereIn('id', $this->idsDasFracoes($c))->orderBy('id')->lockForUpdate()->get();
            $this->exigirSemConflitos($fracoes, $c->posicao, $c->inicio->format('Y-m-d'), $c->fim?->format('Y-m-d'), $c->id);
            if ($c->posicao === 'SENHORIO') {
                foreach ($fracoes as $f) {
                    if ($f->estado === 'VENDIDA') {
                        throw new ErroNegocio("A fracção {$f->designacao} está vendida: não se arrenda.", 'IMO_FRACCAO_VENDIDA');
                    }
                }
                foreach ($fracoes as $f) {
                    if ($f->estado !== 'ARRENDADA') {
                        $this->fracoes->mudarEstado($f, 'ARRENDADA', "Contrato {$c->numero} activado");
                    }
                }
            }
            $de = $c->estado;
            $c->update(['estado' => 'ACTIVO', 'atualizado_por' => Autor::nome()]);
            $this->sincronizarActivo($c);
            $this->registar($c, $de === 'SUSPENSO' ? 'REACTIVADO' : 'ACTIVADO', $de, 'ACTIVO', null);

            return $c->refresh();
        });
    }

    public function suspender(ContratoArrendamento $contrato, ?string $motivo): ContratoArrendamento
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo da suspensão.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(function () use ($contrato, $motivo) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if ($c->estado !== 'ACTIVO') {
                throw new ErroNegocio('Só se suspende um contrato activo.', 'IMO_CONTRATO_NAO_ACTIVO');
            }
            $c->update(['estado' => 'SUSPENSO', 'atualizado_por' => Autor::nome()]);
            $this->registar($c, 'SUSPENSO', 'ACTIVO', 'SUSPENSO', $motivo);

            return $c->refresh();
        });
    }

    /**
     * Termina (termo) ou denuncia um contrato activo ou suspenso. Anula as rendas futuras ainda não documentadas, acerta a renda do
     * período de saída (pro-rata), liberta as fracções e, com `cascata`, faz caducar os subarrendamentos que dele dependem.
     *
     * @param  array{data?: ?string, motivo?: ?string, denuncia?: bool, cascata?: bool}  $o
     * @return array{contrato: ContratoArrendamento, rendas_anuladas: int, rendas_acertadas: int, filhos_terminados: int, caucao_em_poder: float}
     */
    public function terminar(ContratoArrendamento $contrato, array $o): array
    {
        $data = Normaliza::texto($o['data'] ?? null) ?? now()->toDateString();
        $motivo = Normaliza::texto($o['motivo'] ?? null) ?? throw new ErroNegocio('Indique o motivo da saída.', 'IMO_MOTIVO_OBRIGATORIO');

        return DB::transaction(fn () => $this->terminarInterno($contrato->id, $data, $motivo, ! empty($o['denuncia']), ! empty($o['cascata'])));
    }

    /** @return array{contrato: ContratoArrendamento, rendas_anuladas: int, rendas_acertadas: int, filhos_terminados: int, caucao_em_poder: float} */
    private function terminarInterno(int $id, string $data, string $motivo, bool $denuncia, bool $cascata): array
    {
        $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($id);
        if (! in_array($c->estado, ['ACTIVO', 'SUSPENSO'], true)) {
            throw new ErroNegocio('Só se termina ou denuncia um contrato activo ou suspenso.', 'IMO_CONTRATO_NAO_ACTIVO');
        }
        if ($data < $c->inicio->format('Y-m-d')) {
            throw new ErroNegocio('A data de saída não pode ser anterior ao início do contrato.', 'IMO_SAIDA_ANTES_INICIO');
        }
        $filhos = ContratoArrendamento::query()->where('contrato_pai_id', $c->id)->whereIn('estado', ContratoArrendamento::ESTADOS_VIVOS)->orderBy('id')->get(['id', 'numero']);
        if ($filhos->isNotEmpty() && ! $cascata) {
            throw new ErroNegocio("O contrato {$c->numero} dá título a {$filhos->count()} subarrendamento(s) (".$filhos->pluck('numero')->implode(', ').'), que caducam com ele. Confirme a cessação em cascata.', 'IMO_CASCATA_NECESSARIA',
                422, ['subarrendamentos' => $filhos->pluck('numero')->all()]);
        }
        $estado = $denuncia ? 'DENUNCIADO' : 'TERMINADO';
        $de = $c->estado;
        $c->update(['estado' => $estado, 'fim_real' => $data, 'motivo_saida' => mb_substr($motivo, 0, 300), 'atualizado_por' => Autor::nome()]);
        ContratoFracao::query()->where('contrato_id', $c->id)->update(['fim' => $data]);

        $anuladas = 0;
        $acertadas = 0;
        $nota = " · anulada pela saída em {$data}";
        $c = $c->refresh();
        foreach (RendaArrendamento::query()->where('contrato_id', $c->id)->whereIn('estado', ['PREVISTA', 'APROVADA'])->orderBy('periodo')->get() as $r) {
            if ($r->de->format('Y-m-d') > $data) {
                $r->update(['estado' => 'ANULADA', 'notas' => trim(($r->notas ?? '').$nota, ' ·'), 'atualizado_por' => Autor::nome()]);
                $anuladas++;
            } elseif ($c->pro_rata && $r->ate->format('Y-m-d') > $data) {
                $this->rendas->recalcular($r);   // o período em que a saída cai passa a ser proporcional
                $acertadas++;
            }
        }
        if ($c->posicao === 'SENHORIO') {
            foreach (Fracao::query()->whereIn('id', $this->idsDasFracoes($c))->orderBy('id')->lockForUpdate()->get() as $f) {
                if ($f->estado === 'ARRENDADA' && ! $this->temOutroContratoVivo($f->id, $c->id)) {
                    $this->fracoes->mudarEstado($f, 'DISPONIVEL', "Contrato {$c->numero} terminou em {$data}");
                }
            }
        }
        $this->sincronizarActivo($c);
        $this->registar($c, $denuncia ? 'DENUNCIADO' : 'TERMINADO', $de, $estado, $motivo, ['data' => $data, 'rendas_anuladas' => $anuladas]);

        $terminados = 0;
        foreach ($filhos as $filho) {
            $r = $this->terminarInterno($filho->id, $data, "Caducidade: o contrato {$c->numero}, de que depende, cessou em {$data} ({$motivo}).", false, true);
            $terminados += 1 + $r['filhos_terminados'];
            $anuladas += $r['rendas_anuladas'];
            $acertadas += $r['rendas_acertadas'];
        }

        return ['contrato' => $c->refresh(), 'rendas_anuladas' => $anuladas, 'rendas_acertadas' => $acertadas, 'filhos_terminados' => $terminados,
            'caucao_em_poder' => round((float) $c->caucao_recebida - (float) $c->caucao_devolvida - (float) $c->caucao_retida, 2)];
    }

    private function temOutroContratoVivo(int $fracaoId, int $excluir): bool
    {
        return ContratoFracao::query()->where('fracao_id', $fracaoId)->where('posicao', 'SENHORIO')->where('contrato_id', '<>', $excluir)
            ->whereIn('contrato_id', ContratoArrendamento::query()->whereIn('estado', ['ACTIVO', 'SUSPENSO'])->select('id'))->exists();
    }

    /** Prolonga o termo de um contrato activo (renovação). Sem `meses` usa o período de renovação do contrato. */
    public function renovar(ContratoArrendamento $contrato, ?int $meses = null): ContratoArrendamento
    {
        return DB::transaction(function () use ($contrato, $meses) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if ($c->estado !== 'ACTIVO') {
                throw new ErroNegocio('Só se renova um contrato activo.', 'IMO_CONTRATO_NAO_ACTIVO');
            }
            if ($c->fim === null) {
                throw new ErroNegocio('O contrato não tem termo: não há nada a renovar.', 'IMO_SEM_TERMO');
            }
            $meses = max(1, $meses ?: (int) $c->renovacao_meses);
            $fimAntigo = $c->fim->format('Y-m-d');
            $fimNovo = CalculadoraPeriodos::somarMesesData($fimAntigo, $meses);
            if ($c->contrato_pai_id) {
                $pai = ContratoArrendamento::query()->find($c->contrato_pai_id);
                $fimPai = $pai ? ServicoRendas::fimEfectivo($pai) : null;
                if ($fimPai !== null && $fimNovo > $fimPai) {
                    throw new ErroNegocio("O subarrendamento caduca com o contrato {$pai->numero}, que termina em {$fimPai}: não pode ir além dessa data.", 'IMO_SUB_ALEM_PAI');
                }
            }
            $fracoes = Fracao::query()->whereIn('id', $this->idsDasFracoes($c))->orderBy('id')->lockForUpdate()->get();
            $this->exigirSemConflitos($fracoes, $c->posicao, $c->inicio->format('Y-m-d'), $fimNovo, $c->id);
            $c->update(['fim' => $fimNovo, 'atualizado_por' => Autor::nome()]);
            ContratoFracao::query()->where('contrato_id', $c->id)->update(['fim' => $fimNovo]);
            if ($c->pro_rata) {   // a renda do período em que o termo antigo caía deixa de ser proporcional
                foreach (RendaArrendamento::query()->where('contrato_id', $c->id)->whereIn('estado', ['PREVISTA', 'APROVADA'])->where('de', '<=', $fimAntigo)->where('ate', '>=', $fimAntigo)->get() as $r) {
                    $this->rendas->recalcular($r);
                }
            }
            $this->sincronizarActivo($c);
            $this->registar($c, 'RENOVADO', 'ACTIVO', 'ACTIVO', null, ['fim_anterior' => $fimAntigo, 'fim_novo' => $fimNovo, 'meses' => $meses]);

            return $c->refresh();
        });
    }

    /**
     * Acordo de nova renda a partir de uma data. Regista a anterior, passa a contar a indexação a partir daí e, se pedido, recalcula as
     * rendas ainda não documentadas desde essa data (as já facturadas ou pagas ficam como estão, sem rebentar a meio como no legado).
     *
     * @return array{contrato: ContratoArrendamento, recalculadas: int}
     */
    public function actualizarRenda(ContratoArrendamento $contrato, float|string $rendaNova, string $dataEfeito, bool $recalcular = true): array
    {
        if (! is_numeric($rendaNova) || (float) $rendaNova <= 0) {
            throw new ErroNegocio('A renda nova tem de ser maior do que zero.', 'IMO_RENDA_ZERO');
        }

        return DB::transaction(function () use ($contrato, $rendaNova, $dataEfeito, $recalcular) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if ($c->estado !== 'ACTIVO') {
                throw new ErroNegocio('Só se actualiza a renda de um contrato activo.', 'IMO_CONTRATO_NAO_ACTIVO');
            }
            $nova = round((float) $rendaNova, 2);
            $antiga = (float) $c->renda;
            $c->update(['renda' => $nova, 'renda_anterior' => $antiga, 'ultima_actualizacao' => $dataEfeito, 'actualizacao_base' => $dataEfeito, 'atualizado_por' => Autor::nome()]);
            $n = 0;
            if ($recalcular) {
                foreach (RendaArrendamento::query()->where('contrato_id', $c->id)->whereIn('estado', ['PREVISTA', 'APROVADA'])->where('de', '>=', $dataEfeito)->orderBy('periodo')->get() as $r) {
                    $this->rendas->recalcular($r);
                    $n++;
                }
            }
            $this->sincronizarActivo($c->refresh());
            $this->registar($c, 'RENDA_ACTUALIZADA', 'ACTIVO', 'ACTIVO', null, ['renda_anterior' => $antiga, 'renda_nova' => $nova, 'data_efeito' => $dataEfeito, 'recalculadas' => $n]);

            return ['contrato' => $c->refresh(), 'recalculadas' => $n];
        });
    }

    public function eliminar(ContratoArrendamento $contrato): void
    {
        DB::transaction(function () use ($contrato) {
            $c = ContratoArrendamento::query()->lockForUpdate()->findOrFail($contrato->id);
            if (in_array($c->estado, ['ACTIVO', 'SUSPENSO'], true)) {
                throw new ErroNegocio('O contrato está em vigor: termine-o ou denuncie-o (com data e motivo) em vez de o apagar.', 'IMO_CONTRATO_EM_VIGOR');
            }
            $filhos = ContratoArrendamento::query()->where('contrato_pai_id', $c->id)->pluck('numero');
            if ($filhos->isNotEmpty()) {
                throw new ErroNegocio("O contrato dá título a {$filhos->count()} subarrendamento(s) (".$filhos->implode(', ').') — apague-os primeiro.', 'IMO_CONTRATO_COM_FILHOS');
            }
            $docs = RendaArrendamento::query()->where('contrato_id', $c->id)->whereIn('estado', RendaArrendamento::EM_DOCUMENTO)->count();
            if ($docs > 0) {
                throw new ErroNegocio("O contrato tem {$docs} renda(s) já em documento — não se apaga.", 'IMO_CONTRATO_COM_DOCUMENTOS');
            }
            if (MovimentoCaucaoImo::query()->where('contrato_id', $c->id)->exists()) {
                throw new ErroNegocio('O contrato tem movimentos de caução contabilizados — não se apaga.', 'IMO_CONTRATO_COM_CAUCAO');
            }
            RendaArrendamento::query()->where('contrato_id', $c->id)->delete();
            $c->delete();
        });
    }

    /**
     * O contrato tomado é a fonte do bem alheio do Activos (regime arrendado): copia a referência, as datas e a renda mensal (nunca o
     * contrário). Só se o imóvel está ligado a um edifício alheio em regime arrendado.
     */
    private function sincronizarActivo(ContratoArrendamento $c): void
    {
        if ($c->tipo !== 'TOMADA') {
            return;
        }
        $edificio = Imovel::query()->where('id', $c->imovel_id)->value('ativo_edificio_id');
        $ativo = $edificio ? AtivoImobilizado::query()->find($edificio) : null;
        if (! $ativo || $ativo->propriedade !== 'ALHEIO' || $ativo->regime_alheio !== 'ARRENDADO') {
            return;
        }
        $vivo = in_array($c->estado, ['ACTIVO', 'SUSPENSO'], true);
        $ativo->update(['contrato_referencia' => $c->numero, 'contrato_inicio' => $c->inicio->format('Y-m-d'), 'contrato_fim' => ServicoRendas::fimEfectivo($c),
            'renda_mensal' => $vivo ? round((float) $c->renda / CalculadoraPeriodos::meses($c->periodicidade), 2) : $ativo->renda_mensal]);
    }
}
