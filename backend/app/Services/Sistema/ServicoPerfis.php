<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use Illuminate\Support\Facades\DB;

/**
 * Perfis e permissões — editor v2 (renderPerfis / permGravarPerfil / permEliminarPerfil / permCriarModelos /
 * permActualizarModelos / permMatriz, js/permissoes.js:841-1140), sobre o catálogo extraído do próprio legado
 * (CatalogoPermissoes, resources/permissoes/catalogo.json — ADR-024).
 *
 * Paridade:
 *  - um perfil é "acesso total" ({all: true}) ou v2 ({_v2: true, "<ecrã>_view": true, "<tarefa>": true});
 *  - nome obrigatório e único (comparação sem acentos nem maiúsculas — norm do legado);
 *  - um perfil v2 tem de permitir consultar pelo menos um ecrã;
 *  - segregação de funções: os 19 pares incompatíveis do catálogo AVISAM, não bloqueiam — gravar com conflitos exige
 *    confirmação explícita (o legado pedia confirmação num diálogo);
 *  - não se elimina um perfil com utilizadores;
 *  - perfis-modelo: criar os que faltam (pelo nome) e "actualizar" = acrescentar as permissões em falta, nunca retirar.
 *
 * Correcções face ao legado:
 *  - o editor aceitava qualquer chave gravada à mão na base; agora só chaves do catálogo (evita perfis com chaves
 *    mortas que nunca dão acesso a nada);
 *  - só um utilizador de acesso total pode criar ou alterar um perfil de acesso total (escalada de privilégios);
 *  - os utilizadores com eliminação lógica também contam para "perfil em uso" (a FK impede apagar o perfil).
 */
