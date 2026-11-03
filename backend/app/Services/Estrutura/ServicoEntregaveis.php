<?php

namespace App\Services\Estrutura;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\EntregavelTarefaUnidade;
use App\Models\ExecucaoEntregavel;
use App\Models\ImpedimentoEntregavel;
use App\Models\ItemProdutividadeRH;
use App\Models\PostoTrabalho;
use App\Models\TarefaUnidadeOrganica;
use App\Models\UnidadeOrganica;
use App\Services\RH\ServicoProdutividade;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Tarefas das unidades orgânicas, entregáveis mensuráveis, execuções (entregas) validadas pelo responsável, impedimentos e
 * atrasos (Fase J, ADR-078 — js/modules/estrutura/estrutura_tarefas.js). Paridade:
 *   - prazo «dia D do mês M do período» (M até meses+1); o período SERVIDO por uma entrega recua M−1 meses (com prazo «dia 15
 *     do mês seguinte», o que se entrega em Fevereiro é a obrigação de Janeiro); dia 31 num mês curto cai no último dia;
 *   - unidades de medida comparadas sem acentos e com sinónimos (m² = m2, un = unidades, h = horas, ton, %);
 *   - só se eliminam tarefas e entregáveis sem execuções («desactive em vez de apagar»); a cópia não leva o dono nem as entregas;
 *   - ninguém valida a entrega que fez nem a que lançou; valida o responsável da unidade (ou de uma acima) ou quem tem a tarefa
 *     `est_validar_entregas`; VALIDADA torna o executor elegível para o item; DEVOLVIDA volta ao executor, que a reenvia;
 *   - PENDENTE e VALIDADA contam como entregue; RECUSADA e DEVOLVIDA não (realizado, avaliação, atrasos);
 *   - impedimento (um não recusado por entregável e período) aceite pelo responsável justifica o período e tira-o dos atrasos;
 *   - nada se edita nem elimina depois de convertida em produtividade (`periodo_produtividade_id`).
 * Correcção do legado (estrutura_tarefas.js usava `parent_id`, campo inexistente em org_units): aqui a cadeia de responsáveis
 * SOBE mesmo a árvore por `unidade_organica_pai_id` (ignorando unidades inactivas, ciclos cortados a 200) e o âmbito «unidades
 * por que respondo» DESCE a árvore (unidade + descendentes).
 */
final class ServicoEntregaveis
{
    public const MODULO_AUDITORIA = 'Estrutura orgânica › Tarefas';

    /** Meses de cada periodicidade (MESES_PERIODO, estrutura_tarefas.js:61). */
    public const MESES_PERIODO = ['DIARIA' => 1, 'SEMANAL' => 1, 'MENSAL' => 1, 'TRIMESTRAL' => 3, 'ANUAL' => 12, 'PONTUAL' => 1];

    public const DECISOES = ['VALIDADA', 'DEVOLVIDA', 'RECUSADA'];

    /** Sinónimos das unidades de medida (SINONIMOS, estrutura_tarefas.js:133). */
    private const SINONIMOS = [
        'm2' => 'm2', 'm²' => 'm2', 'metrosquadrados' => 'm2', 'm3' => 'm3', 'm³' => 'm3', 'metroscubicos' => 'm3',
        'm' => 'metros', 'metro' => 'metros', 'metros' => 'metros', 'un' => 'unidades', 'unidade' => 'unidades', 'unidades' => 'unidades',
        'pecas' => 'pecas', 'peca' => 'pecas', 'h' => 'horas', 'hora' => 'horas', 'horas' => 'horas',
        'ton' => 'toneladas', 't' => 'toneladas', 'tonelada' => 'toneladas', 'toneladas' => 'toneladas', 'pct' => '%', 'percentagem' => '%',
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoProdutividade $produtividade,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Apoio: utilizador, unidades de medida, URLs ─────────────

    /** Colaborador ligado ao utilizador autenticado na empresa activa (utilizador_empresa.colaborador_id). */
    public function colaboradorDe(): ?int
    {
        $id = Auth::id() ? DB::table('utilizador_empresa')->where('utilizador_id', Auth::id())->where('empresa_id', $this->contexto->obrigatorio())->value('colaborador_id') : null;

        return $id === null ? null : (int) $id;
    }

    /** Chave de comparação de uma unidade de medida: sem acentos, minúsculas, sem espaços, com sinónimos. */
    public static function chaveUnidade(?string $u): string
    {
        $bruto = mb_strtolower(trim((string) $u));
        if (isset(self::SINONIMOS[$bruto])) {
            return self::SINONIMOS[$bruto];
        }
        $s = preg_replace('/\s+/u', '', self::semAcentos($bruto));

        return self::SINONIMOS[$s] ?? $s;
    }

    public static function semAcentos(string $s): string
    {
        $n = class_exists(\Normalizer::class) ? \Normalizer::normalize($s, \Normalizer::FORM_D) : $s;

        return (string) preg_replace('/\p{Mn}+/u', '', $n ?: $s);
    }

    /** Só http(s); acrescenta https:// quando falta o esquema; rejeita outros esquemas e credenciais no endereço (normalizarUrl, :147). */
    public static function normalizarUrl(?string $valor, string $campo = 'Endereço'): ?string
    {
        $v = trim((string) $valor);
        if ($v === '') {
            return null;
        }
        $comEsquema = preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $v) ? $v : 'https://'.$v;
        $p = parse_url($comEsquema);
        if ($p === false || empty($p['host']) || ! in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) {
            throw new ErroNegocio("{$campo}: «{$v}» não é um endereço válido (só http:// ou https://). Exemplo: https://…", 'URL_INVALIDO', 422);
        }
        if (isset($p['user']) || isset($p['pass'])) {
            throw new ErroNegocio("{$campo}: o endereço não pode conter credenciais.", 'URL_INVALIDO', 422);
        }

        return mb_substr($comEsquema, 0, 500);
    }

    // ───────────── Prazo e período servido (estrutura_tarefas.js:55-128) ─────────────

    public static function mesesDoPeriodo(?string $periodicidade): int
    {
        return self::MESES_PERIODO[$periodicidade ?? ''] ?? 1;
    }

    /** Primeiro mês (ano, mes) do período que contém a data, para a periodicidade. */
    public static function inicioDoPeriodo(?string $periodicidade, string $data): array
    {
        $ano = (int) substr($data, 0, 4);
        $mes = (int) substr($data, 5, 2);
        if ($periodicidade === 'TRIMESTRAL') {
            return [$ano, intdiv($mes - 1, 3) * 3 + 1];
        }
        if ($periodicidade === 'ANUAL') {
            return [$ano, 1];
        }

        return [$ano, $mes];
    }

    public static function iso(int $ano, int $mes, int $dia): string
    {
        while ($mes > 12) {
            $mes -= 12;
            $ano++;
        }
        while ($mes < 1) {
            $mes += 12;
            $ano--;
        }
        $ultimo = (int) date('t', mktime(0, 0, 0, $mes, 1, $ano));

        return sprintf('%04d-%02d-%02d', $ano, $mes, min($dia, $ultimo));
    }

    /**
     * Período (obrigação) que uma entrega feita nesta data está a servir: recua prazo_mes − 1 meses e devolve [inicio, fim] do
     * período civil (mês, trimestre ou ano) que contém a data recuada (periodoServido, :105).
     *
     * @return array{inicio: string, fim: string}
     */
    public static function periodoServido(EntregavelTarefaUnidade $e, string $periodicidade, string $dataEntrega): array
    {
        $mesRel = max(1, (int) ($e->prazo_mes ?: 1));
        $ano = (int) substr($dataEntrega, 0, 4);
        $mes = (int) substr($dataEntrega, 5, 2) - ($mesRel - 1);
        [$a, $m] = self::inicioDoPeriodo($periodicidade, self::iso($ano, $mes, 1));

        return ['inicio' => self::iso($a, $m, 1), 'fim' => self::iso($a, $m + self::mesesDoPeriodo($periodicidade) - 1, 31)];
    }

    /** Data-limite da obrigação cujo período começa em $periodoInicio: dia prazo_dia do mês inicio + (prazo_mes − 1); sem prazo_dia → null. */
    public static function limiteDoPeriodo(EntregavelTarefaUnidade $e, string $periodoInicio): ?string
    {
        $dia = (int) $e->prazo_dia;
        if ($dia < 1 || $dia > 31) {
            return null;
        }

        return self::iso((int) substr($periodoInicio, 0, 4), (int) substr($periodoInicio, 5, 2) + max(1, (int) ($e->prazo_mes ?: 1)) - 1, $dia);
    }

    /** Data-limite da obrigação que esta entrega serve (limiteDaEntrega, :114). */
    public static function limiteDaEntrega(EntregavelTarefaUnidade $e, string $periodicidade, string $dataEntrega): ?string
    {
        return self::limiteDoPeriodo($e, self::periodoServido($e, $periodicidade, $dataEntrega)['inicio']);
    }

    /** Uma entrega está fora de prazo se passou do limite da obrigação que serve (foraDePrazo, :125). */
    public static function foraDePrazo(EntregavelTarefaUnidade $e, string $periodicidade, string $dataEntrega): bool
    {
        $limite = self::limiteDaEntrega($e, $periodicidade, $dataEntrega);

        return $limite !== null && substr($dataEntrega, 0, 10) > $limite;
    }

