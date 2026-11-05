<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\PerfilUtilizador;
use App\Models\TicketSuporte;
use App\Models\Utilizador;
use App\Services\Sistema\ServicoPermissoes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Especializações do backoffice de suporte (ADR-077): uma especialização é um perfil de utilizador restrito à
 * plataforma cujas permissões são só `suporte_tickets_<grupo>_view/_gerir`. Cada «grupo» agrupa categorias de tickets
 * (a fonte de verdade é TicketSuporte::CHAVE_POR_CATEGORIA — daqui sai a lista de categorias de cada grupo, nunca
 * duplicada) e decide o que o agente vê e responde em /api/suporte/*. Nunca concede acesso a clientes, planos nem
 * definições: as chaves geradas são sempre do módulo `suporte`.
 */
final class ServicoEspecializacoesSuporte
{
    /** Grupos de categorias (sufixo da chave `suporte_tickets_<grupo>_gerir`) e o seu rótulo. */
    public const GRUPOS = [
        'geral' => 'Geral',
        'financeiro' => 'Financeiro (plano, facturação, pagamento, subscrição)',
        'marketing' => 'Marketing e Comunicação',
        'tecnico' => 'Técnico',
    ];

    /** Especializações base, sempre disponíveis (nome => [descrição, grupos]). */
    public const BASE = [
        'Agente de Suporte Geral' => ['Tickets gerais: dúvidas, melhorias, administrativas e outros.', ['geral']],
        'Agente de Suporte Técnico' => ['Tickets técnicos: incidentes, erros, autenticação, permissões, desempenho, integração, segurança e configuração.', ['tecnico']],
        'Agente de Suporte Financeiro' => ['Tickets de plano, facturação, pagamento e subscrição.', ['financeiro']],
        'Agente de Suporte Marketing e Comunicação' => ['Tickets de marketing e comunicação.', ['marketing']],
        'Agente de Suporte (todas as categorias)' => ['Todas as categorias de tickets: geral, técnico, financeiro e marketing.', ['geral', 'tecnico', 'financeiro', 'marketing']],
    ];

    private const BLOQUEIO_ADVISORY = 770771;

    public function __construct(private readonly ServicoPermissoes $permissoes) {}

    /** @return list<array{id: string, rotulo: string, chave_view: string, chave_gerir: string, categorias: list<string>}> */
    public function grupos(): array
    {
        $out = [];
        foreach (self::GRUPOS as $id => $rotulo) {
            $out[] = [
                'id' => $id, 'rotulo' => $rotulo, 'chave_view' => "suporte_tickets_{$id}_view", 'chave_gerir' => "suporte_tickets_{$id}_gerir",
                'categorias' => $this->categoriasDoGrupo($id),
            ];
        }

        return $out;
    }

    /** @return list<string> categorias de ticket (TicketSuporte::CATEGORIAS) tratadas por um grupo. */
    public function categoriasDoGrupo(string $grupo): array
    {
        $chave = "suporte_tickets_{$grupo}_gerir";

        return array_values(array_keys(array_filter(TicketSuporte::CHAVE_POR_CATEGORIA, fn ($c) => $c === $chave)));
    }

    /** @param list<string> $grupos */
    public static function permissoesDosGrupos(array $grupos): array
    {
        $p = ['_v2' => true];
        foreach (array_intersect(array_keys(self::GRUPOS), $grupos) as $g) {
            $p["suporte_tickets_{$g}_view"] = true;
            $p["suporte_tickets_{$g}_gerir"] = true;
        }

        return $p;
    }

    /** Grupos concedidos por um conjunto de permissões (só olha às chaves `_gerir`, as que decidem o acesso). @return list<string> */
    public function gruposDe(array $permissoes): array
    {
        return array_values(array_filter(array_keys(self::GRUPOS), fn ($g) => ($permissoes["suporte_tickets_{$g}_gerir"] ?? null) === true));
    }

