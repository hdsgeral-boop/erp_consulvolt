<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Models\CargoFuncao;
use App\Models\Colaborador;
use App\Models\PeriodoProcessamentoSalarial;
use App\Models\PostoTrabalho;
use App\Models\ResultadoFolhaSalarial;
use App\Models\UnidadeOrganica;
use App\Support\Dados\VerificadorReferencias;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Estrutura orgânica (js/modules/estrutura/estrutura_dados.js) e cargos/funções (app_v2.js:4083-4128).
 * Paridade: unidades (código único, pai sem ciclos, responsável colocado na unidade), postos (unidade, cargo ou
 * título, vagas; acima das vagas é só aviso), afectação (gestor ≠ o próprio; posto da unidade), chefia directa =
 * gestor explícito ou o primeiro responsável ao subir na árvore; eliminar unidade só sem subunidades nem membros.
 * Correcções (ADR-040):
 *   - ciclos na chefia são recusados (A chefia B e B chefia A — o legado permitia, e uma contestação podia acabar
 *     decidida pelo próprio avaliado); ciclos no «reporta a» dos postos também;
 *   - a chefia ignora colaboradores não activos e unidades inactivas;
 *   - a afectação em massa já não apaga o gestor explícito (o legado gravava gestor = nulo em todos);
 *   - posto sem unidade: a unidade passa a ser a do posto;
 *   - eliminar uma unidade ou posto limpa as referências (inactivos, «reporta a», responsável) em vez de as deixar
 *     penduradas; um cargo em uso por postos também não se elimina (o legado só via os colaboradores);
 *   - cargos com nome único (os 3 pares repetidos do legado ficam).
 */
final class ServicoEstruturaOrg
{
    public const TIPOS_UNIDADE = ['ORGAO_SOCIAL', 'DIRECCAO_GERAL', 'DIRECCAO', 'DEPARTAMENTO', 'GABINETE', 'SECCAO', 'EQUIPA', 'OUTRO'];

    public function __construct(private readonly VerificadorReferencias $referencias) {}

    // ───────────── Árvore ─────────────

    public function arvore(): array
    {
        $colabs = Colaborador::query()->where('estado', 'ACTIVO')->get(['id', 'nome_completo', 'unidade_organica_id', 'posto_trabalho_id', 'colaborador_gestor_id', 'cargo_funcao_id']);
        $postos = PostoTrabalho::query()->orderBy('ordem')->orderBy('id')->get()->map(fn ($p) => $p->toArray() + [
            'ocupados' => $colabs->where('posto_trabalho_id', $p->id)->count(),
            'livres' => max(0, (int) $p->vagas - $colabs->where('posto_trabalho_id', $p->id)->count())]);

        return ['unidades' => UnidadeOrganica::query()->orderBy('ordem')->orderBy('nome')->get()->map(fn ($u) => $u->toArray() + [
            'membros' => $colabs->where('unidade_organica_id', $u->id)->count(), 'postos' => $postos->where('unidade_organica_id', $u->id)->values()])->all(),
            'sem_unidade' => $colabs->whereNull('unidade_organica_id')->count()];
    }

    // ───────────── Mapa de pessoal (ADR-064) ─────────────

