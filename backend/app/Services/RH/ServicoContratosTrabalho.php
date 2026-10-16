<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Contratos de trabalho (js/app_v2.js:4261-4720; contratos_massa.js). Paridade:
 *   - colaborador, data de início e pelo menos uma remuneração > 0 obrigatórios; 1–31 dias/mês (22), 8 h/dia,
 *     moeda AOA, estado ACTIVO por omissão; remunerações = rubricas de VENCIMENTO com valor mensal
 *     (o valor diário é derivado: mensal ÷ dias do contrato);
 *   - terminar (estado INACTIVO ou data de fim num contrato sem fim) exige a permissão própria (contratos_terminate).
 * Correcções (ADR-037):
 *   - o legado só admitia UM contrato por colaborador em toda a vida (qualquer estado e data), obrigando a
 *     reescrever o contrato a cada revisão salarial — e, sem fotografia, reescrevia os meses passados. Agora há
 *     histórico: vários contratos, desde que os períodos não se sobreponham;
 *   - data de fim ≥ data de início (só a ferramenta de massa verificava); horas/dia entre 0 e 24; rubricas
 *     sem repetição; o «sem fim» passa a data de fim vazia (o legado gravava 9999-12-31, que continua aceite);
 *   - as remunerações gravam-se com chaves em português (infotipo_id, valor_mes, valor_dia); as migradas mantêm as
 *     chaves do legado — a leitura (ServicoFolhaSalarial::contratoArray) aceita as duas.
 * Fase B (Versão Actual): horário do contrato (RegrasContrato::validarHorario) e estados SUSPENSO/CESSADO só pelos actos
 * de ServicoEstadoContrato (editar não reactiva nem suspende; a massa não mexe em contratos cessados).
 * Fecho da Versão Actual (#8 e #13): motivo do termo e período experimental (Lei 12/23, arts. 13.º e 18.º — colunas fora do
 * esquema gerado, gravadas com forceFill) e itens de produtividade em massa (contratos_produtividade.js).
 */
final class ServicoContratosTrabalho
{
    public const SEM_FIM = '9999-12-31';

    /** Modos da produtividade em massa (contratos_produtividade.js:257): acrescentar, substituir a lista toda, retirar. */
    public const MODOS_PRODUTIVIDADE = ['ACRESCENTAR', 'SUBSTITUIR', 'RETIRAR'];

    /** Período experimental máximo (art. 18.º da Lei 12/23): 180 dias (direcção) sem termo; 30 dias nos contratos a termo. */
    public const EXPERIMENTAL_MAX_SEM_TERMO = 180;