    /** Janela de datas das entregas que servem o período iniciado em $periodoInicio (o período deslocado prazo_mes − 1 meses). */
    public static function janelaDeEntrega(EntregavelTarefaUnidade $e, string $periodicidade, string $periodoInicio): array
    {
        $desloc = max(1, (int) ($e->prazo_mes ?: 1)) - 1;
        $a = (int) substr($periodoInicio, 0, 4);
        $m = (int) substr($periodoInicio, 5, 2);

        return ['de' => self::iso($a, $m + $desloc, 1), 'ate' => self::iso($a, $m + $desloc + self::mesesDoPeriodo($periodicidade) - 1, 31)];
    }

    /** O prazo por palavras (prazoTexto, :93). */
    public static function prazoTexto(EntregavelTarefaUnidade $e, string $periodicidade): string
    {
        $dia = (int) $e->prazo_dia;
        if ($dia < 1) {
            return '';
        }
        $n = self::mesesDoPeriodo($periodicidade);
        $mesRel = max(1, (int) ($e->prazo_mes ?: 1));
        $texto = $mesRel > $n ? ($periodicidade === 'MENSAL' ? 'do mês seguinte' : 'do mês seguinte ao período')
            : ($periodicidade === 'MENSAL' ? 'do mês corrente' : "do {$mesRel}.º mês do período");

        return "dia {$dia} {$texto}";
    }

    // ───────────── Árvore: cadeia de responsáveis (sobe) e âmbito (desce) ─────────────

    /** @return Collection<int, UnidadeOrganica> todas as unidades da empresa (activas e inactivas), por id */
    public function unidades(): Collection
    {
        return UnidadeOrganica::query()->get(['id', 'nome', 'codigo', 'unidade_organica_pai_id', 'colaborador_responsavel_id', 'ativo'])->keyBy('id');
    }

    /** As unidades indicadas e todas as suas descendentes (ciclos cortados). @return list<int> */
    public function descendentes(array $ids, ?Collection $unidades = null): array
    {
        $unidades ??= $this->unidades();
        $filhos = $unidades->groupBy(fn ($u) => (int) ($u->unidade_organica_pai_id ?? 0));
        $vistos = [];
        $fila = array_map('intval', $ids);
        while ($fila) {
            $id = array_shift($fila);
            if (isset($vistos[$id]) || ! $unidades->has($id)) {
                continue;
            }
            $vistos[$id] = true;
            foreach ($filhos[$id] ?? [] as $f) {
                $fila[] = (int) $f->id;
            }
        }

        return array_map('intval', array_keys($vistos));
    }

    /** Unidades por que o colaborador responde (é responsável) e as que lhes estão abaixo. @return list<int> */
    public function unidadesPorQueRespondo(int $colaborador, ?Collection $unidades = null): array
    {
        $unidades ??= $this->unidades();
        $minhas = $unidades->filter(fn ($u) => (int) $u->colaborador_responsavel_id === $colaborador && $u->ativo !== false)->keys()->all();

        return $minhas ? $this->descendentes($minhas, $unidades) : [];
    }

    /**
     * Responsáveis que podem validar nesta unidade: o da própria unidade e os das unidades acima (sobe por unidade_organica_pai_id,
     * ignora inactivas, corta ciclos a 200).
     *
     * @return list<array{unidade_id: int, unidade_nome: string, colaborador_id: int}>
     */
    public function responsaveisQuePodemValidar(int $unidadeId, ?Collection $unidades = null): array
    {
        $unidades ??= $this->unidades();
        $cadeia = [];
        $vistos = [];
        for ($u = $unidades->get($unidadeId), $n = 0; $u && $n < 200 && ! isset($vistos[$u->id]); $n++) {
            $vistos[$u->id] = true;
            if ($u->ativo !== false && $u->colaborador_responsavel_id) {
                $cadeia[] = ['unidade_id' => (int) $u->id, 'unidade_nome' => (string) $u->nome, 'colaborador_id' => (int) $u->colaborador_responsavel_id];
            }
            $u = $u->unidade_organica_pai_id ? $unidades->get((int) $u->unidade_organica_pai_id) : null;
        }

        return $cadeia;
    }

    /**
     * Pode o utilizador actual validar esta entrega? Nunca quem executou nem quem registou; sim um responsável da cadeia
     * (RESPONSAVEL); senão quem tem `est_validar_entregas` (PERMISSAO; superadministrador/acesso total contam) — podeValidar, :836.
     *
     * @return array{pode: bool, como?: string, motivo?: string}
     */
    public function podeValidar(ExecucaoEntregavel $x, ?Collection $unidades = null): array
    {
        $eu = $this->colaboradorDe();
        $utilizador = (string) (Auth::user()?->nome_utilizador ?? '');
        $cadeia = $this->responsaveisQuePodemValidar((int) $x->unidade_organica_id, $unidades);
        $minha = $eu !== null && (int) $x->colaborador_id === $eu;
        $lancei = ($eu !== null && (int) $x->registado_por_colaborador_id === $eu) || ($utilizador !== '' && (string) $x->registado_por === $utilizador);
        if ($minha || $lancei) {
            $acima = collect($cadeia)->first(fn ($c) => $c['colaborador_id'] !== $eu);
            $quem = $minha ? 'Esta entrega é sua' : 'Foi o próprio a registar esta entrega';

            return ['pode' => false, 'motivo' => $acima ? "{$quem}: tem de ser validada pelo responsável de «{$acima['unidade_nome']}»."
                : "{$quem} e ninguém valida o que lançou. Peça ao responsável acima na estrutura ou a quem tem a permissão de validação de entregas."];
        }
        if ($eu !== null && collect($cadeia)->contains(fn ($c) => $c['colaborador_id'] === $eu)) {
            return ['pode' => true, 'como' => 'RESPONSAVEL'];
        }
        if (Gate::allows('est_validar_entregas')) {
            return ['pode' => true, 'como' => 'PERMISSAO'];
        }
        $nomes = implode(', ', array_column($cadeia, 'unidade_nome'));

        return ['pode' => false, 'motivo' => $cadeia ? "Só o responsável da unidade ({$nomes}) pode validar esta entrega."
            : 'Esta unidade não tem responsável definido. Defina-o na estrutura orgânica ou peça a quem tem a permissão de validação de entregas.'];
    }

    /**
     * Papel do colaborador neste entregável: dono (colaborador_id) e/ou responsável da cadeia da unidade (papelNoEntregavel, :432).
     *
     * @return array{eu: ?int, unidade_id: ?int, periodicidade: string, dono: bool, responsavel: bool}
     */
    public function papelNoEntregavel(EntregavelTarefaUnidade $e, ?int $eu = null, ?Collection $unidades = null): array
    {
        $eu ??= $this->colaboradorDe();
        $t = $e->tarefa ?? TarefaUnidadeOrganica::query()->find($e->tarefa_unidade_id);
        $cadeia = $t ? $this->responsaveisQuePodemValidar((int) $t->unidade_organica_id, $unidades) : [];

        return ['eu' => $eu, 'unidade_id' => $t ? (int) $t->unidade_organica_id : null, 'periodicidade' => (string) ($t?->periodicidade ?: 'MENSAL'),
            'dono' => $eu !== null && (int) $e->colaborador_id === $eu, 'responsavel' => $eu !== null && collect($cadeia)->contains(fn ($c) => $c['colaborador_id'] === $eu)];
    }

    // ───────────── Tarefas ─────────────

    /** @param  array<string, mixed>  $d */
    public function gravarTarefa(array $d, ?TarefaUnidadeOrganica $t = null): TarefaUnidadeOrganica
    {
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome da tarefa.', 'NOME_EM_FALTA', 422);
        }
        $unidadeId = (int) ($d['unidade_organica_id'] ?? $t?->unidade_organica_id ?? 0);
        $unidade = UnidadeOrganica::query()->find($unidadeId) ?? throw new ErroNegocio('A tarefa tem de pertencer a uma unidade orgânica desta empresa.', 'UNIDADE_INVALIDA', 422);
        $codigo = trim((string) ($d['codigo'] ?? '')) ?: null;
        if ($codigo !== null && TarefaUnidadeOrganica::query()->whereRaw('lower(codigo) = lower(?)', [$codigo])->when($t, fn ($q) => $q->whereKeyNot($t->id))->exists()) {
            throw new ErroNegocio("Já existe a tarefa com o código {$codigo}.", 'CODIGO_DUPLICADO', 422);
        }
        if (! empty($d['colaborador_responsavel_id'])) {
            Colaborador::query()->findOrFail($d['colaborador_responsavel_id']);
        }
        if (! empty($d['posto_trabalho_id'])) {
            $posto = PostoTrabalho::query()->findOrFail($d['posto_trabalho_id']);
            if ((int) $posto->unidade_organica_id !== (int) $unidade->id) {
                throw new ErroNegocio('O posto de trabalho tem de ser da unidade da tarefa.', 'POSTO_FORA_DA_UNIDADE', 422);
            }
        }
        $per = in_array($d['periodicidade'] ?? null, TarefaUnidadeOrganica::PERIODICIDADES, true) ? $d['periodicidade'] : ($t?->periodicidade ?? 'MENSAL');
        $user = Auth::user()?->nome_utilizador;
        $reg = ['unidade_organica_id' => $unidade->id, 'codigo' => $codigo, 'nome' => mb_substr($nome, 0, 255), 'descricao' => trim((string) ($d['descricao'] ?? '')) ?: null,
            'url_instrucoes' => self::normalizarUrl($d['url_instrucoes'] ?? null, 'Instruções da tarefa'), 'periodicidade' => $per,
            'colaborador_responsavel_id' => ! empty($d['colaborador_responsavel_id']) ? (int) $d['colaborador_responsavel_id'] : null,
            'posto_trabalho_id' => ! empty($d['posto_trabalho_id']) ? (int) $d['posto_trabalho_id'] : null,
            'ativo' => array_key_exists('ativo', $d) ? (bool) $d['ativo'] : ($t?->ativo ?? true), 'ordem' => (int) ($d['ordem'] ?? $t?->ordem ?? 0), 'atualizado_por' => $user];
        $t ? $t->update($reg) : $t = TarefaUnidadeOrganica::create($reg + ['criado_por' => $user]);