    /**
     * Mapa de pessoal por unidade orgânica (só os postos da própria unidade; o cliente agrega as subunidades) e por
     * cargo: lugares previstos (vagas dos postos), ocupados (colaboradores activos no posto), em aberto e acima do
     * previsto, e colaboradores activos. Com $verSalarios, a massa salarial = ilíquido (bruto) do último
     * processamento fechado ou validado, atribuído à unidade e ao cargo actuais de cada colaborador.
     */
    public function mapaPessoal(bool $verSalarios): array
    {
        $colabs = Colaborador::query()->where('estado', 'ACTIVO')->get(['id', 'unidade_organica_id', 'posto_trabalho_id', 'cargo_funcao_id']);
        $porPosto = $colabs->whereNotNull('posto_trabalho_id')->countBy('posto_trabalho_id');
        $postos = PostoTrabalho::query()->get(['id', 'unidade_organica_id', 'cargo_funcao_id', 'vagas'])->map(function ($p) use ($porPosto) {
            $o = (int) ($porPosto[$p->id] ?? 0);
            $v = (int) $p->vagas;

            return ['unidade' => $p->unidade_organica_id, 'cargo' => $p->cargo_funcao_id, 'previstos' => $v, 'ocupados' => $o, 'em_aberto' => max(0, $v - $o), 'acima' => max(0, $o - $v)];
        });

        $massa = ['periodo' => null, 'por_colaborador' => []];
        $todos = $colabs;
        if ($verSalarios) {
            $ultimo = PeriodoProcessamentoSalarial::query()->whereIn('estado', ['FECHADO', 'VALIDADO'])
                ->orderByRaw('substring(mes_ano from 4 for 4) DESC, substring(mes_ano from 1 for 2) DESC')->first(['id', 'mes_ano']);
            if ($ultimo) {
                $massa = ['periodo' => $ultimo->mes_ano, 'por_colaborador' => ResultadoFolhaSalarial::query()->where('periodo_processamento_salarial_id', $ultimo->id)
                    ->get(['colaborador_id', 'bruto'])->groupBy('colaborador_id')->map(fn ($g) => $g->reduce(fn ($s, $r) => bcadd($s, (string) $r->bruto, 2), '0.00'))->all()];
            }
            // quem recebeu e não está activo conta pela unidade e cargo que tem na ficha
            $fora = array_diff(array_keys($massa['por_colaborador']), $colabs->pluck('id')->all());
            $todos = $fora === [] ? $colabs : $colabs->concat(Colaborador::query()->withTrashed()->whereIn('id', $fora)->get(['id', 'unidade_organica_id', 'posto_trabalho_id', 'cargo_funcao_id']));
        }
        $somaMassa = function (callable $filtro) use ($massa, $todos): string {
            $s = '0.00';
            foreach ($todos->filter($filtro) as $c) {
                $s = bcadd($s, $massa['por_colaborador'][$c->id] ?? '0', 2);
            }

            return $s;
        };
        $linha = function ($grupoPostos, callable $filtroColab) use ($colabs, $verSalarios, $somaMassa): array {
            $l = ['previstos' => (int) $grupoPostos->sum('previstos'), 'ocupados' => (int) $grupoPostos->sum('ocupados'), 'em_aberto' => (int) $grupoPostos->sum('em_aberto'),
                'acima' => (int) $grupoPostos->sum('acima'), 'postos' => $grupoPostos->count(), 'colaboradores' => $colabs->filter($filtroColab)->count()];

            return $verSalarios ? $l + ['massa_salarial' => $somaMassa($filtroColab)] : $l;
        };

        $unidades = UnidadeOrganica::query()->orderBy('ordem')->orderBy('nome')->get(['id', 'codigo', 'nome', 'unidade_organica_pai_id', 'ativo'])
            ->map(fn ($u) => ['unidade_organica_id' => $u->id, 'codigo' => $u->codigo, 'nome' => $u->nome, 'unidade_organica_pai_id' => $u->unidade_organica_pai_id, 'ativo' => (bool) $u->ativo]
                + $linha($postos->where('unidade', $u->id), fn ($c) => (int) $c->unidade_organica_id === $u->id))->values()->all();
        $semUnidade = $linha($postos->whereNull('unidade'), fn ($c) => $c->unidade_organica_id === null);

        $nomesCargo = CargoFuncao::query()->orderBy('nome')->pluck('nome', 'id');
        $idsCargo = $nomesCargo->keys()->all();
        $cargos = collect($idsCargo)->map(fn ($id) => ['cargo_funcao_id' => (int) $id, 'nome' => $nomesCargo[$id]]
            + $linha($postos->where('cargo', $id), fn ($c) => (int) $c->cargo_funcao_id === (int) $id))
            ->filter(fn ($l) => $l['postos'] > 0 || $l['colaboradores'] > 0 || bccomp($l['massa_salarial'] ?? '0', '0', 2) !== 0)->values();
        $semCargo = $linha($postos->filter(fn ($p) => $p['cargo'] === null || ! isset($nomesCargo[$p['cargo']])),
            fn ($c) => $c->cargo_funcao_id === null || ! isset($nomesCargo[$c->cargo_funcao_id]));
        if ($semCargo['postos'] > 0 || $semCargo['colaboradores'] > 0 || bccomp($semCargo['massa_salarial'] ?? '0', '0', 2) !== 0) {
            $cargos->push(['cargo_funcao_id' => null, 'nome' => 'Sem cargo'] + $semCargo);
        }

        return ['ver_salarios' => $verSalarios, 'periodo_salarial' => $massa['periodo'], 'por_unidade' => $unidades, 'sem_unidade' => $semUnidade,
            'por_cargo' => $cargos->all(), 'totais' => $linha($postos, fn () => true)];
    }

