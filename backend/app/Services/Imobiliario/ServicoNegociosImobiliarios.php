<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AmortizacaoAtivo;
use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\ContratoArrendamento;
use App\Models\ContratoFracao;
use App\Models\CustoNegocioImo;
use App\Models\Fracao;
use App\Models\HistoricoNegocioImo;
use App\Models\Imovel;
use App\Models\NegocioImobiliario;
use App\Models\PagamentoNegocioImo;
use App\Models\Terceiro;
use App\Models\Venda;
use App\Services\Ativos\ServicoAbatesAtivos;
use App\Services\Ativos\ServicoAtivos;
use App\Services\Vendas\ServicoDocumentosVenda;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compra e venda de imóveis e fracções (TR do legado, sobre o ERP novo). Um negócio é um processo: negociação → promessa → escritura → registo → concluído
 * (ou cancelado antes da escritura). A escritura transmite a propriedade e fixa os impostos da transmissão (taxas do ano da escritura, de
 * `taxas_imobiliario`, «por confirmar»); antes dela recalculam-se a cada gravação. Corrige o legado em: aprovação do negócio (sem ela não se avança nem
 * se paga), pagamentos com registo e anulação (o legado não os tinha), um só negócio aberto por imóvel ou fracção e tipo, preço e partes bloqueados depois
 * da escritura, numeração atómica, conclusão transaccional e repetível sem efeitos duplicados, contratos tratados também na venda de um imóvel inteiro,
 * recusa de concluir a venda com contratos ainda em vigor, e contabilização equilibrada da compra e da alienação (P4).
 *
 *  - COMPRA concluída: o imóvel passa a próprio, o bem é criado em Activos (ou, se o imóvel já está ligado a um bem, liga-se a ele sem o duplicar nem o
 *    lançar de novo) e a aquisição lança-se ao custo (preço + impostos + encargos capitalizáveis).
 *  - VENDA concluída (imo_neg_concluir): factura em Vendas (ServicoDocumentosVenda, IVA 0 %), fracções VENDIDA com trilho, imóvel ALIENADO quando nada resta,
 *    resultado apurado. O ABATE do bem e o lançamento da alienação (mais ou menos-valia) são um passo à parte (imo_neg_abater): quem conclui a venda
 *    não abate o bem.
 *  - A venda de uma fracção com contrato em vigor exige uma decisão (transmitir → termina o contrato em cascata pelo ServicoContratos::terminar; manter);
 *    concluir com contratos ainda vivos recusa-se.
 */