        return $t->refresh();
    }

    public function eliminarTarefa(TarefaUnidadeOrganica $t): void
    {
        $ents = EntregavelTarefaUnidade::query()->where('tarefa_unidade_id', $t->id)->get();
        $comExec = ExecucaoEntregavel::query()->whereIn('entregavel_id', $ents->pluck('id')->all() ?: [0])->selectRaw('entregavel_id, count(*) AS n')->groupBy('entregavel_id')->pluck('n', 'entregavel_id');
        if ($comExec->isNotEmpty()) {
            $lista = $ents->filter(fn ($e) => isset($comExec[$e->id]))->map(fn ($e) => "{$e->nome} ({$comExec[$e->id]})")->implode(', ');
            throw new ErroNegocio("Não se elimina: há execuções registadas em {$lista}. Desactive a tarefa em vez de a apagar — o histórico da produtividade depende destes registos.", 'REGISTO_EM_USO', 422);
        }
        DB::transaction(function () use ($t, $ents) {
            ImpedimentoEntregavel::query()->where('tarefa_unidade_id', $t->id)->delete();
            foreach ($ents as $e) {
                $e->delete();
            }
            $t->delete();
        });
    }

    // ───────────── Entregáveis ─────────────

    /** @param  array<string, mixed>  $d */
    public function gravarEntregavel(array $d, ?EntregavelTarefaUnidade $e = null, ?TarefaUnidadeOrganica $tarefa = null): EntregavelTarefaUnidade
    {
        $t = $tarefa ?? ($e ? TarefaUnidadeOrganica::query()->findOrFail($e->tarefa_unidade_id) : throw new ErroNegocio('O entregável tem de pertencer a uma tarefa.', 'TAREFA_EM_FALTA', 422));
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($nome === '') {
            throw new ErroNegocio('Indique o nome do entregável.', 'NOME_EM_FALTA', 422);
        }
        $metrica = ServicoProdutividade::metricaDe($d['metrica'] ?? $e?->metrica);
        $unidade = trim((string) ($d['unidade'] ?? $e?->unidade ?? ''));
        if ($unidade === '') {
            throw new ErroNegocio('Indique a unidade de medida (peças, kg, m³, relatórios, horas…). Sem ela não há como medir o entregável.', 'UNIDADE_MEDIDA_EM_FALTA', 422);
        }
        $meta = isset($d['meta']) && $d['meta'] !== '' ? round((float) $d['meta'], 4) : null;
        $avaliacao = (bool) ($d['avaliacao'] ?? false);
        if ($avaliacao && ! ($meta > 0)) {
            throw new ErroNegocio('Para o entregável contar na avaliação de desempenho tem de ter meta por período — é sobre ela que se calcula a percentagem atingida.', 'META_EM_FALTA', 422);
        }
        $prazoDia = isset($d['prazo_dia']) && $d['prazo_dia'] !== '' && $d['prazo_dia'] !== null ? max(1, min(31, (int) $d['prazo_dia'])) : null;
        $alerta = (bool) ($d['alerta'] ?? false);
        if ($alerta && $prazoDia === null) {
            throw new ErroNegocio('Para avisar do incumprimento é preciso definir o prazo de execução — sem prazo não há atraso que se possa detectar.', 'PRAZO_EM_FALTA', 422);
        }
        $prazoMes = isset($d['prazo_mes']) && $d['prazo_mes'] !== '' && $d['prazo_mes'] !== null ? max(1, (int) $d['prazo_mes']) : 1;
        $maxMes = self::mesesDoPeriodo($t->periodicidade) + 1;
        if ($prazoMes > $maxMes) {
            throw new ErroNegocio("O mês do prazo tem de estar entre 1 e {$maxMes} para a periodicidade da tarefa.", 'PRAZO_MES_INVALIDO', 422);
        }
        $itemId = ! empty($d['item_produtividade_id']) ? (int) $d['item_produtividade_id'] : null;
        if ($itemId) {
            $item = ItemProdutividadeRH::query()->find($itemId) ?? throw new ErroNegocio('O item de produtividade escolhido já não existe.', 'ITEM_INCOMPATIVEL', 422);
            if (! $item->ativo) {
                throw new ErroNegocio("O item {$item->codigo} está inactivo.", 'ITEM_INCOMPATIVEL', 422);
            }
            if (ServicoProdutividade::metricaDe($item->metrica) !== $metrica) {
                throw new ErroNegocio('O entregável é medido em «'.ServicoProdutividade::DESCRICAO_METRICAS[$metrica]['nome'].'» e o item «'.$item->descricao.'» em «'
                    .ServicoProdutividade::DESCRICAO_METRICAS[ServicoProdutividade::metricaDe($item->metrica)]['nome'].'». Escolha um item da mesma métrica.', 'ITEM_INCOMPATIVEL', 422);
            }
            if (self::chaveUnidade($item->unidade) !== self::chaveUnidade($unidade)) {
                throw new ErroNegocio("A unidade de medida não coincide: entregável em «{$unidade}», item em «{$item->unidade}».", 'ITEM_INCOMPATIVEL', 422);
            }
        }
        $dono = ! empty($d['colaborador_id']) ? (int) $d['colaborador_id'] : null;
        if ($dono && ! Colaborador::query()->whereKey($dono)->where('estado', 'ACTIVO')->exists()) {
            throw new ErroNegocio('O dono do entregável tem de ser um colaborador activo desta empresa.', 'COLABORADOR_INVALIDO', 422);
        }
        $origem = ($d['origem_dados'] ?? $e?->origem_dados) === 'CRM' ? 'CRM' : 'MANUAL';
        $filtro = null;
        if ($origem === 'CRM') {
            $f = (array) ($d['crm_filtro'] ?? $e?->crm_filtro ?? []);
            $tipos = array_values(array_filter(array_map('strval', (array) ($f['tipos'] ?? []))));
            $funil = ! empty($f['funil_vendas_crm_id']) ? (int) $f['funil_vendas_crm_id'] : null;
            if (! $tipos && ! $funil) {
                throw new ErroNegocio('Um entregável alimentado pelo CRM precisa do filtro: tipos de actividade e/ou funil de vendas.', 'CRM_FILTRO_EM_FALTA', 422);
            }
            $filtro = ['tipos' => $tipos, 'funil_vendas_crm_id' => $funil, 'so_ganhas' => (bool) ($f['so_ganhas'] ?? false)];
        }
        $user = Auth::user()?->nome_utilizador;
        $reg = ['tarefa_unidade_id' => $t->id, 'nome' => mb_substr($nome, 0, 255), 'descricao' => trim((string) ($d['descricao'] ?? '')) ?: null, 'metrica' => $metrica,
            'unidade' => mb_substr($unidade, 0, 20), 'meta' => $meta, 'item_produtividade_id' => $itemId, 'colaborador_id' => $dono, 'origem_dados' => $origem, 'crm_filtro' => $filtro,
            'prazo_dia' => $prazoDia, 'prazo_mes' => $prazoMes, 'paga_fora_prazo' => array_key_exists('paga_fora_prazo', $d) ? (bool) $d['paga_fora_prazo'] : ($e?->paga_fora_prazo ?? true),
            'alerta' => $alerta, 'multa_valor' => isset($d['multa_valor']) && $d['multa_valor'] !== '' ? round((float) $d['multa_valor'], 2) : null,
            'multa_descricao' => trim((string) ($d['multa_descricao'] ?? '')) ?: null, 'avaliacao' => $avaliacao,
            'peso_avaliacao' => isset($d['peso_avaliacao']) && $d['peso_avaliacao'] !== '' ? round((float) $d['peso_avaliacao'], 2) : ($e?->peso_avaliacao ?? 1),
            'ativo' => array_key_exists('ativo', $d) ? (bool) $d['ativo'] : ($e?->ativo ?? true), 'ordem' => (int) ($d['ordem'] ?? $e?->ordem ?? 0), 'atualizado_por' => $user];
        $e ? $e->update($reg) : $e = EntregavelTarefaUnidade::create($reg + ['criado_por' => $user]);

        return $e->refresh();
    }

    public function eliminarEntregavel(EntregavelTarefaUnidade $e): void
    {
        $n = ExecucaoEntregavel::query()->where('entregavel_id', $e->id)->count();
        if ($n > 0) {
            throw new ErroNegocio("Não se elimina: há {$n} execução(ões) registada(s). Desactive o entregável — o histórico da produtividade depende destes registos.", 'REGISTO_EM_USO', 422);
        }
        DB::transaction(function () use ($e) {
            ImpedimentoEntregavel::query()->where('entregavel_id', $e->id)->delete();
            $e->delete();
        });
    }

    /** Copia a definição (nunca as entregas nem o dono) para a mesma tarefa ou para outra da empresa (copiarEntregavel, :973). */
    public function copiarEntregavel(EntregavelTarefaUnidade $e, ?int $tarefaDestino, ?string $nome): EntregavelTarefaUnidade
    {
        $t = TarefaUnidadeOrganica::query()->find($tarefaDestino ?: $e->tarefa_unidade_id) ?? throw new ErroNegocio('Escolha a tarefa de destino (desta empresa).', 'TAREFA_INVALIDA', 422);
        $novo = trim((string) $nome) ?: "{$e->nome} (cópia)";
        if (EntregavelTarefaUnidade::query()->where('tarefa_unidade_id', $t->id)->whereRaw('lower(nome) = lower(?)', [$novo])->exists()) {
            throw new ErroNegocio("A tarefa de destino já tem um entregável chamado «{$novo}». Dê-lhe outro nome.", 'NOME_DUPLICADO', 422);
        }
        $prazoMes = min((int) ($e->prazo_mes ?: 1), self::mesesDoPeriodo($t->periodicidade) + 1);
        $user = Auth::user()?->nome_utilizador;

        return EntregavelTarefaUnidade::create(['tarefa_unidade_id' => $t->id, 'nome' => mb_substr($novo, 0, 255), 'descricao' => $e->descricao, 'metrica' => $e->metrica, 'unidade' => $e->unidade,
            'meta' => $e->meta, 'item_produtividade_id' => $e->item_produtividade_id, 'colaborador_id' => null, 'origem_dados' => $e->origem_dados, 'crm_filtro' => $e->crm_filtro,
            'prazo_dia' => $e->prazo_dia, 'prazo_mes' => $prazoMes, 'paga_fora_prazo' => $e->paga_fora_prazo, 'alerta' => $e->alerta, 'multa_valor' => $e->multa_valor,
            'multa_descricao' => $e->multa_descricao, 'avaliacao' => $e->avaliacao, 'peso_avaliacao' => $e->peso_avaliacao, 'ativo' => $e->ativo, 'ordem' => $e->ordem,
            'copiado_de_id' => $e->id, 'criado_por' => $user, 'atualizado_por' => $user]);
    }

    /** Sugestão de código para o item: nome sem acentos, maiúsculas, até 14 caracteres, sufixo -2…-99 se já existir (sugerirCodigoItem, :792). */
    public function sugerirCodigo(string $nome): string
    {
        $base = trim((string) preg_replace('/[^A-Z0-9]+/', '-', mb_strtoupper(self::semAcentos($nome))), '-');
        $base = substr($base, 0, 14) ?: 'ENTREGAVEL';
        $base = rtrim($base, '-') ?: 'ENTREGAVEL';
        $usados = ItemProdutividadeRH::query()->pluck('codigo')->map(fn ($c) => mb_strtoupper((string) $c))->flip();
        if (! isset($usados[$base])) {
            return $base;
        }
        for ($i = 2; $i < 100; $i++) {
            $c = "{$base}-{$i}";
            if (! isset($usados[$c])) {
                return $c;
            }
        }

        return $base;
    }

    /**
     * Cria o item de produtividade a partir do entregável (métrica e unidade do entregável; código por omissão ENT-<id>) e liga-os
     * (criarItemDeProdutividade, :776).
     *
     * @param  array{codigo?: ?string, preco_unitario: mixed, infotipo_salarial_id: mixed, minimo?: mixed, maximo?: mixed}  $o
     */
    public function criarItemDeProdutividade(EntregavelTarefaUnidade $e, array $o): ItemProdutividadeRH
    {
        if ($e->item_produtividade_id) {
            throw new ErroNegocio('Este entregável já está ligado a um item de produtividade.', 'JA_LIGADO', 422);
        }
        $preco = (float) ($o['preco_unitario'] ?? 0);
        if ($preco <= 0) {
            $rotulo = ServicoProdutividade::DESCRICAO_METRICAS[ServicoProdutividade::metricaDe($e->metrica)]['rotulo_preco'];
            throw new ErroNegocio("Indique «{$rotulo}» (maior que zero) — é o que define quanto se paga por este entregável.", 'PRECO_EM_FALTA', 422);
        }
        if (empty($o['infotipo_salarial_id'])) {
            throw new ErroNegocio('Escolha a rubrica de vencimento onde o subsídio é pago.', 'RUBRICA_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($e, $o, $preco) {
            $item = $this->produtividade->gravarItem(['codigo' => trim((string) ($o['codigo'] ?? '')) ?: "ENT-{$e->id}", 'descricao' => $e->nome, 'metrica' => ServicoProdutividade::metricaDe($e->metrica),
                'unidade' => mb_substr((string) $e->unidade, 0, 10), 'preco_unitario' => $preco, 'infotipo_salarial_id' => (int) $o['infotipo_salarial_id'],
                'minimo' => isset($o['minimo']) && $o['minimo'] !== '' ? $o['minimo'] : null, 'maximo' => isset($o['maximo']) && $o['maximo'] !== '' ? $o['maximo'] : null]);
            $e->update(['item_produtividade_id' => $item->id, 'atualizado_por' => Auth::user()?->nome_utilizador]);
            $this->auditoria->registar(self::MODULO_AUDITORIA, 'Criar item de produtividade a partir do entregável', "{$e->nome} → item {$item->codigo}", 'entregaveis_tarefa_unidade', $e->id);

            return $item;
        });
    }

    // ───────────── Execuções (entregas) ─────────────

    /** Quem pode registar/alterar uma entrega a partir da estrutura: est_editar, responsável da cadeia ou o próprio executor. */
    public function exigirPodeRegistar(EntregavelTarefaUnidade $e, int $executor): void
    {
        if (Gate::allows('est_editar')) {
            return;
        }
        $p = $this->papelNoEntregavel($e);
        if ($p['responsavel'] || ($p['eu'] !== null && $p['eu'] === $executor)) {
            return;
        }
        throw new ErroNegocio('Só quem edita a estrutura, o responsável da unidade ou o próprio executor regista esta entrega.', 'SEM_PERMISSAO', 403);
    }

    /**
     * @param  array<string, mixed>  $d  colaborador_id, quantidade, data, observacoes?, url_evidencia?, origem_chave?, registado_por_colaborador_id?
     */
    public function gravarExecucao(EntregavelTarefaUnidade $e, array $d, ?ExecucaoEntregavel $x = null, string $origem = 'MANUAL'): ExecucaoEntregavel
    {
        if (! $e->ativo) {
            throw new ErroNegocio('Este entregável está inactivo.', 'ENTREGAVEL_INACTIVO', 422);
        }
        if ($x && $x->convertida()) {
            throw new ErroNegocio('Esta entrega já foi convertida em produtividade. Anule a conversão antes de a alterar.', 'JA_CONVERTIDA', 422);
        }
        $colab = (int) ($d['colaborador_id'] ?? $x?->colaborador_id ?? 0);
        if (! $colab || ! Colaborador::query()->whereKey($colab)->where('estado', 'ACTIVO')->exists()) {
            throw new ErroNegocio('Indique quem executou (colaborador activo desta empresa) — sem pessoa não há produtividade a remunerar.', 'COLABORADOR_INVALIDO', 422);
        }
        $q = round((float) ($d['quantidade'] ?? 0), 4);
        if ($q <= 0) {
            throw new ErroNegocio('A quantidade entregue tem de ser maior do que zero.', 'QUANTIDADE_INVALIDA', 422);
        }
        $data = substr((string) (($d['data'] ?? null) ?: now()->toDateString()), 0, 10);
        if ($data > now()->toDateString()) {
            throw new ErroNegocio('A data da entrega não pode ser futura.', 'DATA_FUTURA', 422);
        }
        $t = TarefaUnidadeOrganica::query()->findOrFail($e->tarefa_unidade_id);
        $user = Auth::user()?->nome_utilizador;
        $reg = ['entregavel_id' => $e->id, 'tarefa_unidade_id' => $t->id, 'unidade_organica_id' => $t->unidade_organica_id, 'colaborador_id' => $colab, 'quantidade' => $q, 'data' => $data,
            'observacoes' => trim((string) ($d['observacoes'] ?? '')) ?: null, 'url_evidencia' => self::normalizarUrl($d['url_evidencia'] ?? null, 'Evidência da entrega'), 'atualizado_por' => $user];
        if ($x) {
            // alterar a entrega deita abaixo a validação: validou-se outra coisa; guarda-se a decisão anterior (devolvida/recusada)
            $anterior = in_array($x->validacao, ['DEVOLVIDA', 'RECUSADA'], true)
                ? ['validacao' => $x->validacao, 'motivo' => $x->validacao_motivo, 'por' => $x->validada_por, 'em' => $x->validada_em?->toIso8601String()] : $x->devolucao_anterior;
            $x->update($reg + ['validacao' => 'PENDENTE', 'validacao_motivo' => null, 'validada_por' => null, 'validada_por_colaborador_id' => null, 'validada_em' => null, 'validada_como' => null,
                'devolucao_anterior' => $anterior]);

            return $x->refresh();
        }

        return ExecucaoEntregavel::create($reg + ['registado_por' => $user, 'registado_por_colaborador_id' => array_key_exists('registado_por_colaborador_id', $d) ? $d['registado_por_colaborador_id'] : $this->colaboradorDe(),
            'origem' => in_array($origem, ExecucaoEntregavel::ORIGENS, true) ? $origem : 'MANUAL', 'origem_chave' => trim((string) ($d['origem_chave'] ?? '')) ?: null,
            'validacao' => 'PENDENTE', 'criado_por' => $user]);
    }

    public function eliminarExecucao(ExecucaoEntregavel $x): void
    {
        if ($x->convertida()) {
            throw new ErroNegocio('Esta entrega já foi convertida em produtividade. Anule a conversão antes de a eliminar.', 'JA_CONVERTIDA', 422);
        }
        $x->delete();
    }

    /** Validar (aceita), devolver (corrija e volte a submeter) ou recusar (não conta) — validarExecucao, :862. */
    public function validar(ExecucaoEntregavel $x, string $decisao, ?string $motivo): ExecucaoEntregavel
    {
        if (! in_array($decisao, self::DECISOES, true)) {
            throw new ErroNegocio('Decisão de validação desconhecida.', 'DECISAO_INVALIDA', 422);
        }
        if ($x->convertida()) {
            throw new ErroNegocio('Esta entrega já foi convertida em produtividade. Anule a conversão antes de mudar a validação.', 'JA_CONVERTIDA', 422);
        }
        $v = $this->podeValidar($x);
        if (! $v['pode']) {
            throw new ErroNegocio($v['motivo'], 'SEM_PERMISSAO', 403);
        }
        $motivo = trim((string) $motivo);
        if ($decisao !== 'VALIDADA' && mb_strlen($motivo) < 3) {
            throw new ErroNegocio($decisao === 'RECUSADA' ? 'Indique o motivo da recusa — quem entregou tem de saber porquê.' : 'Diga o que tem de ser corrigido — é isso que quem entregou vai ler.',
                'MOTIVO_EM_FALTA', 422);
        }

        return DB::transaction(function () use ($x, $decisao, $motivo, $v) {
            $x->update(['validacao' => $decisao, 'validacao_motivo' => $motivo ?: null, 'validada_por' => Auth::user()?->nome_utilizador, 'validada_por_colaborador_id' => $this->colaboradorDe(),
                'validada_em' => now(), 'validada_como' => $v['como'], 'atualizado_por' => Auth::user()?->nome_utilizador]);
            $rotulo = ['VALIDADA' => 'Validar entrega', 'DEVOLVIDA' => 'Devolver entrega para correcção', 'RECUSADA' => 'Recusar entrega'][$decisao];
            $this->auditoria->registar(self::MODULO_AUDITORIA, $rotulo, "{$x->quantidade} em {$x->data->toDateString()}".($motivo ? " — {$motivo}" : ''), 'execucoes_entregavel', $x->id);
            // executado E aprovado é o momento em que a elegibilidade se ganha: quem fez e teve o visto passa a elegível para o item
            if ($decisao === 'VALIDADA') {
                $e = EntregavelTarefaUnidade::query()->find($x->entregavel_id);
                if ($e?->item_produtividade_id) {
                    try {
                        $this->produtividade->tornarElegivel((int) $x->colaborador_id, (int) $e->item_produtividade_id, 'ENTREGAVEL', null, "Executou «{$e->nome}», validado em ".now()->toDateString());
                    } catch (Throwable $erro) {
                        report($erro);
                    }
                }
            }

            return $x->refresh();
        });
    }

    /** Quem entregou volta a submeter depois de corrigir: só o executor e só se DEVOLVIDA (reenviarEntrega, :900). */
    public function reenviar(ExecucaoEntregavel $x): ExecucaoEntregavel
    {
        if ($x->validacao !== 'DEVOLVIDA') {
            throw new ErroNegocio('Só se reenviam entregas devolvidas para correcção.', 'ESTADO_INVALIDO', 422);
        }
        $eu = $this->colaboradorDe();
        if ($eu === null || (int) $x->colaborador_id !== $eu) {
            throw new ErroNegocio('Só quem executou a entrega a pode reenviar.', 'SEM_PERMISSAO', 403);
        }
        $x->update(['validacao' => 'PENDENTE', 'validada_por' => null, 'validada_por_colaborador_id' => null, 'validada_em' => null, 'validada_como' => null,
            'devolucao_anterior' => ['validacao' => 'DEVOLVIDA', 'motivo' => $x->validacao_motivo, 'por' => $x->validada_por, 'em' => $x->validada_em?->toIso8601String()],
            'validacao_motivo' => null, 'atualizado_por' => Auth::user()?->nome_utilizador]);
        $this->auditoria->registar(self::MODULO_AUDITORIA, 'Reenviar entrega corrigida', (string) ($x->devolucao_anterior['motivo'] ?? ''), 'execucoes_entregavel', $x->id);

        return $x->refresh();
    }

    /** Repor em «por validar» (a validação foi engano): quem pode validar (reporValidacao, :916). */
    public function reporValidacao(ExecucaoEntregavel $x): ExecucaoEntregavel
    {
        if ($x->convertida()) {
            throw new ErroNegocio('Esta entrega já foi convertida em produtividade. Anule a conversão primeiro.', 'JA_CONVERTIDA', 422);
        }
        $v = $this->podeValidar($x);
        if (! $v['pode']) {
            throw new ErroNegocio($v['motivo'], 'SEM_PERMISSAO', 403);
        }
        $x->update(['validacao' => 'PENDENTE', 'validacao_motivo' => null, 'validada_por' => null, 'validada_por_colaborador_id' => null, 'validada_em' => null, 'validada_como' => null,
            'atualizado_por' => Auth::user()?->nome_utilizador]);
        $this->auditoria->registar(self::MODULO_AUDITORIA, 'Repor entrega por validar', null, 'execucoes_entregavel', $x->id);

        return $x->refresh();
    }

    /**
     * Execuções de um entregável (filtros de, ate, validacao), cada uma com pode_validar, pode_reenviar e convertida.
     *
     * @return list<array<string, mixed>>
     */
    public function execucoesDe(EntregavelTarefaUnidade $e, array $f = []): array
    {
        $lista = ExecucaoEntregavel::query()->where('entregavel_id', $e->id)->when($f['de'] ?? null, fn ($q, $d) => $q->where('data', '>=', $d))
            ->when($f['ate'] ?? null, fn ($q, $d) => $q->where('data', '<=', $d))->when($f['validacao'] ?? null, fn ($q, $v) => $q->where('validacao', $v))
            ->orderByDesc('data')->orderByDesc('id')->limit(500)->get();

        return $this->decorarExecucoes($lista);
    }

    /** @return list<array<string, mixed>> */
    private function decorarExecucoes(Collection $lista): array
    {
        $unidades = $this->unidades();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $eu = $this->colaboradorDe();

        return $lista->map(function ($x) use ($unidades, $nomes, $eu) {
            $v = $this->podeValidar($x, $unidades);

            return $x->toArray() + ['colaborador_nome' => $nomes[$x->colaborador_id] ?? null, 'rotulo_validacao' => ExecucaoEntregavel::ROTULOS_VALIDACAO[$x->validacao] ?? $x->validacao,
                'convertida' => $x->convertida(), 'pode_validar' => ! $x->convertida() && $v['pode'], 'motivo_nao_valida' => $v['motivo'] ?? null,
                'pode_reenviar' => $x->validacao === 'DEVOLVIDA' && $eu !== null && (int) $x->colaborador_id === $eu];
        })->values()->all();
    }

    // ───────────── Impedimentos («não há condições») ─────────────

    /** @param  array{periodo_de: string, prazo?: ?string, motivo: string, url_documento?: ?string}  $d */
    public function registarImpedimento(EntregavelTarefaUnidade $e, array $d, int $colaborador): ImpedimentoEntregavel
    {
        $motivo = trim((string) ($d['motivo'] ?? ''));
        if ($motivo === '') {
            throw new ErroNegocio('Diga porque é que não há condições de executar — é isso que o responsável vai analisar.', 'MOTIVO_EM_FALTA', 422);
        }
        $p = $this->papelNoEntregavel($e, $colaborador);
        if (! $p['dono'] && ! $p['responsavel']) {
            throw new ErroNegocio('Este entregável não lhe está atribuído nem é de uma unidade por que responda.', 'SEM_PERMISSAO', 403);
        }
        [$a, $m] = self::inicioDoPeriodo($p['periodicidade'], substr((string) (($d['periodo_de'] ?? null) ?: now()->toDateString()), 0, 10));
        $periodoDe = self::iso($a, $m, 1);
        $existente = ImpedimentoEntregavel::query()->where('entregavel_id', $e->id)->where('periodo_de', $periodoDe)->where('estado', '<>', 'RECUSADO')->first();
        if ($existente) {
            throw new ErroNegocio('Já há um impedimento registado para este período ('.ImpedimentoEntregavel::ROTULOS[$existente->estado].').', 'IMPEDIMENTO_DUPLICADO', 422);
        }
        $user = Auth::user()?->nome_utilizador;
        $i = ImpedimentoEntregavel::create(['entregavel_id' => $e->id, 'tarefa_unidade_id' => $e->tarefa_unidade_id, 'unidade_organica_id' => $p['unidade_id'], 'colaborador_id' => $colaborador,
            'periodo_de' => $periodoDe, 'prazo' => ! empty($d['prazo']) ? substr((string) $d['prazo'], 0, 10) : self::limiteDoPeriodo($e, $periodoDe), 'motivo' => $motivo,
            'url_documento' => self::normalizarUrl($d['url_documento'] ?? null, 'Documento do impedimento'), 'estado' => 'ABERTO', 'criado_por' => $user, 'atualizado_por' => $user]);
        $this->auditoria->registar(self::MODULO_AUDITORIA, 'Registar impedimento de entregável', "{$e->nome} · {$periodoDe} — {$motivo}", 'impedimentos_entregavel', $i->id);

        return $i;
    }

    /** Só um responsável da cadeia (ou est_validar_entregas) decide, nunca o próprio autor; a recusa exige nota (decidirImpedimento, :510). */
    public function decidirImpedimento(ImpedimentoEntregavel $i, string $estado, ?string $nota): ImpedimentoEntregavel
    {
        if (! in_array($estado, ['ACEITE', 'RECUSADO'], true)) {
            throw new ErroNegocio('Decisão desconhecida.', 'DECISAO_INVALIDA', 422);
        }
        if ($i->estado !== 'ABERTO') {
            throw new ErroNegocio('Este impedimento já foi decidido.', 'ESTADO_INVALIDO', 422);
        }
        $eu = $this->colaboradorDe();
        $cadeia = $this->responsaveisQuePodemValidar((int) $i->unidade_organica_id);
        $responsavel = $eu !== null && collect($cadeia)->contains(fn ($c) => $c['colaborador_id'] === $eu);
        if (! $responsavel && ! Gate::allows('est_validar_entregas')) {
            throw new ErroNegocio('Só o responsável da unidade decide impedimentos.', 'SEM_PERMISSAO', 403);
        }
        if ($eu !== null && (int) $i->colaborador_id === $eu) {
            throw new ErroNegocio('Não pode decidir o impedimento que foi o próprio a registar: cabe ao responsável acima.', 'AUTO_DECISAO', 403);
        }
        $nota = trim((string) $nota);
        if ($estado === 'RECUSADO' && $nota === '') {
            throw new ErroNegocio('Diga porque é que o impedimento não é aceite.', 'NOTA_EM_FALTA', 422);
        }
        $i->update(['estado' => $estado, 'decisao_nota' => $nota ?: null, 'decidido_por' => Auth::user()?->nome_utilizador, 'decidido_por_colaborador_id' => $eu, 'decidido_em' => now(),
            'atualizado_por' => Auth::user()?->nome_utilizador]);
        $this->auditoria->registar(self::MODULO_AUDITORIA, $estado === 'ACEITE' ? 'Aceitar impedimento' : 'Recusar impedimento', $nota ?: null, 'impedimentos_entregavel', $i->id);

        return $i->refresh();
    }

    /** @return list<array<string, mixed>> */
    public function impedimentosDe(EntregavelTarefaUnidade $e): array
    {
        $lista = ImpedimentoEntregavel::query()->where('entregavel_id', $e->id)->orderByDesc('periodo_de')->orderByDesc('id')->get();
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->unique()->all() ?: [0])->pluck('nome_completo', 'id');

        return $lista->map(fn ($i) => $i->toArray() + ['colaborador_nome' => $nomes[$i->colaborador_id] ?? null, 'rotulo_estado' => ImpedimentoEntregavel::ROTULOS[$i->estado] ?? $i->estado])->values()->all();
    }

    // ───────────── Atrasos e resumo do período ─────────────

    /**
     * Entregáveis cujo prazo do período servido corrente já passou sem a meta cumprida e sem impedimento aceite
     * (entregaveisEmAtraso, :201). Por omissão só os marcados para avisar (alerta).
     *
     * @param  array{soComAlerta?: bool, unidadeIds?: ?list<int>, responsavelColaboradorId?: ?int, hoje?: ?string}  $o
     * @return list<array<string, mixed>>
     */
    public function entregaveisEmAtraso(array $o = []): array
    {
        $soComAlerta = $o['soComAlerta'] ?? true;
        $hoje = $o['hoje'] ?? now()->toDateString();
        $unidades = $this->unidades();
        $alcance = null;
        if (! empty($o['responsavelColaboradorId'])) {
            $alcance = $this->unidadesPorQueRespondo((int) $o['responsavelColaboradorId'], $unidades);
            if (! $alcance) {
                return [];
            }
        }
        if (isset($o['unidadeIds'])) {
            $alcance = $alcance === null ? $o['unidadeIds'] : array_values(array_intersect($alcance, $o['unidadeIds']));
        }
        $tarefas = TarefaUnidadeOrganica::query()->where('ativo', true)->when($alcance !== null, fn ($q) => $q->whereIn('unidade_organica_id', $alcance ?: [0]))->get()->keyBy('id');
        $ents = EntregavelTarefaUnidade::query()->where('ativo', true)->whereNotNull('prazo_dia')->when($soComAlerta, fn ($q) => $q->where('alerta', true))
            ->whereIn('tarefa_unidade_id', $tarefas->keys()->all() ?: [0])->get();
        if ($ents->isEmpty()) {
            return [];
        }
        $execs = ExecucaoEntregavel::query()->whereIn('entregavel_id', $ents->pluck('id')->all())->whereIn('validacao', ['PENDENTE', 'VALIDADA'])->get(['entregavel_id', 'data', 'quantidade'])->groupBy('entregavel_id');
        $imps = ImpedimentoEntregavel::query()->whereIn('entregavel_id', $ents->pluck('id')->all())->get()->groupBy('entregavel_id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $ents->pluck('colaborador_id')->filter()->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $saida = [];
        foreach ($ents as $e) {
            $t = $tarefas[$e->tarefa_unidade_id];
            $per = (string) ($t->periodicidade ?: 'MENSAL');
            $limite = self::limiteDaEntrega($e, $per, $hoje);
            if ($limite === null || $hoje <= $limite) {
                continue;   // ainda dentro do prazo
            }
            $p = self::periodoServido($e, $per, $hoje);
            $imp = ($imps[$e->id] ?? collect())->filter(fn ($i) => $i->periodo_de->toDateString() === $p['inicio']);
            if ($imp->contains(fn ($i) => $i->estado === 'ACEITE')) {
                continue;   // período justificado
            }
            $janela = self::janelaDeEntrega($e, $per, $p['inicio']);
            $feito = (float) ($execs[$e->id] ?? collect())->filter(fn ($x) => $x->data->toDateString() >= $janela['de'] && $x->data->toDateString() <= $limite)->sum(fn ($x) => (float) $x->quantidade);
            $meta = $e->meta !== null ? (float) $e->meta : 0.0;
            if (($meta > 0 && round($feito, 4) >= $meta) || ($meta <= 0 && $feito > 0)) {
                continue;
            }
            $aberto = $imp->first(fn ($i) => $i->estado !== 'ACEITE');
            $u = $unidades->get((int) $t->unidade_organica_id);
            $saida[] = ['entregavel_id' => (int) $e->id, 'entregavel' => ['id' => (int) $e->id, 'nome' => $e->nome, 'unidade' => $e->unidade, 'metrica' => $e->metrica, 'alerta' => (bool) $e->alerta,
                'paga_fora_prazo' => (bool) $e->paga_fora_prazo, 'prazo_texto' => self::prazoTexto($e, $per)],
                'tarefa' => ['id' => (int) $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome, 'periodicidade' => $per],
                'unidade' => ['id' => (int) $t->unidade_organica_id, 'nome' => (string) ($u?->nome ?? '')], 'unidade_organica_id' => (int) $t->unidade_organica_id,
                'periodo_de' => $p['inicio'], 'periodo_ate' => $p['fim'], 'limite' => $limite,
                'dias_atraso' => (int) (new \DateTimeImmutable($limite))->diff(new \DateTimeImmutable($hoje))->days,
                'realizado' => round($feito, 4), 'meta' => $meta > 0 ? $meta : null, 'falta' => $meta > 0 ? round(max(0, $meta - $feito), 4) : null,
                'multa_valor' => $e->multa_valor !== null ? (float) $e->multa_valor : null, 'multa_descricao' => $e->multa_descricao,
                'impedimento' => $aberto ? ['id' => (int) $aberto->id, 'estado' => $aberto->estado, 'motivo' => $aberto->motivo, 'periodo_de' => $aberto->periodo_de->toDateString()] : null,
                'dono' => $e->colaborador_id ? ['id' => (int) $e->colaborador_id, 'nome' => $nomes[$e->colaborador_id] ?? "#{$e->colaborador_id}"] : null];
        }
        usort($saida, fn ($a, $b) => $b['dias_atraso'] <=> $a['dias_atraso'] ?: strcmp(mb_strtolower($a['entregavel']['nome']), mb_strtolower($b['entregavel']['nome'])));

        return $saida;
    }

    /**
     * Resumo do período servido actual de um entregável (para os ecrãs): realizado, meta, %, estado e contagens por validação.
     *
     * @return array{periodo_de: string, periodo_ate: string, limite: ?string, realizado: float, meta: ?float, pct: ?float, estado: string, pendentes: int, devolvidas: int, recusadas: int, prazo_texto: string}
     */
    public function resumoPeriodo(EntregavelTarefaUnidade $e, ?string $referencia = null, ?string $periodicidade = null, ?Collection $execs = null, ?Collection $imps = null): array
    {
        $hoje = $referencia ?? now()->toDateString();
        $per = $periodicidade ?? (string) (($e->tarefa ?? TarefaUnidadeOrganica::query()->find($e->tarefa_unidade_id))?->periodicidade ?: 'MENSAL');
        $p = self::periodoServido($e, $per, $hoje);
        $limite = self::limiteDoPeriodo($e, $p['inicio']);
        $janela = self::janelaDeEntrega($e, $per, $p['inicio']);
        $execs ??= ExecucaoEntregavel::query()->where('entregavel_id', $e->id)->whereBetween('data', [$janela['de'], $janela['ate']])->get();
        $doPeriodo = $execs->filter(fn ($x) => $x->data->toDateString() >= $janela['de'] && $x->data->toDateString() <= $janela['ate']);
        $realizado = round((float) $doPeriodo->filter(fn ($x) => $x->contaComoEntregue())->sum(fn ($x) => (float) $x->quantidade), 4);
        $meta = $e->meta !== null && (float) $e->meta > 0 ? (float) $e->meta : null;
        $imps ??= ImpedimentoEntregavel::query()->where('entregavel_id', $e->id)->where('periodo_de', $p['inicio'])->get();
        $justificado = $imps->contains(fn ($i) => $i->estado === 'ACEITE' && $i->periodo_de->toDateString() === $p['inicio']);
        $cumprido = $meta !== null ? $realizado >= $meta : $realizado > 0;
        $estado = match (true) {
            $cumprido => 'CUMPRIDO',
            $justificado => 'JUSTIFICADO',
            $limite === null => 'SEM_PRAZO',
            $hoje <= $limite => 'DENTRO_DO_PRAZO',
            default => 'EM_ATRASO',
        };

        return ['periodo_de' => $p['inicio'], 'periodo_ate' => $p['fim'], 'limite' => $limite, 'realizado' => $realizado, 'meta' => $meta,
            'pct' => $meta !== null ? round($realizado / $meta * 100, 1) : null, 'estado' => $estado,
            'pendentes' => $doPeriodo->where('validacao', 'PENDENTE')->count(), 'devolvidas' => $doPeriodo->where('validacao', 'DEVOLVIDA')->count(),
            'recusadas' => $doPeriodo->where('validacao', 'RECUSADA')->count(), 'prazo_texto' => self::prazoTexto($e, $per)];
    }

    /**
     * Tudo o que a unidade tem, já montado para o ecrã (daUnidade, :584): tarefas com os entregáveis (resumo do período, item, dono,
     * impedimento aberto, contagens) e os atrasos da unidade (sem exigir alerta).
     *
     * @return array{tarefas: list<array<string, mixed>>, atrasos: list<array<string, mixed>>}
     */
    public function daUnidade(int $unidadeId): array
    {
        $tarefas = TarefaUnidadeOrganica::query()->where('unidade_organica_id', $unidadeId)->orderBy('ordem')->orderBy('codigo')->ordenarPorNome()->get();
        $ents = EntregavelTarefaUnidade::query()->whereIn('tarefa_unidade_id', $tarefas->pluck('id')->all() ?: [0])->orderBy('ordem')->ordenarPorNome()->get()->groupBy('tarefa_unidade_id');
        $todos = $ents->flatten(1);
        $execs = ExecucaoEntregavel::query()->whereIn('entregavel_id', $todos->pluck('id')->all() ?: [0])->get()->groupBy('entregavel_id');
        $imps = ImpedimentoEntregavel::query()->whereIn('entregavel_id', $todos->pluck('id')->all() ?: [0])->get()->groupBy('entregavel_id');
        $itens = ItemProdutividadeRH::query()->whereIn('id', $todos->pluck('item_produtividade_id')->filter()->unique()->all() ?: [0])->get()->keyBy('id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $todos->pluck('colaborador_id')->filter()->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $hoje = now()->toDateString();

        $linhas = $tarefas->map(function ($t) use ($ents, $execs, $imps, $itens, $nomes, $hoje) {
            $per = (string) ($t->periodicidade ?: 'MENSAL');
            $lista = ($ents[$t->id] ?? collect())->map(function ($e) use ($execs, $imps, $itens, $nomes, $per, $hoje) {
                $regs = $execs[$e->id] ?? collect();
                $meus = $imps[$e->id] ?? collect();
                $resumo = $this->resumoPeriodo($e, $hoje, $per, $regs, $meus);
                $aberto = $meus->first(fn ($i) => $i->estado === 'ABERTO' && $i->periodo_de->toDateString() === $resumo['periodo_de']);
                $item = $e->item_produtividade_id ? ($itens[$e->item_produtividade_id] ?? null) : null;

                return $e->toArray() + ['resumo' => $resumo,
                    'item' => $item ? $item->only(['id', 'codigo', 'descricao', 'metrica', 'unidade', 'preco_unitario', 'ativo']) : null,
                    'dono' => $e->colaborador_id ? ['id' => (int) $e->colaborador_id, 'nome' => $nomes[$e->colaborador_id] ?? "#{$e->colaborador_id}"] : null,
                    'impedimento_aberto' => $aberto ? $aberto->only(['id', 'periodo_de', 'motivo', 'estado', 'colaborador_id']) : null,
                    'execucoes' => $regs->count(), 'por_validar' => $regs->filter(fn ($x) => ! $x->convertida() && $x->validacao === 'PENDENTE')->count(),
                    'por_converter' => $regs->filter(fn ($x) => ! $x->convertida() && $x->validacao === 'VALIDADA')->count(),
                    'devolvidas' => $regs->where('validacao', 'DEVOLVIDA')->count(), 'recusadas' => $regs->where('validacao', 'RECUSADA')->count(),
                    'sem_evidencia' => $regs->filter(fn ($x) => trim((string) $x->url_evidencia) === '')->count(), 'remuneravel' => (bool) $e->item_produtividade_id,
                    'prazo_texto' => $resumo['prazo_texto']];
            })->values()->all();

            return $t->toArray() + ['entregaveis' => $lista, 'total_entregaveis' => count($lista), 'remuneraveis' => count(array_filter($lista, fn ($e) => $e['remuneravel']))];
        })->values()->all();

        return ['tarefas' => $linhas, 'atrasos' => $this->entregaveisEmAtraso(['soComAlerta' => false, 'unidadeIds' => [$unidadeId]])];
    }

    // ───────────── Portal ─────────────

    /**
     * Os entregáveis que esta pessoa tem em mãos: os que lhe estão atribuídos (DONO) e os das unidades por que responde, incluindo
     * as de baixo (RESPONSAVEL) — meusEntregaveis, :262. Cada um com o resumo do período e as últimas entregas (as minhas, ou todas
     * se responsável).
     *
     * @return list<array<string, mixed>>
     */
    public function meusEntregaveis(int $colaborador): array
    {
        $unidades = $this->unidades();
        $alcance = $this->unidadesPorQueRespondo($colaborador, $unidades);
        $tarefas = TarefaUnidadeOrganica::query()->where('ativo', true)->get()->keyBy('id');
        $ents = EntregavelTarefaUnidade::query()->where('ativo', true)->whereIn('tarefa_unidade_id', $tarefas->keys()->all() ?: [0])
            ->where(fn ($q) => $q->where('colaborador_id', $colaborador)->orWhereIn('tarefa_unidade_id', $tarefas->filter(fn ($t) => in_array((int) $t->unidade_organica_id, $alcance, true))->keys()->all() ?: [0]))
            ->get();
        if ($ents->isEmpty()) {
            return [];
        }
        $execs = ExecucaoEntregavel::query()->whereIn('entregavel_id', $ents->pluck('id')->all())->orderByDesc('data')->orderByDesc('id')->get()->groupBy('entregavel_id');
        $imps = ImpedimentoEntregavel::query()->whereIn('entregavel_id', $ents->pluck('id')->all())->orderByDesc('periodo_de')->get()->groupBy('entregavel_id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $ents->pluck('colaborador_id')->filter()->merge($execs->flatten(1)->pluck('colaborador_id'))->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $hoje = now()->toDateString();
        $saida = $ents->map(function ($e) use ($tarefas, $unidades, $execs, $imps, $nomes, $colaborador, $alcance, $hoje) {
            $t = $tarefas[$e->tarefa_unidade_id];
            $per = (string) ($t->periodicidade ?: 'MENSAL');
            $dono = (int) $e->colaborador_id === $colaborador;
            $responsavel = in_array((int) $t->unidade_organica_id, $alcance, true);
            $todas = $execs[$e->id] ?? collect();
            // como dono vejo o meu trabalho; como responsável vejo o do entregável todo
            $minhas = $dono && ! $responsavel ? $todas->filter(fn ($x) => (int) $x->colaborador_id === $colaborador) : $todas;
            $meusImps = $imps[$e->id] ?? collect();

            return $e->toArray() + ['papel' => $dono ? 'DONO' : 'RESPONSAVEL', 'dono' => $e->colaborador_id ? ['id' => (int) $e->colaborador_id, 'nome' => $nomes[$e->colaborador_id] ?? "#{$e->colaborador_id}"] : null,
                'tarefa' => ['id' => (int) $t->id, 'codigo' => $t->codigo, 'nome' => $t->nome, 'periodicidade' => $per, 'url_instrucoes' => $t->url_instrucoes],
                // «unidade» é a unidade de MEDIDA do entregável; a unidade orgânica vai em «unidade_organica»
                'unidade_organica' => ['id' => (int) $t->unidade_organica_id, 'nome' => (string) ($unidades->get((int) $t->unidade_organica_id)?->nome ?? '')],
                'resumo' => $this->resumoPeriodo($e, $hoje, $per, $minhas, $meusImps), 'periodico' => $per !== 'PONTUAL',
                'impedimentos' => $meusImps->take(6)->map(fn ($i) => $i->only(['id', 'periodo_de', 'prazo', 'motivo', 'estado', 'decisao_nota', 'colaborador_id']) + ['rotulo_estado' => ImpedimentoEntregavel::ROTULOS[$i->estado] ?? $i->estado])->values()->all(),
                'contadores' => ['por_validar' => $minhas->where('validacao', 'PENDENTE')->count(), 'devolvidas' => $minhas->where('validacao', 'DEVOLVIDA')->count(), 'recusadas' => $minhas->where('validacao', 'RECUSADA')->count()],
                'execucoes' => $minhas->take($dono && ! $responsavel ? 10 : 200)->map(fn ($x) => $x->toArray() + ['colaborador_nome' => $nomes[$x->colaborador_id] ?? null, 'convertida' => $x->convertida(),
                    'rotulo_validacao' => ExecucaoEntregavel::ROTULOS_VALIDACAO[$x->validacao] ?? $x->validacao, 'pode_reenviar' => $x->validacao === 'DEVOLVIDA' && (int) $x->colaborador_id === $colaborador])->values()->all()];
        });

        return $saida->sortBy([fn ($a, $b) => strcmp(mb_strtolower($a['unidade_organica']['nome']), mb_strtolower($b['unidade_organica']['nome'])) ?: strcmp(mb_strtolower($a['nome']), mb_strtolower($b['nome']))])->values()->all();
    }

    /** Entregas minhas recusadas ou devolvidas (ainda por converter), para o contador do portal. @return array{recusadas: int, devolvidas: int} */
    public function contadoresPortal(int $colaborador): array
    {
        $q = fn (string $v) => ExecucaoEntregavel::query()->where('colaborador_id', $colaborador)->where('validacao', $v)->whereNull('periodo_produtividade_id')->count();

        return ['recusadas' => $q('RECUSADA'), 'devolvidas' => $q('DEVOLVIDA')];
    }

    /** Há entregáveis que me digam respeito (para mostrar o separador)? */
    public function temEntregaveis(int $colaborador): bool
    {
        if (EntregavelTarefaUnidade::query()->where('ativo', true)->where('colaborador_id', $colaborador)->exists()) {
            return true;
        }
        $alcance = $this->unidadesPorQueRespondo($colaborador);

        return $alcance && TarefaUnidadeOrganica::query()->where('ativo', true)->whereIn('unidade_organica_id', $alcance)->whereHas('entregaveis', fn ($q) => $q->where('ativo', true))->exists();
    }

    /**
     * Registo de entrega a partir do portal: o executor é o próprio; só um responsável regista em nome de outra pessoa (membro
     * da unidade) — registarEntregaPortal, :447.
     */
    public function registarEntregaPortal(EntregavelTarefaUnidade $e, array $d, int $colaborador): ExecucaoEntregavel
    {
        if (! $e->ativo) {
            throw new ErroNegocio('Este entregável está inactivo.', 'ENTREGAVEL_INACTIVO', 422);
        }
        $p = $this->papelNoEntregavel($e, $colaborador);
        if (! $p['dono'] && ! $p['responsavel']) {
            throw new ErroNegocio('Este entregável não lhe está atribuído nem é de uma unidade por que responda.', 'SEM_PERMISSAO', 403);
        }
        $porConta = ! empty($d['colaborador_id']) ? (int) $d['colaborador_id'] : ($p['dono'] ? $colaborador : ($e->colaborador_id ? (int) $e->colaborador_id : $colaborador));
        if ($porConta !== $colaborador) {
            if (! $p['responsavel']) {
                throw new ErroNegocio('Só o responsável da unidade pode registar entregas em nome de outra pessoa.', 'SEM_PERMISSAO', 403);
            }
            $membro = Colaborador::query()->whereKey($porConta)->where('estado', 'ACTIVO')->first();
            if (! $membro || ! in_array((int) $membro->unidade_organica_id, $this->descendentes([$p['unidade_id']]), true)) {
                throw new ErroNegocio('Só se regista em nome de um membro da unidade (ou das suas subunidades).', 'COLABORADOR_FORA_DA_UNIDADE', 422);
            }
        }

        return $this->gravarExecucao($e, ['colaborador_id' => $porConta, 'quantidade' => $d['quantidade'] ?? null, 'data' => $d['data'] ?? null, 'observacoes' => $d['observacoes'] ?? null,
            'url_evidencia' => $d['url_evidencia'] ?? null, 'registado_por_colaborador_id' => $colaborador], null, 'PORTAL');
    }

    /**
     * Entregas à espera do meu visto: PENDENTES das unidades por que respondo (e das de baixo), nunca as que executei ou lancei
     * (entregasParaValidar, :318).
     *
     * @return list<array<string, mixed>>
     */
    public function entregasParaValidar(int $colaborador): array
    {
        $unidades = $this->unidades();
        $alcance = $this->unidadesPorQueRespondo($colaborador, $unidades);
        if (! $alcance) {
            return [];
        }
        $lista = ExecucaoEntregavel::query()->whereIn('unidade_organica_id', $alcance)->where('validacao', 'PENDENTE')->whereNull('periodo_produtividade_id')
            ->where('colaborador_id', '<>', $colaborador)->where(fn ($q) => $q->whereNull('registado_por_colaborador_id')->orWhere('registado_por_colaborador_id', '<>', $colaborador))
            ->orderBy('data')->orderBy('id')->limit(500)->get();

        return $this->contextualizar($lista, $unidades);
    }

    /** @return list<array<string, mixed>> */
    private function contextualizar(Collection $lista, Collection $unidades): array
    {
        $ents = EntregavelTarefaUnidade::query()->whereIn('id', $lista->pluck('entregavel_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $tarefas = TarefaUnidadeOrganica::query()->whereIn('id', $lista->pluck('tarefa_unidade_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->unique()->all() ?: [0])->pluck('nome_completo', 'id');

        return $lista->map(function ($x) use ($ents, $tarefas, $unidades, $nomes) {
            $e = $ents[$x->entregavel_id] ?? null;
            $t = $tarefas[$x->tarefa_unidade_id] ?? null;
            $v = $this->podeValidar($x, $unidades);

            return $x->toArray() + ['entregavel' => $e?->nome ?? '—', 'unidade_medida' => $e?->unidade ?? '', 'tarefa' => $t?->nome ?? '', 'unidade_nome' => (string) ($unidades->get((int) $x->unidade_organica_id)?->nome ?? ''),
                'colaborador_nome' => $nomes[$x->colaborador_id] ?? "#{$x->colaborador_id}", 'rotulo_validacao' => ExecucaoEntregavel::ROTULOS_VALIDACAO[$x->validacao] ?? $x->validacao,
                'convertida' => $x->convertida(), 'pode_validar' => $v['pode'], 'motivo_nao_valida' => $v['motivo'] ?? null, 'item_produtividade_id' => $e?->item_produtividade_id];
        })->values()->all();
    }

    /** Impedimentos ABERTOS das unidades por que respondo, excluindo os meus (impedimentosParaDecidir, :529). @return list<array<string, mixed>> */
    public function impedimentosParaDecidir(int $colaborador): array
    {
        $unidades = $this->unidades();
        $alcance = $this->unidadesPorQueRespondo($colaborador, $unidades);
        if (! $alcance) {
            return [];
        }
        $lista = ImpedimentoEntregavel::query()->whereIn('unidade_organica_id', $alcance)->where('estado', 'ABERTO')->where('colaborador_id', '<>', $colaborador)->orderBy('periodo_de')->orderBy('id')->get();
        $ents = EntregavelTarefaUnidade::query()->whereIn('id', $lista->pluck('entregavel_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('colaborador_id')->unique()->all() ?: [0])->pluck('nome_completo', 'id');

        return $lista->map(fn ($i) => $i->toArray() + ['entregavel' => $ents[$i->entregavel_id]?->nome ?? '—', 'unidade_nome' => (string) ($unidades->get((int) $i->unidade_organica_id)?->nome ?? ''),
            'colaborador_nome' => $nomes[$i->colaborador_id] ?? "#{$i->colaborador_id}", 'rotulo_estado' => ImpedimentoEntregavel::ROTULOS[$i->estado] ?? $i->estado])->values()->all();
    }

    /** Atrasos (com ou sem alerta) das unidades por que respondo. @return list<array<string, mixed>> */
    public function atrasosDasMinhasUnidades(int $colaborador): array
    {
        return $this->entregaveisEmAtraso(['soComAlerta' => false, 'responsavelColaboradorId' => $colaborador]);
    }

    // ───────────── Ponte para a avaliação de desempenho ─────────────

    /**
     * Objectivos quantitativos virtuais dos entregáveis marcados para avaliação, executados pela pessoa no intervalo
     * (objectivosAvaliacao, :557): {chave ENT-<id>, ambito ENTREGAVEL, natureza QUANTITATIVO, meta, unidade, sentido MAIOR, atingido, peso}.
     *
     * @return list<array<string, mixed>>
     */
    public function objectivosAvaliacao(int $colaborador, string $de, string $ate): array
    {
        $marcados = EntregavelTarefaUnidade::query()->where('avaliacao', true)->where('ativo', true)->get();
        if ($marcados->isEmpty()) {
            return [];
        }
        $execs = ExecucaoEntregavel::query()->where('colaborador_id', $colaborador)->whereIn('entregavel_id', $marcados->pluck('id')->all())->whereIn('validacao', ['PENDENTE', 'VALIDADA'])
            ->whereBetween('data', [$de, $ate])->get()->groupBy('entregavel_id');
        $tarefas = TarefaUnidadeOrganica::query()->whereIn('id', $marcados->pluck('tarefa_unidade_id')->unique()->all())->get()->keyBy('id');
        $saida = [];
        foreach ($marcados as $e) {
            $minhas = $execs[$e->id] ?? collect();
            if ($minhas->isEmpty()) {
                continue;   // quem não executou o entregável não é avaliado por ele
            }
            $t = $tarefas[$e->tarefa_unidade_id] ?? null;
            $pendentes = $minhas->where('validacao', 'PENDENTE')->count();
            $saida[] = ['chave' => "ENT-{$e->id}", 'item_id' => null, 'ambito' => 'ENTREGAVEL', 'entregavel_id' => (int) $e->id, 'descricao' => $e->nome,
                'detalhe' => ($t ? ($t->codigo ? "{$t->codigo} · " : '').$t->nome.' — ' : '').$minhas->count().' entrega(s) no período'.($pendentes ? " · {$pendentes} ainda por validar" : ''),
                'peso' => $e->peso_avaliacao !== null ? (float) $e->peso_avaliacao : 1.0, 'natureza' => 'QUANTITATIVO', 'meta' => $e->meta !== null ? (float) $e->meta : null,
                'unidade' => (string) $e->unidade, 'sentido' => 'MAIOR', 'atingido' => round((float) $minhas->sum(fn ($x) => (float) $x->quantidade), 4), 'nota_qual' => null];
        }

        return $saida;
    }
}