    // ───────────── Unidades ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarUnidade(array $d, ?UnidadeOrganica $u = null): UnidadeOrganica
    {
        $d['nome'] = trim((string) $d['nome']);
        if (! empty($d['codigo'])) {
            $d['codigo'] = trim((string) $d['codigo']);
            if (UnidadeOrganica::query()->whereRaw('lower(codigo) = lower(?)', [$d['codigo']])->when($u, fn ($q) => $q->whereKeyNot($u->id))->exists()) {
                throw new ErroNegocio("Já existe a unidade com o código {$d['codigo']}.", 'CODIGO_DUPLICADO', 422);
            }
        }
        if (! empty($d['unidade_organica_pai_id'])) {
            UnidadeOrganica::query()->findOrFail($d['unidade_organica_pai_id']);
            if ($u && in_array($u->id, [(int) $d['unidade_organica_pai_id'], ...$this->antepassados((int) $d['unidade_organica_pai_id'])], true)) {
                throw new ErroNegocio('A unidade não pode depender de si própria nem de uma das suas subunidades.', 'CICLO_HIERARQUIA', 422);
            }
        }
        $aviso = null;
        if (! empty($d['centro_custo_id']) && UnidadeOrganica::query()->where('centro_custo_id', $d['centro_custo_id'])->when($u, fn ($q) => $q->whereKeyNot($u->id))->exists()) {
            $aviso = 'O centro de custo já está ligado a outra unidade.';
        }

        return DB::transaction(function () use ($d, $u, $aviso) {
            $user = Auth::user()?->nome_utilizador;
            $u ? $u->update($d + ['atualizado_por' => $user]) : $u = UnidadeOrganica::create($d + ['ativo' => true, 'criado_por' => $user]);
            if (! empty($d['colaborador_responsavel_id'])) {   // o responsável fica na unidade se ainda não tiver nenhuma
                Colaborador::query()->whereKey($d['colaborador_responsavel_id'])->whereNull('unidade_organica_id')->update(['unidade_organica_id' => $u->id]);
            }
            $u->refresh()->setAttribute('aviso', $aviso);

            return $u;
        });
    }

    public function eliminarUnidade(UnidadeOrganica $u): void
    {
        if (UnidadeOrganica::query()->where('unidade_organica_pai_id', $u->id)->exists()) {
            throw new ErroNegocio("A unidade {$u->nome} tem subunidades: mova-as ou elimine-as primeiro.", 'REGISTO_EM_USO', 422);
        }
        if (Colaborador::query()->where('unidade_organica_id', $u->id)->where('estado', 'ACTIVO')->exists()) {
            throw new ErroNegocio("A unidade {$u->nome} tem colaboradores activos: afecte-os a outra unidade primeiro.", 'REGISTO_EM_USO', 422);
        }
        DB::transaction(function () use ($u) {
            foreach (PostoTrabalho::query()->where('unidade_organica_id', $u->id)->get() as $p) {
                $this->limparPosto($p);
                $p->delete();
            }
            Colaborador::query()->where('unidade_organica_id', $u->id)->update(['unidade_organica_id' => null]);
            $u->delete();
        });
    }

