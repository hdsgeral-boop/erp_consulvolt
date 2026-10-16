<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AndarEdificio;
use App\Models\AtivoImobilizado;
use App\Models\InventarioFisicoAtivo;
use App\Models\PortaEdificio;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Activos › Edifícios e Locais (Fase F, Versão Actual — js/activos_edificios.js). Um activo do tipo EDIFICIO tem andares
 * (andares_edificios) e portas numeradas (portas_edificios); os outros bens ficam localizados numa porta
 * (ativos_imobilizados.edificio_id/andar_id/porta_id). O edifício é um activo normal (categoria, amortização, abate).
 * Regras do legado mantidas:
 *   - cartões por edifício com n.º de andares, portas, bens dentro e Σ valor de aquisição (renderAssetBuildings :99-153);
 *   - planta por andar (assetBuildingPlan :158-248); o nome do andar por omissão é «Rés-do-chão» / «N.º andar» (:352);
 *   - portas em série «prefixo + n.º» de/até, no máximo 200 de cada vez, ignorando as que já existem no andar (:421-450);
 *   - eliminar um andar ou uma porta liberta os bens (localização a null), nunca os apaga (:370-384, :490-501);
 *   - um edifício não fica dentro de uma porta (assetMoverBem :277-295);
 *   - na ficha, o tipo não muda depois de criado (ui_assets.js:465-480); o legado recusava edifício e andar
 *     sem porta — desde ADR-071 o bem pode ficar no edifício sem porta.
 * Fecho (ADR-071, A2): um bem pode ficar «neste edifício, sem porta» (edificio_id com andar opcional e porta_id null); a lista
 * «Bens sem porta» da planta mostra os bens do edifício sem porta e os bens sem localização; à parte vêm os bens de outros
 * edifícios (activos_edificios.js:92 — serve para arrastar bens entre edifícios).
 * Correcções/melhorias: validação no servidor (o edifício tem de ser do tipo EDIFICIO e a porta tem de pertencer ao andar
 * e ao edifício indicados), n.º de andar e de porta únicos no edifício/andar, bloqueio de eliminar andares ou portas com
 * folha de inventário aberta, bens abatidos fora da planta e auditoria de cada operação.
 */
final class ServicoLocaisAtivos
{
    public const TIPO_BEM = 'BEM';

    public const TIPO_EDIFICIO = 'EDIFICIO';

    public const MAX_PORTAS = 200;

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    // ───────────── Consulta ─────────────

    /** Cartões dos edifícios (renderAssetBuildings) e n.º de bens sem porta. */
    public function edificios(): array
    {
        $edificios = AtivoImobilizado::query()->where('tipo_ativo', self::TIPO_EDIFICIO)->orderBy('codigo')->get(['id', 'codigo', 'descricao', 'estado', 'valor_aquisicao']);
        $andares = AndarEdificio::query()->selectRaw('edificio_id, COUNT(*) AS n')->groupBy('edificio_id')->pluck('n', 'edificio_id');
        $portas = PortaEdificio::query()->selectRaw('edificio_id, COUNT(*) AS n')->groupBy('edificio_id')->pluck('n', 'edificio_id');
        // bens dentro do edifício: numa porta ou «neste edifício, sem porta»
        $bens = $this->bensLocalizaveis()->whereNotNull('edificio_id')
            ->selectRaw('edificio_id, COUNT(*) AS n, COUNT(*) FILTER (WHERE porta_id IS NULL) AS sem_porta, COALESCE(SUM(valor_aquisicao), 0) AS valor')
            ->groupBy('edificio_id')->get()->keyBy('edificio_id');
        $inventarios = InventarioFisicoAtivo::query()->where('estado', '<>', InventarioFisicoAtivo::ANULADO)->selectRaw('edificio_id, COUNT(*) AS n')
            ->groupBy('edificio_id')->pluck('n', 'edificio_id');

        return [
            'edificios' => $edificios->map(fn (AtivoImobilizado $e) => $e->only(['id', 'codigo', 'descricao', 'estado']) + [
                'andares' => (int) ($andares[$e->id] ?? 0),
                'portas' => (int) ($portas[$e->id] ?? 0),
                'bens' => (int) ($bens[$e->id]->n ?? 0),
                'sem_porta' => (int) ($bens[$e->id]->sem_porta ?? 0),
                'valor_bens' => CalculadoraAmortizacoes::d($bens[$e->id]->valor ?? 0),
                'inventarios' => (int) ($inventarios[$e->id] ?? 0),
            ])->values()->all(),
            'sem_local' => $this->bensLocalizaveis()->whereNull('edificio_id')->count(),
            'sem_porta' => $this->bensLocalizaveis()->whereNotNull('edificio_id')->whereNull('porta_id')->count(),
            'inventarios' => (int) InventarioFisicoAtivo::query()->where('estado', '<>', InventarioFisicoAtivo::ANULADO)->count(),
        ];
    }

