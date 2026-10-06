<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\ContratoTrabalho;
use App\Models\InfotipoSalarial;
use App\Models\ItemProdutividadeRH;
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
 */
final class ServicoContratosTrabalho
{
    public const SEM_FIM = '9999-12-31';

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

        return DB::transaction(function () use ($d, $c, $colab, $dias, $inicio, $fim, $remuneracoes) {
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
                'horas_por_dia' => (string) ($d['horas_por_dia'] ?? $c?->horas_por_dia ?? 8), 'data_inicio' => $inicio, 'data_fim' => $fim,
                'estado' => $d['estado'] ?? $c?->estado ?? 'ACTIVO', 'codigo_moeda' => strtoupper((string) ($d['codigo_moeda'] ?? $c?->codigo_moeda ?? 'AOA'))];
            if (array_key_exists('produtividade', $d)) {
                $ids = array_map(fn ($x) => (int) $x['item_id'], $d['produtividade'] ?? []);
                if (count($ids) !== count(array_unique($ids)) || ItemProdutividadeRH::query()->whereKey($ids)->count() !== count(array_unique($ids))) {
                    throw new ErroNegocio('Itens de produtividade inválidos ou repetidos no contrato.', 'ITEM_PRODUTIVIDADE_INVALIDO', 422);
                }
                // mantêm-se os itens entretanto desactivados (o legado retirava-os ao gravar o contrato)
                $dados['produtividade'] = array_map(fn ($x) => ['item_id' => (int) $x['item_id'], 'preco_unitario' => isset($x['preco_unitario']) && (float) $x['preco_unitario'] > 0
                    ? (float) $x['preco_unitario'] : null], $d['produtividade'] ?? []);
            }
            $c ? $c->update($dados) : $c = ContratoTrabalho::create($dados);

            return $c->refresh();
        });
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
            foreach (Colaborador::query()->whereKey(array_unique($colaboradores))->orderBy('nome_completo')->get() as $colab) {
                if ($colab->estado !== 'ACTIVO') {
                    $res['inactivos'][] = $colab->nome_completo;

                    continue;
                }
                $doColab = ContratoTrabalho::query()->where('colaborador_id', $colab->id)->where('estado', '<>', 'INACTIVO')->orderByDesc('data_inicio')->orderByDesc('id')->get();
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

    /** Terminar: fixa a data de fim; o estado passa a INACTIVO quando a data já passou. */
    public function terminar(ContratoTrabalho $c, string $dataFim): ContratoTrabalho
    {
        $dataFim = substr($dataFim, 0, 10);
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
