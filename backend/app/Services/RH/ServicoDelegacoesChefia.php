<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\Colaborador;
use App\Models\DelegacaoChefia;
use App\Services\Sistema\NotificadorRH;
use App\Services\Sistema\ServicoAuditoria;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Delegação da chefia (Fase K, ADR-079) — PONTO ÚNICO: todo o sítio onde decide «o aprovador designado / o responsável da cadeia /
 * a chefia» pergunta aqui se o utilizador actual está a decidir em nome de alguém (portal, Método de Trabalho, entregáveis).
 *   - uma delegação vale por datas (início e fim inclusive, máx. 90 dias, nunca no passado) e por âmbito (PORTAL, METODO,
 *     ENTREGAVEIS); acabado o período deixa de valer sem mais nada (não há revogação automática a fazer);
 *   - o delegado herda o que o delegante podia decidir, nunca mais: não decide o que é seu (requerente, executor, penalizado),
 *     nem fura a segregação CHEFIA → RH (a mesma pessoa não aprova as duas etapas — regra do portal, por utilizador);
 *   - sem cadeia: um delegado não delega o que recebeu (o delegante não pode ser delegado, no mesmo período e âmbito) e só se
 *     consulta uma ligação directa (delegantesDe nunca é transitivo);
 *   - o delegado tem de ter utilizador ligado (senão não pode agir) e delegante e delegado são colaboradores activos e diferentes;
 *   - não há duas delegações activas do mesmo delegante a sobrepor-se no mesmo âmbito;
 *   - quem cria: o próprio delegante (portal, sem permissão especial) ou quem tem `est_delegar` (em nome de outro); revoga o
 *     delegante ou quem tem `est_delegar`;
 *   - o rasto fica em `por_delegado_de` ({colaborador_id, nome} do delegante) nas decisões e na auditoria («em nome de X»).
 */
final class ServicoDelegacoesChefia
{
    public const MODULO_AUDITORIA = 'Recursos Humanos › Delegações';

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    // ───────────── Apoio ─────────────

    /** Colaborador ligado ao utilizador autenticado na empresa activa. */
    public function colaboradorDe(): ?int
    {
        $id = Auth::id() ? DB::table('utilizador_empresa')->where('utilizador_id', Auth::id())->where('empresa_id', $this->contexto->obrigatorio())->value('colaborador_id') : null;

        return $id === null ? null : (int) $id;
    }

    private static function hoje(): string
    {
        return now()->toDateString();
    }

    /** @return list<string> */
    public static function normalizarAmbito(mixed $ambito): array
    {
        if ($ambito === null || $ambito === []) {
            return DelegacaoChefia::AMBITOS;
        }
        $lista = array_values(array_unique(array_map(fn ($a) => strtoupper(trim((string) $a)), (array) $ambito)));
        $invalidos = array_diff($lista, DelegacaoChefia::AMBITOS);
        if ($invalidos) {
            throw new ErroNegocio('Âmbito desconhecido: '.implode(', ', $invalidos).'. Use PORTAL, METODO e/ou ENTREGAVEIS.', 'AMBITO_INVALIDO', 422);
        }

        return array_values(array_intersect(DelegacaoChefia::AMBITOS, $lista));
    }

    /** Delegações ACTIVAS vigentes na data (omissão: hoje) para o âmbito. @return Collection<int, DelegacaoChefia> */
    private function vigentes(string $ambito, ?string $data = null, ?int $delegado = null, ?int $delegante = null): Collection
    {
        $data ??= self::hoje();

        return DelegacaoChefia::query()->where('estado', 'ATIVA')->where('data_inicio', '<=', $data)->where('data_fim', '>=', $data)
            ->whereRaw('ambito @> ?::jsonb', [json_encode([$ambito])])
            ->when($delegado !== null, fn ($q) => $q->where('delegado_colaborador_id', $delegado))
            ->when($delegante !== null, fn ($q) => $q->where('delegante_colaborador_id', $delegante))->get();
    }

    // ───────────── Consulta (o que os pontos de decisão usam) ─────────────