    /** Planta do edifício (assetBuildingPlan): andares › portas › bens, bens sem porta e totais. */
    public function planta(AtivoImobilizado $edificio): array
    {
        $this->exigirEdificio($edificio);
        $andares = AndarEdificio::query()->where('edificio_id', $edificio->id)->orderBy('ordem')->orderBy('id')->get();
        $portas = PortaEdificio::query()->where('edificio_id', $edificio->id)->get()->sort(fn ($a, $b) => [(int) $a->ordem, $a->numero] <=> [(int) $b->ordem, $b->numero]);
        $bens = $this->bensLocalizaveis()->with('categoriaAtivo:id,nome')->whereIn('porta_id', $portas->pluck('id'))->orderBy('codigo')
            ->get(['id', 'codigo', 'descricao', 'valor_aquisicao', 'categoria_ativo_id', 'porta_id', 'andar_id', 'edificio_id', 'estado']);
        $porPorta = $bens->groupBy('porta_id');
        $resumoBem = fn (AtivoImobilizado $b) => ['id' => $b->id, 'codigo' => $b->codigo, 'descricao' => $b->descricao, 'valor_aquisicao' => CalculadoraAmortizacoes::d($b->valor_aquisicao),
            'categoria' => $b->categoriaAtivo?->nome, 'estado' => $b->estado];

        $listaAndares = $andares->map(function (AndarEdificio $f) use ($portas, $porPorta, $resumoBem) {
            $ps = $portas->where('andar_id', $f->id)->values()->map(function (PortaEdificio $p) use ($porPorta, $resumoBem) {
                $bs = $porPorta->get($p->id, collect());

                return $p->only(['id', 'numero', 'nome', 'tipo', 'capacidade', 'ordem', 'andar_id']) + [
                    'bens' => $bs->map($resumoBem)->values()->all(), 'n_bens' => $bs->count(), 'valor' => $this->soma($bs),
                ];
            });

            return $f->only(['id', 'numero', 'nome', 'notas', 'ordem']) + [
                'portas' => $ps->all(), 'n_portas' => $ps->count(), 'n_bens' => $ps->sum('n_bens'),
                'valor' => $ps->reduce(fn ($s, $p) => bcadd($s, $p['valor'], 2), '0.00'),
            ];
        });
        // «Bens sem porta»: primeiro os deste edifício sem porta, depois os sem localização nenhuma
        $semPorta = $this->bensLocalizaveis()->with('andar:id,numero,nome')
            ->where(fn ($q) => $q->whereNull('edificio_id')->orWhere(fn ($q) => $q->where('edificio_id', $edificio->id)->whereNull('porta_id')))
            ->orderByRaw('CASE WHEN edificio_id IS NULL THEN 1 ELSE 0 END')->orderBy('codigo')->limit(2000)
            ->get(['id', 'codigo', 'descricao', 'valor_aquisicao', 'estado', 'edificio_id', 'andar_id']);
        // bens de outros edifícios (com ou sem porta), para os arrastar para este (activos_edificios.js:92)
        $outros = $this->bensLocalizaveis()->with(['edificio:id,codigo,descricao', 'andar:id,numero,nome', 'porta:id,numero'])
            ->whereNotNull('edificio_id')->where('edificio_id', '<>', $edificio->id)->orderBy('codigo')->limit(2000)
            ->get(['id', 'codigo', 'descricao', 'valor_aquisicao', 'estado', 'edificio_id', 'andar_id', 'porta_id']);
        $nomeAndar = fn ($f) => $f ? ($f->nome ?: 'Piso '.$f->numero) : null;
        $semPortaEste = $semPorta->filter(fn ($b) => (int) $b->edificio_id === $edificio->id);

        return [
            'edificio' => $edificio->only(['id', 'codigo', 'descricao', 'estado']),
            'andares' => $listaAndares->values()->all(),
            'sem_local' => $semPorta->map(fn ($b) => ['id' => $b->id, 'codigo' => $b->codigo, 'descricao' => $b->descricao,
                'valor_aquisicao' => CalculadoraAmortizacoes::d($b->valor_aquisicao),
                'grupo' => $b->edificio_id ? 'EDIFICIO' : 'SEM_LOCAL', 'local' => $b->edificio_id ? $nomeAndar($b->andar) : null])->values()->all(),
            'outros_edificios' => $outros->map(fn ($b) => ['id' => $b->id, 'codigo' => $b->codigo, 'descricao' => $b->descricao,
                'valor_aquisicao' => CalculadoraAmortizacoes::d($b->valor_aquisicao), 'grupo' => 'OUTRO',
                'local' => implode(' · ', array_filter([$b->edificio?->codigo ?: $b->edificio?->descricao, $nomeAndar($b->andar), $b->porta?->numero ?? 'sem porta']))])->values()->all(),
            'totais' => ['andares' => $andares->count(), 'portas' => $portas->count(), 'bens' => $bens->count() + $semPortaEste->count(),
                'sem_porta' => $semPortaEste->count(), 'valor' => $this->soma($bens->concat($semPortaEste))],
        ];
    }