    public const EXPERIMENTAL_MAX_A_TERMO = 30;

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d, ?ContratoTrabalho $c = null): ContratoTrabalho
    {
        $colab = Colaborador::query()->findOrFail($d['colaborador_id']);
        $dias = (int) ($d['dias_contrato_mes'] ?? $c?->dias_contrato_mes ?? 22);
        $inicio = substr((string) $d['data_inicio'], 0, 10);
        $fim = isset($d['data_fim']) && $d['data_fim'] !== '' ? substr((string) $d['data_fim'], 0, 10) : null;
        $fim = $fim === self::SEM_FIM ? null : $fim;
        if ($fim !== null && $fim < $inicio) {
            throw new ErroNegocio('A data de fim não pode ser anterior à data de início.', 'DATAS_INVALIDAS', 422);
        }
        $remuneracoes = $this->remuneracoes($d['remuneracoes'], $dias);
        $estado = $this->estadoAoGravar($d, $c, $fim);
        $minuta = $this->camposMinuta($d, $c, $fim);
        // Fase B (Versão Actual): horário do contrato (1 ou 2 períodos); as «Horas por dia» acompanham o horário (app_v2.js:4751)
        $horario = array_key_exists('horario', $d) ? RegrasContrato::validarHorario($d['horario'], 'O horário do contrato') : RegrasContrato::horario($c?->horario);
        $horasDia = $horario ? (string) RegrasContrato::horasPrevistas($horario) : (string) ($d['horas_por_dia'] ?? $c?->horas_por_dia ?? 8);
        if ((float) $horasDia <= 0 || (float) $horasDia > 24) {
            throw new ErroNegocio('As horas por dia têm de estar entre 0 e 24.', 'HORAS_INVALIDAS', 422);
        }

        return DB::transaction(function () use ($d, $c, $colab, $dias, $inicio, $fim, $remuneracoes, $estado, $horario, $horasDia, $minuta) {
            DB::statement('SELECT pg_advisory_xact_lock(hashtext(?))', ["contratos:{$colab->id}"]);
            $sobreposto = ContratoTrabalho::query()->where('colaborador_id', $colab->id)->when($c, fn ($q) => $q->whereKeyNot($c->id))
                ->where(fn ($q) => $q->whereNull('data_inicio')->orWhere('data_inicio', '<=', $fim ?? self::SEM_FIM))
                ->where(fn ($q) => $q->whereNull('data_fim')->orWhere('data_fim', '>=', $inicio))->first();
            if ($sobreposto) {
                $ate = $sobreposto->data_fim && $sobreposto->data_fim->toDateString() !== self::SEM_FIM ? $sobreposto->data_fim->toDateString() : 'sem fim';
                throw new ErroNegocio("{$colab->nome_completo} já tem um contrato nesse período (#{$sobreposto->id}, de {$sobreposto->data_inicio?->toDateString()} a {$ate}). "
                    .'Termine-o antes de criar o novo.', 'CONTRATO_SOBREPOSTO', 422, ['contrato_id' => $sobreposto->id]);
            }
            $dados = ['colaborador_id' => $colab->id, 'remuneracoes' => $remuneracoes, 'dias_contrato_mes' => $dias,
                'horas_por_dia' => $horasDia, 'data_inicio' => $inicio, 'data_fim' => $fim, 'horario' => $horario,
                'estado' => $estado, 'codigo_moeda' => strtoupper((string) ($d['codigo_moeda'] ?? $c?->codigo_moeda ?? 'AOA'))];
            if (array_key_exists('produtividade', $d)) {
                $ids = array_map(fn ($x) => (int) $x['item_id'], $d['produtividade'] ?? []);
                if (count($ids) !== count(array_unique($ids)) || ItemProdutividadeRH::query()->whereKey($ids)->count() !== count(array_unique($ids))) {
                    throw new ErroNegocio('Itens de produtividade inválidos ou repetidos no contrato.', 'ITEM_PRODUTIVIDADE_INVALIDO', 422);
                }
                // mantêm-se os itens entretanto desactivados (o legado retirava-os ao gravar o contrato)
                $dados['produtividade'] = array_map(fn ($x) => ['item_id' => (int) $x['item_id'], 'preco_unitario' => isset($x['preco_unitario']) && (float) $x['preco_unitario'] > 0
                    ? (float) $x['preco_unitario'] : null], $d['produtividade'] ?? []);
            }
            // motivo do termo e período experimental: colunas fora do esquema gerado (forceFill), gravadas no mesmo save
            $c ??= new ContratoTrabalho;
            $c->fill($dados)->forceFill($minuta)->save();

            return $c->refresh();
        });
    }

    /**
     * Minuta do contrato (Lei n.º 12/23): motivo do termo (art. 13.º/2 — só nos contratos a termo; a falta dele não bloqueia,
     * a minuta sai com o aviso do art. 13.º/3) e período experimental (art. 18.º: até 180 dias sem termo, até 30 a termo).
     *
     * @return array<string, mixed>
     */
    private function camposMinuta(array $d, ?ContratoTrabalho $c, ?string $fim): array
    {
        $saida = [];
        if (array_key_exists('motivo_termo', $d)) {
            $motivo = trim((string) ($d['motivo_termo'] ?? ''));
            $saida['motivo_termo'] = $motivo === '' ? null : mb_substr($motivo, 0, 2000);
        }
        $dias = array_key_exists('periodo_experimental_dias', $d) ? $d['periodo_experimental_dias'] : $c?->getAttribute('periodo_experimental_dias');
        $dias = $dias === null || $dias === '' ? null : (int) $dias;
        if ($dias !== null) {
            $max = $fim === null ? self::EXPERIMENTAL_MAX_SEM_TERMO : self::EXPERIMENTAL_MAX_A_TERMO;
            if ($dias < 0 || $dias > $max) {
                throw new ErroNegocio($fim === null
                    ? 'O período experimental não pode exceder 180 dias (art. 18.º da Lei n.º 12/23: 60 dias, alargável por escrito até 120, ou 180 em funções de direcção).'
                    : 'Nos contratos a termo o período experimental não pode exceder 30 dias (art. 18.º, n.º 3, da Lei n.º 12/23).', 'PERIODO_EXPERIMENTAL_INVALIDO', 422);
            }
        }
        if (array_key_exists('periodo_experimental_dias', $d)) {
            $saida['periodo_experimental_dias'] = $dias;
        }

        return $saida;
    }

    /**
     * #13/#26 — itens de produtividade em massa (aplicarProdutividadeEmMassa, js/modules/rh/contratos_produtividade.js:235):
     *   - ACRESCENTAR: junta os itens aos que o contrato já tem; se o item já lá estiver, SUBSTITUIR o preço ou MANTER o actual;
     *   - SUBSTITUIR: o contrato fica só com estes itens;
     *   - RETIRAR: tira estes itens do contrato;
     *   - preço próprio (> 0) ou nulo (= preço do item, lido ao registar a produtividade);
     *   - contratos INACTIVOS/CESSADOS e colaboradores sem contrato ficam de fora (listados no resultado);
     *   - tudo numa transacção, com auditoria; `simular` devolve o plano sem gravar.
     * A selecção vem por contratos (ids) e/ou por colaboradores (o contrato vigente de cada um).
     *
     * @param  list<int>  $contratos
     * @param  list<int>  $colaboradores
     * @param  list<array{item_id: int, preco_unitario?: mixed}>  $itens
     * @return array{actualizados: list<string>, sem_alteracao: list<string>, inactivos: list<string>, sem_contrato: list<string>, simulacao: bool}
     */
    public function aplicarProdutividadeEmMassa(array $contratos, array $colaboradores, array $itens, string $modo, string $precoExistente, bool $simular): array
    {
        $modo = $modo === 'SUBSTITUIR_LISTA' ? 'SUBSTITUIR' : $modo;
        if (! in_array($modo, self::MODOS_PRODUTIVIDADE, true)) {
            throw new ErroNegocio('Modo inválido: use ACRESCENTAR, SUBSTITUIR ou RETIRAR.', 'DADOS_INVALIDOS', 422);
        }
        if ($itens === [] || ($contratos === [] && $colaboradores === [])) {
            throw new ErroNegocio('Escolha pelo menos um item de produtividade e um contrato.', 'DADOS_INVALIDOS', 422);
        }
        $its = [];
        foreach ($itens as $i) {
            $id = (int) $i['item_id'];
            $preco = isset($i['preco_unitario']) && $i['preco_unitario'] !== '' && (float) $i['preco_unitario'] > 0 ? round((float) $i['preco_unitario'], 4) : null;
            $its[$id] = ['item_id' => $id, 'preco_unitario' => $preco];
        }
        $catalogo = ItemProdutividadeRH::query()->whereKey(array_keys($its))->get()->keyBy('id');
        if ($catalogo->count() !== count($its)) {
            throw new ErroNegocio('Há itens de produtividade inexistentes na selecção.', 'ITEM_PRODUTIVIDADE_INVALIDO', 422);
        }
        if ($modo !== 'RETIRAR' && ($inactivo = $catalogo->first(fn ($i) => ! $i->ativo))) {
            throw new ErroNegocio("O item {$inactivo->codigo} está inactivo: active-o antes de o aplicar aos contratos.", 'ITEM_PRODUTIVIDADE_INACTIVO', 422);
        }
        $res = ['actualizados' => [], 'sem_alteracao' => [], 'inactivos' => [], 'sem_contrato' => [], 'simulacao' => $simular];
        $alvos = ContratoTrabalho::query()->whereKey(array_unique($contratos))->get()->keyBy('id');
        $nomes = Colaborador::query()->whereKey($alvos->pluck('colaborador_id')->merge($colaboradores)->unique()->values()->all())->pluck('nome_completo', 'id');
        $hoje = now()->toDateString();
        foreach (array_unique($colaboradores) as $colab) {
            $doColab = ContratoTrabalho::query()->where('colaborador_id', $colab)->orderByDesc('data_inicio')->orderByDesc('id')->get();
            $abertos = $doColab->whereNotIn('estado', ['INACTIVO', 'CESSADO']);
            $vigente = $abertos->first(fn ($c) => (! $c->data_inicio || $c->data_inicio->toDateString() <= $hoje) && (! $c->data_fim || $c->data_fim->toDateString() >= $hoje))
                ?? $abertos->first();
            if ($vigente) {
                $alvos[$vigente->id] = $vigente;
            } else {
                $res[$doColab->isEmpty() ? 'sem_contrato' : 'inactivos'][] = (string) ($nomes[$colab] ?? "#{$colab}");
            }
        }

        DB::beginTransaction();
        try {
            foreach ($alvos->sortBy(fn ($c) => mb_strtolower((string) ($nomes[$c->colaborador_id] ?? ''))) as $c) {
                $nome = (string) ($nomes[$c->colaborador_id] ?? "Contrato #{$c->id}");
                if (in_array($c->estado, ['INACTIVO', 'CESSADO'], true)) {
                    $res['inactivos'][] = $nome;

                    continue;
                }
                $c = ContratoTrabalho::query()->lockForUpdate()->findOrFail($c->id);
                $antes = self::listaProdutividade($c->produtividade);
                $depois = self::regraProdutividade($antes, array_values($its), $modo, $precoExistente);
                if (self::ordenarProdutividade($antes) === self::ordenarProdutividade($depois)) {
                    $res['sem_alteracao'][] = $nome;

                    continue;
                }
                $c->update(['produtividade' => $depois]);
                $res['actualizados'][] = $nome;
            }
            if ($simular) {
                DB::rollBack();
            } else {
                app(ServicoAuditoria::class)->registar('RH', 'Aplicar produtividade em massa', "{$modo} · ".count($res['actualizados']).' contrato(s) · itens '
                    .$catalogo->pluck('codigo')->implode(', '), 'contratos_trabalho');
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $res;
    }

    /** @return list<array{item_id: int, preco_unitario: ?float}> lista do contrato normalizada (preço 0/vazio = preço do item) */
    public static function listaProdutividade(mixed $lista): array
    {
        $lista = is_array($lista) ? $lista : (json_decode((string) $lista, true) ?: []);

        return array_values(array_map(fn ($x) => ['item_id' => (int) $x['item_id'], 'preco_unitario' => isset($x['preco_unitario']) && (float) $x['preco_unitario'] > 0
            ? round((float) $x['preco_unitario'], 4) : null], array_filter($lista, fn ($x) => is_array($x) && (int) ($x['item_id'] ?? 0) > 0)));
    }

    /**
     * Regra única da massa (aplicarRegra, contratos_produtividade.js:257).
     *
     * @param  list<array{item_id: int, preco_unitario: ?float}>  $antes
     * @param  list<array{item_id: int, preco_unitario: ?float}>  $its
     * @return list<array{item_id: int, preco_unitario: ?float}>
     */
    public static function regraProdutividade(array $antes, array $its, string $modo, string $precoExistente): array
    {
        if ($modo === 'SUBSTITUIR') {
            return $its;
        }
        if ($modo === 'RETIRAR') {
            $fora = array_column($its, 'item_id');

            return array_values(array_filter($antes, fn ($x) => ! in_array($x['item_id'], $fora, true)));
        }
        $out = $antes;
        foreach ($its as $i) {
            $k = array_search($i['item_id'], array_column($out, 'item_id'), true);
            if ($k === false) {
                $out[] = $i;
            } elseif ($precoExistente === 'SUBSTITUIR') {
                $out[$k] = $i;
            }
        }

        return array_values($out);
    }

    private static function ordenarProdutividade(array $l): string
    {
        usort($l, fn ($a, $b) => $a['item_id'] <=> $b['item_id']);

        return (string) json_encode($l);
    }

    /**
     * A-09 — aplicar rubricas em massa (aplicarRubricasEmMassa, js/modules/rh/contratos_massa.js): uma ou mais rubricas
     * de vencimento, cada uma com o MESMO valor mensal para todos, aplicadas aos colaboradores escolhidos:
     *   - com contrato vigente (ACTIVO e válido hoje; senão o último ACTIVO): acrescenta a rubrica; se já a tiver,
     *     SUBSTITUIR o valor ou MANTER (à escolha);
     *   - sem contrato: cria-o (início, dias/mês, horas/dia, moeda) com essas rubricas, se `criar`;
     *   - colaboradores não ACTIVOS e contratos INACTIVOS não são alterados.
     * Tudo numa transacção (nada fica a meio); `simular` devolve o plano sem gravar.
     *
     * @param  list<int>  $colaboradores
     * @param  list<array{infotipo_salarial_id: int, valor_mes: mixed}>  $rubricas
     * @param  array{data_inicio?: string, dias_contrato_mes?: int, horas_por_dia?: mixed, codigo_moeda?: string}  $novos
     * @return array{actualizados: list<string>, criados: list<string>, sem_alteracao: list<string>, inactivos: list<string>, sem_contrato: list<string>, simulacao: bool}
     */
    public function aplicarRubricasEmMassa(array $colaboradores, array $rubricas, string $modo, bool $criar, array $novos, bool $simular): array
    {
        if ($rubricas === [] || $colaboradores === []) {
            throw new ErroNegocio('Escolha pelo menos um colaborador e uma rubrica com valor.', 'DADOS_INVALIDOS', 422);
        }
        if ($criar && empty($novos['data_inicio'])) {
            throw new ErroNegocio('Indique a data de início dos contratos a criar.', 'DADOS_INVALIDOS', 422);
        }
        $res = ['actualizados' => [], 'criados' => [], 'sem_alteracao' => [], 'inactivos' => [], 'sem_contrato' => [], 'simulacao' => $simular];
        DB::beginTransaction();
        try {
            $hoje = now()->toDateString();
            foreach (Colaborador::query()->whereKey(array_unique($colaboradores))->ordenarPorNome('nome_completo')->get() as $colab) {
                if ($colab->estado !== 'ACTIVO') {
                    $res['inactivos'][] = $colab->nome_completo;

                    continue;
                }
                $doColab = ContratoTrabalho::query()->where('colaborador_id', $colab->id)->whereNotIn('estado', ['INACTIVO', 'CESSADO'])->orderByDesc('data_inicio')->orderByDesc('id')->get();
                $vigente = $doColab->first(fn ($c) => (! $c->data_inicio || $c->data_inicio->toDateString() <= $hoje) && (! $c->data_fim || $c->data_fim->toDateString() >= $hoje))
                    ?? $doColab->first();
                if (! $vigente) {
                    if (! $criar) {
                        $res['sem_contrato'][] = $colab->nome_completo;

                        continue;
                    }
                    $this->guardar(['colaborador_id' => $colab->id, 'data_inicio' => $novos['data_inicio'], 'data_fim' => null, 'estado' => 'ACTIVO',
                        'dias_contrato_mes' => $novos['dias_contrato_mes'] ?? 22, 'horas_por_dia' => $novos['horas_por_dia'] ?? 8, 'codigo_moeda' => $novos['codigo_moeda'] ?? 'AOA',
                        'remuneracoes' => array_map(fn ($r) => ['infotipo_salarial_id' => (int) $r['infotipo_salarial_id'], 'valor_mes' => $r['valor_mes']], $rubricas)]);
                    $res['criados'][] = $colab->nome_completo;

                    continue;
                }
                $dias = max(1, (int) ($vigente->dias_contrato_mes ?: 22));
                $actuais = [];
                foreach ((array) (is_array($vigente->remuneracoes) ? $vigente->remuneracoes : json_decode((string) $vigente->remuneracoes, true)) as $x) {
                    $id = (int) ($x['infotipo_id'] ?? $x['infotype_id'] ?? 0);
                    if ($id > 0) {
                        $mes = $x['valor_mes'] ?? $x['value_month'] ?? null;
                        $actuais[$id] = round($mes !== null && $mes !== '' ? (float) $mes : (float) ($x['valor_dia'] ?? $x['value_per_day'] ?? 0) * $dias, 2);
                    }
                }
                $muda = false;
                foreach ($rubricas as $r) {
                    $id = (int) $r['infotipo_salarial_id'];
                    $v = round((float) $r['valor_mes'], 2);
                    if (! array_key_exists($id, $actuais) || ($modo === 'SUBSTITUIR' && $actuais[$id] !== $v)) {
                        $actuais[$id] = $v;
                        $muda = true;
                    }
                }
                if (! $muda) {
                    $res['sem_alteracao'][] = $colab->nome_completo;

                    continue;
                }
                $this->guardar(['colaborador_id' => $colab->id, 'data_inicio' => $vigente->data_inicio?->toDateString() ?? $hoje, 'data_fim' => $vigente->data_fim?->toDateString(),
                    'remuneracoes' => array_map(fn ($id, $v) => ['infotipo_salarial_id' => $id, 'valor_mes' => $v], array_keys($actuais), $actuais)], $vigente);
                $res['actualizados'][] = $colab->nome_completo;
            }
            $simular ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $res;
    }

    /**
     * Estado ao gravar pelo formulário (Fase B, app_v2.js ≈ 4451 e 4883): editar o contrato NUNCA o reactiva sozinho —
     * um contrato SUSPENSO ou CESSADO mantém o estado; suspender e cessar fazem-se pelos actos próprios
     * (ServicoEstadoContrato), que registam o fundamento. A data de fim de um contrato cessado é a da cessação.
     */
    private function estadoAoGravar(array $d, ?ContratoTrabalho $c, ?string $fim): string
    {
        $actual = $c?->estado;
        $pedido = $d['estado'] ?? $actual ?? 'ACTIVO';
        if (in_array($actual, ['SUSPENSO', 'CESSADO'], true)) {
            if ($actual === 'CESSADO' && $fim !== $c->data_fim?->toDateString()) {
                throw new ErroNegocio('O contrato está cessado: a data de fim é a da cessação. Para a mudar, anule primeiro a cessação.', 'CONTRATO_CESSADO', 422);
            }

            return $actual;
        }
        if (in_array($pedido, ['SUSPENSO', 'CESSADO'], true)) {
            throw new ErroNegocio($pedido === 'SUSPENSO' ? 'Para suspender o contrato use a acção «Suspender» (regista o período e o fundamento).'
                : 'Para cessar o contrato use a acção «Cessar» (regista o fundamento e a compensação).', 'USAR_ACTO_CONTRATO', 422);
        }

        return $pedido;
    }

    /** Terminar: fixa a data de fim; o estado passa a INACTIVO quando a data já passou. */
    public function terminar(ContratoTrabalho $c, string $dataFim): ContratoTrabalho
    {
        $dataFim = substr($dataFim, 0, 10);
        if ($c->estado === 'CESSADO') {
            throw new ErroNegocio('O contrato já está cessado.', 'CONTRATO_CESSADO', 422);
        }
        if ($c->data_inicio && $dataFim < $c->data_inicio->toDateString()) {
            throw new ErroNegocio('A data de fim não pode ser anterior à data de início.', 'DATAS_INVALIDAS', 422);
        }
        $c->update(['data_fim' => $dataFim, 'estado' => $dataFim < now()->toDateString() ? 'INACTIVO' : $c->estado]);

        return $c->refresh();
    }

    /** Uma alteração que termina o contrato? (exige contratos_terminate) */
    public static function terminaContrato(?ContratoTrabalho $c, array $d): bool
    {
        if (($d['estado'] ?? null) === 'INACTIVO' && $c?->estado !== 'INACTIVO') {
            return true;
        }
        $semFim = ! $c || ! $c->data_fim || $c->data_fim->toDateString() === self::SEM_FIM;

        return $c && $semFim && ! empty($d['data_fim']) && $d['data_fim'] !== self::SEM_FIM;
    }

    /** Remunerações normalizadas: VENCIMENTO, sem repetições, pelo menos uma > 0; valor diário derivado. */
    private function remuneracoes(array $lista, int $dias): array
    {
        $ids = array_map(fn ($r) => (int) $r['infotipo_salarial_id'], $lista);
        if (count($ids) !== count(array_unique($ids))) {
            throw new ErroNegocio('Cada rubrica só pode aparecer uma vez no contrato.', 'RUBRICA_REPETIDA', 422);
        }
        $infotipos = InfotipoSalarial::query()->whereKey($ids)->get()->keyBy('id');
        $saida = [];
        $positivo = false;
        foreach ($lista as $r) {
            $i = $infotipos[(int) $r['infotipo_salarial_id']] ?? throw new ErroNegocio('Rubrica inexistente no contrato.', 'RUBRICA_INEXISTENTE', 422);
            if ($i->tipo !== 'VENCIMENTO') {
                throw new ErroNegocio("A rubrica {$i->nome} não é um vencimento: o contrato só tem remunerações.", 'RUBRICA_NAO_VENCIMENTO', 422);
            }
            $mes = number_format((float) $r['valor_mes'], 2, '.', '');
            $positivo = $positivo || bccomp($mes, '0', 2) > 0;
            $saida[] = ['infotipo_id' => $i->id, 'valor_mes' => (float) $mes, 'valor_dia' => round((float) $mes / max(1, $dias), 4)];
        }
        if (! $positivo) {
            throw new ErroNegocio('O contrato tem de ter pelo menos uma remuneração com valor.', 'SEM_REMUNERACAO', 422);
        }

        return $saida;
    }
}
