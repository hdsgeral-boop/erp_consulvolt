<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ElegibilidadeProdutividadeRH;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Models\LinhaFolhaSalarial;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PeriodoProdutividadeRH;
use App\Models\RegistoProdutividadeRH;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Subsídio de produtividade (js/modules/rh/produtividade.js). Paridade: catálogo de itens (código único, métrica,
 * preço > 0, rubrica de VENCIMENTO, mínimo/máximo), períodos de medição por mês (janela ≤ 93 dias, sem
 * sobreposição, fechar/reabrir com motivo), registos só de colaboradores com o item na elegibilidade (tabela, não o contrato), preço da elegibilidade
 * (se > 0) ou do item fixado na data do registo, lançamento no processamento (período de produtividade FECHADO,
 * processamento ABERTO), item em uso não se elimina (desactiva-se).
 * Correcções (ADR-039):
 *   - o mínimo e o máximo aplicam-se ao TOTAL do colaborador no item e no período (o legado aplicava-os a cada
 *     registo: dividir a quantidade em registos diários contornava o tecto e mudava o efeito do mínimo);
 *   - um registo por (colaborador, item, data) também na introdução manual (só a importação verificava);
 *   - elegibilidade: colaborador ACTIVO com o item na tabela de elegibilidade (o legado ligava os itens ao contrato; deixou de ser assim);
 *   - relançar retira os lançamentos de produtividade que deixaram de ter valor (o legado deixava-os ficar).
 * Fecho da Versão Actual (#11, produtividade.js:36-160): base de cálculo por item — UNITARIO (quantidade considerada × preço,
 * a regra de sempre; os itens sem base valem como UNITARIO) ou PREMIO, só na métrica OBJECTIVO (prémio a 100 % × % considerada
 * ÷ 100) — e a explicação do cálculo por extenso (fórmula da métrica, conta com os números do caso e cortes de mínimo e tecto).
 */
final class ServicoProdutividade
{
    /** Modos da elegibilidade em massa: acrescentar, substituir a lista toda, retirar. */
    public const MODOS_MASSA = ['ACRESCENTAR', 'SUBSTITUIR', 'RETIRAR'];

    public const METRICAS = ['QUANTIDADE', 'HORAS', 'OBJECTIVO', 'PONTOS', 'TAREFAS'];

    public const BASES = ['UNITARIO', 'PREMIO'];

    public const ORIGEM_LANCAMENTO = 'PRODUTIVIDADE';

    /** Rótulos, fórmula e exemplo de cada métrica (METRICAS, produtividade.js:36) — alimentam o ecrã e a explicação do cálculo. */
    public const DESCRICAO_METRICAS = [
        'QUANTIDADE' => ['nome' => 'Quantidade produzida', 'rotulo_quantidade' => 'Quantidade produzida no período', 'rotulo_preco' => 'Valor por unidade',
            'rotulo_minimo' => 'Quantidade mínima para pagar', 'rotulo_maximo' => 'Quantidade máxima paga',
            'formula' => 'Subsídio = quantidade produzida × valor por unidade', 'exemplo' => '40 m³ × 2 000 Kz/m³ = 80 000 Kz',
            'ajuda' => 'Paga-se o que foi produzido, à peça. Escreve-se a quantidade do período e o sistema multiplica pelo valor da unidade.', 'base_premio' => false,
            'quantidade_exemplo' => 10],
        'HORAS' => ['nome' => 'Horas produtivas', 'rotulo_quantidade' => 'Horas produtivas no período', 'rotulo_preco' => 'Valor por hora',
            'rotulo_minimo' => 'Horas mínimas para pagar', 'rotulo_maximo' => 'Horas máximas pagas',
            'formula' => 'Subsídio = horas produtivas × valor por hora', 'exemplo' => '120 h × 800 Kz/h = 96 000 Kz',
            'ajuda' => 'Paga-se o tempo efectivamente produtivo ou de máquina. Não confundir com horas extraordinárias, que se tratam na Efectividade.', 'base_premio' => false,
            'quantidade_exemplo' => 10],
        'OBJECTIVO' => ['nome' => 'Cumprimento de objectivo (%)', 'rotulo_quantidade' => 'Percentagem atingida do objectivo', 'rotulo_preco' => 'Prémio a 100% do objectivo',
            'rotulo_minimo' => '% mínima para haver prémio', 'rotulo_maximo' => '% máxima considerada (tecto)',
            'formula' => 'Subsídio = prémio a 100% × (% atingida ÷ 100)', 'exemplo' => 'objectivo cumprido a 95% com prémio de 50 000 Kz → 50 000 × 0,95 = 47 500 Kz',
            'ajuda' => 'Define-se quanto vale o objectivo cumprido a 100% e paga-se na proporção do que foi atingido. Use a % mínima (ex.: 80%) para não pagar abaixo disso e o tecto (ex.: 120%) para limitar o excesso.',
            'base_premio' => true, 'quantidade_exemplo' => 95,
            // OBJECTIVO com a base UNITARIO (a regra de sempre): o preço vale por ponto percentual atingido
            'formula_unitario' => 'Subsídio = % atingida × valor por ponto percentual', 'rotulo_preco_unitario' => 'Valor por ponto percentual'],
        'PONTOS' => ['nome' => 'Pontos de desempenho/qualidade', 'rotulo_quantidade' => 'Pontos obtidos na grelha', 'rotulo_preco' => 'Valor por ponto',
            'rotulo_minimo' => 'Pontos mínimos para pagar', 'rotulo_maximo' => 'Pontos máximos pagos',
            'formula' => 'Subsídio = pontos obtidos × valor por ponto', 'exemplo' => '85 pontos × 300 Kz/ponto = 25 500 Kz',
            'ajuda' => 'Para grelhas de avaliação ou de qualidade (ex.: 0 a 100 pontos). Cada ponto vale um valor fixo. Ponha o tecto igual ao máximo da grelha.', 'base_premio' => false,
            'quantidade_exemplo' => 10],
        'TAREFAS' => ['nome' => 'Tarefas / ordens concluídas', 'rotulo_quantidade' => 'Tarefas concluídas no período', 'rotulo_preco' => 'Valor por tarefa concluída',
            'rotulo_minimo' => 'Tarefas mínimas para pagar', 'rotulo_maximo' => 'Tarefas máximas pagas',
            'formula' => 'Subsídio = tarefas concluídas × valor por tarefa', 'exemplo' => '12 entregas × 2 000 Kz/entrega = 24 000 Kz',
            'ajuda' => 'Paga-se por trabalho concluído: ordem de serviço, visita, entrega ou contrato. Só contam as concluídas no período.', 'base_premio' => false,
            'quantidade_exemplo' => 10],
    ];