    /** Edifícios, andares e portas da empresa (selects encadeados da ficha e coluna «Localização» — assetLocalSelects/assetLocalTexto :973-1027). */
    public function opcoes(): array
    {
        return [
            'edificios' => AtivoImobilizado::query()->where('tipo_ativo', self::TIPO_EDIFICIO)->orderBy('codigo')->get(['id', 'codigo', 'descricao'])->toArray(),
            'andares' => AndarEdificio::query()->orderBy('edificio_id')->orderBy('ordem')->orderBy('id')->get(['id', 'edificio_id', 'numero', 'nome', 'ordem'])->toArray(),
            'portas' => PortaEdificio::query()->orderBy('andar_id')->orderBy('ordem')->orderBy('numero')->get(['id', 'edificio_id', 'andar_id', 'numero', 'nome', 'tipo'])->toArray(),
        ];
    }

    // ───────────── Andares ─────────────

    /** @param  array{numero?: ?string, nome?: ?string, notas?: ?string, ordem?: ?int}  $d */
    public function guardarAndar(AtivoImobilizado $edificio, array $d, ?AndarEdificio $andar = null): AndarEdificio
    {
        $this->exigirEdificio($edificio);
        $numero = trim((string) ($d['numero'] ?? ''));
        $nome = trim((string) ($d['nome'] ?? ''));
        if ($numero === '' && $nome === '') {
            throw new ErroNegocio('Indique o número do piso ou um nome.', 'DADOS_INVALIDOS', 422);
        }
        if ($andar && (int) $andar->edificio_id !== $edificio->id) {
            throw new ErroNegocio('O andar não pertence a este edifício.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($edificio, $andar, $numero, $nome, $d) {
            $existentes = AndarEdificio::query()->where('edificio_id', $edificio->id)->when($andar, fn ($q) => $q->whereKeyNot($andar->id))->lockForUpdate()->get();
            if ($numero !== '' && $existentes->contains(fn ($f) => (string) $f->numero === $numero)) {
                throw new ErroNegocio("O edifício já tem o piso {$numero}.", 'ANDAR_DUPLICADO', 422);
            }
            $numerico = $numero !== '' && preg_match('/^-?\d+$/', $numero);
            // nome por omissão como no legado (assetFloorModal :352)
            $nome = $nome !== '' ? $nome : ($numerico ? ((int) $numero === 0 ? 'Rés-do-chão' : ((int) $numero).'.º andar') : 'Piso '.$numero);
            $dados = ['edificio_id' => $edificio->id, 'numero' => $numero !== '' ? $numero : null, 'nome' => mb_substr($nome, 0, 255),
                'notas' => trim((string) ($d['notas'] ?? '')) ?: null,
                'ordem' => isset($d['ordem']) && $d['ordem'] !== null ? (int) $d['ordem'] : ($numerico ? (int) $numero : $existentes->count())];
            if ($andar) {
                $andar->update($dados);

                return $andar->refresh();
            }

            return AndarEdificio::create($dados);
        });
    }