final class ServicoNegociosImobiliarios
{
    public const CAMPOS_FIXOS_NA_ESCRITURA = ['tipo', 'imovel_id', 'fracao_id', 'terceiro_id', 'preco', 'vpt', 'sinal', 'finalidade', 'isencao'];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoTaxasImobiliario $taxas,
        private readonly ServicoNumeracaoImobiliario $numeracao,
        private readonly ServicoFracoes $fracoes,
        private readonly ServicoContratos $contratos,
        private readonly ServicoDocumentosVenda $vendas,
        private readonly ServicoConfiguracaoImobiliario $config,
        private readonly ServicoContabilizacaoNegocios $contab,
        private readonly ServicoAtivos $ativos,
        private readonly ServicoAbatesAtivos $abates,
    ) {}

    // ---------------------------------------------------------------- leitura

    /**
     * @param  array{tipo?: ?string, estado?: ?string, abertos?: mixed, imovel_id?: ?int, fracao_id?: ?int, texto?: ?string}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = []): array
    {
        $q = NegocioImobiliario::query();
        foreach (['tipo', 'estado', 'imovel_id', 'fracao_id'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['abertos'])) {
            $q->whereNotIn('estado', NegocioImobiliario::FECHADOS);
        }
        $linhas = $q->orderByRaw('coalesce(data_escritura, data_promessa) desc nulls last')->orderByDesc('id')->limit(1000)->get();
        $nomes = $this->nomes($linhas);
        $pagos = $this->pagoPorNegocio($linhas->pluck('id')->all());
        $texto = isset($f['texto']) ? mb_strtolower(trim((string) $f['texto'])) : '';
        $r = [];
        foreach ($linhas as $n) {
            $linha = $n->attributesToArray() + $this->enriquecer($n, $nomes) + ['pago_total' => (float) ($pagos[$n->id] ?? '0.00')];
            if ($texto !== '' && ! str_contains(mb_strtolower($linha['numero'].' '.$linha['imovel_nome'].' '.$linha['fracao_designacao'].' '.$linha['terceiro_nome']), $texto)) {
                continue;
            }
            $r[] = $linha;
        }

        return $r;
    }

    /** @return array<string, mixed> */
    public function ficha(NegocioImobiliario $n): array
    {
        $nomes = $this->nomes(collect([$n]));
        $custos = CustoNegocioImo::query()->where('negocio_id', $n->id)->orderBy('ordem')->orderBy('id')->get()->map(fn ($c) => $c->attributesToArray())->all();
        $pagamentos = PagamentoNegocioImo::query()->where('negocio_id', $n->id)->orderBy('data')->orderBy('id')->get()->map(fn ($p) => $p->attributesToArray())->all();
        $historico = HistoricoNegocioImo::query()->where('negocio_id', $n->id)->orderBy('em')->orderBy('id')->get(['evento', 'estado_de', 'estado_para', 'motivo', 'em'])->map(fn ($h) => $h->attributesToArray())->all();
        $pago = $this->pagoPorNegocio([$n->id])[$n->id] ?? '0.00';
        $seguinte = $this->fasesSeguintes($n);
        $vivos = $n->tipo === 'VENDA' && ! $n->fechado() ? $this->contratosVivos($n)->map(fn ($c) => ['id' => (int) $c->id, 'numero' => $c->numero, 'fim' => $c->fim?->format('Y-m-d'), 'renda' => (float) $c->renda, 'estado' => $c->estado])->all() : [];
        $ficha = $n->attributesToArray() + $this->enriquecer($n, $nomes) + [
            'aprovado' => $n->aprovado_em !== null, 'custos' => $custos, 'pagamentos' => $pagamentos, 'historico' => $historico, 'pago_total' => (float) $pago,
            'por_liquidar' => round(max(0, (float) $n->preco - (float) $pago), 2), 'fases_seguintes' => $seguinte, 'contratos_em_vigor' => $vivos,
            'custo_aquisicao_calculado' => $n->tipo === 'COMPRA' ? $this->custoAquisicao($n) : null, 'resultado_venda' => null, 'faltas' => [],
        ];
        if (! $n->fechado() && $seguinte !== []) {
            $ficha['faltas'] = $this->faltas($n, $seguinte[0]);
        }
        if ($n->tipo === 'VENDA' && $n->abatido_em === null) {
            $ficha['resultado_venda'] = $this->resultadoVenda($n);
        }

        return $ficha;
    }

    /** @return list<string> as fases para onde se pode avançar (a imediata primeiro; a promessa pode ser saltada) */
    private function fasesSeguintes(NegocioImobiliario $n): array
    {
        if ($n->fechado()) {
            return [];
        }

        return array_values(array_filter(['PROMESSA', 'ESCRITURA', 'REGISTADO'], fn ($f) => CalculadoraNegocios::FASES[$f]['ordem'] > $n->ordem()));
    }

    /** @return array<string, mixed> */
    public function custoAquisicao(NegocioImobiliario $n): array
    {
        $custos = CustoNegocioImo::query()->where('negocio_id', $n->id)->get(['valor', 'capitaliza'])->map(fn ($c) => ['valor' => $c->valor, 'capitaliza' => (bool) $c->capitaliza])->all();

        return CalculadoraNegocios::custoAquisicao($n->preco, $n->total_impostos, $custos);
    }

    /**
     * Simulação dos impostos (ecrã de gravação): taxas do ano da escritura (ou da promessa, ou de hoje).
     *
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    public function simular(array $d): array
    {
        $ano = (int) substr((string) ($d['data_escritura'] ?? $d['data_promessa'] ?? now()->toDateString()), 0, 4);
        $t = $this->taxas->doAno($ano);
        $imp = CalculadoraNegocios::impostos($d['preco'] ?? 0, $d['vpt'] ?? 0, $t['taxas'], (string) ($d['isencao'] ?? 'NAO'));

        return $imp + ['ano' => $ano, 'sugestao' => CalculadoraNegocios::sugerirIsencao($imp['base'], (string) ($d['finalidade'] ?? 'HABITACAO'), $t['taxas']), 'estado_validacao' => $t['estado_validacao'],
            'aviso' => $t['aviso']];
    }

    // ---------------------------------------------------------------- gravar e apagar

    /**
     * Cria ou edita um negócio. Antes da escritura os impostos recalculam-se; depois ficam fixos e o preço, as partes e o imóvel já não mudam.
     *
     * @param  array<string, mixed>  $d
     */
    public function gravar(array $d, ?NegocioImobiliario $existente = null): NegocioImobiliario
    {
        return DB::transaction(function () use ($d, $existente) {
            $n = $existente ? NegocioImobiliario::query()->whereKey($existente->id)->lockForUpdate()->firstOrFail() : null;
            if ($n?->fechado()) {
                throw new ErroNegocio('O negócio está '.mb_strtolower(CalculadoraNegocios::FASES[$n->estado]['rot']).': não se altera.', 'NEG_FECHADO');
            }
            if ($n && isset($d['tipo']) && $d['tipo'] !== $n->tipo) {
                throw new ErroNegocio('O tipo do negócio (compra ou venda) não muda depois de criado.', 'NEG_TIPO_FIXO');
            }
            $chaves = ['tipo', 'imovel_id', 'fracao_id', 'terceiro_id', 'preco', 'vpt', 'sinal', 'finalidade', 'isencao', 'data_promessa', 'data_escritura', 'data_registo', 'notario', 'notas'];
            $base = $n ? $n->only($chaves) : ['tipo' => 'COMPRA', 'vpt' => 0, 'sinal' => 0, 'finalidade' => 'HABITACAO', 'isencao' => 'NAO'];
            foreach (['data_promessa', 'data_escritura', 'data_registo'] as $k) {
                if ($n && $n->{$k} !== null) {
                    $base[$k] = $n->{$k}->format('Y-m-d');
                }
            }
            $x = array_replace($base, array_intersect_key($d, array_flip($chaves)));
            foreach (['imovel_id', 'fracao_id', 'data_promessa', 'data_escritura', 'data_registo', 'notario', 'notas'] as $k) {
                $x[$k] = isset($x[$k]) && $x[$k] !== '' ? $x[$k] : null;
            }
            $x['notario'] = Normaliza::texto($x['notario']);
            $x['notas'] = Normaliza::texto($x['notas']);
            $x = $this->validar($x, $n);

            $mudouNucleo = $n === null ? false : $this->mudou($n, $x, ['imovel_id', 'fracao_id', 'terceiro_id', 'preco', 'vpt', 'sinal', 'finalidade', 'isencao']);
            if ($n && $n->ordem() >= NegocioImobiliario::FASES['ESCRITURA'] && $this->mudou($n, $x, self::CAMPOS_FIXOS_NA_ESCRITURA)) {
                throw new ErroNegocio('Depois da escritura o preço, as partes, o imóvel e a isenção já não se alteram: foram esses que se declararam. Só se completam as datas, o notário e as notas.', 'NEG_FIXO');
            }
            if ($n && $mudouNucleo && $this->mudou($n, $x, ['imovel_id', 'fracao_id', 'terceiro_id', 'preco']) && isset($this->pagoPorNegocio([$n->id])[$n->id])) {
                throw new ErroNegocio('O negócio já tem pagamentos registados: anule-os antes de mudar o preço, as partes ou o imóvel.', 'NEG_COM_PAGAMENTOS');
            }
            $this->conferirConflito($x, $n);
            $this->conferirSinal($x, $n);

            $campos = ['imovel_id' => $x['imovel_id'], 'fracao_id' => $x['fracao_id'], 'terceiro_id' => $x['terceiro_id'], 'preco' => $x['preco'], 'vpt' => $x['vpt'], 'sinal' => $x['sinal'],
                'finalidade' => $x['finalidade'], 'isencao' => $x['isencao'], 'data_promessa' => $x['data_promessa'], 'data_escritura' => $x['data_escritura'], 'data_registo' => $x['data_registo'],
                'notario' => $x['notario'], 'notas' => $x['notas'], 'atualizado_por' => Autor::nome()];
            if ($n === null || $n->ordem() < NegocioImobiliario::FASES['ESCRITURA']) {
                $campos += $this->camposImpostos($x);
            }
            if ($n === null) {
                $n = NegocioImobiliario::create($campos + ['numero' => $this->numeracao->proximoNumeroNegocio($this->contexto->obrigatorio(), $x['tipo']), 'tipo' => $x['tipo'], 'estado' => 'NEGOCIACAO',
                    'criado_por' => Autor::nome()]);
                $this->registar($n, 'CRIADO', null, 'NEGOCIACAO', null, ['preco' => $n->preco]);

                return $n->refresh();
            }
            if ($mudouNucleo && $n->aprovado_em !== null && $n->ordem() < NegocioImobiliario::FASES['ESCRITURA']) {
                $campos += ['aprovado_em' => null, 'aprovado_por' => null];   // mudou o que se aprovou: aprova-se outra vez
                $this->registar($n, 'APROVACAO_RETIRADA', $n->estado, $n->estado, 'Mudaram o preço, as partes, o imóvel ou a isenção.');
            }
            $n->update($campos);

            return $n->refresh();
        });
    }

    /** @param  array<string, mixed>  $x @return array<string, mixed> */
    private function validar(array $x, ?NegocioImobiliario $n): array
    {
        if (! in_array($x['tipo'], NegocioImobiliario::TIPOS, true)) {
            throw new ErroNegocio('Escolha se é uma compra ou uma venda.', 'NEG_TIPO');
        }
        $venda = $x['tipo'] === 'VENDA';
        if (! empty($x['fracao_id'])) {
            $f = Fracao::query()->find((int) $x['fracao_id']) ?? throw new ErroNegocio('Fracção não encontrada.', 'NEG_FRACCAO');
            if (! empty($x['imovel_id']) && (int) $x['imovel_id'] !== (int) $f->imovel_id) {
                throw new ErroNegocio('A fracção escolhida não é deste imóvel.', 'NEG_FRACCAO_OUTRO_IMOVEL');
            }
            $x['imovel_id'] = (int) $f->imovel_id;
            $x['fracao_id'] = (int) $f->id;
            if ($venda && $f->estado === 'VENDIDA' && $n === null) {
                throw new ErroNegocio('A fracção já está vendida.', 'NEG_FRACCAO_VENDIDA');
            }
        }
        if (empty($x['imovel_id']) && $venda) {
            throw new ErroNegocio('Escolha o imóvel ou a fracção que está a ser vendida.', 'NEG_ALVO');
        }
        if (empty($x['imovel_id']) && empty($x['fracao_id']) && ! $venda) {
            // uma compra pode ficar sem imóvel no cadastro até a escritura; mas tem de se saber de que se trata
            $x['imovel_id'] = null;
        }
        if (! empty($x['imovel_id'])) {
            $i = Imovel::query()->find((int) $x['imovel_id']) ?? throw new ErroNegocio('Imóvel não encontrado.', 'NEG_IMOVEL');
            $x['imovel_id'] = (int) $i->id;
            if ($venda) {
                if ($i->papel !== 'PROPRIO') {
                    throw new ErroNegocio('Só se vende o que é da empresa: este imóvel tem o papel «'.mb_strtolower(CatalogoImobiliario::PAPEIS[$i->papel]['rot'] ?? $i->papel).'».', 'NEG_NAO_PROPRIO');
                }
                if ($i->estado === 'ALIENADO' && $n === null) {
                    throw new ErroNegocio('O imóvel já foi alienado.', 'NEG_ALIENADO');
                }
                if (empty($x['fracao_id']) && $n === null && Fracao::query()->where('imovel_id', $i->id)->where('estado', 'VENDIDA')->exists()) {
                    throw new ErroNegocio('Há fracções deste imóvel já vendidas: venda as restantes fracção a fracção.', 'NEG_FRACCOES_VENDIDAS');
                }
            }
        }
        if (empty($x['terceiro_id'])) {
            throw new ErroNegocio($venda ? 'Indique o comprador.' : 'Indique o vendedor.', 'NEG_TERCEIRO');
        }
        $x['terceiro_id'] = (int) Terceiro::query()->find((int) $x['terceiro_id'])?->id ?: throw new ErroNegocio($venda ? 'Comprador não encontrado.' : 'Vendedor não encontrado.', 'NEG_TERCEIRO');
        if (! is_numeric($x['preco'] ?? null) || (float) $x['preco'] <= 0) {
            throw new ErroNegocio('O preço tem de ser maior do que zero.', 'NEG_PRECO');
        }
        foreach (['vpt', 'sinal'] as $k) {
            if (! is_numeric($x[$k] ?? 0) || (float) ($x[$k] ?? 0) < 0) {
                throw new ErroNegocio($k === 'vpt' ? 'O valor patrimonial não pode ser negativo.' : 'O sinal não pode ser negativo.', 'NEG_VALOR');
            }
        }
        $x['preco'] = CalculadoraNegocios::dinheiro($x['preco']);
        $x['vpt'] = CalculadoraNegocios::dinheiro($x['vpt'] ?? 0);
        $x['sinal'] = CalculadoraNegocios::dinheiro($x['sinal'] ?? 0);
        if (bccomp($x['sinal'], $x['preco'], 2) > 0) {
            throw new ErroNegocio('O sinal não pode passar do preço.', 'NEG_SINAL');
        }
        if (! isset(CalculadoraNegocios::FINALIDADES[$x['finalidade']])) {
            throw new ErroNegocio('Finalidade desconhecida.', 'NEG_FINALIDADE');
        }
        if (! isset(CalculadoraNegocios::ISENCOES[$x['isencao']])) {
            throw new ErroNegocio('Isenção desconhecida.', 'NEG_ISENCAO');
        }
        if ($x['data_promessa'] && $x['data_escritura'] && $x['data_escritura'] < $x['data_promessa']) {
            throw new ErroNegocio('A escritura não pode ser anterior à promessa.', 'NEG_DATAS');
        }
        if ($x['data_escritura'] && $x['data_registo'] && $x['data_registo'] < $x['data_escritura']) {
            throw new ErroNegocio('O registo não pode ser anterior à escritura.', 'NEG_DATAS');
        }

        return $x;
    }

    /** Um só negócio aberto do mesmo tipo por imóvel ou fracção (o legado deixava vários). */
    private function conferirConflito(array $x, ?NegocioImobiliario $n): void
    {
        if (empty($x['imovel_id'])) {
            return;
        }
        $q = NegocioImobiliario::query()->where('tipo', $x['tipo'])->whereNotIn('estado', NegocioImobiliario::FECHADOS)->where('imovel_id', $x['imovel_id']);
        if ($n) {
            $q->whereKeyNot($n->id);
        }
        $q->where(function ($w) use ($x) {
            // o imóvel inteiro choca com qualquer fracção dele, e uma fracção com o imóvel inteiro e com ela própria
            if (empty($x['fracao_id'])) {
                return;
            }
            $w->whereNull('fracao_id')->orWhere('fracao_id', $x['fracao_id']);
        });
        $outro = $q->first(['numero']);
        if ($outro !== null) {
            throw new ErroNegocio("Já há um negócio aberto do mesmo tipo sobre este imóvel ou fracção ({$outro->numero}): conclua-o ou cancele-o primeiro.", 'NEG_JA_ABERTO');
        }
    }

    private function conferirSinal(array $x, ?NegocioImobiliario $n): void
    {
        if ($n === null) {
            return;
        }
        $sinalPago = (string) PagamentoNegocioImo::query()->where('negocio_id', $n->id)->where('estado', 'VALIDO')->where('tipo', 'SINAL')->sum('valor');
        if (bccomp(CalculadoraNegocios::dinheiro($sinalPago), $x['sinal'], 2) > 0) {
            throw new ErroNegocio('O sinal já registado nos pagamentos é maior do que o sinal indicado.', 'NEG_SINAL_PAGO');
        }
        $pago = $this->pagoPorNegocio([$n->id])[$n->id] ?? '0.00';
        if (bccomp($pago, $x['preco'], 2) > 0) {
            throw new ErroNegocio('Os pagamentos já registados passam do novo preço.', 'NEG_PRECO_PAGO');
        }
    }

    /** @param  array<string, mixed>  $x @return array<string, mixed> impostos recalculados com as taxas do ano da escritura (ou da promessa, ou de hoje) */
    private function camposImpostos(array $x): array
    {
        $ano = (int) substr((string) ($x['data_escritura'] ?? $x['data_promessa'] ?? now()->toDateString()), 0, 4);
        $i = CalculadoraNegocios::impostos($x['preco'], $x['vpt'], $this->taxas->doAno($ano)['taxas'], $x['isencao']);

        return ['base' => $i['base'], 'base_e_vpt' => $i['base_e_vpt'], 'ip_taxa' => $i['ip_taxa'], 'ip_valor' => $i['ip_valor'], 'selo_pct' => $i['selo_pct'], 'selo_valor' => $i['selo_valor'],
            'escritura_valor' => $i['escritura_valor'], 'total_impostos' => $i['total_impostos'], 'impostos_ano' => $ano];
    }

    /** @param  array<string, mixed>  $x  @param  list<string>  $chaves */
    private function mudou(NegocioImobiliario $n, array $x, array $chaves): bool
    {
        foreach ($chaves as $k) {
            if (! array_key_exists($k, $x)) {
                continue;
            }
            $a = $n->{$k};
            $novo = $x[$k];
            if (in_array($k, ['preco', 'vpt', 'sinal'], true)) {
                if (bccomp(CalculadoraNegocios::dinheiro($a), CalculadoraNegocios::dinheiro($novo), 2) !== 0) {
                    return true;
                }

                continue;
            }
            if ((string) ($a ?? '') !== (string) ($novo ?? '')) {
                return true;
            }
        }

        return false;
    }

    public function eliminar(NegocioImobiliario $n): void
    {
        DB::transaction(function () use ($n) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->estado === 'CONCLUIDO' || $n->ordem() >= NegocioImobiliario::FASES['ESCRITURA']) {
                throw new ErroNegocio('Um negócio já escriturado não se apaga: a propriedade transmitiu-se e tem de deixar rasto.', 'NEG_NAO_APAGA');
            }
            if (PagamentoNegocioImo::query()->where('negocio_id', $n->id)->exists()) {
                throw new ErroNegocio('O negócio tem pagamentos registados: não se apaga. Cancele-o.', 'NEG_COM_PAGAMENTOS');
            }
            HistoricoNegocioImo::query()->where('negocio_id', $n->id)->delete();
            CustoNegocioImo::query()->where('negocio_id', $n->id)->delete();
            $n->delete();
        });
    }

    // ---------------------------------------------------------------- aprovar, fases, cancelar

    /** Aprova o negócio (quem aprova não regista pagamentos: par do catálogo). Repetir não muda quem aprovou nem quando. */
    public function aprovar(NegocioImobiliario $n): NegocioImobiliario
    {
        return DB::transaction(function () use ($n) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->fechado()) {
                throw new ErroNegocio('O negócio está fechado: já não se aprova.', 'NEG_FECHADO');
            }
            if ($n->aprovado_em !== null) {
                return $n;
            }
            $n->update(['aprovado_em' => now(), 'aprovado_por' => Autor::nome(), 'atualizado_por' => Autor::nome()]);
            $this->registar($n, 'APROVADO', $n->estado, $n->estado, null, ['preco' => $n->preco]);

            return $n->refresh();
        });
    }

    /** @return list<string> o que falta para avançar para a fase */
    public function faltas(NegocioImobiliario $n, string $fase): array
    {
        $ordem = CalculadoraNegocios::FASES[$fase]['ordem'] ?? 0;
        $f = [];
        if ($ordem >= 2 && $n->aprovado_em === null) {
            $f[] = 'Aprove o negócio antes de avançar.';
        }
        if ($fase === 'PROMESSA' && $n->data_promessa === null) {
            $f[] = 'Indique a data da promessa.';
        }
        if ($ordem >= 3) {
            if ($n->data_escritura === null) {
                $f[] = 'Indique a data da escritura.';
            }
            if (trim((string) $n->notario) === '') {
                $f[] = 'Indique o cartório ou notário.';
            }
            if ((float) $n->vpt <= 0) {
                $f[] = 'Indique o valor patrimonial: é ele que faz a base do imposto quando é maior do que o preço.';
            }
            if ($n->tipo === 'VENDA' && $n->contratos_destino === null) {
                $vivos = $this->contratosVivos($n);
                if ($vivos->isNotEmpty()) {
                    $f[] = "A venda tem {$vivos->count()} contrato(s) em vigor (".$vivos->pluck('numero')->implode(', ').'). A venda não faz cessar o arrendamento: decida o que lhes acontece antes de escriturar.';
                }
            }
        }
        if ($ordem >= 4 && $n->data_registo === null) {
            $f[] = 'Indique a data do registo na Conservatória.';
        }

        return $f;
    }

    public function avancar(NegocioImobiliario $n, string $fase): NegocioImobiliario
    {
        if (! in_array($fase, ['PROMESSA', 'ESCRITURA', 'REGISTADO'], true)) {
            throw new ErroNegocio('Fase desconhecida. Para concluir use «Concluir» e para desistir «Cancelar».', 'NEG_FASE');
        }

        return DB::transaction(function () use ($n, $fase) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->estado === 'CANCELADO') {
                throw new ErroNegocio('O negócio está cancelado.', 'NEG_FECHADO');
            }
            if ($n->estado === 'CONCLUIDO') {
                throw new ErroNegocio('O negócio está concluído.', 'NEG_FECHADO');
            }
            $alvo = CalculadoraNegocios::FASES[$fase];
            if ($alvo['ordem'] <= $n->ordem()) {
                throw new ErroNegocio('Não se recua de «'.CalculadoraNegocios::FASES[$n->estado]['rot'].'» para «'.$alvo['rot'].'»: o que já foi assinado não se desfaz por mudança de estado.', 'NEG_RECUO');
            }
            $faltas = $this->faltas($n, $fase);
            if ($faltas !== []) {
                throw new ErroNegocio(implode(' ', $faltas), 'NEG_FALTAS', 422, ['faltas' => $faltas]);
            }
            $de = $n->estado;
            $campos = ['estado' => $fase, 'atualizado_por' => Autor::nome()];
            if ($alvo['ordem'] >= NegocioImobiliario::FASES['ESCRITURA'] && $n->ordem() < NegocioImobiliario::FASES['ESCRITURA']) {
                $campos += $this->camposImpostos(['preco' => $n->preco, 'vpt' => $n->vpt, 'isencao' => $n->isencao, 'data_escritura' => $n->data_escritura->format('Y-m-d'), 'data_promessa' => null])
                    + ['impostos_fixados_em' => now()->toDateString()];
            }
            $n->update($campos);
            $this->registar($n, 'FASE', $de, $fase);

            return $n->refresh();
        });
    }

    public function cancelar(NegocioImobiliario $n, ?string $motivo): NegocioImobiliario
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo do cancelamento.', 'NEG_MOTIVO');

        return DB::transaction(function () use ($n, $motivo) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->estado === 'CANCELADO') {
                return $n;
            }
            if ($n->estado === 'CONCLUIDO' || $n->ordem() >= NegocioImobiliario::FASES['ESCRITURA']) {
                throw new ErroNegocio('Um negócio já escriturado não se cancela: a propriedade transmitiu-se. Faça o negócio inverso.', 'NEG_NAO_CANCELA');
            }
            if (PagamentoNegocioImo::query()->where('negocio_id', $n->id)->where('estado', 'VALIDO')->exists()) {
                throw new ErroNegocio('O negócio tem pagamentos válidos registados: anule-os (com motivo) antes de o cancelar, para que o dinheiro devolvido fique registado.', 'NEG_COM_PAGAMENTOS');
            }
            $de = $n->estado;
            $n->update(['estado' => 'CANCELADO', 'motivo_cancelamento' => mb_substr($motivo, 0, 300), 'cancelado_em' => now(), 'atualizado_por' => Autor::nome()]);
            $this->registar($n, 'CANCELADO', $de, 'CANCELADO', $motivo);

            return $n->refresh();
        });
    }

    // ---------------------------------------------------------------- encargos

    /**
     * Grava os encargos do negócio (substitui todos, numa só transacção). Uma linha em branco descarta-se; uma linha a meio (sem designação ou sem valor) recusa-se
     * em vez de se ignorar sem aviso, como fazia o legado.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @return list<array<string, mixed>>
     */
    public function gravarCustos(NegocioImobiliario $n, array $linhas): array
    {
        return DB::transaction(function () use ($n, $linhas) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->fechado()) {
                throw new ErroNegocio('O negócio está fechado: os encargos não se alteram.', 'NEG_FECHADO');
            }
            $novas = [];
            foreach (array_values($linhas) as $i => $l) {
                $desig = Normaliza::texto($l['designacao'] ?? null);
                $vazio = $desig === null && (($l['valor'] ?? '') === '' || (float) ($l['valor'] ?? 0) === 0.0);
                if ($vazio) {
                    continue;
                }
                $tipo = (string) ($l['tipo'] ?? 'OUTRO');
                if (! isset(CalculadoraNegocios::TIPOS_CUSTO[$tipo])) {
                    throw new ErroNegocio('Encargo '.($i + 1).': tipo desconhecido.', 'NEG_CUSTO_TIPO');
                }
                if ($desig === null) {
                    throw new ErroNegocio('Encargo '.($i + 1).': indique a designação.', 'NEG_CUSTO_DESIGNACAO');
                }
                if (! is_numeric($l['valor'] ?? null) || (float) $l['valor'] <= 0) {
                    throw new ErroNegocio('Encargo '.($i + 1).': o valor tem de ser maior do que zero.', 'NEG_CUSTO_VALOR');
                }
                $novas[] = ['negocio_id' => $n->id, 'tipo' => $tipo, 'designacao' => mb_substr($desig, 0, 200), 'valor' => CalculadoraNegocios::dinheiro($l['valor']),
                    'capitaliza' => array_key_exists('capitaliza', $l) && $l['capitaliza'] !== null ? (bool) $l['capitaliza'] : CalculadoraNegocios::TIPOS_CUSTO[$tipo]['capitaliza'],
                    'pago' => ! empty($l['pago']), 'data' => $l['data'] ?? now()->toDateString(), 'ordem' => $i];
            }
            CustoNegocioImo::query()->where('negocio_id', $n->id)->delete();
            foreach ($novas as $c) {
                CustoNegocioImo::create($c);
            }
            $this->registar($n, 'ENCARGOS', $n->estado, $n->estado, null, ['n' => count($novas)]);

            return CustoNegocioImo::query()->where('negocio_id', $n->id)->orderBy('ordem')->get()->map(fn ($c) => $c->attributesToArray())->all();
        });
    }

    // ---------------------------------------------------------------- pagamentos

    /**
     * Regista um pagamento do negócio (pago na compra, recebido na venda). Exige o negócio aprovado e não cancelado; a soma não passa do preço e o sinal
     * não passa do sinal acordado. `chave` torna o pedido repetível sem duplicar.
     *
     * @param  array{tipo: string, valor: mixed, data?: ?string, meio?: ?string, referencia?: ?string, chave?: ?string}  $d
     */
    public function registarPagamento(NegocioImobiliario $n, array $d): PagamentoNegocioImo
    {
        $tipo = (string) ($d['tipo'] ?? '');
        if (! isset(CalculadoraNegocios::TIPOS_PAGAMENTO[$tipo])) {
            throw new ErroNegocio('Escolha o tipo de pagamento: sinal, prestação ou saldo.', 'NEG_PAG_TIPO');
        }
        if (! is_numeric($d['valor'] ?? null) || (float) $d['valor'] <= 0) {
            throw new ErroNegocio('Indique o valor do pagamento.', 'NEG_PAG_VALOR');
        }
        $valor = CalculadoraNegocios::dinheiro($d['valor']);
        $chave = Normaliza::texto($d['chave'] ?? null);
        if ($chave !== null && mb_strlen($chave) > 80) {
            throw new ErroNegocio('A chave do pedido é demasiado longa.', 'NEG_PAG_CHAVE');
        }

        return DB::transaction(function () use ($n, $tipo, $valor, $d, $chave) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($chave !== null && ($existente = PagamentoNegocioImo::query()->where('chave', $chave)->first())) {
                if ($existente->negocio_id !== $n->id || $existente->tipo !== $tipo || bccomp(CalculadoraNegocios::dinheiro($existente->valor), $valor, 2) !== 0) {
                    throw new ErroNegocio('Esta chave já foi usada noutro pagamento.', 'NEG_PAG_CHAVE_REPETIDA', 409);
                }

                return $existente;   // pedido repetido: o pagamento já existe
            }
            if ($n->estado === 'CANCELADO') {
                throw new ErroNegocio('O negócio está cancelado: não recebe pagamentos.', 'NEG_FECHADO');
            }
            if ($n->aprovado_em === null) {
                throw new ErroNegocio('Aprove o negócio antes de registar pagamentos.', 'NEG_NAO_APROVADO');
            }
            $pago = $this->pagoPorNegocio([$n->id])[$n->id] ?? '0.00';
            if (bccomp(bcadd($pago, $valor, 2), CalculadoraNegocios::dinheiro($n->preco), 2) > 0) {
                throw new ErroNegocio('Os pagamentos não podem passar do preço: faltam '.number_format((float) bcsub(CalculadoraNegocios::dinheiro($n->preco), $pago, 2), 2, ',', ' ').' Kz.', 'NEG_PAG_EXCEDE');
            }
            if ($tipo === 'SINAL') {
                $sinalPago = CalculadoraNegocios::dinheiro((string) PagamentoNegocioImo::query()->where('negocio_id', $n->id)->where('estado', 'VALIDO')->where('tipo', 'SINAL')->sum('valor'));
                if (bccomp(CalculadoraNegocios::dinheiro($n->sinal), '0', 2) === 0) {
                    throw new ErroNegocio('O negócio não prevê sinal: indique-o primeiro no negócio.', 'NEG_PAG_SEM_SINAL');
                }
                if (bccomp(bcadd($sinalPago, $valor, 2), CalculadoraNegocios::dinheiro($n->sinal), 2) > 0) {
                    throw new ErroNegocio('O sinal não pode passar do sinal acordado ('.number_format((float) $n->sinal, 2, ',', ' ').' Kz).', 'NEG_PAG_SINAL');
                }
            }
            $p = PagamentoNegocioImo::create(['negocio_id' => $n->id, 'tipo' => $tipo, 'valor' => $valor, 'data' => Normaliza::texto($d['data'] ?? null) ?? now()->toDateString(),
                'meio' => Normaliza::texto($d['meio'] ?? null), 'referencia' => Normaliza::texto($d['referencia'] ?? null), 'estado' => 'VALIDO',
                'chave' => $chave ?? 'auto:'.Str::uuid()->toString(), 'criado_por' => Autor::nome()]);
            $this->registar($n, 'PAGAMENTO', $n->estado, $n->estado, null, ['tipo' => $tipo, 'valor' => $valor]);

            return $p->refresh();
        });
    }

    public function anularPagamento(NegocioImobiliario $n, int $pagamentoId, ?string $motivo): PagamentoNegocioImo
    {
        $motivo = Normaliza::texto($motivo) ?? throw new ErroNegocio('Indique o motivo da anulação.', 'NEG_MOTIVO');

        return DB::transaction(function () use ($n, $pagamentoId, $motivo) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            $p = PagamentoNegocioImo::query()->where('negocio_id', $n->id)->whereKey($pagamentoId)->lockForUpdate()->first() ?? throw new ErroNegocio('Pagamento não encontrado.', 'NEG_PAG_INEXISTENTE', 404);
            if ($p->estado === 'ANULADO') {
                return $p;
            }
            $p->update(['estado' => 'ANULADO', 'anulado_motivo' => mb_substr($motivo, 0, 300), 'anulado_por' => Autor::nome(), 'anulado_em' => now()]);
            $this->registar($n, 'PAGAMENTO_ANULADO', $n->estado, $n->estado, $motivo, ['valor' => $p->valor]);

            return $p->refresh();
        });
    }

    // ---------------------------------------------------------------- contratos na venda

    /** @return Collection<int, ContratoArrendamento> contratos em vigor (activos ou suspensos) do lado senhorio nas fracções vendidas */
    public function contratosVivos(NegocioImobiliario $n): Collection
    {
        $ids = $this->idsDasFracoes($n);
        if ($ids === []) {
            return collect();
        }
        $contratos = ContratoFracao::query()->whereIn('fracao_id', $ids)->where('posicao', 'SENHORIO')->pluck('contrato_id')->all();

        return ContratoArrendamento::query()->whereIn('id', $contratos)->whereIn('estado', ['ACTIVO', 'SUSPENSO'])->orderBy('id')->get(['id', 'numero', 'fim', 'renda', 'estado', 'contrato_pai_id']);
    }

    /** @return list<int> */
    private function idsDasFracoes(NegocioImobiliario $n): array
    {
        if ($n->fracao_id) {
            return [(int) $n->fracao_id];
        }

        return $n->imovel_id ? Fracao::query()->where('imovel_id', $n->imovel_id)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    /**
     * Decide o que acontece aos contratos em vigor da venda: TRANSMITE termina-os em cascata (ServicoContratos::terminar) na data da escritura (ou hoje) com o
     * motivo registado; MANTEM regista que a venda não abrange o arrendamento (e a conclusão fica bloqueada enquanto houver contratos vivos).
     *
     * @return array{negocio: NegocioImobiliario, terminados: int}
     */
    public function tratarContratos(NegocioImobiliario $n, string $destino): array
    {
        if (! isset(CalculadoraNegocios::DESTINOS_CONTRATOS[$destino])) {
            throw new ErroNegocio('Escolha o que acontece aos contratos: transmitir ao comprador ou manter nesta empresa.', 'NEG_DESTINO');
        }

        return DB::transaction(function () use ($n, $destino) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->tipo !== 'VENDA') {
                throw new ErroNegocio('Os contratos tratam-se na venda, não na compra.', 'NEG_NAO_E_VENDA');
            }
            if ($n->fechado()) {
                throw new ErroNegocio('O negócio está fechado.', 'NEG_FECHADO');
            }
            $terminados = 0;
            if ($destino === 'TRANSMITE') {
                $data = $n->data_escritura?->format('Y-m-d') ?? now()->toDateString();
                foreach ($this->contratosVivos($n) as $c) {
                    $actual = ContratoArrendamento::query()->find($c->id);
                    if ($actual === null || ! in_array($actual->estado, ['ACTIVO', 'SUSPENSO'], true)) {
                        continue;   // já cessou pela cascata de outro
                    }
                    $r = $this->contratos->terminar($actual, ['data' => $data, 'cascata' => true,
                        'motivo' => "Transmissão da propriedade pela venda {$n->numero}: a posição de senhorio passou para o comprador."]);
                    $terminados += 1 + $r['filhos_terminados'];
                }
            }
            $n->update(['contratos_destino' => $destino, 'contratos_tratados_em' => now(), 'atualizado_por' => Autor::nome()]);
            $this->registar($n, 'CONTRATOS', $n->estado, $n->estado, null, ['destino' => $destino, 'terminados' => $terminados]);

            return ['negocio' => $n->refresh(), 'terminados' => $terminados];
        });
    }

    // ---------------------------------------------------------------- concluir a compra

    /**
     * Conclui a compra: o imóvel passa a próprio e o bem nasce em Activos ao custo de aquisição, lançado (D activo / C contrapartida). Se o imóvel já está
     * ligado a um bem, liga-se a ele sem o duplicar e sem lançar de novo. Repetir devolve o resultado já registado.
     *
     * @param  array{categoria_ativo_id?: ?int}  $d
     * @return array<string, mixed>
     */
    public function concluirCompra(NegocioImobiliario $n, array $d = []): array
    {
        return DB::transaction(function () use ($n, $d) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->tipo !== 'COMPRA') {
                throw new ErroNegocio('Este negócio é uma venda.', 'NEG_NAO_E_COMPRA');
            }
            if ($n->estado === 'CONCLUIDO') {
                return ['negocio' => $n, 'repetida' => true, 'avisos' => [], 'ativo' => $n->ativo_id ? ['id' => $n->ativo_id] : null, 'lancamento' => $n->lancamento_compra, 'custo_aquisicao' => $this->custoAquisicao($n)];
            }
            if ($n->estado === 'CANCELADO') {
                throw new ErroNegocio('O negócio está cancelado.', 'NEG_FECHADO');
            }
            if ($n->ordem() < NegocioImobiliario::FASES['ESCRITURA']) {
                throw new ErroNegocio('A compra só se conclui depois da escritura.', 'NEG_SEM_ESCRITURA');
            }
            $fracao = $n->fracao_id ? Fracao::query()->find($n->fracao_id) : null;
            $imovelId = $n->imovel_id ?? $fracao?->imovel_id;
            $imovel = $imovelId ? Imovel::query()->lockForUpdate()->find($imovelId) : null;
            if ($imovel === null) {
                throw new ErroNegocio('Ligue o negócio ao imóvel no cadastro antes de o concluir.', 'NEG_SEM_IMOVEL');
            }
            $custo = $this->custoAquisicao($n);
            $avisos = [];
            $lancamento = null;
            $criado = false;
            $ativo = $imovel->ativo_edificio_id ? AtivoImobilizado::query()->find($imovel->ativo_edificio_id) : null;
            if ($ativo === null) {
                $cat = CategoriaAtivo::query()->find((int) ($d['categoria_ativo_id'] ?? 0)) ?? throw new ErroNegocio('Indique a categoria do activo (Activos › Categorias): é ela que dá a conta do imobilizado e as amortizações.', 'NEG_SEM_CATEGORIA');
                $ativo = $this->ativos->guardar(['tipo_ativo' => 'EDIFICIO', 'descricao' => mb_substr("Imóvel {$imovel->codigo} — {$imovel->nome}".($fracao ? " ({$fracao->designacao})" : ''), 0, 200),
                    'categoria_ativo_id' => $cat->id, 'data_aquisicao' => $n->data_escritura->format('Y-m-d'), 'valor_aquisicao' => $custo['total'], 'valor_residual' => 0, 'fornecedor_id' => $n->terceiro_id]);
                $r = $this->contab->compra($n, $ativo, $cat, $custo['total']);
                $ativo->update(['lancamento_contabil_id' => $r['linha_id']]);
                $lancamento = $r['numero_lan'];
                $criado = true;
            } else {
                $avisos[] = "O imóvel já estava ligado ao bem {$ativo->codigo}: não se criou outro nem se lançou a aquisição outra vez. Reveja o valor do bem em Activos.";
            }
            $ligavel = ! NegocioImobiliario::query()->where('tipo', 'COMPRA')->where('ativo_id', $ativo->id)->whereKeyNot($n->id)->exists();
            $imovel->update(['papel' => 'PROPRIO', 'proprietario_terceiro_id' => null, 'valor_aquisicao' => $custo['total'], 'data_aquisicao' => $n->data_escritura->format('Y-m-d'),
                'ativo_edificio_id' => $ativo->id] + ((float) $n->vpt > 0 && $imovel->valor_patrimonial === null ? ['valor_patrimonial' => $n->vpt, 'valor_patrimonial_data' => $n->data_escritura->format('Y-m-d')] : [])
                + ['atualizado_por' => Autor::nome()]);
            $de = $n->estado;
            $n->update(['estado' => 'CONCLUIDO', 'concluido_em' => now()->toDateString(), 'custo_aquisicao' => $custo['total'], 'ativo_id' => $ligavel ? $ativo->id : null,
                'lancamento_compra' => $lancamento, 'imovel_id' => $imovel->id, 'atualizado_por' => Autor::nome()]);
            $this->registar($n, 'CONCLUIDO', $de, 'CONCLUIDO', null, ['custo_aquisicao' => $custo['total'], 'ativo' => $ativo->codigo, 'criado' => $criado]);

            return ['negocio' => $n->refresh(), 'repetida' => false, 'avisos' => $avisos, 'ativo' => ['id' => (int) $ativo->id, 'codigo' => $ativo->codigo, 'criado' => $criado], 'lancamento' => $lancamento, 'custo_aquisicao' => $custo];
        });
    }

    // ---------------------------------------------------------------- concluir a venda e abater o bem

    /**
     * O resultado da venda sobre a quota do bem vendida (sem gravar nada).
     *
     * @return array<string, mixed>
     */
    public function resultadoVenda(NegocioImobiliario $n): array
    {
        $imovel = $n->imovel_id ? Imovel::query()->find($n->imovel_id, ['id', 'ativo_edificio_id']) : null;
        if ($imovel === null || ! $imovel->ativo_edificio_id) {
            return ['conhecido' => false, 'motivo' => 'O imóvel não está ligado ao imobilizado: sem custo de aquisição registado não há valor contabilístico.'];
        }
        $a = AtivoImobilizado::query()->find($imovel->ativo_edificio_id);
        if ($a === null) {
            return ['conhecido' => false, 'motivo' => 'O activo ligado a este imóvel já não existe no imobilizado.'];
        }
        if (! $a->eProprio()) {
            return ['conhecido' => false, 'motivo' => 'O activo ligado a este imóvel é alheio: não se abate nem se vende.'];
        }
        if ($a->estado === AtivoImobilizado::ESTADO_ABATIDO && $n->abatido_em === null) {
            return ['conhecido' => false, 'motivo' => 'O activo ligado a este imóvel já está abatido.'];
        }
        $anterior = (string) NegocioImobiliario::query()->where('tipo', 'VENDA')->where('ativo_id', $a->id)->whereNotNull('abatido_em')->whereKeyNot($n->id)->sum('quota');
        $fracoes = Fracao::query()->where('imovel_id', $imovel->id)->orderBy('id')->get(['id', 'area_util', 'area_bruta'])->map(fn ($f) => ['id' => (int) $f->id, 'area_util' => $f->area_util, 'area_bruta' => $f->area_bruta])->all();
        $q = CalculadoraNegocios::quota($n->fracao_id ? (int) $n->fracao_id : null, $fracoes);
        $quota = $q['quota'];
        $resta = bcsub('1', number_format((float) $anterior, 6, '.', ''), 6);
        if (bccomp($resta, '0', 6) <= 0) {
            return ['conhecido' => false, 'motivo' => 'Todo o activo já foi vendido nos negócios anteriores.'];
        }
        if (bccomp($quota, $resta, 6) > 0) {
            $quota = $resta;
        }
        $final = bccomp(bcsub($resta, $quota, 6), '0.00001', 6) <= 0;
        if ($final) {
            $quota = $resta;
        }
        $acum = bcadd(CalculadoraNegocios::dinheiro($a->amortizacao_acumulada_inicial), CalculadoraNegocios::dinheiro((string) AmortizacaoAtivo::query()->where('ativo_imobilizado_id', $a->id)->where('contabilizado', true)->sum('valor')), 2);
        $encargos = CalculadoraNegocios::dinheiro((string) CustoNegocioImo::query()->where('negocio_id', $n->id)->sum('valor'));
        $r = CalculadoraNegocios::resultadoVenda($n->preco, $a->valor_aquisicao, $acum, $quota, $encargos);

        return $r + ['conhecido' => true, 'ativo_id' => (int) $a->id, 'ativo_codigo' => $a->codigo, 'quota' => $quota, 'criterio_quota' => $q['criterio'], 'parcial' => ! $final,
            'custo_total' => CalculadoraNegocios::dinheiro($a->valor_aquisicao), 'amortizacoes_total' => $acum, 'encargos_venda' => $encargos];
    }

    /**
     * Conclui a venda: factura em Vendas (IVA 0 %), fracções VENDIDA (com trilho), imóvel ALIENADO se nada resta, resultado provisório apurado. O abate do bem em
     * Activos e o lançamento da alienação são o passo seguinte (`abater`). Repetir devolve o resultado já registado.
     *
     * @return array<string, mixed>
     */
    public function concluirVenda(NegocioImobiliario $n): array
    {
        return DB::transaction(function () use ($n) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->tipo !== 'VENDA') {
                throw new ErroNegocio('Este negócio é uma compra.', 'NEG_NAO_E_VENDA');
            }
            if ($n->estado === 'CONCLUIDO') {
                return ['negocio' => $n, 'repetida' => true, 'avisos' => [], 'venda' => $n->venda_id ? ['id' => $n->venda_id] : null];
            }
            if ($n->estado === 'CANCELADO') {
                throw new ErroNegocio('O negócio está cancelado.', 'NEG_FECHADO');
            }
            if ($n->ordem() < NegocioImobiliario::FASES['ESCRITURA']) {
                throw new ErroNegocio('A venda só se conclui depois da escritura.', 'NEG_SEM_ESCRITURA');
            }
            $vivos = $this->contratosVivos($n);
            if ($vivos->isNotEmpty()) {
                throw new ErroNegocio("A venda tem {$vivos->count()} contrato(s) ainda em vigor (".$vivos->pluck('numero')->implode(', ').'): transmita-os ao comprador (terminam aqui) ou termine-os antes de concluir.', 'NEG_CONTRATOS_EM_VIGOR');
            }
            $produto = $this->config->produtoDaVenda();
            $imovel = Imovel::query()->lockForUpdate()->findOrFail($n->imovel_id);
            $fracao = $n->fracao_id ? Fracao::query()->find($n->fracao_id) : null;
            $referencia = $imovel->codigo.' · '.$imovel->nome.($fracao ? ' — '.$fracao->designacao : ' (imóvel inteiro)');
            $resultado = $this->resultadoVenda($n);

            $venda = $this->vendas->emitir(['tipo_documento' => 'FT', 'cliente_id' => $n->terceiro_id, 'data_emissao' => now()->toDateString(),
                'linhas' => [['produto_id' => $produto->id, 'quantidade' => '1', 'preco_unitario' => CalculadoraNegocios::dinheiro($n->preco), 'descricao' => mb_substr("Venda de imóvel — {$n->numero} ({$referencia})", 0, 250)]],
                'observacoes' => "Venda de imóvel {$n->numero}: {$referencia}. Escritura em ".$n->data_escritura->format('Y-m-d').'.']);
            if (bccomp(CalculadoraNegocios::dinheiro($venda->total_bruto), CalculadoraNegocios::dinheiro($n->preco), 2) !== 0) {
                throw new ErroNegocio('O total da factura não bate com o preço da venda. Reveja o artigo da venda de imóveis (IVA 0 %).', 'NEG_FACTURA_NAO_BATE');
            }
            $this->contab->validarFactura($venda);

            $marcadas = 0;
            foreach (Fracao::query()->whereIn('id', $this->idsDasFracoes($n))->orderBy('id')->lockForUpdate()->get() as $f) {
                if ($f->estado !== 'VENDIDA') {
                    $this->fracoes->mudarEstado($f, 'VENDIDA', "Venda {$n->numero}");
                    $marcadas++;
                }
            }
            $restam = Fracao::query()->where('imovel_id', $imovel->id)->where('estado', '<>', 'VENDIDA')->count();
            if ($restam === 0) {
                $imovel->update(['estado' => 'ALIENADO', 'data_alienacao' => $n->data_escritura->format('Y-m-d'), 'atualizado_por' => Autor::nome()]);
            }
            $de = $n->estado;
            $conhecido = $resultado['conhecido'];
            $n->update(['estado' => 'CONCLUIDO', 'concluido_em' => now()->toDateString(), 'venda_id' => $venda->id, 'atualizado_por' => Autor::nome(),
                'ativo_id' => $conhecido ? $resultado['ativo_id'] : null, 'quota' => $conhecido ? $resultado['quota'] : null, 'valor_contabilistico' => $conhecido ? $resultado['valor_liquido'] : null,
                'mais_valia' => $conhecido ? $resultado['mais_valia'] : null, 'resultado_contabilistico' => $conhecido ? $resultado['resultado_contabilistico'] : null]);
            $this->registar($n, 'CONCLUIDO', $de, 'CONCLUIDO', null, ['venda' => $venda->numero_documento, 'fracoes' => $marcadas, 'restam' => $restam]);
            $avisos = [];
            if ($conhecido) {
                $avisos[] = 'O bem ainda não foi abatido em Activos nem lançada a '.($resultado['tipo'] === 'MAIS_VALIA' ? 'mais-valia' : 'menos-valia').': falta o passo «Abater o bem» (outra pessoa).';
            } else {
                $avisos[] = $resultado['motivo'].' A venda concluiu-se à mesma, mas sem resultado apurado nem abate.';
            }

            return ['negocio' => $n->refresh(), 'repetida' => false, 'avisos' => $avisos, 'venda' => ['id' => (int) $venda->id, 'numero' => $venda->numero_documento], 'fracoes' => $marcadas, 'restam' => $restam,
                'resultado' => $resultado];
        });
    }

    /**
     * Abate o bem vendido: regista o abate em Activos (só quando se vendeu a última quota do bem; antes disso o bem continua) e lança a alienação, com a mais ou
     * menos-valia nas contas da empresa. Idempotente: repetir devolve o que já foi lançado.
     *
     * @return array<string, mixed>
     */
    public function abater(NegocioImobiliario $n): array
    {
        return DB::transaction(function () use ($n) {
            $n = NegocioImobiliario::query()->whereKey($n->id)->lockForUpdate()->firstOrFail();
            if ($n->tipo !== 'VENDA' || $n->estado !== 'CONCLUIDO') {
                throw new ErroNegocio('Só se abate o bem de uma venda já concluída.', 'NEG_NAO_CONCLUIDA');
            }
            if ($n->abatido_em !== null) {
                return ['negocio' => $n, 'repetida' => true, 'lancamento' => $n->lancamento_venda, 'avisos' => []];
            }
            if ($n->ativo_id === null) {
                throw new ErroNegocio('Esta venda não tem bem do imobilizado para abater: o imóvel não estava ligado a Activos.', 'NEG_SEM_ATIVO');
            }
            $a = AtivoImobilizado::query()->lockForUpdate()->find($n->ativo_id) ?? throw new ErroNegocio('O bem ligado a este negócio já não existe.', 'NEG_SEM_ATIVO');
            $a = $this->ativos->recalcularAcumulado($a);
            $r = $this->resultadoVenda($n);
            if (! $r['conhecido']) {
                throw new ErroNegocio($r['motivo'], 'NEG_SEM_RESULTADO');
            }
            $cat = CategoriaAtivo::withTrashed()->find($a->categoria_ativo_id);
            $venda = Venda::query()->findOrFail($n->venda_id);
            $avisos = [];
            $abateId = null;
            if (! $r['parcial']) {
                $reg = $this->abates->registar(['ativo_imobilizado_id' => $a->id, 'tipo' => 'VENDA', 'data' => $n->data_escritura->format('Y-m-d'), 'descricao' => "Venda de imóvel {$n->numero}",
                    'valor' => CalculadoraNegocios::dinheiro($n->preco), 'terceiro_id' => $n->terceiro_id, 'conta_terceiro' => $this->contab->contaDoComprador($n), 'taxa_iva' => 0, 'contabilizar' => false]);
                $abateId = (int) $reg['abate']->id;
                $avisos = array_merge($avisos, $reg['avisos']);
            } else {
                $avisos[] = 'Venda parcial: é a quota-parte de '.number_format((float) $r['quota'] * 100, 1, ',', ' ').' % do bem, repartida por '.$r['criterio_quota'].'. O bem continua no imobilizado até se vender o resto.';
            }
            $lan = $this->contab->alienacao($n, $a, $cat, $r, $venda);
            $n->update(['abatido_em' => now(), 'abate_id' => $abateId, 'abate_parcial' => $r['parcial'], 'lancamento_venda' => $lan, 'quota' => $r['quota'], 'valor_contabilistico' => $r['valor_liquido'],
                'mais_valia' => $r['mais_valia'], 'resultado_contabilistico' => $r['resultado_contabilistico'], 'atualizado_por' => Autor::nome()]);
            $this->registar($n, 'ABATIDO', 'CONCLUIDO', 'CONCLUIDO', null, ['lancamento' => $lan, 'quota' => $r['quota'], 'resultado' => $r['resultado_contabilistico']]);

            return ['negocio' => $n->refresh(), 'repetida' => false, 'lancamento' => $lan, 'avisos' => $avisos, 'resultado' => $r];
        });
    }

    // ---------------------------------------------------------------- indicadores e avisos

    /** @return array<string, mixed> */
    public function resumo(?int $ano = null): array
    {
        $ano ??= (int) now()->format('Y');
        $a = (string) $ano;
        $todos = NegocioImobiliario::query()->get();
        $doAno = $todos->filter(fn ($n) => $n->data_escritura !== null && $n->data_escritura->format('Y') === $a && $n->estado !== 'CANCELADO' && $n->ordem() >= NegocioImobiliario::FASES['ESCRITURA']);
        $compras = $doAno->where('tipo', 'COMPRA');
        $vendas = $doAno->where('tipo', 'VENDA');
        $soma = fn ($c, $campo) => (string) $c->reduce(fn ($t, $n) => bcadd($t, CalculadoraNegocios::dinheiro($n->{$campo}), 2), '0.00');
        $investido = $compras->reduce(fn ($t, $n) => bcadd($t, CalculadoraNegocios::dinheiro($n->custo_aquisicao ?? $n->preco), 2), '0.00');
        $mv = $vendas->filter(fn ($n) => $n->mais_valia !== null && (float) $n->mais_valia > 0);
        $menos = $vendas->filter(fn ($n) => $n->mais_valia !== null && (float) $n->mais_valia < 0);
        $abertos = $todos->filter(fn ($n) => ! $n->fechado());
        $sinais = $this->pagoPorNegocio($abertos->pluck('id')->all(), 'SINAL');

        return ['ano' => $ano, 'compras' => $compras->count(), 'investido' => (float) $investido, 'vendas' => $vendas->count(), 'encaixado' => (float) $soma($vendas, 'preco'),
            'mais_valias' => (float) $soma($mv, 'mais_valia'), 'menos_valias' => (float) $soma($menos, 'mais_valia'), 'impostos' => (float) $soma($doAno, 'total_impostos'),
            'impostos_compras' => (float) $soma($compras, 'total_impostos'), 'impostos_vendas' => (float) $soma($vendas, 'total_impostos'),
            'sinais_em_curso' => (float) array_reduce($sinais, fn ($t, $v) => bcadd($t, $v, 2), '0.00'), 'total' => $todos->count(), 'abertos' => $abertos->count(),
            'por_aprovar' => $abertos->whereNull('aprovado_em')->count(), 'abates_pendentes' => $todos->where('tipo', 'VENDA')->where('estado', 'CONCLUIDO')->whereNotNull('ativo_id')->whereNull('abatido_em')->count()];
    }

    /** @return list<array{tipo: string, texto: string, negocio_id: int}> */
    public function avisos(): array
    {
        $r = [];
        foreach (NegocioImobiliario::query()->orderBy('id')->get() as $n) {
            $aviso = function (string $tipo, string $texto) use (&$r, $n) {
                $r[] = ['tipo' => $tipo, 'texto' => $texto, 'negocio_id' => (int) $n->id];
            };
            if ($n->tipo === 'VENDA' && $n->estado === 'CONCLUIDO' && $n->ativo_id !== null && $n->abatido_em === null) {
                $aviso('ABATE_PENDENTE', "{$n->numero}: venda concluída e ainda sem abate do bem nem lançamento da alienação.");
            }
            if ($n->fechado()) {
                continue;
            }
            if ($n->aprovado_em === null) {
                $aviso('POR_APROVAR', "{$n->numero}: negócio por aprovar.");
            }
            if ($n->estado === 'PROMESSA' && $n->data_promessa !== null && $n->data_promessa->diffInDays(now()->startOfDay()) > 180) {
                $aviso('PROMESSA_ANTIGA', "{$n->numero}: promessa assinada há ".(int) $n->data_promessa->diffInDays(now()->startOfDay()).' dias e ainda sem escritura.');
            }
            if ($n->estado === 'ESCRITURA') {
                $aviso('POR_REGISTAR', "{$n->numero}: escriturado em {$n->data_escritura?->format('Y-m-d')} e ainda sem registo na Conservatória: sem registo não é oponível a terceiros.");
            }
            if ($n->estado === 'REGISTADO') {
                $aviso('POR_CONCLUIR', "{$n->numero}: registado e por concluir: concluir leva o valor ao imobilizado (compra) ou apura o resultado (venda).");
            }
            if ($n->tipo === 'VENDA' && $n->ordem() >= NegocioImobiliario::FASES['PROMESSA'] && $n->contratos_destino === null && ($vivos = $this->contratosVivos($n))->isNotEmpty()) {
                $aviso('ARRENDADO', "{$n->numero}: há contrato(s) em vigor ({$vivos->pluck('numero')->implode(', ')}). A venda não faz cessar o arrendamento: decida o que lhes acontece.");
            }
        }

        return $r;
    }

    // ---------------------------------------------------------------- auxiliares

    /**
     * Soma dos pagamentos válidos por negócio.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function pagoPorNegocio(array $ids, ?string $tipo = null): array
    {
        if ($ids === []) {
            return [];
        }
        $q = PagamentoNegocioImo::query()->whereIn('negocio_id', $ids)->where('estado', 'VALIDO')->groupBy('negocio_id')->selectRaw('negocio_id, SUM(valor) AS v');
        if ($tipo !== null) {
            $q->where('tipo', $tipo);
        }
        $r = [];
        foreach ($q->toBase()->get() as $x) {
            $r[(int) $x->negocio_id] = CalculadoraNegocios::dinheiro((string) $x->v);
        }

        return $r;
    }

    /**
     * @param  Collection<int, NegocioImobiliario>  $negocios
     * @return array{imoveis: array<int, array<string, mixed>>, fracoes: array<int, string>, terceiros: array<int, array<string, mixed>>}
     */
    private function nomes($negocios): array
    {
        $imoveis = Imovel::query()->whereIn('id', $negocios->pluck('imovel_id')->filter()->unique()->all())->get(['id', 'codigo', 'nome', 'papel', 'estado'])->keyBy('id')->map(fn ($i) => $i->attributesToArray())->all();
        $fracoes = Fracao::query()->whereIn('id', $negocios->pluck('fracao_id')->filter()->unique()->all())->pluck('designacao', 'id')->all();
        $terceiros = Terceiro::query()->withTrashed()->whereIn('id', $negocios->pluck('terceiro_id')->unique()->all())->get(['id', 'nome', 'nif'])->keyBy('id')->map(fn ($t) => $t->attributesToArray())->all();

        return ['imoveis' => $imoveis, 'fracoes' => $fracoes, 'terceiros' => $terceiros];
    }

    /** @param  array<string, mixed>  $nomes @return array<string, mixed> */
    private function enriquecer(NegocioImobiliario $n, array $nomes): array
    {
        $i = $nomes['imoveis'][$n->imovel_id] ?? null;
        $t = $nomes['terceiros'][$n->terceiro_id] ?? null;

        return ['imovel_nome' => $i['nome'] ?? null, 'imovel_codigo' => $i['codigo'] ?? null, 'imovel_papel' => $i['papel'] ?? null, 'fracao_designacao' => $nomes['fracoes'][$n->fracao_id] ?? null,
            'terceiro_nome' => $t['nome'] ?? null, 'terceiro_nif' => $t['nif'] ?? null];
    }

    /** @param  array<string, mixed>|null  $dados */
    private function registar(NegocioImobiliario $n, string $evento, ?string $de, ?string $para, ?string $motivo = null, ?array $dados = null): void
    {
        HistoricoNegocioImo::create(['negocio_id' => $n->id, 'evento' => $evento, 'estado_de' => $de, 'estado_para' => $para, 'motivo' => $motivo !== null ? mb_substr($motivo, 0, 300) : null,
            'dados' => $dados, 'utilizador_id' => Autor::id()]);
    }
}
