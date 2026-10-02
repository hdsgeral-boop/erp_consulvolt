<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\PerfilUtilizador;
use App\Models\Utilizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Utilizadores (renderUsers / saveUser / deleteUser, js/app_v2.js:3011-3203; ligação ao colaborador
 * _preencherColaboradorUtilizador, :3138-3148; definirPalavraPasse, js/data/servicos.js:41-46).
 *
 * Paridade:
 *  - nome de utilizador obrigatório e único (comparação exacta, como no login); perfil obrigatório;
 *  - palavra-passe obrigatória na criação, opcional na edição (em branco = mantém), mínimo 8 caracteres;
 *  - papel derivado do perfil (nome com "admin" => ADMINISTRADOR; senão UTILIZADOR); o SUPER_ADMINISTRADOR mantém o papel;
 *  - empresas autorizadas e, em cada uma, o colaborador associado (dá o Portal do Colaborador); um colaborador só
 *    pode estar ligado a um utilizador em cada empresa;
 *  - não se elimina um perfil/utilizador protegido (ver correcções).
 *
 * Correcções face ao legado (defeitos reais):
 *  - escalada de privilégios: no legado quem tinha "config_util_gerir" podia dar a si próprio ou a outro um perfil de
 *    acesso total, editar o Super Admin ou autorizar empresas a que não tinha acesso (app_v2.js:3150-3191). Agora só um
 *    utilizador de acesso total o pode fazer; os restantes gerem apenas as empresas a que eles próprios acedem;
 *  - "admin" protegido pelo NOME (app_v2.js:3197) passa a regra real: não se elimina/desactiva/rebaixa o último
 *    Super Administrador activo, nem a própria conta;
 *  - a eliminação era física (perdia-se o rasto); passa a lógica, com revogação imediata das sessões;
 *  - repor a palavra-passe (ou desactivar) revoga todas as sessões abertas do utilizador;
 *  - a palavra-passe nunca é devolvida (nem o hash) e nunca vai para a auditoria.
 */
final class ServicoUtilizadores
{
    public const MINIMO_PALAVRA_PASSE = 8;

    public function __construct(
        private readonly ServicoPermissoes $permissoes,
        private readonly ServicoEmpresas $empresas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @param  array<string, mixed>  $f  pesquisa, ativo, perfil_utilizador_id, empresa_id, por_pagina */
    public function listar(array $f, ?Utilizador $actor = null): LengthAwarePaginator
    {
        $dominio = $actor ? $this->dominio($actor) : null;
        $pagina = Utilizador::query()->with('perfil')
            // Segurança (Fase 6): sem acesso total só se vêem os utilizadores com acesso a uma das empresas do actor.
            ->when($dominio !== null, fn ($q) => $q->where(fn ($w) => $this->visiveis($w, $dominio)))
            ->when(isset($f['pesquisa']) && $f['pesquisa'] !== '', function ($q) use ($f) {
                $termo = '%'.mb_strtolower((string) $f['pesquisa']).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(nome_utilizador) LIKE ?', [$termo])->orWhereRaw('lower(nome_completo) LIKE ?', [$termo])
                    ->orWhereRaw('lower(email) LIKE ?', [$termo]));
            })
            ->when(isset($f['ativo']), fn ($q) => $q->where('ativo', (bool) $f['ativo']))
            ->when(isset($f['perfil_utilizador_id']), fn ($q) => $q->where('perfil_utilizador_id', (int) $f['perfil_utilizador_id']))
            ->when(isset($f['empresa_id']), fn ($q) => $q->where(fn ($w) => $w->where('acesso_todas_empresas', true)
                ->orWhere('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('utilizador_empresa')->whereColumn('utilizador_empresa.utilizador_id', 'utilizadores.id')
                    ->where('utilizador_empresa.empresa_id', (int) $f['empresa_id']))))
            ->orderBy('nome_utilizador')
            ->paginate(min((int) ($f['por_pagina'] ?? 50), 500));
        $pagina->setCollection($pagina->getCollection()->map(fn (Utilizador $u) => $this->apresentar($u, $actor)));

        return $pagina;
    }