    /** Elimina o andar e as suas portas; os bens ficam sem localização (assetEliminarAndar :370-384). */
    public function eliminarAndar(AndarEdificio $andar): array
    {
        return DB::transaction(function () use ($andar) {
            $andar = AndarEdificio::query()->lockForUpdate()->findOrFail($andar->id);
            $portas = PortaEdificio::query()->where('andar_id', $andar->id)->pluck('id');
            $this->exigirSemInventarioAberto($andar->id, $portas);
            $libertados = $this->libertar(AtivoImobilizado::query()->where(fn ($q) => $q->whereIn('porta_id', $portas)->orWhere('andar_id', $andar->id)));
            PortaEdificio::query()->whereIn('id', $portas)->get()->each->delete();
            $andar->delete();
            $this->auditoria->registar('Activos', 'Eliminar andar', "{$andar->nome}: {$portas->count()} porta(s), {$libertados} bem(ns) libertado(s)", 'andares_edificios', $andar->id);

            return ['portas' => $portas->count(), 'bens_libertados' => $libertados];
        });
    }

    // ───────────── Portas ─────────────

    /**
     * Portas numeradas «prefixo + n.º» de/até (assetGravarPortas :436-450).
     *
     * @return array{criadas: int, ignoradas: list<string>, numeros: list<string>}
     */
    public function criarPortas(AtivoImobilizado $edificio, int $andarId, ?string $prefixo, int $de, int $ate, ?string $tipo = null, ?int $capacidade = null): array
    {
        $this->exigirEdificio($edificio);
        if ($ate < $de) {
            throw new ErroNegocio('Indique um intervalo válido (o fim não pode ser anterior ao início).', 'DADOS_INVALIDOS', 422);
        }
        if ($ate - $de + 1 > self::MAX_PORTAS) {
            throw new ErroNegocio('No máximo '.self::MAX_PORTAS.' portas de cada vez.', 'DADOS_INVALIDOS', 422);
        }
        $andar = AndarEdificio::query()->where('edificio_id', $edificio->id)->find($andarId)
            ?? throw new ErroNegocio('O andar não pertence a este edifício.', 'DADOS_INVALIDOS', 422);
        $prefixo = trim((string) $prefixo);

        return DB::transaction(function () use ($edificio, $andar, $prefixo, $de, $ate, $tipo, $capacidade) {
            $existentes = PortaEdificio::query()->where('andar_id', $andar->id)->lockForUpdate()->pluck('numero')->map(fn ($n) => (string) $n)->flip();
            $criadas = [];
            $ignoradas = [];
            for ($i = $de; $i <= $ate; $i++) {
                $numero = mb_substr($prefixo.$i, 0, 20);
                if (isset($existentes[$numero])) {
                    $ignoradas[] = $numero;

                    continue;
                }
                PortaEdificio::create(['edificio_id' => $edificio->id, 'andar_id' => $andar->id, 'numero' => $numero, 'nome' => null,
                    'tipo' => trim((string) $tipo) ?: null, 'capacidade' => $capacidade, 'ordem' => $i]);
                $existentes[$numero] = true;
                $criadas[] = $numero;
            }
            if (! $criadas) {
                throw new ErroNegocio('Todas essas portas já existem neste andar.', 'PORTAS_EXISTENTES', 422);
            }
            $this->auditoria->registar('Activos', 'Criar portas', count($criadas).' porta(s) no andar '.($andar->nome ?? $andar->numero), 'andares_edificios', $andar->id);

            return ['criadas' => count($criadas), 'ignoradas' => $ignoradas, 'numeros' => $criadas];
        });
    }

    /** @param  array{numero?: string, nome?: ?string, tipo?: ?string, capacidade?: ?int}  $d */
    public function guardarPorta(PortaEdificio $porta, array $d): PortaEdificio
    {
        $numero = array_key_exists('numero', $d) ? trim((string) $d['numero']) : (string) $porta->numero;
        if ($numero === '') {
            throw new ErroNegocio('A porta tem de ter número.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($porta, $d, $numero) {
            $porta = PortaEdificio::query()->lockForUpdate()->findOrFail($porta->id);
            if (PortaEdificio::query()->where('andar_id', $porta->andar_id)->whereKeyNot($porta->id)->where('numero', $numero)->exists()) {
                throw new ErroNegocio("O andar já tem a porta {$numero}.", 'PORTA_DUPLICADA', 422);
            }
            $alt = ['numero' => mb_substr($numero, 0, 20)];
            foreach (['nome', 'tipo'] as $c) {
                if (array_key_exists($c, $d)) {
                    $alt[$c] = trim((string) $d[$c]) ?: null;
                }
            }
            if (array_key_exists('capacidade', $d)) {
                $alt['capacidade'] = $d['capacidade'] === null ? null : (int) $d['capacidade'];
            }
            $porta->update($alt);

            return $porta->refresh();
        });
    }