    /**
     * Colaboradores que delegaram ao colaborador, vigentes na data, para o âmbito. Ligação directa: nunca transitivo.
     *
     * @return list<int>
     */
    public function delegantesDe(int $colaboradorId, string $ambito, ?string $data = null): array
    {
        return $this->vigentes($ambito, $data, $colaboradorId)->pluck('delegante_colaborador_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /**
     * Colaboradores a quem o colaborador delegou, vigentes na data, para o âmbito.
     *
     * @return list<int>
     */
    public function substitutoDe(int $colaboradorId, string $ambito, ?string $data = null): array
    {
        return $this->vigentes($ambito, $data, null, $colaboradorId)->pluck('delegado_colaborador_id')->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /**
     * Entre os `$aprovadores` (colaboradores a quem cabia decidir), o delegante em nome de quem `$colaboradorId` pode decidir agora,
     * ou null. Quem já é um deles decide por si (o chamador verifica primeiro) — aqui só conta a delegação.
     *
     * `$excluir`: delegantes que não valem para este caso (o dono do que se decide: a decisão nunca é sobre o que é do delegante).
     * `$delegantes`: os delegantes já calculados por quem percorre uma lista (evita uma consulta por linha).
     *
     * @param  list<int>  $aprovadores
     * @param  list<int>  $excluir
     * @param  list<int>|null  $delegantes
     * @return array{colaborador_id: int, nome: string}|null
     */
    public function emNomeDe(int $colaboradorId, string $ambito, array $aprovadores, ?string $data = null, array $excluir = [], ?array $delegantes = null): ?array
    {
        $aprovadores = array_map('intval', $aprovadores);
        $excluir = array_map('intval', $excluir);
        foreach ($delegantes ?? $this->delegantesDe($colaboradorId, $ambito, $data) as $delegante) {
            if (in_array($delegante, $aprovadores, true) && ! in_array($delegante, $excluir, true)) {
                return $this->rotulo($delegante);
            }
        }

        return null;
    }

    /** @return array{colaborador_id: int, nome: string} */
    public function rotulo(int $colaboradorId): array
    {
        return ['colaborador_id' => $colaboradorId, 'nome' => (string) (Colaborador::query()->withTrashed()->whereKey($colaboradorId)->value('nome_completo') ?? "#{$colaboradorId}")];
    }

    /** Delegações recebidas e vigentes hoje (contador do resumo do portal). */
    public function contarRecebidasAtivas(int $colaboradorId): int
    {
        $hoje = self::hoje();

        return DelegacaoChefia::query()->where('estado', 'ATIVA')->where('delegado_colaborador_id', $colaboradorId)->where('data_inicio', '<=', $hoje)->where('data_fim', '>=', $hoje)->count();
    }

    /**
     * Colaboradores que podem receber uma delegação: activos, com utilizador ligado na empresa e diferentes do delegante.
     * Só id e nome (o chefe comum não tem acesso à lista de colaboradores do RH).
     *
     * @return list<array{id: int, nome: string}>
     */
    public function candidatos(int $delegante): array
    {
        $ligados = DB::table('utilizador_empresa')->where('empresa_id', $this->contexto->obrigatorio())->whereNotNull('colaborador_id')->pluck('colaborador_id')->all();

        return Colaborador::query()->where('estado', 'ACTIVO')->whereIn('id', $ligados ?: [0])->where('id', '!=', $delegante)
            ->ordenarPorNome('nome_completo')->get(['id', 'nome_completo'])->map(fn ($c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome_completo])->all();
    }

    // ───────────── Criar e revogar ─────────────

    /**
     * @param  array{delegante_colaborador_id?: ?int, delegado_colaborador_id: int, data_inicio: string, data_fim: string, ambito?: ?array, motivo?: ?string}  $d
     */
    public function criar(array $d): DelegacaoChefia
    {
        $eu = $this->colaboradorDe();
        $delegante = (int) ($d['delegante_colaborador_id'] ?? 0) ?: $eu;
        if (! $delegante) {
            throw new ErroNegocio('O seu utilizador não está ligado a um colaborador desta empresa: indique em nome de quem cria a delegação.', 'SEM_COLABORADOR', 403);
        }
        $emNomeDeOutro = $delegante !== $eu;
        if ($emNomeDeOutro && ! Gate::allows('est_delegar')) {
            throw new ErroNegocio('Só o próprio ou quem tem a permissão de delegar em nome de outros cria esta delegação.', 'SEM_PERMISSAO', 403);
        }
        $delegado = (int) ($d['delegado_colaborador_id'] ?? 0);
        if ($delegado === $delegante) {
            throw new ErroNegocio('Não se delega em si próprio.', 'DELEGADO_INVALIDO', 422);
        }
        $inicio = substr((string) ($d['data_inicio'] ?? ''), 0, 10);
        $fim = substr((string) ($d['data_fim'] ?? ''), 0, 10);
        if ($inicio === '' || $fim === '') {
            throw new ErroNegocio('Indique o início e o fim da delegação.', 'PERIODO_INVALIDO', 422);
        }
        if ($inicio < self::hoje()) {
            throw new ErroNegocio('A delegação não pode começar no passado.', 'DATA_NO_PASSADO', 422);
        }
        if ($fim < $inicio) {
            throw new ErroNegocio('O fim da delegação não pode ser anterior ao início.', 'PERIODO_INVALIDO', 422);
        }
        if (Carbon::parse($inicio)->diffInDays(Carbon::parse($fim)) + 1 > DelegacaoChefia::MAX_DIAS) {
            throw new ErroNegocio('Uma delegação dura no máximo '.DelegacaoChefia::MAX_DIAS.' dias.', 'PERIODO_DEMASIADO_LONGO', 422);
        }
        $ambito = self::normalizarAmbito($d['ambito'] ?? null);
        $motivo = trim((string) ($d['motivo'] ?? '')) ?: null;
        $user = Auth::user()?->nome_utilizador;

        $res = DB::transaction(function () use ($delegante, $delegado, $inicio, $fim, $ambito, $motivo, $user, $eu) {
            // serializa as criações do mesmo delegante (sobreposição) — o bloqueio é na ficha do delegante
            Colaborador::query()->whereKey($delegante)->lockForUpdate()->first();
            $activos = Colaborador::query()->whereIn('id', [$delegante, $delegado])->where('estado', 'ACTIVO')->pluck('id')->map(fn ($i) => (int) $i)->all();
            if (! in_array($delegante, $activos, true) || ! in_array($delegado, $activos, true)) {
                throw new ErroNegocio('O delegante e o delegado têm de ser colaboradores activos desta empresa.', 'COLABORADOR_INVALIDO', 422);
            }
            if (! DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')->where('ue.empresa_id', $this->contexto->obrigatorio())
                ->where('ue.colaborador_id', $delegado)->where('u.ativo', true)->whereNull('u.eliminado_em')->exists()) {
                throw new ErroNegocio('O delegado não tem utilizador ligado e activo nesta empresa: sem ele não pode decidir. Peça ao RH que ligue o utilizador ao colaborador.', 'DELEGADO_SEM_UTILIZADOR', 422);
            }
            $sobrepoe = fn () => DelegacaoChefia::query()->where('estado', 'ATIVA')->where('data_inicio', '<=', $fim)->where('data_fim', '>=', $inicio)
                ->where(fn ($q) => collect($ambito)->each(fn ($a) => $q->orWhereRaw('ambito @> ?::jsonb', [json_encode([$a])])));
            if ($sobrepoe()->where('delegante_colaborador_id', $delegante)->exists()) {
                throw new ErroNegocio('Já há uma delegação activa deste colaborador que se sobrepõe a este período e âmbito. Revogue-a primeiro.', 'DELEGACAO_SOBREPOSTA', 422);
            }
            if ($sobrepoe()->where('delegado_colaborador_id', $delegante)->exists()) {
                throw new ErroNegocio('Quem recebeu uma delegação neste período não a pode delegar a outra pessoa (sem delegação em cadeia).', 'RE_DELEGACAO', 422);
            }
            $registo = DelegacaoChefia::create(['delegante_colaborador_id' => $delegante, 'delegado_colaborador_id' => $delegado, 'data_inicio' => $inicio, 'data_fim' => $fim,
                'ambito' => $ambito, 'motivo' => $motivo, 'estado' => 'ATIVA', 'criado_por' => $user, 'criado_por_colaborador_id' => $eu]);
            $nomes = Colaborador::query()->withTrashed()->whereIn('id', [$delegante, $delegado])->pluck('nome_completo', 'id');
            $this->auditoria->registar(self::MODULO_AUDITORIA, 'Criar delegação da chefia', "{$nomes[$delegante]} → {$nomes[$delegado]} de {$inicio} a {$fim} (".implode(', ', $ambito).')',
                'delegacoes_chefia', $registo->id);

            return $registo;
        });

        $this->notificarCriada($res, $emNomeDeOutro);

        return $res;
    }

    public function revogar(DelegacaoChefia $d): DelegacaoChefia
    {
        $eu = $this->colaboradorDe();
        $propria = $eu !== null && (int) $d->delegante_colaborador_id === $eu;
        if (! $propria && ! Gate::allows('est_delegar')) {
            throw new ErroNegocio('Só o delegante ou quem tem a permissão de delegar em nome de outros revoga esta delegação.', 'SEM_PERMISSAO', 403);
        }
        $res = DB::transaction(function () use ($d) {
            $d = DelegacaoChefia::query()->lockForUpdate()->findOrFail($d->id);
            if ($d->estado !== 'ATIVA') {
                throw new ErroNegocio('Esta delegação já foi revogada.', 'ESTADO_INVALIDO', 422);
            }
            $d->update(['estado' => 'REVOGADA', 'revogada_por' => Auth::user()?->nome_utilizador, 'revogada_em' => now()]);
            $this->auditoria->registar(self::MODULO_AUDITORIA, 'Revogar delegação da chefia', "{$d->data_inicio->toDateString()} a {$d->data_fim->toDateString()}", 'delegacoes_chefia', $d->id);

            return $d->refresh();
        });
        $this->notificarRevogada($res, ! $propria);

        return $res;
    }

    private function notificarCriada(DelegacaoChefia $d, bool $porTerceiro): void
    {
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', [$d->delegante_colaborador_id, $d->delegado_colaborador_id])->pluck('nome_completo', 'id');
        $periodo = "de {$d->data_inicio->toDateString()} a {$d->data_fim->toDateString()}";
        $n = app(NotificadorRH::class);
        $n->paraColaborador((int) $d->delegado_colaborador_id, 'Delegação de chefia recebida', "{$nomes[$d->delegante_colaborador_id]} delegou-lhe a chefia {$periodo} ({$this->ambitoTexto($d)}). Pode decidir em nome dele/dela nesse período.", '/m/rh/rh_portal');
        if ($porTerceiro) {
            $n->paraColaborador((int) $d->delegante_colaborador_id, 'Delegou a sua chefia', "Foi criada, em seu nome, uma delegação da sua chefia em {$nomes[$d->delegado_colaborador_id]} {$periodo} ({$this->ambitoTexto($d)}).", '/m/rh/rh_portal');
        }
    }

    private function notificarRevogada(DelegacaoChefia $d, bool $porTerceiro): void
    {
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', [$d->delegante_colaborador_id, $d->delegado_colaborador_id])->pluck('nome_completo', 'id');
        $periodo = "de {$d->data_inicio->toDateString()} a {$d->data_fim->toDateString()}";
        $n = app(NotificadorRH::class);
        $n->paraColaborador((int) $d->delegado_colaborador_id, 'Delegação de chefia revogada', "A delegação de {$nomes[$d->delegante_colaborador_id]} {$periodo} foi revogada: já não decide em nome dele/dela.", '/m/rh/rh_portal', 'rh', 'AVISO');
        if ($porTerceiro) {
            $n->paraColaborador((int) $d->delegante_colaborador_id, 'Delegação da sua chefia revogada', "A delegação da sua chefia em {$nomes[$d->delegado_colaborador_id]} {$periodo} foi revogada.", '/m/rh/rh_portal');
        }
    }

    private function ambitoTexto(DelegacaoChefia $d): string
    {
        return implode(', ', array_map(fn ($a) => ['PORTAL' => 'pedidos do portal', 'METODO' => 'Método de Trabalho', 'ENTREGAVEIS' => 'entregáveis'][$a] ?? $a, (array) $d->ambito));
    }

    // ───────────── Listagens ─────────────

    /**
     * Lista para a Estrutura: quem tem `est_estrutura_view`, `est_delegar` (ou `colaboradores_view`) vê todas; os outros só as próprias.
     *
     * @param  array{estado?: ?string, colaborador_id?: ?int}  $f
     * @return list<array<string, mixed>>
     */
    public function listar(array $f = [], bool $todas = false): array
    {
        $eu = $this->colaboradorDe();
        if (! $todas && $eu === null) {
            throw new ErroNegocio('Sem permissão para ver as delegações da empresa e o seu utilizador não está ligado a um colaborador.', 'SEM_PERMISSAO', 403);
        }
        $q = DelegacaoChefia::query()->orderByDesc('data_inicio')->orderByDesc('id')->limit(500)
            ->when($f['estado'] ?? null, fn ($q, $e) => $q->where('estado', $e))
            ->when($f['colaborador_id'] ?? null, fn ($q, $c) => $q->where(fn ($w) => $w->where('delegante_colaborador_id', $c)->orWhere('delegado_colaborador_id', $c)))
            ->when(! $todas, fn ($q) => $q->where(fn ($w) => $w->where('delegante_colaborador_id', $eu)->orWhere('delegado_colaborador_id', $eu)));

        return $this->formatar($q->get(), $eu);
    }

    /** Uma delegação já formatada para o ecrã (a que acabou de ser criada ou revogada). @return array<string, mixed> */
    public function uma(DelegacaoChefia $d): array
    {
        return $this->formatar(collect([$d]), $this->colaboradorDe())[0];
    }

    /** Portal: as que dei e as que recebi. @return array{dadas: list<array<string, mixed>>, recebidas: list<array<string, mixed>>} */
    public function doPortal(int $eu): array
    {
        $todas = collect($this->formatar(DelegacaoChefia::query()->where(fn ($w) => $w->where('delegante_colaborador_id', $eu)->orWhere('delegado_colaborador_id', $eu))
            ->orderByDesc('data_inicio')->orderByDesc('id')->limit(200)->get(), $eu));

        return ['dadas' => $todas->filter(fn ($x) => $x['delegante']['id'] === $eu)->values()->all(), 'recebidas' => $todas->filter(fn ($x) => $x['delegado']['id'] === $eu)->values()->all()];
    }

    /**
     * @param  Collection<int, DelegacaoChefia>  $lista
     * @return list<array<string, mixed>>
     */
    private function formatar(Collection $lista, ?int $eu): array
    {
        $nomes = Colaborador::query()->withTrashed()->whereIn('id', $lista->pluck('delegante_colaborador_id')->merge($lista->pluck('delegado_colaborador_id'))->unique()->all() ?: [0])->pluck('nome_completo', 'id');
        $hoje = self::hoje();
        $podeDelegar = Gate::allows('est_delegar');

        return $lista->map(function (DelegacaoChefia $d) use ($nomes, $hoje, $eu, $podeDelegar) {
            $ativa = $d->estado === 'ATIVA';
            $inicio = $d->data_inicio->toDateString();
            $fim = $d->data_fim->toDateString();

            return ['id' => (int) $d->id, 'delegante' => ['id' => (int) $d->delegante_colaborador_id, 'nome' => $nomes[$d->delegante_colaborador_id] ?? "#{$d->delegante_colaborador_id}"],
                'delegado' => ['id' => (int) $d->delegado_colaborador_id, 'nome' => $nomes[$d->delegado_colaborador_id] ?? "#{$d->delegado_colaborador_id}"],
                'delegante_colaborador_id' => (int) $d->delegante_colaborador_id, 'delegado_colaborador_id' => (int) $d->delegado_colaborador_id,
                'data_inicio' => $inicio, 'data_fim' => $fim, 'ambito' => array_values((array) $d->ambito), 'motivo' => $d->motivo, 'estado' => $d->estado,
                'vigente' => $ativa && $inicio <= $hoje && $fim >= $hoje, 'terminada' => $ativa && $fim < $hoje, 'futura' => $ativa && $inicio > $hoje,
                'revogada_por' => $d->revogada_por, 'revogada_em' => $d->revogada_em?->toIso8601String(), 'criado_por' => $d->criado_por, 'criado_por_colaborador_id' => $d->criado_por_colaborador_id,
                'pode_revogar' => $ativa && ($podeDelegar || ($eu !== null && (int) $d->delegante_colaborador_id === $eu))];
        })->values()->all();
    }
}