    /**
     * Ficha de um utilizador visível pelo actor (404 se estiver fora das empresas que o actor administra).
     *
     * @return array<string, mixed>
     */
    public function obter(int $id, Utilizador $actor): array
    {
        $dominio = $this->dominio($actor);
        $u = Utilizador::query()->with('perfil')->when($dominio !== null, fn ($q) => $q->where(fn ($w) => $this->visiveis($w, $dominio)))->findOrFail($id);

        return $this->apresentar($u, $actor);
    }

    /**
     * @return array<string, mixed> dados públicos (sem hashes nem segredos); com $actor sem acesso total, as ligações
     *                              a empresas fora do seu domínio não são mostradas
     */
    public function apresentar(Utilizador $u, ?Utilizador $actor = null): array
    {
        $u->loadMissing('perfil');
        $dominio = $actor ? $this->dominio($actor) : null;
        $ligacoes = DB::table('utilizador_empresa as ue')->join('empresas as e', 'e.id', '=', 'ue.empresa_id')
            ->leftJoin('colaboradores as c', 'c.id', '=', 'ue.colaborador_id')
            ->where('ue.utilizador_id', $u->id)->when($dominio !== null, fn ($q) => $q->whereIn('ue.empresa_id', $dominio))->orderBy('e.nome')
            ->get(['ue.empresa_id', 'e.nome', 'e.estado', 'e.eliminado_em', 'ue.colaborador_id', 'c.nome_completo as colaborador_nome']);

        return [
            'id' => $u->id,
            'nome_utilizador' => $u->nome_utilizador,
            'nome_completo' => $u->nome_completo,
            'email' => $u->email,
            'papel' => $u->papel,
            'perfil' => $u->perfil ? ['id' => $u->perfil->id, 'nome' => $u->perfil->nome, 'acesso_total' => ($u->perfil->permissoes['all'] ?? null) === true] : null,
            'acesso_todas_empresas' => (bool) $u->acesso_todas_empresas,
            'ativo' => (bool) $u->ativo,
            'credencial_por_migrar' => $u->temCredencialLegada(),
            'ultimo_acesso_em' => $u->ultimo_acesso_em?->toAtomString(),
            'palavra_passe_alterada_em' => $u->palavra_passe_alterada_em?->toAtomString(),
            'empresas' => $ligacoes->map(fn ($l) => ['empresa_id' => (int) $l->empresa_id, 'nome' => $l->nome,
                'empresa_ativa' => $l->estado === Empresa::ESTADO_ATIVO && $l->eliminado_em === null,
                'colaborador_id' => $l->colaborador_id !== null ? (int) $l->colaborador_id : null, 'colaborador_nome' => $l->colaborador_nome])->all(),
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function criar(array $d, Utilizador $actor): Utilizador
    {
        return DB::transaction(function () use ($d, $actor) {
            $nome = trim((string) $d['nome_utilizador']);
            if (Utilizador::withTrashed()->where('nome_utilizador', $nome)->exists()) {
                throw new ErroNegocio('Este nome de utilizador já existe.', 'UTILIZADOR_DUPLICADO', 422);
            }
            $perfil = $this->perfilAtribuivel((int) $d['perfil_utilizador_id'], $actor);
            $this->exigirPalavraPasse((string) ($d['palavra_passe'] ?? ''));
            $todas = (bool) ($d['acesso_todas_empresas'] ?? false);
            if ($todas && ! $this->permissoes->total($actor)) {
                throw new ErroNegocio('Só um administrador de acesso total pode dar acesso a todas as empresas.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }

            $u = new Utilizador([
                'nome_utilizador' => $nome,
                'nome_completo' => $d['nome_completo'] ?? null,
                'email' => $d['email'] ?? null,
                'perfil_utilizador_id' => $perfil->id,
                'papel' => $this->papel($d, $perfil, null, $actor),
                'acesso_todas_empresas' => $todas,
                'ativo' => (bool) ($d['ativo'] ?? true),
                'palavra_passe' => (string) $d['palavra_passe'],
            ]);
            $u->forceFill(['palavra_passe_alterada_em' => now()])->save();
            $this->sincronizarEmpresas($u, $d['empresas'] ?? [], $actor);

            $this->auditoria->registar('Sistema/Utilizadores', 'Criar utilizador', "Utilizador '{$u->nome_utilizador}' criado com o perfil '{$perfil->nome}'.",
                'utilizadores', $u->id, null, $this->resumoAuditoria($u));

            return $u->refresh();
        });
    }

    /** @param  array<string, mixed>  $d */
    public function atualizar(Utilizador $u, array $d, Utilizador $actor): Utilizador
    {
        return DB::transaction(function () use ($u, $d, $actor) {
            $u = Utilizador::query()->lockForUpdate()->findOrFail($u->id);
            $this->exigirGerivel($u, $actor, isset($d['palavra_passe']) && $d['palavra_passe'] !== '');   // redefinir a palavra-passe exige todas as empresas
            $antes = $this->resumoAuditoria($u);

            if (isset($d['nome_utilizador'])) {
                $nome = trim((string) $d['nome_utilizador']);
                if ($nome !== $u->nome_utilizador && Utilizador::withTrashed()->where('nome_utilizador', $nome)->whereKeyNot($u->id)->exists()) {
                    throw new ErroNegocio('Este nome de utilizador já existe.', 'UTILIZADOR_DUPLICADO', 422);
                }
                $u->nome_utilizador = $nome;
            }
            foreach (['nome_completo', 'email'] as $campo) {
                if (array_key_exists($campo, $d)) {
                    $u->{$campo} = $d[$campo];
                }
            }
            $perfil = isset($d['perfil_utilizador_id']) ? $this->perfilAtribuivel((int) $d['perfil_utilizador_id'], $actor) : $u->perfil;
            if ($perfil) {
                $u->perfil_utilizador_id = $perfil->id;
            }
            $papel = $this->papel($d, $perfil, $u, $actor);
            if ($u->papel === Utilizador::PAPEL_SUPER_ADMINISTRADOR && $papel !== Utilizador::PAPEL_SUPER_ADMINISTRADOR) {
                $this->exigirOutroSuperAdministrador($u, 'rebaixar');
            }
            $u->papel = $papel;
            if (array_key_exists('acesso_todas_empresas', $d) && (bool) $d['acesso_todas_empresas'] !== (bool) $u->acesso_todas_empresas) {
                if (! $this->permissoes->total($actor)) {
                    throw new ErroNegocio('Só um administrador de acesso total pode alterar o acesso a todas as empresas.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
                }
                $u->acesso_todas_empresas = (bool) $d['acesso_todas_empresas'];
            }
            $novaPalavraPasse = isset($d['palavra_passe']) && $d['palavra_passe'] !== '' ? (string) $d['palavra_passe'] : null;
            if ($novaPalavraPasse !== null) {
                $this->definirPalavraPasse($u, $novaPalavraPasse);
            }
            $u->save();
            if (array_key_exists('empresas', $d)) {
                $this->sincronizarEmpresas($u, $d['empresas'] ?? [], $actor);
            }
            if ($novaPalavraPasse !== null && $u->id !== $actor->id) {
                $u->tokens()->delete();
            }

            $this->auditoria->registar('Sistema/Utilizadores', 'Alterar utilizador', "Utilizador '{$u->nome_utilizador}' alterado"
                .($novaPalavraPasse !== null ? ' (palavra-passe redefinida).' : '.'), 'utilizadores', $u->id, $antes, $this->resumoAuditoria($u));

            return $u->refresh();
        });
    }

    public function definirEstado(Utilizador $u, bool $ativo, Utilizador $actor): Utilizador
    {
        return DB::transaction(function () use ($u, $ativo, $actor) {
            $u = Utilizador::query()->lockForUpdate()->findOrFail($u->id);
            $this->exigirGerivel($u, $actor);
            if (! $ativo && $u->id === $actor->id) {
                throw new ErroNegocio('Não pode desactivar a sua própria conta.', 'OPERACAO_PROPRIA_CONTA', 422);
            }
            if (! $ativo && $u->eSuperAdministrador()) {
                $this->exigirOutroSuperAdministrador($u, 'desactivar');
            }
            if ((bool) $u->ativo === $ativo) {
                return $u;
            }
            $u->ativo = $ativo;
            $u->save();
            if (! $ativo) {
                $u->tokens()->delete();
            }
            $this->auditoria->registar('Sistema/Utilizadores', $ativo ? 'Activar utilizador' : 'Desactivar utilizador',
                "Utilizador '{$u->nome_utilizador}' ".($ativo ? 'activado.' : 'desactivado (sessões revogadas).'), 'utilizadores', $u->id,
                ['ativo' => ! $ativo], ['ativo' => $ativo]);

            return $u;
        });
    }

    public function reporPalavraPasse(Utilizador $u, string $palavraPasse, Utilizador $actor): Utilizador
    {
        return DB::transaction(function () use ($u, $palavraPasse, $actor) {
            $u = Utilizador::query()->lockForUpdate()->findOrFail($u->id);
            $this->exigirGerivel($u, $actor);
            $this->definirPalavraPasse($u, $palavraPasse);
            $u->save();
            $revogadas = $u->id === $actor->id ? 0 : $u->tokens()->delete();
            $this->auditoria->registar('Sistema/Utilizadores', 'Repor palavra-passe', "Palavra-passe de '{$u->nome_utilizador}' reposta por um administrador"
                ." ({$revogadas} sessão(ões) revogada(s)).", 'utilizadores', $u->id);

            return $u;
        });
    }

    public function eliminar(Utilizador $u, Utilizador $actor): void
    {
        DB::transaction(function () use ($u, $actor) {
            $u = Utilizador::query()->lockForUpdate()->findOrFail($u->id);
            $this->exigirGerivel($u, $actor);
            if ($u->id === $actor->id) {
                throw new ErroNegocio('Não pode eliminar a sua própria conta.', 'OPERACAO_PROPRIA_CONTA', 422);
            }
            if ($u->eSuperAdministrador()) {
                $this->exigirOutroSuperAdministrador($u, 'eliminar');
            }
            $u->tokens()->delete();
            $u->delete();
            $this->auditoria->registar('Sistema/Utilizadores', 'Eliminar utilizador', "Utilizador '{$u->nome_utilizador}' eliminado (eliminação lógica; sessões revogadas).",
                'utilizadores', $u->id, $this->resumoAuditoria($u), null);
        });
    }

    /**
     * Colaboradores da empresa que podem ser ligados a um utilizador (os já ligados a outro utilizador vêm marcados).
     *
     * @return list<array{id: int, nome: string, ligado_a: ?string}>
     */
    public function colaboradoresLigaveis(int $empresaId, ?int $utilizadorId = null): array
    {
        $ocupados = DB::table('utilizador_empresa as ue')->join('utilizadores as u', 'u.id', '=', 'ue.utilizador_id')
            ->where('ue.empresa_id', $empresaId)->whereNotNull('ue.colaborador_id')
            ->when($utilizadorId, fn ($q) => $q->where('ue.utilizador_id', '<>', $utilizadorId))
            ->pluck('u.nome_utilizador', 'ue.colaborador_id');

        return DB::table('colaboradores')->where('empresa_id', $empresaId)->whereNull('eliminado_em')->orderBy('nome_completo')
            ->get(['id', 'nome_completo'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'nome' => (string) $c->nome_completo, 'ligado_a' => $ocupados[$c->id] ?? null])->all();
    }

    // ───────────── regras internas ─────────────

    private function perfilAtribuivel(int $perfilId, Utilizador $actor): PerfilUtilizador
    {
        $perfil = PerfilUtilizador::query()->find($perfilId) ?? throw new ErroNegocio('O perfil indicado não existe.', 'PERFIL_INEXISTENTE', 422);
        if (($perfil->permissoes['all'] ?? null) === true && ! $this->permissoes->total($actor)) {
            throw new ErroNegocio('Só um administrador de acesso total pode atribuir um perfil de acesso total.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }

        return $perfil;
    }

    /** Um utilizador sem acesso total não gere contas de acesso total (Super Administrador ou perfil "all"). */
    private function exigirGerivel(Utilizador $alvo, Utilizador $actor, bool $todasAsEmpresas = true): void
    {
        if ($this->permissoes->total($actor)) {
            return;
        }
        if ($this->permissoes->total($alvo)) {
            throw new ErroNegocio('Só um administrador de acesso total pode alterar esta conta.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
        // Segurança (Fase 6): antes, um administrador da empresa A podia repor a palavra-passe, desactivar, editar ou eliminar
        // um utilizador só da empresa B. Sem acesso total:
        //  - editar (atualizar) exige partilhar pelo menos uma empresa — as ligações às outras empresas mantêm-se (regra existente);
        //  - repor a palavra-passe, mudar o estado ou eliminar afecta o acesso a TODAS as empresas do alvo, por isso exige que
        //    todas estejam no domínio do actor.
        $dominio = $this->dominio($actor);
        if ($dominio === null) {
            return;
        }
        $ligacoes = DB::table('utilizador_empresa')->where('utilizador_id', $alvo->id);
        $fora = $alvo->acesso_todas_empresas || ($todasAsEmpresas
            ? (clone $ligacoes)->whereNotIn('empresa_id', $dominio)->exists()
            : ((clone $ligacoes)->exists() && ! (clone $ligacoes)->whereIn('empresa_id', $dominio)->exists()));
        if ($fora) {
            throw new ErroNegocio('Este utilizador tem acesso a empresas que não administra.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
        }
    }

    /**
     * Empresas que o actor administra: null = todas (acesso total ou acesso a todas as empresas); senão as empresas a
     * que está ligado (activas ou não).
     *
     * @return list<int>|null
     */
    public function dominio(Utilizador $actor): ?array
    {
        if ($this->permissoes->total($actor) || $actor->acesso_todas_empresas) {
            return null;
        }

        return DB::table('utilizador_empresa')->where('utilizador_id', $actor->id)->pluck('empresa_id')->map(fn ($x) => (int) $x)->all();
    }

    /**
     * Restrição «utilizadores visíveis num domínio»: com acesso a todas as empresas, Super Administradores ou ligados a
     * uma das empresas do domínio.
     *
     * @param  Builder<Utilizador>  $q
     * @param  list<int>  $dominio
     */
    private function visiveis($q, array $dominio): void
    {
        $q->where('acesso_todas_empresas', true)->orWhere('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)
            ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('utilizador_empresa')->whereColumn('utilizador_empresa.utilizador_id', 'utilizadores.id')
                ->whereIn('utilizador_empresa.empresa_id', $dominio));
    }

    private function exigirOutroSuperAdministrador(Utilizador $u, string $operacao): void
    {
        $outros = Utilizador::query()->where('papel', Utilizador::PAPEL_SUPER_ADMINISTRADOR)->where('ativo', true)->whereKeyNot($u->id)->count();
        if ($outros === 0) {
            throw new ErroNegocio("Não é possível {$operacao} o último Super Administrador activo.", 'ULTIMO_SUPER_ADMINISTRADOR', 422);
        }
    }

    /**
     * Papel explícito: só um Super Administrador o define. Sem indicação, um utilizador novo é UTILIZADOR e um existente
     * mantém o seu papel. No legado (app_v2.js:3165) era ADMINISTRADOR quando o nome do perfil continha "admin" — um
     * perfil «Administrativo» ou «Administração financeira» dava poderes de aprovação (Manutenção de dados): abandonado.
     * Os papéis migrados mantêm-se (ETL).
     *
     * @param  array<string, mixed>  $d
     */
    private function papel(array $d, ?PerfilUtilizador $perfil, ?Utilizador $alvo, Utilizador $actor): string
    {
        if (isset($d['papel']) && $d['papel'] !== ($alvo?->papel)) {
            if (! $actor->eSuperAdministrador()) {
                throw new ErroNegocio('Só um Super Administrador pode definir o papel de um utilizador.', 'SEM_PERMISSAO_ADMINISTRATIVA', 403);
            }

            return (string) $d['papel'];
        }

        return $alvo?->papel ?: Utilizador::PAPEL_UTILIZADOR;
    }

    private function exigirPalavraPasse(string $palavraPasse): void
    {
        if (mb_strlen($palavraPasse) < self::MINIMO_PALAVRA_PASSE) {
            throw new ErroNegocio('A palavra-passe tem de ter pelo menos '.self::MINIMO_PALAVRA_PASSE.' caracteres.', 'PALAVRA_PASSE_CURTA', 422);
        }
    }

    /** Hash Argon2id (cast "hashed" do model, o mesmo do login); limpa a credencial PBKDF2 do legado. */
    private function definirPalavraPasse(Utilizador $u, string $palavraPasse): void
    {
        $this->exigirPalavraPasse($palavraPasse);
        $u->palavra_passe = $palavraPasse;
        $u->forceFill(['hash_password_legado' => null, 'salt_password_legado' => null, 'algoritmo_password_legado' => null, 'palavra_passe_alterada_em' => now()]);
    }

    /**
     * Empresas autorizadas e colaborador associado em cada uma. Quem não tem acesso total só acrescenta/retira as
     * empresas a que ele próprio acede; as restantes ligações do utilizador ficam como estão.
     *
     * @param  list<array{empresa_id: int, colaborador_id?: ?int}>  $pedido
     */
    private function sincronizarEmpresas(Utilizador $u, array $pedido, Utilizador $actor): void
    {
        $total = $this->permissoes->total($actor);
        $controlaveis = $total ? null : $this->empresas->idsAcessiveis($actor);
        $desejadas = [];
        foreach ($pedido as $l) {
            $empresaId = (int) $l['empresa_id'];
            if (isset($desejadas[$empresaId])) {
                throw new ErroNegocio("A empresa #{$empresaId} está repetida.", 'EMPRESA_REPETIDA', 422);
            }
            if ($controlaveis !== null && ! in_array($empresaId, $controlaveis, true)) {
                throw new ErroNegocio("Não tem acesso à empresa #{$empresaId}: não a pode autorizar.", 'EMPRESA_SEM_ACESSO', 403);
            }
            if (! Empresa::query()->whereKey($empresaId)->exists()) {
                throw new ErroNegocio("A empresa #{$empresaId} não existe.", 'EMPRESA_INEXISTENTE', 422);
            }
            $colaborador = isset($l['colaborador_id']) && $l['colaborador_id'] !== null ? (int) $l['colaborador_id'] : null;
            if ($colaborador !== null) {
                $nome = DB::table('colaboradores')->where('id', $colaborador)->where('empresa_id', $empresaId)->whereNull('eliminado_em')->value('nome_completo');
                if ($nome === null) {
                    throw new ErroNegocio("O colaborador #{$colaborador} não pertence à empresa #{$empresaId}.", 'COLABORADOR_INVALIDO', 422);
                }
                $outro = DB::table('utilizador_empresa as ue')->join('utilizadores as x', 'x.id', '=', 'ue.utilizador_id')
                    ->where('ue.empresa_id', $empresaId)->where('ue.colaborador_id', $colaborador)->where('ue.utilizador_id', '<>', $u->id)->value('x.nome_utilizador');
                if ($outro !== null) {
                    throw new ErroNegocio("O colaborador «{$nome}» já está ligado ao utilizador '{$outro}' nesta empresa.", 'COLABORADOR_JA_LIGADO', 422);
                }
            }
            $desejadas[$empresaId] = ['colaborador_id' => $colaborador];
        }

        $atuais = $u->empresas()->withTrashed()->pluck('empresas.id')->map(fn ($id) => (int) $id)->all();
        $retirar = array_values(array_filter($atuais, fn ($id) => ! isset($desejadas[$id]) && ($controlaveis === null || in_array($id, $controlaveis, true))));
        if ($retirar) {
            $u->empresas()->detach($retirar);
        }
        if ($desejadas) {
            $u->empresas()->syncWithoutDetaching($desejadas);
        }
        $this->empresas->invalidarUtilizador($u->id);
    }

    /** @return array<string, mixed> */
    private function resumoAuditoria(Utilizador $u): array
    {
        return ['nome_utilizador' => $u->nome_utilizador, 'papel' => $u->papel, 'perfil_utilizador_id' => $u->perfil_utilizador_id,
            'acesso_todas_empresas' => (bool) $u->acesso_todas_empresas, 'ativo' => (bool) $u->ativo,
            'empresas' => DB::table('utilizador_empresa')->where('utilizador_id', $u->id)->orderBy('empresa_id')->get(['empresa_id', 'colaborador_id'])
                ->map(fn ($l) => ['empresa_id' => (int) $l->empresa_id, 'colaborador_id' => $l->colaborador_id !== null ? (int) $l->colaborador_id : null])->all()];
    }
}