    /** Elimina a porta; os bens ficam sem localização (assetEliminarPorta :490-501). */
    public function eliminarPorta(PortaEdificio $porta): array
    {
        return DB::transaction(function () use ($porta) {
            $porta = PortaEdificio::query()->lockForUpdate()->findOrFail($porta->id);
            $this->exigirSemInventarioAberto(null, collect([$porta->id]));
            $libertados = $this->libertar(AtivoImobilizado::query()->where('porta_id', $porta->id));
            $porta->delete();
            $this->auditoria->registar('Activos', 'Eliminar porta', "Porta {$porta->numero}: {$libertados} bem(ns) libertado(s)", 'portas_edificios', $porta->id);

            return ['bens_libertados' => $libertados];
        });
    }

    // ───────────── Localização dos bens ─────────────

    /**
     * Move um bem para uma porta (assetMoverBem :277-295); sem porta mas com edifício (e andar opcional) fica «neste
     * edifício, sem porta» (ADR-071, A2); tudo a null retira-o do local.
     */
    public function moverBem(AtivoImobilizado $bem, ?int $portaId, ?int $edificioId = null, ?int $andarId = null): AtivoImobilizado
    {
        return DB::transaction(function () use ($bem, $portaId, $edificioId, $andarId) {
            $bem = AtivoImobilizado::query()->lockForUpdate()->findOrFail($bem->id);
            if ($bem->tipo_ativo === self::TIPO_EDIFICIO) {
                throw new ErroNegocio('Um edifício não pode ficar dentro de uma porta.', 'EDIFICIO_NAO_LOCALIZAVEL', 422);
            }
            if ($bem->estado === AtivoImobilizado::ESTADO_ABATIDO) {
                throw new ErroNegocio("O activo {$bem->codigo} está abatido.", 'ATIVO_ABATIDO', 422);
            }
            $dados = $this->resolverLocal($portaId, $edificioId, $andarId);
            if ((int) $bem->porta_id !== (int) $dados['porta_id'] || (int) $bem->edificio_id !== (int) $dados['edificio_id'] || (int) $bem->andar_id !== (int) $dados['andar_id']) {
                $bem->update($dados);
            }

            return $bem->refresh();
        });
    }

    /**
     * Normaliza o tipo e a localização vindos da ficha do activo (saveAsset, ui_assets.js:465-480). Chamado por
     * ServicoAtivos::validar. O tipo só se escolhe na criação; um edifício nunca tem localização; a porta manda.
     */
    public function normalizarFicha(array $d, ?AtivoImobilizado $a = null): array
    {
        $tipoAtual = $a ? ($a->tipo_ativo ?: self::TIPO_BEM) : null;
        if (array_key_exists('tipo_ativo', $d)) {
            $tipo = $d['tipo_ativo'] ?: self::TIPO_BEM;
            if (! in_array($tipo, [self::TIPO_BEM, self::TIPO_EDIFICIO], true)) {
                throw new ErroNegocio('Tipo de activo inválido (BEM ou EDIFICIO).', 'DADOS_INVALIDOS', 422);
            }
            if ($a && $tipo !== $tipoAtual) {
                throw new ErroNegocio('O tipo do activo não muda depois de criado.', 'TIPO_ATIVO_FIXO', 422);
            }
            $d['tipo_ativo'] = $tipo;
        } elseif (! $a) {
            $d['tipo_ativo'] = self::TIPO_BEM;
        }
        $tipo = $d['tipo_ativo'] ?? $tipoAtual;
        $mexeLocal = array_key_exists('porta_id', $d) || array_key_exists('andar_id', $d) || array_key_exists('edificio_id', $d);
        if ($tipo === self::TIPO_EDIFICIO) {
            if ($mexeLocal && (! empty($d['porta_id']) || ! empty($d['andar_id']) || ! empty($d['edificio_id']))) {
                throw new ErroNegocio('Um edifício não pode ficar dentro de uma porta.', 'EDIFICIO_NAO_LOCALIZAVEL', 422);
            }
            if (! $a || $mexeLocal) {
                $d = array_merge($d, ['edificio_id' => null, 'andar_id' => null, 'porta_id' => null]);
            }

            return $d;
        }
        if (! $mexeLocal) {
            return $d;
        }
        // ADR-071 (A2): edifício sem porta (andar opcional) é aceite — o bem fica «neste edifício, sem porta»
        $local = $this->resolverLocal(empty($d['porta_id']) ? null : (int) $d['porta_id'],
            empty($d['edificio_id']) ? null : (int) $d['edificio_id'], empty($d['andar_id']) ? null : (int) $d['andar_id']);

        return array_merge($d, $local);
    }