    // ───────────── Postos ─────────────

    /** @param  array<string, mixed>  $d */
    public function guardarPosto(array $d, ?PostoTrabalho $p = null): PostoTrabalho
    {
        UnidadeOrganica::query()->findOrFail($d['unidade_organica_id']);
        if (empty($d['cargo_funcao_id']) && trim((string) ($d['titulo'] ?? '')) === '') {
            throw new ErroNegocio('Indique o cargo ou o título do posto.', 'POSTO_SEM_CARGO', 422);
        }
        if (! empty($d['cargo_funcao_id'])) {
            CargoFuncao::query()->findOrFail($d['cargo_funcao_id']);
        }
        if (! empty($d['posto_superior_id'])) {
            PostoTrabalho::query()->findOrFail($d['posto_superior_id']);
            $cadeia = [(int) $d['posto_superior_id']];
            for ($x = PostoTrabalho::query()->find($d['posto_superior_id']); $x && $x->posto_superior_id && count($cadeia) < 200; $x = PostoTrabalho::query()->find($x->posto_superior_id)) {
                $cadeia[] = (int) $x->posto_superior_id;
            }
            if ($p && in_array($p->id, $cadeia, true)) {
                throw new ErroNegocio('O posto não pode reportar a si próprio nem a um posto que dele depende.', 'CICLO_HIERARQUIA', 422);
            }
        }
        $p ? $p->update($d) : $p = PostoTrabalho::create($d + ['vagas' => 1]);

        return $p->refresh();
    }

    public function eliminarPosto(PostoTrabalho $p): void
    {
        if (Colaborador::query()->where('posto_trabalho_id', $p->id)->where('estado', 'ACTIVO')->exists()) {
            throw new ErroNegocio('O posto tem ocupantes activos: afecte-os a outro posto primeiro.', 'REGISTO_EM_USO', 422);
        }
        DB::transaction(function () use ($p) {
            $this->limparPosto($p);
            $p->delete();
        });
    }

    // ───────────── Cargos ─────────────

    public function guardarCargo(array $d, ?CargoFuncao $c = null): CargoFuncao
    {
        $d['nome'] = trim((string) $d['nome']);
        if (CargoFuncao::query()->whereRaw('lower(nome) = lower(?)', [$d['nome']])->when($c, fn ($q) => $q->whereKeyNot($c->id))->exists()) {
            throw new ErroNegocio("Já existe o cargo «{$d['nome']}».", 'CARGO_DUPLICADO', 422);
        }
        $c ? $c->update($d) : $c = CargoFuncao::create($d);

        return $c->refresh();
    }

    public function eliminarCargo(CargoFuncao $c): void
    {
        $this->referencias->exigirLivre('cargos_funcoes', $c->id, "o cargo {$c->nome}");
        $c->delete();
    }

    // ───────────── Afectação e chefia ─────────────

    /**
     * Afecta o colaborador a unidade/posto/gestor. Só as chaves presentes em $d são alteradas (a afectação em massa
     * não mexe no gestor).
     *
     * @return array{colaborador: Colaborador, avisos: list<string>}
     */
    public function afectar(Colaborador $c, array $d): array
    {
        $avisos = [];
        if (array_key_exists('posto_trabalho_id', $d) && $d['posto_trabalho_id']) {
            $posto = PostoTrabalho::query()->findOrFail($d['posto_trabalho_id']);
            $unidade = $d['unidade_organica_id'] ?? null;
            if ($unidade && (int) $unidade !== (int) $posto->unidade_organica_id) {
                throw new ErroNegocio('O posto de trabalho tem de pertencer à unidade orgânica indicada.', 'POSTO_FORA_DA_UNIDADE', 422);
            }
            $d['unidade_organica_id'] = $posto->unidade_organica_id;
            $ocupados = Colaborador::query()->where('posto_trabalho_id', $posto->id)->where('estado', 'ACTIVO')->whereKeyNot($c->id)->count();
            if ($ocupados + 1 > (int) $posto->vagas) {
                $avisos[] = "O posto fica com mais ocupantes ({$ocupados} + 1) do que vagas ({$posto->vagas}).";
            }
        }
        if (! empty($d['unidade_organica_id'])) {
            UnidadeOrganica::query()->findOrFail($d['unidade_organica_id']);
        }
        if (array_key_exists('colaborador_gestor_id', $d) && $d['colaborador_gestor_id']) {
            $this->exigirGestorValido($c->id, (int) $d['colaborador_gestor_id']);
        }
        $c->update(array_intersect_key($d, array_flip(['unidade_organica_id', 'posto_trabalho_id', 'colaborador_gestor_id'])));

        return ['colaborador' => $c->refresh(), 'avisos' => $avisos];
    }