    /** Só chaves do módulo `suporte` (e _v2) — nunca `all` nem chaves de outros módulos. */
    public static function soSuporte(array $permissoes): bool
    {
        $chaves = array_keys(array_filter($permissoes, fn ($v, $k) => $v === true && $k !== '_v2', ARRAY_FILTER_USE_BOTH));

        return $chaves !== [] && ($permissoes['all'] ?? null) !== true && collect($chaves)->every(fn ($k) => str_starts_with($k, 'suporte_'));
    }

    /**
     * Categorias de ticket que o utilizador pode ver/responder: null = todas (super-administrador/root); senão as cujas
     * chaves o perfil concede. Fonte única — usada por SuporteController, DashboardSuporteController e /suporte/eu.
     *
     * @return list<string>|null
     */
    public function categoriasPermitidas(Utilizador $u): ?array
    {
        if ($u->eSuperAdministrador() || $u->eRoot()) {
            return null;
        }

        return array_values(array_unique(array_keys(array_filter(TicketSuporte::CHAVE_POR_CATEGORIA, fn ($chave) => $this->permissoes->tem($u, $chave)))));
    }

    /** Grupos (rótulo e categorias) que o utilizador cobre, para o «As suas categorias: …». */
    public function minhasCategorias(Utilizador $u): array
    {
        $todas = $this->categoriasPermitidas($u);
        $grupos = array_values(array_filter($this->grupos(), fn ($g) => $todas === null || array_intersect($g['categorias'], $todas) !== []));

        return [
            'todas' => $todas === null,
            'categorias' => $todas ?? TicketSuporte::CATEGORIAS,
            'grupos' => array_map(fn ($g) => ['id' => $g['id'], 'rotulo' => $g['rotulo'], 'categorias' => $g['categorias']], $grupos),
            'especializacao' => $u->perfil_utilizador_id ? PerfilUtilizador::query()->whereKey($u->perfil_utilizador_id)->value('nome') : null,
        ];
    }

    /**
     * Cria, de forma idempotente, as especializações base em falta. Uma já existe se houver um perfil do backoffice
     * com o mesmo conjunto de grupos (ou o mesmo nome); nunca altera nem apaga perfis existentes.
     *
     * @return list<string> nomes criados
     */
    public function garantirBase(): array
    {
        return DB::transaction(function () {
            DB::select('select pg_advisory_xact_lock(?)', [self::BLOQUEIO_ADVISORY]);
            $existentes = PerfilUtilizador::query()->where('restrito_plataforma', true)->get(['id', 'nome', 'permissoes']);
            $nomes = $existentes->map(fn ($p) => ServicoPermissoes::norm((string) $p->nome))->all();
            $assinaturas = $existentes->filter(fn ($p) => self::soSuporte($p->permissoes ?? []))
                ->map(fn ($p) => implode(',', $this->gruposDe($p->permissoes ?? [])))->all();
            $criados = [];
            foreach (self::BASE as $nome => [$descricao, $grupos]) {
                if (in_array(ServicoPermissoes::norm($nome), $nomes, true) || in_array(implode(',', array_values(array_intersect(array_keys(self::GRUPOS), $grupos))), $assinaturas, true)) {
                    continue;
                }
                PerfilUtilizador::create(['nome' => $nome, 'descricao' => $descricao, 'permissoes' => self::permissoesDosGrupos($grupos), 'restrito_plataforma' => true, 'ativo' => true]);
                $criados[] = $nome;
            }

            return $criados;
        });
    }

    /** Lista as especializações (perfis restritos só com chaves `suporte_*`); se não houver nenhuma, cria as base (lazy, seguro). */
    public function listar(bool $soActivas = false): array
    {
        $perfis = $this->perfisSuporte();
        if ($perfis->isEmpty()) {
            $this->garantirBase();
            $perfis = $this->perfisSuporte();
        }
        $uso = DB::table('utilizadores')->whereNull('eliminado_em')->selectRaw('perfil_utilizador_id, COUNT(*) AS n')->groupBy('perfil_utilizador_id')->pluck('n', 'perfil_utilizador_id');
        $base = array_map(fn ($n) => ServicoPermissoes::norm($n), array_keys(self::BASE));

        return $perfis->filter(fn ($p) => ! $soActivas || $p->ativo !== false)->map(fn (PerfilUtilizador $p) => $this->apresentar($p) + [
            'agentes' => (int) ($uso[$p->id] ?? 0), 'base' => in_array(ServicoPermissoes::norm((string) $p->nome), $base, true),
        ])->values()->all();
    }