final class ServicoPerfis
{
    public function __construct(
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<array<string, mixed>> */
    public function listar(): array
    {
        $utilizadores = DB::table('utilizadores')->whereNull('eliminado_em')->selectRaw('perfil_utilizador_id, COUNT(*) AS n')
            ->groupBy('perfil_utilizador_id')->pluck('n', 'perfil_utilizador_id');

        return PerfilUtilizador::query()->orderBy('nome')->get()
            ->map(fn (PerfilUtilizador $p) => $this->resumo($p) + ['utilizadores' => (int) ($utilizadores[$p->id] ?? 0)])->all();
    }

    /** @return array<string, mixed> */
    public function obter(PerfilUtilizador $p): array
    {
        $chaves = $this->chaves($p->permissoes ?? []);

        return $this->resumo($p) + [
            'permissoes' => $chaves,
            'chaves_fora_do_catalogo' => array_values(array_filter($chaves, fn ($k) => ! CatalogoPermissoes::chaveValida($k))),
            'utilizadores' => DB::table('utilizadores')->whereNull('eliminado_em')->where('perfil_utilizador_id', $p->id)->orderBy('nome_utilizador')
                ->get(['id', 'nome_utilizador', 'ativo'])->map(fn ($u) => ['id' => (int) $u->id, 'nome_utilizador' => $u->nome_utilizador, 'ativo' => (bool) $u->ativo])->all(),
            'tem_permissoes_originais' => $p->getAttribute('permissoes_originais') !== null,
        ];
    }

    /**
     * @param  array{nome: string, descricao?: ?string, acesso_total?: bool, permissoes?: list<string>, confirmar_conflitos?: bool}  $d
     * @return array{perfil: PerfilUtilizador, avisos: list<array<string, string>>}
     */
    public function guardar(array $d, ?PerfilUtilizador $p, Utilizador $actor): array
    {
        return DB::transaction(function () use ($d, $p, $actor) {
            if ($p) {
                $p = PerfilUtilizador::query()->lockForUpdate()->findOrFail($p->id);
            }
            $nome = trim((string) ($d['nome'] ?? $p?->nome));
            if ($nome === '') {
                throw new ErroNegocio('Indique o nome do perfil.', 'NOME_OBRIGATORIO', 422);
            }
            $repetido = PerfilUtilizador::query()->when($p, fn ($q) => $q->whereKeyNot($p->id))->get(['id', 'nome'])
                ->first(fn ($x) => ServicoPermissoes::norm((string) $x->nome) === ServicoPermissoes::norm($nome));
            if ($repetido) {
                throw new ErroNegocio("Já existe um perfil com o nome «{$repetido->nome}».", 'PERFIL_DUPLICADO', 422);
            }

            if ($p) {
                $this->exigirPerfilGerivel($p, $actor);
            }
            $eraTotal = $p && ($p->permissoes['all'] ?? null) === true;
            $total = (bool) ($d['acesso_total'] ?? ($p ? $eraTotal : false));
            if (($total || $eraTotal) && ! $this->permissoes->total($actor)) {
                throw new ErroNegocio('Só um administrador de acesso total pode criar ou alterar um perfil de acesso total.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }

            $avisos = [];
            if ($total) {
                $permissoes = ['all' => true, '_v2' => true];
            } else {
                $chaves = array_key_exists('permissoes', $d) ? $d['permissoes'] : $this->chaves($p?->permissoes ?? []);
                $chaves = array_values(array_unique(array_map(fn ($k) => ServicoPermissoes::norm((string) $k), $chaves)));
                $invalidas = array_values(array_filter($chaves, fn ($k) => ! CatalogoPermissoes::chaveValida($k)));
                if ($invalidas) {
                    throw new ErroNegocio('Há permissões que não existem no catálogo: '.implode(', ', $invalidas).'.', 'PERMISSAO_INEXISTENTE', 422, ['permissoes' => $invalidas]);
                }
                $contagem = $this->contar($chaves);
                if ($contagem['ecras'] === 0) {
                    throw new ErroNegocio('Marque pelo menos um ecrã para consultar.', 'PERFIL_SEM_ECRAS', 422);
                }
                $avisos = $contagem['conflitos'];
                if ($avisos && ! ($d['confirmar_conflitos'] ?? false)) {
                    throw new ErroNegocio('Este perfil junta tarefas que devem ficar com pessoas diferentes. Confirme para gravar mesmo assim.',
                        'SEGREGACAO_FUNCOES', 422, ['conflitos' => $avisos]);
                }
                $permissoes = ['_v2' => true] + array_fill_keys($chaves, true);
            }

            $antes = $p ? ['nome' => $p->nome, 'permissoes' => $p->permissoes] : null;
            $dados = ['nome' => $nome, 'permissoes' => $permissoes] + (array_key_exists('descricao', $d) ? ['descricao' => $d['descricao']] : []);
            $p ? $p->update($dados) : $p = PerfilUtilizador::create($dados);

            $this->auditoria->registar('Sistema/Perfis', $antes ? 'Alterar perfil' : 'Criar perfil', "Perfil «{$nome}» gravado"
                .($avisos ? ' com '.count($avisos).' conflito(s) de segregação de funções confirmados.' : '.'),
                'perfis_utilizador', $p->id, $antes, ['nome' => $nome, 'permissoes' => $permissoes, 'conflitos' => $avisos]);

            return ['perfil' => $p->refresh(), 'avisos' => $avisos];
        });
    }

    /**
     * Segurança (Fase 6): os perfis são globais. Um actor sem acesso total (nem acesso a todas as empresas) não altera o
     * seu próprio perfil (escalada de privilégios) nem um perfil usado por utilizadores de empresas que não administra.
     */
    private function exigirPerfilGerivel(PerfilUtilizador $p, Utilizador $actor): void
    {
        $dominio = app(ServicoUtilizadores::class)->dominio($actor);
        if ($dominio === null) {
            return;
        }
        if ((int) $actor->perfil_utilizador_id === (int) $p->id) {
            throw new ErroNegocio('Não pode alterar o perfil que lhe está atribuído.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        $fora = DB::table('utilizadores as u')->whereNull('u.eliminado_em')->where('u.perfil_utilizador_id', $p->id)
            ->where(fn ($w) => $w->where('u.acesso_todas_empresas', true)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('utilizador_empresa as ue')->whereColumn('ue.utilizador_id', 'u.id')->whereNotIn('ue.empresa_id', $dominio)))
            ->exists();
        if ($fora) {
            throw new ErroNegocio('Este perfil é usado por utilizadores de empresas que não administra.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    public function duplicar(PerfilUtilizador $p, Utilizador $actor): PerfilUtilizador
    {
        $base = 'Cópia de '.$p->nome;
        $nome = $base;
        for ($i = 2; PerfilUtilizador::query()->get(['nome'])->contains(fn ($x) => ServicoPermissoes::norm((string) $x->nome) === ServicoPermissoes::norm($nome)); $i++) {
            $nome = "{$base} ({$i})";
        }
        $total = ($p->permissoes['all'] ?? null) === true;

        return $this->guardar(['nome' => $nome, 'descricao' => $p->descricao, 'acesso_total' => $total,
            'permissoes' => $this->chaves($p->permissoes ?? []), 'confirmar_conflitos' => true], null, $actor)['perfil'];
    }

    public function eliminar(PerfilUtilizador $p, Utilizador $actor): void
    {
        DB::transaction(function () use ($p, $actor) {
            $p = PerfilUtilizador::query()->lockForUpdate()->findOrFail($p->id);
            if (($p->permissoes['all'] ?? null) === true && ! $this->permissoes->total($actor)) {
                throw new ErroNegocio('Só um administrador de acesso total pode eliminar um perfil de acesso total.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }
            $n = DB::table('utilizadores')->where('perfil_utilizador_id', $p->id)->count();
            if ($n) {
                throw new ErroNegocio("Não é possível eliminar o perfil «{$p->nome}»: tem {$n} utilizador(es) associado(s).", 'PERFIL_EM_USO', 422, ['utilizadores' => $n]);
            }
            $p->delete();
            $this->auditoria->registar('Sistema/Perfis', 'Eliminar perfil', "Perfil «{$p->nome}» eliminado.", 'perfis_utilizador', $p->id,
                ['nome' => $p->nome, 'permissoes' => $p->permissoes], null);
        });
    }

    /**
     * Perfis-modelo em falta (comparação pelo nome) — permCriarModelos. Com $simular só lista.
     *
     * @return list<string> nomes criados (ou a criar)
     */
    public function criarModelos(bool $simular = false): array
    {
        $existentes = PerfilUtilizador::query()->pluck('nome')->map(fn ($n) => ServicoPermissoes::norm((string) $n))->all();
        $novos = array_values(array_filter(CatalogoPermissoes::modelos(), fn ($m) => ! in_array(ServicoPermissoes::norm($m['nome']), $existentes, true)));
        if (! $simular && $novos) {
            DB::transaction(function () use ($novos) {
                foreach ($novos as $m) {
                    PerfilUtilizador::create(['nome' => $m['nome'], 'permissoes' => $m['permissoes']]);
                }
                $this->auditoria->registar('Sistema/Perfis', 'Criar perfis-modelo', count($novos).' perfil(is)-modelo criado(s): '.implode(', ', array_column($novos, 'nome')).'.');
            });
        }

        return array_column($novos, 'nome');
    }

    /**
     * Acrescenta aos perfis com o nome de um modelo as permissões do modelo que lhes faltam; nunca retira
     * (permActualizarModelos). Perfis de acesso total ou fora do formato v2 ficam de fora.
     *
     * @return list<array{perfil_id: int, nome: string, acrescentar: list<string>}>
     */
    public function actualizarModelos(bool $simular = false): array
    {
        $perfis = PerfilUtilizador::query()->get();
        $alteracoes = [];
        foreach (CatalogoPermissoes::modelos() as $m) {
            $p = $perfis->first(fn ($x) => ServicoPermissoes::norm((string) $x->nome) === ServicoPermissoes::norm($m['nome']));
            $perm = $p?->permissoes ?? [];
            if (! $p || ($perm['all'] ?? null) === true || ($perm['_v2'] ?? null) !== true) {
                continue;
            }
            $novas = array_keys(array_filter($m['permissoes'], fn ($v, $k) => $v === true && $k !== '_v2' && ($perm[$k] ?? null) !== true, ARRAY_FILTER_USE_BOTH));
            if ($novas) {
                $alteracoes[] = ['perfil_id' => $p->id, 'nome' => $p->nome, 'acrescentar' => $novas];
            }
        }
        if (! $simular && $alteracoes) {
            DB::transaction(function () use ($alteracoes, $perfis) {
                foreach ($alteracoes as $a) {
                    $p = $perfis->firstWhere('id', $a['perfil_id']);
                    $p->update(['permissoes' => $p->permissoes + array_fill_keys($a['acrescentar'], true)]);
                }
                $this->auditoria->registar('Sistema/Perfis', 'Actualizar perfis-modelo', implode(', ', array_column($alteracoes, 'nome')));
            });
        }

        return $alteracoes;
    }

    /**
     * Matriz perfis × permissões (permMatriz).
     *
     * @return array{perfis: list<array{id: int, nome: string, acesso_total: bool}>, chaves: array<string, list<int>>}
     */
    public function matriz(): array
    {
        $perfis = PerfilUtilizador::query()->orderBy('nome')->get();
        $chaves = [];
        foreach ($perfis as $p) {
            foreach ($this->chaves($p->permissoes ?? []) as $k) {
                $chaves[$k][] = $p->id;
            }
        }
        ksort($chaves);

        return ['perfis' => $perfis->map(fn ($p) => ['id' => $p->id, 'nome' => $p->nome, 'acesso_total' => ($p->permissoes['all'] ?? null) === true])->all(), 'chaves' => $chaves];
    }

    /**
     * Contagem do editor (contar, js/permissoes.js:832-839): ecrãs consultados, tarefas, sensíveis e conflitos.
     *
     * @param  list<string>  $chaves
     * @return array{ecras: int, tarefas: int, sensiveis: int, conflitos: list<array{a: string, b: string, motivo: string}>}
     */
    public function contar(array $chaves): array
    {
        $set = array_flip($chaves);
        $tarefas = CatalogoPermissoes::tarefas();

        return [
            'ecras' => count(array_filter(array_keys(CatalogoPermissoes::ecras()), fn ($id) => isset($set["{$id}_view"]))),
            'tarefas' => count(array_filter(array_keys($tarefas), fn ($k) => isset($set[$k]))),
            'sensiveis' => count(array_filter($tarefas, fn ($t, $k) => ($t['sensivel'] ?? false) && isset($set[$k]), ARRAY_FILTER_USE_BOTH)),
            'conflitos' => array_values(array_filter(CatalogoPermissoes::segregacao(), fn ($s) => isset($set[$s['a']], $set[$s['b']]))),
        ];
    }

    /** @return array<string, mixed> */
    private function resumo(PerfilUtilizador $p): array
    {
        $perm = $p->permissoes ?? [];
        $total = ($perm['all'] ?? null) === true;

        return ['id' => $p->id, 'nome' => $p->nome, 'descricao' => $p->descricao, 'acesso_total' => $total,
            'formato' => $total ? 'TOTAL' : (($perm['_v2'] ?? null) === true ? 'V2' : 'ANTIGO'),
            'contagem' => $total ? null : $this->contar($this->chaves($perm))];
    }

    /** @return list<string> chaves activas (sem _v2/all) */
    private function chaves(array $permissoes): array
    {
        return array_values(array_map('strval', array_keys(array_filter($permissoes, fn ($v, $k) => $v === true && $k !== '_v2' && $k !== 'all', ARRAY_FILTER_USE_BOTH))));
    }
}