    /** Bases de cálculo (BASES, produtividade.js:86). */
    public const DESCRICAO_BASES = [
        'UNITARIO' => ['nome' => 'Valor por unidade (quantidade × preço)', 'formula' => 'quantidade × valor unitário'],
        'PREMIO' => ['nome' => 'Prémio proporcional à percentagem atingida', 'formula' => 'prémio × (% atingida ÷ 100)'],
    ];

    /** Base efectiva do item: só PREMIO quando gravado assim; os itens antigos (sem base) valem como UNITARIO (baseDe, produtividade.js:92). */
    public static function baseDe(?ItemProdutividadeRH $i): string
    {
        return $i && $i->base_calculo === 'PREMIO' ? 'PREMIO' : 'UNITARIO';
    }

    /** Métrica conhecida (as desconhecidas valem como QUANTIDADE). */
    public static function metricaDe(?string $m): string
    {
        return in_array($m, self::METRICAS, true) ? $m : 'QUANTIDADE';
    }

    /**
     * Fórmula por palavras da conta que `valor()` faz para esta métrica e base: PREMIO → prémio × % ÷ 100; OBJECTIVO em UNITARIO
     * → % atingida × valor por ponto percentual; as outras → a fórmula da métrica. É a única fonte do texto mostrado no ecrã (#11).
     */
    public static function formula(?string $metrica, string $base): string
    {
        $m = self::metricaDe($metrica);
        if ($base === 'PREMIO') {
            return self::DESCRICAO_METRICAS['OBJECTIVO']['formula'];
        }

        return self::DESCRICAO_METRICAS[$m]['formula_unitario'] ?? self::DESCRICAO_METRICAS[$m]['formula'];
    }

    /** Rótulo do preço do item para a métrica e base (no OBJECTIVO em UNITARIO o preço vale por ponto percentual). */
    public static function rotuloPreco(?string $metrica, string $base): string
    {
        $m = self::metricaDe($metrica);

        return $base === 'PREMIO' ? self::DESCRICAO_METRICAS[$m]['rotulo_preco'] : (self::DESCRICAO_METRICAS[$m]['rotulo_preco_unitario'] ?? self::DESCRICAO_METRICAS[$m]['rotulo_preco']);
    }

    /**
     * Item por gravar, para pré-visualizar a conta no formulário com o MESMO motor (`explicar`/`valor`): parte do item gravado
     * (se houver) e aplica os valores do formulário; a base «prémio» só vale no OBJECTIVO, como em `gravarItem`.
     *
     * @param  array{metrica?: ?string, base_calculo?: ?string, unidade?: ?string, minimo?: mixed, maximo?: mixed, preco?: mixed}  $d
     */
    public static function itemSimulado(?ItemProdutividadeRH $gravado, array $d): ItemProdutividadeRH
    {
        $i = $gravado ? $gravado->replicate() : new ItemProdutividadeRH;
        foreach (['metrica', 'base_calculo', 'unidade', 'minimo', 'maximo'] as $k) {
            if (array_key_exists($k, $d)) {
                $i->setAttribute($k, $d[$k] === '' ? null : $d[$k]);
            }
        }
        if (array_key_exists('preco', $d) && $d['preco'] !== null) {
            $i->setAttribute('preco_unitario', $d['preco']);
        }
        $i->setAttribute('metrica', self::metricaDe($i->metrica));
        $i->setAttribute('base_calculo', $i->base_calculo === 'PREMIO' && $i->metrica === 'OBJECTIVO' ? 'PREMIO' : 'UNITARIO');

        return $i;
    }

    /**
     * Valor de uma quantidade (total do colaborador no item e no período) ao preço indicado, com os cortes de mínimo e tecto.
     * UNITARIO: considerada × preço; PREMIO: preço × (considerada ÷ 100) — calcularValor, produtividade.js:149.
     */
    public static function valor(ItemProdutividadeRH $i, float $quantidade, float $preco): string
    {
        $q = self::considerar($quantidade, $i->minimo !== null ? (float) $i->minimo : null, $i->maximo !== null ? (float) $i->maximo : null);
        $qs = number_format($q, 3, '.', '');
        $bruto = self::baseDe($i) === 'PREMIO' ? bcdiv(bcmul((string) $preco, $qs, 10), '100', 10) : bcmul($qs, (string) $preco, 10);

        return self::arred($bruto, 2);
    }