    /** @return Collection<int, PerfilUtilizador> */
    private function perfisSuporte()
    {
        return PerfilUtilizador::query()->where('restrito_plataforma', true)->ordenarPorNome()->get()->filter(fn ($p) => self::soSuporte($p->permissoes ?? []))->values();
    }

    /** @return array<string, mixed> */
    public function apresentar(PerfilUtilizador $p): array
    {
        $grupos = $this->gruposDe($p->permissoes ?? []);
        $categorias = [];
        foreach ($grupos as $g) {
            $categorias = array_merge($categorias, $this->categoriasDoGrupo($g));
        }

        return [
            'id' => $p->id, 'nome' => $p->nome, 'descricao' => $p->descricao, 'ativo' => $p->ativo !== false,
            'grupos' => $grupos, 'categorias' => $categorias,
            'grupos_rotulos' => array_values(array_map(fn ($g) => self::GRUPOS[$g], $grupos)),
        ];
    }

    /** @param array{nome: string, descricao?: ?string, grupos: list<string>} $d */
    public function criar(array $d): PerfilUtilizador
    {
        $this->nomeLivre($d['nome']);

        return PerfilUtilizador::create([
            'nome' => trim($d['nome']), 'descricao' => $d['descricao'] ?? null, 'permissoes' => self::permissoesDosGrupos($d['grupos']),
            'restrito_plataforma' => true, 'ativo' => true,
        ]);
    }

    /** @param array{nome?: string, descricao?: ?string, grupos?: list<string>, ativo?: bool} $d */
    public function atualizar(PerfilUtilizador $p, array $d): PerfilUtilizador
    {
        $this->exigirEspecializacao($p);
        if (isset($d['nome'])) {
            $this->nomeLivre($d['nome'], $p->id);
        }
        $alt = [];
        foreach (['nome', 'descricao', 'ativo'] as $c) {
            if (array_key_exists($c, $d)) {
                $alt[$c] = $c === 'nome' ? trim((string) $d[$c]) : $d[$c];
            }
        }
        if (isset($d['grupos'])) {
            $alt['permissoes'] = self::permissoesDosGrupos($d['grupos']);
        }
        $p->update($alt);

        return $p->refresh();
    }

    public function eliminar(PerfilUtilizador $p): void
    {
        $this->exigirEspecializacao($p);
        $n = DB::table('utilizadores')->where('perfil_utilizador_id', $p->id)->count();
        if ($n) {
            throw new ErroNegocio("Não é possível eliminar a especialização «{$p->nome}»: tem {$n} agente(s) associado(s). Reatribua-os ou desactive-a.", 'ESPECIALIZACAO_EM_USO', 422, ['agentes' => $n]);
        }
        $p->delete();
    }

    /** Só perfis restritos com chaves `suporte_*` são especializações — nenhum outro perfil se edita por aqui. */
    public function exigirEspecializacao(PerfilUtilizador $p): void
    {
        if (! $p->restrito_plataforma || ! self::soSuporte($p->permissoes ?? [])) {
            throw new ErroNegocio('Este perfil não é uma especialização de suporte.', 'NAO_E_ESPECIALIZACAO', 404);
        }
    }

    private function nomeLivre(string $nome, ?int $ignorar = null): void
    {
        $n = ServicoPermissoes::norm($nome);
        $repetido = $this->perfisSuporte()->contains(fn ($p) => $p->id !== $ignorar && ServicoPermissoes::norm((string) $p->nome) === $n);
        if ($repetido) {
            throw new ErroNegocio("Já existe uma especialização com o nome «{$nome}».", 'ESPECIALIZACAO_REPETIDA', 422);
        }
    }
}