    /**
     * Localização coerente: a porta manda (edifício/andar indicados têm de bater certo); sem porta, o edifício tem de ser do
     * tipo EDIFICIO e o andar (opcional) desse edifício; um andar sozinho dá o edifício dele.
     *
     * @return array{edificio_id: ?int, andar_id: ?int, porta_id: ?int}
     */
    private function resolverLocal(?int $portaId, ?int $edificioId, ?int $andarId): array
    {
        if ($portaId) {
            $local = $this->dadosPorta($portaId);
            if (($edificioId && $edificioId !== $local['edificio_id']) || ($andarId && $andarId !== $local['andar_id'])) {
                throw new ErroNegocio('A porta escolhida não pertence ao edifício/andar indicado.', 'LOCAL_INCOERENTE', 422);
            }

            return $local;
        }
        if ($andarId) {
            $andar = AndarEdificio::query()->find($andarId) ?? throw new ErroNegocio('Andar não encontrado.', 'DADOS_INVALIDOS', 422);
            if ($edificioId && $edificioId !== (int) $andar->edificio_id) {
                throw new ErroNegocio('O andar escolhido não pertence ao edifício indicado.', 'LOCAL_INCOERENTE', 422);
            }
            $edificioId = (int) $andar->edificio_id;
        }
        if (! $edificioId) {
            return ['edificio_id' => null, 'andar_id' => null, 'porta_id' => null];
        }
        $edificio = AtivoImobilizado::query()->find($edificioId) ?? throw new ErroNegocio('Edifício não encontrado.', 'DADOS_INVALIDOS', 422);
        $this->exigirEdificio($edificio);

        return ['edificio_id' => $edificio->id, 'andar_id' => $andarId ?: null, 'porta_id' => null];
    }

    // ───────────── Auxiliares ─────────────

    /** @return array{edificio_id: ?int, andar_id: ?int, porta_id: ?int} */
    private function dadosPorta(?int $portaId): array
    {
        if (! $portaId) {
            return ['edificio_id' => null, 'andar_id' => null, 'porta_id' => null];
        }
        $porta = PortaEdificio::query()->find($portaId) ?? throw new ErroNegocio('Porta não encontrada.', 'DADOS_INVALIDOS', 422);

        return ['edificio_id' => (int) $porta->edificio_id, 'andar_id' => (int) $porta->andar_id, 'porta_id' => $porta->id];
    }

    /** Bens que podem ter localização: não edifícios e não abatidos. */
    private function bensLocalizaveis()
    {
        return AtivoImobilizado::query()->where(fn ($q) => $q->whereNull('tipo_ativo')->orWhere('tipo_ativo', '<>', self::TIPO_EDIFICIO))
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', AtivoImobilizado::ESTADO_ABATIDO));
    }

    private function exigirEdificio(AtivoImobilizado $a): void
    {
        if ($a->tipo_ativo !== self::TIPO_EDIFICIO) {
            throw new ErroNegocio("O activo {$a->codigo} não é um edifício/local.", 'NAO_E_EDIFICIO', 422);
        }
    }

    /** @param  Collection<int, int>  $portas */
    private function exigirSemInventarioAberto(?int $andarId, Collection $portas): void
    {
        $aberto = InventarioFisicoAtivo::query()->where('estado', InventarioFisicoAtivo::ABERTO)
            ->where(fn ($q) => $q->when($andarId, fn ($q) => $q->orWhere('andar_id', $andarId))->orWhereIn('porta_id', $portas))
            ->exists();
        if ($aberto) {
            throw new ErroNegocio('Há uma folha de inventário aberta para este local: feche-a ou anule-a primeiro.', 'INVENTARIO_ABERTO', 422);
        }
    }

    /** Liberta os bens (localização a null) e devolve quantos. Cada alteração fica auditada (Auditavel). */
    private function libertar($consulta): int
    {
        $bens = $consulta->lockForUpdate()->get();
        foreach ($bens as $b) {
            $b->update(['edificio_id' => null, 'andar_id' => null, 'porta_id' => null]);
        }

        return $bens->count();
    }

    private function soma(Collection $bens): string
    {
        return $bens->reduce(fn ($s, $b) => bcadd($s, CalculadoraAmortizacoes::d($b->valor_aquisicao), 2), '0.00');
    }
}