    /**
     * A conta escrita por extenso, com os números do caso (explicarCalculo, produtividade.js:155).
     *
     * @return array{metrica: string, base_calculo: string, formula: string, rotulo_preco: string, conta: string, cortes: list<string>, quantidade: float, quantidade_considerada: float, preco: float, valor: string}
     */
    public static function explicar(ItemProdutividadeRH $i, float $quantidade, float $preco): array
    {
        $metrica = self::metricaDe($i->metrica);
        $base = self::baseDe($i);
        $min = $i->minimo !== null ? (float) $i->minimo : null;
        $max = $i->maximo !== null ? (float) $i->maximo : null;
        $qc = self::considerar($quantidade, $min, $max);
        $un = (string) ($i->unidade ?: '');
        $n = fn (float $v) => rtrim(rtrim(number_format($v, 4, ',', ' '), '0'), ',');
        $cortes = [];
        if ($min !== null && $quantidade < $min) {
            $cortes[] = "abaixo do mínimo de {$n($min)} {$un} — não há subsídio";
        }
        if ($max !== null && $max > 0 && $quantidade > $max) {
            $cortes[] = "acima do tecto de {$n($max)} {$un} — conta {$n($max)}";
        }
        $conta = $base === 'PREMIO' ? "{$n($preco)} Kz × ({$n($qc)} ÷ 100)" : trim("{$n($qc)} {$un}")." × {$n($preco)} Kz";
        $valor = self::valor($i, $quantidade, $preco);

        return ['metrica' => $metrica, 'base_calculo' => $base, 'formula' => self::formula($metrica, $base), 'rotulo_preco' => self::rotuloPreco($metrica, $base),
            'conta' => $conta.' = '.number_format((float) $valor, 2, ',', ' ').' Kz', 'cortes' => array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', $c)), $cortes),
            'quantidade' => $quantidade, 'quantidade_considerada' => $qc, 'preco' => $preco, 'valor' => $valor];
    }

    // ───────────── Itens ─────────────

    /** @param  array<string, mixed>  $d */
    public function gravarItem(array $d, ?ItemProdutividadeRH $i = null): ItemProdutividadeRH
    {
        $d['codigo'] = mb_strtoupper(trim((string) $d['codigo']));
        if (! preg_match('/^[A-Z0-9._-]{1,20}$/', $d['codigo'])) {
            throw new ErroNegocio('Código inválido: 1 a 20 caracteres (letras, números, «.», «_» ou «-»).', 'CODIGO_INVALIDO', 422);
        }
        if (ItemProdutividadeRH::query()->whereRaw('upper(codigo) = ?', [$d['codigo']])->when($i, fn ($q) => $q->whereKeyNot($i->id))->exists()) {
            throw new ErroNegocio("Já existe o item {$d['codigo']}.", 'CODIGO_DUPLICADO', 422);
        }
        if (InfotipoSalarial::query()->findOrFail($d['infotipo_salarial_id'])->tipo !== 'VENCIMENTO') {
            throw new ErroNegocio('A rubrica do item tem de ser um vencimento.', 'RUBRICA_NAO_VENCIMENTO', 422);
        }
        if (isset($d['minimo'], $d['maximo']) && (float) $d['maximo'] < (float) $d['minimo']) {
            throw new ErroNegocio('O máximo não pode ser inferior ao mínimo.', 'LIMITES_INVALIDOS', 422);
        }
        // base «prémio» só no cumprimento de objectivo (produtividade.js:118); sem base indicada mantém-se a gravada (ou UNITARIO)
        $metrica = $d['metrica'] ?? $i?->metrica ?? 'QUANTIDADE';
        $base = $d['base_calculo'] ?? $i?->base_calculo ?? 'UNITARIO';
        if ($base === 'PREMIO' && $metrica !== 'OBJECTIVO') {
            if (isset($d['base_calculo'])) {
                throw new ErroNegocio('A base «prémio» só se aplica à métrica «Cumprimento de objectivo (%)»; nas outras métricas o subsídio é sempre quantidade × preço.',
                    'BASE_CALCULO_INVALIDA', 422);
            }
            $base = 'UNITARIO';
        }
        $d['base_calculo'] = $base === 'PREMIO' ? 'PREMIO' : 'UNITARIO';
        $mudaBase = $i && self::baseDe($i) !== $d['base_calculo'];
        $user = Auth::user()?->nome_utilizador;
        $i ? $i->update($d + ['atualizado_por' => $user]) : $i = ItemProdutividadeRH::create($d + ['metrica' => 'QUANTIDADE', 'ativo' => true, 'criado_por' => $user]);
        if ($mudaBase) {   // a base mudou: os registos dos períodos ainda abertos passam a valer pela nova conta
            $abertos = PeriodoProdutividadeRH::query()->where('estado', 'ABERTO')->pluck('id');
            RegistoProdutividadeRH::query()->where('item_produtividade_id', $i->id)->whereIn('periodo_produtividade_id', $abertos)
                ->select(['periodo_produtividade_id', 'colaborador_id'])->distinct()->get()
                ->each(fn ($r) => $this->recalcular((int) $r->periodo_produtividade_id, (int) $r->colaborador_id, $i->id));
        }

        return $i->refresh();
    }