    public function exigirGestorValido(int $colaborador, int $gestor): void
    {
        if ($gestor === $colaborador) {
            throw new ErroNegocio('O colaborador não pode ser o seu próprio gestor.', 'GESTOR_INVALIDO', 422);
        }
        Colaborador::query()->findOrFail($gestor);
        $visto = [$gestor => true];
        for ($x = $gestor, $n = 0; $n < 200 && ($x = $this->chefiaDe($x, false)); $n++) {
            if ($x === $colaborador) {
                throw new ErroNegocio('Esse gestor depende (directa ou indirectamente) deste colaborador: a chefia ficaria em ciclo.', 'CICLO_CHEFIA', 422);
            }
            if (isset($visto[$x])) {
                break;
            }
            $visto[$x] = true;
        }
    }

    /**
     * Chefia directa: o gestor explícito (se activo) ou, subindo na árvore a partir da unidade do colaborador, o
     * primeiro responsável activo que não seja o próprio (unidades inactivas não contam).
     */
    public function chefiaDe(int $colaborador, bool $soActivos = true): ?int
    {
        $c = Colaborador::query()->find($colaborador);
        if (! $c) {
            return null;
        }
        $activo = fn (?int $id) => $id && Colaborador::query()->whereKey($id)->when($soActivos, fn ($q) => $q->where('estado', 'ACTIVO'))->exists();
        if ($c->colaborador_gestor_id && $activo($c->colaborador_gestor_id)) {
            return (int) $c->colaborador_gestor_id;
        }
        for ($u = $c->unidade_organica_id ? UnidadeOrganica::query()->find($c->unidade_organica_id) : null, $n = 0; $u && $n < 200; $u = $u->unidade_organica_pai_id ? UnidadeOrganica::query()->find($u->unidade_organica_pai_id) : null, $n++) {
            if ($u->ativo !== false && $u->colaborador_responsavel_id && (int) $u->colaborador_responsavel_id !== $c->id && $activo($u->colaborador_responsavel_id)) {
                return (int) $u->colaborador_responsavel_id;
            }
        }

        return null;
    }

    /** @return list<int> colaboradores activos cuja chefia directa é $chefe */
    public function equipaDirecta(int $chefe): array
    {
        return Colaborador::query()->where('estado', 'ACTIVO')->whereKeyNot($chefe)->pluck('id')
            ->filter(fn ($id) => $this->chefiaDe((int) $id) === $chefe)->values()->all();
    }

    /** @return list<int> */
    private function antepassados(int $unidade): array
    {
        $saida = [];
        for ($u = UnidadeOrganica::query()->find($unidade); $u && $u->unidade_organica_pai_id && count($saida) < 200; $u = UnidadeOrganica::query()->find($u->unidade_organica_pai_id)) {
            $saida[] = (int) $u->unidade_organica_pai_id;
        }

        return $saida;
    }

    private function limparPosto(PostoTrabalho $p): void
    {
        PostoTrabalho::query()->where('posto_superior_id', $p->id)->update(['posto_superior_id' => null]);
        Colaborador::query()->where('posto_trabalho_id', $p->id)->update(['posto_trabalho_id' => null]);
    }
}