    public function eliminarItem(ItemProdutividadeRH $i): void
    {
        $registos = RegistoProdutividadeRH::query()->where('item_produtividade_id', $i->id)->exists();
        $elegibilidade = ElegibilidadeProdutividadeRH::query()->where('item_produtividade_id', $i->id)->exists();
        $entregaveis = DB::table('entregaveis_tarefa_unidade')->where('item_produtividade_id', $i->id)->exists();
        if ($registos || $elegibilidade || $entregaveis) {
            $onde = $registos ? 'registos' : ($elegibilidade ? 'elegibilidade' : 'entregáveis das unidades');
            throw new ErroNegocio("O item {$i->codigo} está em uso ({$onde}): desactive-o em vez de o eliminar.", 'REGISTO_EM_USO', 422);
        }
        $i->delete();
    }

    // ───────────── Períodos ─────────────

    /** @param  array<string, mixed>  $d  mes (AAAA-MM), data_inicio, data_fim, observacoes? */
    public function gravarPeriodo(array $d, ?PeriodoProdutividadeRH $p = null): PeriodoProdutividadeRH
    {
        $mes = ServicoCalendarioRH::mes($d['mes']);
        [$ini, $fim] = [substr($d['data_inicio'], 0, 10), substr($d['data_fim'], 0, 10)];
        if ($p?->estado === 'FECHADO') {
            throw new ErroNegocio('O período está fechado: reabra-o para alterar.', 'PERIODO_FECHADO', 422);
        }
        if ($fim < $ini) {
            throw new ErroNegocio('A data de fim não pode ser anterior à de início.', 'DATAS_INVALIDAS', 422);
        }
        if (count(ServicoCalendarioRH::dias($ini, $fim)) > 93) {
            throw new ErroNegocio('A janela de medição não pode exceder 93 dias.', 'JANELA_EXCESSIVA', 422);
        }

        return DB::transaction(function () use ($d, $p, $mes, $ini, $fim) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ['produtividade_periodos']);
            if (PeriodoProdutividadeRH::query()->where('mes', $mes)->when($p, fn ($q) => $q->whereKeyNot($p->id))->exists()) {
                throw new ErroNegocio("Já existe o período de produtividade de {$mes}.", 'PERIODO_EXISTENTE', 422);
            }
            if (PeriodoProdutividadeRH::query()->when($p, fn ($q) => $q->whereKeyNot($p->id))->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $ini)->exists()) {
                throw new ErroNegocio('A janela de medição sobrepõe-se à de outro período.', 'JANELA_SOBREPOSTA', 422);
            }
            if ($p && RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->whereNotNull('data')->where(fn ($q) => $q->where('data', '<', $ini)->orWhere('data', '>', $fim))->exists()) {
                throw new ErroNegocio('Há registos fora da nova janela de medição.', 'REGISTOS_FORA_DA_JANELA', 422);
            }
            $dados = ['mes' => $mes, 'data_inicio' => $ini, 'data_fim' => $fim, 'observacoes' => $d['observacoes'] ?? $p?->observacoes, 'atualizado_por' => Auth::user()?->nome_utilizador];
            if ($p) {
                $p->update($dados);
                RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->update(['mes' => $mes]);
            } else {
                $p = PeriodoProdutividadeRH::create($dados + ['estado' => 'ABERTO', 'criado_por' => Auth::user()?->nome_utilizador]);
            }

            return $p->refresh();
        });
    }

    public function fecharPeriodo(PeriodoProdutividadeRH $p): PeriodoProdutividadeRH
    {
        if ($p->estado === 'FECHADO') {
            throw new ErroNegocio('O período já está fechado.', 'PERIODO_FECHADO', 422);
        }
        $q = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id);
        $p->update(['estado' => 'FECHADO', 'fechado_em' => now(), 'fechado_por' => Auth::user()?->nome_utilizador, 'total_fecho' => (clone $q)->sum('valor'), 'registos_fecho' => $q->count()]);

        return $p->refresh();
    }

    public function reabrirPeriodo(PeriodoProdutividadeRH $p, string $motivo): PeriodoProdutividadeRH
    {
        if ($p->estado !== 'FECHADO') {
            throw new ErroNegocio('O período não está fechado.', 'PERIODO_NAO_FECHADO', 422);
        }
        $sal = PeriodoProcessamentoSalarial::query()->where('mes_ano', ServicoCalendarioRH::mesSalarial($p->mes))->first();
        if ($sal && $sal->estado !== 'ABERTO') {
            throw new ErroNegocio('O processamento salarial do mês já foi encerrado: reabra-o primeiro.', 'PERIODO_SALARIAL_ENCERRADO', 422);
        }
        $p->update(['estado' => 'ABERTO', 'reaberto_em' => now(), 'reaberto_por' => Auth::user()?->nome_utilizador, 'motivo_reabertura' => $motivo]);

        return $p->refresh();
    }

    // ───────────── Registos ─────────────

    /**
     * Colaboradores ACTIVOS com o item, SÓ pela tabela `elegibilidade_produtividade_rh` (linhas activas de qualquer origem;
     * preço da linha se > 0, senão o do item). Os itens deixaram de pertencer ao contrato de trabalho.
     */
    public function elegiveis(PeriodoProdutividadeRH $p): array
    {
        $itens = ItemProdutividadeRH::query()->where('ativo', true)->get()->keyBy('id');
        $tabela = ElegibilidadeProdutividadeRH::query()->where('ativo', true)->get()->groupBy('colaborador_id');
        $saida = [];
        foreach (Colaborador::query()->where('estado', 'ACTIVO')->ordenarPorNome('nome_completo')->get() as $c) {
            $meus = [];
            foreach ($tabela[$c->id] ?? [] as $e) {
                $item = $itens[(int) $e->item_produtividade_id] ?? null;
                if ($item) {
                    $meus[$item->id] = ['item_id' => $item->id, 'codigo' => $item->codigo, 'preco' => (float) $e->preco_unitario > 0 ? (string) $e->preco_unitario : (string) $item->preco_unitario,
                        'origem' => $e->origem];
                }
            }
            if ($meus) {
                $saida[$c->id] = ['colaborador_id' => $c->id, 'nome' => $c->nome_completo, 'itens' => $meus];
            }
        }

        return $saida;
    }

    /**
     * Torna o colaborador elegível para o item fora do contrato (Fase J, rh_prod_eligibility do legado): idempotente — se a linha
     * já existir, reactiva-a (mantendo a origem mais forte: ENTREGAVEL ganha-se por executar e validar). Devolve a linha e se é nova.
     *
     * @return array{linha: ElegibilidadeProdutividadeRH, novo: bool}
     */
    public function tornarElegivel(int $colaborador, int $item, string $origem = 'MANUAL', ?string $preco = null, ?string $nota = null): array
    {
        $origem = in_array($origem, ElegibilidadeProdutividadeRH::ORIGENS, true) ? $origem : 'MANUAL';
        $i = ItemProdutividadeRH::query()->findOrFail($item);
        if (! $i->ativo) {
            throw new ErroNegocio("O item {$i->codigo} está inactivo.", 'ITEM_INACTIVO', 422);
        }
        Colaborador::query()->findOrFail($colaborador);
        $user = Auth::user()?->nome_utilizador;
        $linha = ElegibilidadeProdutividadeRH::query()->where('colaborador_id', $colaborador)->where('item_produtividade_id', $item)->first();
        if ($linha) {
            $dados = ['ativo' => true, 'atualizado_por' => $user];
            if ($preco !== null && (float) $preco > 0) {
                $dados['preco_unitario'] = $preco;
            }
            if ($nota !== null) {
                $dados['nota'] = $nota;
            }
            if ($origem === 'ENTREGAVEL') {
                $dados['origem'] = 'ENTREGAVEL';
            }
            $linha->update($dados);

            return ['linha' => $linha->refresh(), 'novo' => false];
        }

        return ['linha' => ElegibilidadeProdutividadeRH::create(['colaborador_id' => $colaborador, 'item_produtividade_id' => $item, 'ativo' => true, 'origem' => $origem,
            'preco_unitario' => $preco !== null && (float) $preco > 0 ? $preco : null, 'nota' => $nota, 'criado_por' => $user]), 'novo' => true];
    }

    /**
     * Linhas de elegibilidade (tabela).
     *
     * @return array{linhas: list<array<string, mixed>>}
     */
    public function elegibilidade(?int $colaborador = null, ?int $item = null): array
    {
        $itens = ItemProdutividadeRH::query()->get()->keyBy('id');
        $nomes = Colaborador::query()->withTrashed()->pluck('nome_completo', 'id');
        $linhas = ElegibilidadeProdutividadeRH::query()->when($colaborador, fn ($q, $c) => $q->where('colaborador_id', $c))->when($item, fn ($q, $i) => $q->where('item_produtividade_id', $i))
            ->orderBy('colaborador_id')->orderBy('item_produtividade_id')->get()
            ->map(fn ($e) => $e->toArray() + ['colaborador_nome' => $nomes[$e->colaborador_id] ?? null, 'item_codigo' => $itens[$e->item_produtividade_id]->codigo ?? null,
                'item_descricao' => $itens[$e->item_produtividade_id]->descricao ?? null, 'preco_aplicado' => (float) $e->preco_unitario > 0 ? (string) $e->preco_unitario : (string) ($itens[$e->item_produtividade_id]->preco_unitario ?? '0')])->values()->all();

        return ['linhas' => $linhas];
    }

    /**
     * Elegibilidade em massa (#13/#26 do legado, agora sobre `elegibilidade_produtividade_rh`): os mesmos modos do ecrã individual.
     *   - ACRESCENTAR: junta os itens aos que o colaborador já tem; se o item já lá estiver, SUBSTITUIR o preço próprio ou MANTER o actual
     *     (uma linha inactiva reactiva-se);
     *   - SUBSTITUIR: o colaborador fica só com estes itens (as linhas de outros itens retiram-se, menos as de origem ENTREGAVEL,
     *     que se ganham por executar e validar);
     *   - RETIRAR: tira estes itens da elegibilidade (qualquer origem);
     *   - preço próprio (> 0) ou nulo (= preço do item, lido ao registar a produtividade);
     *   - colaboradores não ACTIVOS ficam de fora (listados no resultado), excepto para RETIRAR;
     *   - tudo numa transacção (tudo-ou-nada), com auditoria; `simular` devolve o plano sem gravar.
     *
     * A selecção vem de `colaboradores` × `itens` (grelha) ou, em alternativa, de `linhas` [{colaborador_id, item_id, preco_unitario?}] (cada
     * colaborador fica com os itens das suas linhas: Excel e vista Linhas).
     *
     * @param  list<int>  $colaboradores
     * @param  list<array{item_id: int, preco_unitario?: mixed}>  $itens
     * @param  list<array{colaborador_id: int, item_id: int, preco_unitario?: mixed}>  $linhas
     * @return array{actualizados: list<string>, sem_alteracao: list<string>, inactivos: list<string>, acrescentadas: int, alteradas: int, retiradas: int, detalhe: list<array<string, mixed>>, simulacao: bool}
     */
    public function aplicarElegibilidadeEmMassa(array $colaboradores, array $itens, string $modo, string $precoExistente, bool $simular, array $linhas = []): array
    {
        $modo = $modo === 'SUBSTITUIR_LISTA' ? 'SUBSTITUIR' : $modo;
        if (! in_array($modo, self::MODOS_MASSA, true)) {
            throw new ErroNegocio('Modo inválido: use ACRESCENTAR, SUBSTITUIR ou RETIRAR.', 'DADOS_INVALIDOS', 422);
        }
        // plano por colaborador: itens × colaboradores (grelha) ou uma linha por colaborador/item com o seu preço (linhas, Excel)
        $planos = [];
        $norm = fn (array $i) => ['item_id' => (int) $i['item_id'], 'preco_unitario' => isset($i['preco_unitario']) && $i['preco_unitario'] !== '' && (float) $i['preco_unitario'] > 0 ? round((float) $i['preco_unitario'], 4) : null];
        if ($linhas !== []) {
            foreach ($linhas as $l) {
                $x = $norm($l);
                $c = (int) $l['colaborador_id'];
                if (isset($planos[$c][$x['item_id']])) {
                    throw new ErroNegocio('Há linhas repetidas (o mesmo colaborador e item) na selecção.', 'LINHAS_REPETIDAS', 422);
                }
                $planos[$c][$x['item_id']] = $x;
            }
        } else {
            $its = [];
            foreach ($itens as $i) {
                $x = $norm($i);
                if (isset($its[$x['item_id']])) {
                    throw new ErroNegocio('Há itens de produtividade repetidos na selecção.', 'ITEM_PRODUTIVIDADE_INVALIDO', 422);
                }
                $its[$x['item_id']] = $x;
            }
            foreach (array_unique(array_map('intval', $colaboradores)) as $c) {
                $planos[$c] = $its;
            }
        }
        if ($planos === [] || in_array([], $planos, true)) {
            throw new ErroNegocio('Escolha pelo menos um colaborador e um item de produtividade.', 'DADOS_INVALIDOS', 422);
        }
        $idsItens = array_values(array_unique(array_merge(...array_map('array_keys', array_values($planos)))));
        $catalogo = ItemProdutividadeRH::query()->whereKey($idsItens)->get()->keyBy('id');
        if ($catalogo->count() !== count($idsItens)) {
            throw new ErroNegocio('Há itens de produtividade inexistentes na selecção.', 'ITEM_PRODUTIVIDADE_INVALIDO', 422);
        }
        if ($modo !== 'RETIRAR' && ($inactivo = $catalogo->first(fn ($i) => ! $i->ativo))) {
            throw new ErroNegocio("O item {$inactivo->codigo} está inactivo: active-o antes de o atribuir.", 'ITEM_PRODUTIVIDADE_INACTIVO', 422);
        }
        $pessoas = Colaborador::query()->whereKey(array_keys($planos))->ordenarPorNome('nome_completo')->get();
        if ($pessoas->count() !== count($planos)) {
            throw new ErroNegocio('Há colaboradores inexistentes na selecção.', 'DADOS_INVALIDOS', 422);
        }
        $user = Auth::user()?->nome_utilizador;
        $res = ['actualizados' => [], 'sem_alteracao' => [], 'inactivos' => [], 'acrescentadas' => 0, 'alteradas' => 0, 'retiradas' => 0, 'detalhe' => [], 'simulacao' => $simular];

        DB::beginTransaction();
        try {
            foreach ($pessoas as $p) {
                $its = $planos[$p->id];
                if ($p->estado !== 'ACTIVO' && $modo !== 'RETIRAR') {
                    $res['inactivos'][] = $p->nome_completo;

                    continue;
                }
                $linhas = ElegibilidadeProdutividadeRH::query()->where('colaborador_id', $p->id)->lockForUpdate()->get()->keyBy('item_produtividade_id');
                $acr = $alt = $ret = [];
                if ($modo === 'RETIRAR') {
                    foreach ($its as $id => $x) {
                        if ($l = $linhas[$id] ?? null) {
                            $l->delete();
                            $ret[] = $catalogo[$id]->codigo;
                        }
                    }
                } else {
                    if ($modo === 'SUBSTITUIR') {
                        foreach ($linhas as $id => $l) {
                            if (! isset($its[$id]) && $l->origem !== 'ENTREGAVEL') {
                                $l->delete();
                                $ret[] = ItemProdutividadeRH::query()->whereKey($id)->value('codigo') ?? "#{$id}";
                            }
                        }
                    }
                    foreach ($its as $id => $x) {
                        $l = $linhas[$id] ?? null;
                        if (! $l) {
                            ElegibilidadeProdutividadeRH::create(['colaborador_id' => $p->id, 'item_produtividade_id' => $id, 'ativo' => true, 'origem' => 'MANUAL',
                                'preco_unitario' => $x['preco_unitario'], 'criado_por' => $user]);
                            $acr[] = $catalogo[$id]->codigo;

                            continue;
                        }
                        $dados = [];
                        if (! $l->ativo) {
                            $dados['ativo'] = true;
                        }
                        $actual = (float) $l->preco_unitario > 0 ? round((float) $l->preco_unitario, 4) : null;
                        if (($modo === 'SUBSTITUIR' || $precoExistente === 'SUBSTITUIR') && $actual !== $x['preco_unitario']) {
                            $dados['preco_unitario'] = $x['preco_unitario'];
                        }
                        if ($dados) {
                            $l->update($dados + ['atualizado_por' => $user]);
                            $alt[] = $catalogo[$id]->codigo;
                        }
                    }
                }
                if (! $acr && ! $alt && ! $ret) {
                    $res['sem_alteracao'][] = $p->nome_completo;

                    continue;
                }
                $res['actualizados'][] = $p->nome_completo;
                $res['acrescentadas'] += count($acr);
                $res['alteradas'] += count($alt);
                $res['retiradas'] += count($ret);
                $res['detalhe'][] = ['colaborador_id' => $p->id, 'colaborador' => $p->nome_completo, 'acrescentados' => $acr, 'alterados' => $alt, 'retirados' => $ret];
            }
            if ($simular) {
                DB::rollBack();
            } else {
                app(ServicoAuditoria::class)->registar('RH', 'Atribuir elegibilidade de produtividade em massa', "{$modo} · ".count($res['actualizados']).' colaborador(es) · itens '
                    .$catalogo->pluck('codigo')->implode(', '), 'elegibilidade_produtividade_rh');
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $res;
    }

    /** @param  array<string, mixed>  $d  colaborador_id, item_produtividade_id, quantidade, data?, observacoes? */
    public function gravarRegisto(PeriodoProdutividadeRH $p, array $d, ?RegistoProdutividadeRH $r = null, string $origem = 'MANUAL'): RegistoProdutividadeRH
    {
        return DB::transaction(function () use ($p, $d, $r, $origem) {
            $p = PeriodoProdutividadeRH::query()->lockForUpdate()->findOrFail($p->id);
            if ($p->estado !== 'ABERTO') {
                throw new ErroNegocio('O período de produtividade está fechado.', 'PERIODO_FECHADO', 422);
            }
            $el = $this->elegiveis($p)[(int) $d['colaborador_id']] ?? throw new ErroNegocio('O colaborador não é elegível: tem de estar ACTIVO e ter o item na elegibilidade de produtividade.', 'NAO_ELEGIVEL', 422);
            $itemEl = $el['itens'][(int) $d['item_produtividade_id']] ?? throw new ErroNegocio('O item não está na elegibilidade do colaborador (ou está inactivo).', 'ITEM_FORA_DO_CONTRATO', 422);
            $item = ItemProdutividadeRH::query()->findOrFail($itemEl['item_id']);
            $data = ! empty($d['data']) ? substr($d['data'], 0, 10) : null;
            if ($data && ($data < $p->data_inicio->toDateString() || $data > $p->data_fim->toDateString() || $data > now()->toDateString())) {
                throw new ErroNegocio('A data tem de estar dentro da janela de medição e não pode ser futura.', 'DATA_FORA_DA_JANELA', 422);
            }
            $q = (float) $d['quantidade'];
            if ($q < 0 || ($item->metrica === 'OBJECTIVO' && $q > 1000)) {
                throw new ErroNegocio('Quantidade inválida.', 'QUANTIDADE_INVALIDA', 422);
            }
            $dup = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->where('colaborador_id', $d['colaborador_id'])
                ->where('item_produtividade_id', $item->id)->when($data, fn ($x) => $x->where('data', $data), fn ($x) => $x->whereNull('data'))
                ->when($r, fn ($x) => $x->whereKeyNot($r->id))->exists();
            if ($dup) {
                throw new ErroNegocio('Já existe um registo deste item para o colaborador nessa data.', 'REGISTO_DUPLICADO', 422);
            }
            $dados = ['periodo_produtividade_id' => $p->id, 'mes' => $p->mes, 'colaborador_id' => (int) $d['colaborador_id'], 'item_produtividade_id' => $item->id, 'data' => $data,
                'quantidade' => $q, 'observacoes' => $d['observacoes'] ?? null, 'origem' => $origem];
            $user = Auth::user()?->nome_utilizador;
            // preço fixado na data do registo (mantém-se numa edição da quantidade)
            $r ? $r->update($dados + ['atualizado_por' => $user]) : $r = RegistoProdutividadeRH::create($dados + ['preco_unitario' => $itemEl['preco'], 'criado_por' => $user]);
            $this->recalcular($p->id, $r->colaborador_id, $item->id);

            return $r->refresh();
        });
    }

    public function eliminarRegisto(RegistoProdutividadeRH $r): void
    {
        DB::transaction(function () use ($r) {
            if (PeriodoProdutividadeRH::query()->lockForUpdate()->findOrFail($r->periodo_produtividade_id)->estado !== 'ABERTO') {
                throw new ErroNegocio('O período de produtividade está fechado.', 'PERIODO_FECHADO', 422);
            }
            $r->delete();
            $this->recalcular($r->periodo_produtividade_id, $r->colaborador_id, $r->item_produtividade_id);
        });
    }

    /**
     * Mínimo/máximo sobre o total do colaborador no item e no período; a quantidade considerada reparte-se pelos
     * registos na proporção da quantidade (valor = quantidade × considerada ÷ total × preço do registo).
     * E-RH-1: o valor calcula-se sem arredondamento intermédio (bcmath) e o último registo absorve o resto, para que a soma
     * dos valores seja o total exacto arredondado ao cêntimo (antes: 3 × 3,333 × 50 000 = 499 950 em vez de 500 000).
     * A quantidade considerada de cada registo fica a 3 casas só para apresentação.
     */
    public function recalcular(int $periodo, int $colaborador, int $item): void
    {
        $i = ItemProdutividadeRH::query()->findOrFail($item);
        $regs = RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $periodo)->where('colaborador_id', $colaborador)->where('item_produtividade_id', $item)->orderBy('id')->get();
        $total = $regs->reduce(fn ($c, $r) => bcadd($c, (string) ($r->quantidade ?? 0), 3), '0.000');
        $considerada = number_format(self::considerar((float) $total, $i->minimo !== null ? (float) $i->minimo : null, $i->maximo !== null ? (float) $i->maximo : null), 3, '.', '');
        $positivo = bccomp($total, '0', 3) > 0;
        // #11: na base «prémio» o preço é o prémio a 100 % e a quantidade é a % atingida → divide-se por 100
        $divisor = self::baseDe($i) === 'PREMIO' ? bcmul($total, '100', 10) : $total;
        $exactos = $regs->map(fn ($r) => $positivo ? bcdiv(bcmul(bcmul((string) $r->quantidade, $considerada, 10), (string) ($r->preco_unitario ?? 0), 10), $divisor, 10) : '0');
        $alvo = self::arred($exactos->reduce(fn ($c, $v) => bcadd($c, $v, 10), '0'), 2);
        $acumulado = '0.00';
        foreach ($regs->values() as $n => $r) {
            $valor = $n === $regs->count() - 1 ? bcsub($alvo, $acumulado, 2) : self::arred($exactos[$n], 2);
            $acumulado = bcadd($acumulado, $valor, 2);
            $qc = $positivo ? self::arred(bcdiv(bcmul((string) $r->quantidade, $considerada, 10), $total, 10), 3) : '0.000';
            $r->update(['quantidade_considerada' => $qc, 'valor' => $valor]);
        }
    }

    /** Arredondamento half-up (bcmath). */
    private static function arred(string $v, int $casas): string
    {
        $ajuste = '0.'.str_repeat('0', $casas).'5';

        return bccomp($v, '0', 10) >= 0 ? bcadd($v, $ajuste, $casas) : bcsub($v, $ajuste, $casas);
    }

    public static function considerar(float $q, ?float $minimo, ?float $maximo): float
    {
        if ($minimo !== null && $q < $minimo) {
            return 0.0;
        }

        return $maximo !== null && $maximo > 0 && $q > $maximo ? $maximo : $q;
    }

    // ───────────── Lançamento no processamento ─────────────

    /** @return array{lancados: int, ignorados: int, removidos: int, total: string} */
    public function lancarNoPeriodo(PeriodoProcessamentoSalarial $sal, bool $substituir = true): array
    {
        return DB::transaction(function () use ($sal, $substituir) {
            $sal = PeriodoProcessamentoSalarial::query()->lockForUpdate()->findOrFail($sal->id);
            if ($sal->estado !== 'ABERTO') {
                throw new ErroNegocio("O processamento de {$sal->mes_ano} não está aberto.", 'PERIODO_ESTADO_INVALIDO', 422);
            }
            $p = PeriodoProdutividadeRH::query()->where('mes', ServicoCalendarioRH::mes($sal->mes_ano))->lockForUpdate()->first()
                ?? throw new ErroNegocio("Não há período de produtividade de {$sal->mes_ano}.", 'SEM_PERIODO_PRODUTIVIDADE', 422);
            if ($p->estado !== 'FECHADO') {
                throw new ErroNegocio('Feche primeiro o período de produtividade.', 'PERIODO_NAO_FECHADO', 422);
            }
            $infotipos = ItemProdutividadeRH::query()->pluck('infotipo_salarial_id', 'id');
            $somas = [];
            foreach (RegistoProdutividadeRH::query()->where('periodo_produtividade_id', $p->id)->get() as $r) {
                $k = $r->colaborador_id.'|'.$infotipos[$r->item_produtividade_id];
                $somas[$k] = bcadd($somas[$k] ?? '0.00', (string) $r->valor, 2);
            }
            $res = ['lancados' => 0, 'ignorados' => 0, 'removidos' => 0, 'total' => '0.00'];
            $manter = [];
            foreach ($somas as $k => $valor) {
                [$colab, $infotipo] = explode('|', $k);
                if (bccomp($valor, '0', 2) <= 0) {
                    continue;
                }
                $existente = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal->id)->where('colaborador_id', $colab)->where('infotipo_salarial_id', $infotipo)->first();
                if ($existente && ! $substituir) {
                    $res['ignorados']++;
                    $manter[] = $existente->id;

                    continue;
                }
                $l = LinhaFolhaSalarial::query()->updateOrCreate(['periodo_processamento_salarial_id' => $sal->id, 'colaborador_id' => (int) $colab, 'infotipo_salarial_id' => (int) $infotipo],
                    ['valor' => $valor, 'horas' => null, 'dias_trabalhados' => null, 'origem' => self::ORIGEM_LANCAMENTO]);
                $manter[] = $l->id;
                $res['lancados']++;
                $res['total'] = bcadd($res['total'], $valor, 2);
            }
            $res['removidos'] = LinhaFolhaSalarial::query()->where('periodo_processamento_salarial_id', $sal->id)->where('origem', self::ORIGEM_LANCAMENTO)
                ->whereNotIn('id', $manter ?: [0])->delete();
            $p->update(['lancado_em' => now(), 'lancado_por' => Auth::user()?->nome_utilizador, 'periodo_processamento_salarial_id' => $sal->id]);

            return $res;
        });
    }
}
