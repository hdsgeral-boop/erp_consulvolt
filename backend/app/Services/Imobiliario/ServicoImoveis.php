<?php

namespace App\Services\Imobiliario;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\Terceiro;
use App\Support\Angola\NifAngolano;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro de imóveis (porte de imobiliario_dados.js: gravarImovel, eliminarImovel, avisosDoImovel, resumo). Regras com
 * mensagem própria; nunca se devolve texto de excepção ao cliente. Corrige: código atómico (ServicoNumeracaoImobiliario),
 * edifício do Activos ligado a um só imóvel e ao papel certo (próprio ↔ bem próprio, arrendado ↔ bem alheio arrendado).
 */
final class ServicoImoveis
{
    public function __construct(private readonly ServicoNumeracaoImobiliario $numeracao, private readonly ContextoEmpresa $contexto) {}

    /**
     * @param  array<string, mixed>  $f  filtros: texto, papel, estado, tipo
     * @return Collection<int, array<string, mixed>>
     */
    public function listar(array $f = []): Collection
    {
        $q = Imovel::query()->orderByRaw('lower(codigo)');
        $q->when($f['papel'] ?? null, fn ($q, $v) => $q->where('papel', $v));
        $q->when($f['estado'] ?? null, fn ($q, $v) => $q->where('estado', $v));
        $q->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('tipo', $v));
        $q->when($f['texto'] ?? null, fn ($q, $t) => $q->where(fn ($x) => $x->where('codigo', 'ilike', "%{$t}%")->orWhere('nome', 'ilike', "%{$t}%")
            ->orWhere('morada', 'ilike', "%{$t}%")->orWhere('matriz_predial', 'ilike', "%{$t}%")));
        $imoveis = $q->limit(2000)->get();
        $contagens = $this->contagens($imoveis->pluck('id')->all());
        $donos = Terceiro::query()->whereIn('id', $imoveis->pluck('proprietario_terceiro_id')->filter()->all())->pluck('nome', 'id');

        return $imoveis->map(fn (Imovel $i) => $this->linha($i, $contagens[$i->id] ?? [], $donos[$i->proprietario_terceiro_id] ?? null));
    }

    /** @return array<string, mixed> o imóvel com a síntese das suas fracções e os avisos */
    public function ficha(Imovel $i): array
    {
        $us = Fracao::query()->where('imovel_id', $i->id)->get();
        $dono = $i->proprietario_terceiro_id ? Terceiro::query()->find($i->proprietario_terceiro_id) : null;
        $edificio = $i->ativo_edificio_id ? AtivoImobilizado::query()->find($i->ativo_edificio_id, ['id', 'codigo', 'descricao']) : null;

        return $this->linha($i, $this->contagens([$i->id])[$i->id] ?? [], $dono?->nome) + [
            'avisos' => $this->avisos($i, $us, $dono),
            'edificio' => $edificio ? ['id' => $edificio->id, 'codigo' => $edificio->codigo, 'descricao' => $edificio->descricao] : null,
        ];
    }

    /** @param  array<string, mixed>  $d  já validado pelo GravarImovelRequest */
    public function criar(array $d): Imovel
    {
        $reg = $this->normalizar($d, null);
        $this->conferir($reg, null);
        $codigo = $reg['codigo'] ?? $this->numeracao->proximoCodigoImovel($this->contexto->id());
        try {
            return DB::transaction(fn () => Imovel::query()->create(array_merge($reg, ['codigo' => $codigo, 'criado_por' => $this->utilizador(), 'atualizado_por' => $this->utilizador()])));
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe um imóvel com esse código, artigo matricial ou edifício. Verifique os dados.', 'IMOVEL_DUPLICADO');
        }
    }

    /** @param  array<string, mixed>  $d */
    public function actualizar(Imovel $i, array $d): Imovel
    {
        $reg = $this->normalizar($d + $i->only(['codigo', 'nome', 'tipo', 'papel']), $i);
        $reg['codigo'] ??= $i->codigo;
        $this->conferir($reg, $i);
        try {
            DB::transaction(fn () => $i->update($reg + ['atualizado_por' => $this->utilizador()]));
        } catch (UniqueConstraintViolationException) {
            throw new ErroNegocio('Já existe um imóvel com esse código, artigo matricial ou edifício. Verifique os dados.', 'IMOVEL_DUPLICADO');
        }

        return $i->refresh();
    }

    public function eliminar(Imovel $i): void
    {
        $n = Fracao::query()->where('imovel_id', $i->id)->count();
        if ($n > 0) {
            throw new ErroNegocio("O imóvel tem {$n} fracção(ões). Apague-as primeiro, ou ponha o imóvel como inactivo.", 'IMOVEL_COM_FRACCOES');
        }
        $i->delete();
    }

    /** @return array<string, mixed> resumo global: imóveis, fracções, ocupação, vagas, por estado e por papel */
    public function resumo(): array
    {
        $porEstado = array_fill_keys(array_keys(CatalogoImobiliario::ESTADOS_FRACAO), 0);
        $principais = 0;
        $ocupadas = 0;
        $area = 0.0;
        $vagas = 0;
        $arrecadacoes = 0;
        foreach (Fracao::query()->get(['natureza', 'estado', 'area_bruta']) as $u) {
            if (CatalogoImobiliario::principal($u->natureza)) {
                $principais++;
                $porEstado[$u->estado] = ($porEstado[$u->estado] ?? 0) + 1;
                $ocupadas += CatalogoImobiliario::ESTADOS_FRACAO[$u->estado]['ocupa'] ? 1 : 0;
                $area += (float) $u->area_bruta;
            } elseif ($u->natureza === 'ESTACIONAMENTO') {
                $vagas++;
            } else {
                $arrecadacoes++;
            }
        }
        $porPapel = array_fill_keys(Imovel::PAPEIS, 0);
        foreach (Imovel::query()->selectRaw('papel, count(*) as n')->groupBy('papel')->get() as $p) {
            $porPapel[$p->papel] = (int) $p->n;
        }

        return ['imoveis' => array_sum($porPapel), 'unidades' => $principais, 'ocupadas' => $ocupadas, 'taxa_ocupacao' => self::taxaOcupacao($ocupadas, $principais),
            'area_bruta' => round($area, 2), 'vagas' => $vagas, 'arrecadacoes' => $arrecadacoes, 'por_estado' => $porEstado, 'por_papel' => $porPapel];
    }

    /** Percentagem de ocupação arredondada (principais ARRENDADA ou VENDIDA sobre o total de principais); null sem fracções. */
    public static function taxaOcupacao(int $ocupadas, int $principais): ?int
    {
        return $principais > 0 ? (int) round($ocupadas / $principais * 100) : null;
    }

    /**
     * Avisos do imóvel (não bloqueiam). Mensagens do legado (avisosDoImovel).
     *
     * @param  Collection<int, Fracao>  $us
     * @return list<string>
     */
    public function avisos(Imovel $p, Collection $us, ?Terceiro $dono = null): array
    {
        $tipo = CatalogoImobiliario::TIPOS_IMOVEL[$p->tipo] ?? CatalogoImobiliario::TIPOS_IMOVEL['OUTRO'];
        $principais = $us->filter(fn ($u) => CatalogoImobiliario::principal($u->natureza));
        $a = [];
        if ($principais->isEmpty()) {
            $a[] = 'Sem fracções: os contratos prendem-se a fracções, crie pelo menos uma.';
        }
        if ($tipo['habitacoes'] && $p->n_habitacoes > 0) {
            $d = $principais->count() - $p->n_habitacoes;
            if ($d < 0) {
                $a[] = 'Faltam '.abs($d)." fracção(ões): o condomínio declara {$p->n_habitacoes} habitações e só estão registadas {$principais->count()}.";
            } elseif ($d > 0) {
                $a[] = "Há {$d} fracção(ões) a mais do que as {$p->n_habitacoes} habitações declaradas no condomínio.";
            }
        }
        if ($tipo['pisos'] && $p->n_pisos > 0) {
            $pisos = $principais->pluck('andar')->map(fn ($x) => trim((string) $x))->filter()->unique()->count();
            if ($pisos > $p->n_pisos) {
                $a[] = "As fracções estão em {$pisos} pisos diferentes, mais do que os {$p->n_pisos} declarados.";
            }
            $semAndar = $principais->filter(fn ($u) => trim((string) $u->andar) === '')->count();
            if ($semAndar > 0) {
                $a[] = "{$semAndar} fracção(ões) sem piso indicado: sem isso não se conta por andar.";
            }
        } elseif ($tipo['pisos']) {
            $a[] = 'Sem número de pisos: é o que permite gerar e contar as fracções por andar.';
        }
        $soma = round((float) $us->sum('area_bruta'), 2);
        if ($p->area_bruta > 0 && $soma > (float) $p->area_bruta) {
            $a[] = "As fracções somam {$soma} m² de área bruta, mais do que os {$p->area_bruta} m² do imóvel.";
        }
        $semArea = $principais->filter(fn ($u) => (float) $u->area_bruta <= 0 && (float) $u->area_util <= 0)->count();
        if ($semArea > 0) {
            $a[] = "{$semArea} fracção(ões) sem área: a área é a base da renda por m² e da repartição de encargos.";
        }
        if (trim((string) $p->matriz_predial) === '') {
            $a[] = 'Sem artigo matricial: é o que identifica o prédio perante a AGT.';
        }
        if ($p->papel === 'PROPRIO' && $p->valor_patrimonial === null) {
            $a[] = 'Sem valor patrimonial: é a base do Imposto Predial da detenção.';
        }
        if ($p->papel === 'PROPRIO' && ! $p->ativo_edificio_id) {
            $a[] = 'Não está ligado a nenhum edifício do imobilizado: sem isso não há planta, inventário nem manutenção.';
        }
        if ($dono !== null && trim((string) $dono->nif) !== '' && ! NifAngolano::valido($dono->nif)) {
            $a[] = 'O NIF do proprietário registado em Terceiros não é válido: corrija-o antes de emitir documentos.';
        }

        return $a;
    }

    /** @return array<string, mixed> */
    private function linha(Imovel $i, array $c, ?string $dono): array
    {
        $principais = (int) ($c['principais'] ?? 0);
        $ocupadas = (int) ($c['ocupadas'] ?? 0);

        return $i->attributesToArray() + [
            'proprietario_nome' => $dono, 'total_fracoes' => $principais, 'ocupadas' => $ocupadas, 'livres' => $principais - $ocupadas,
            'taxa_ocupacao' => self::taxaOcupacao($ocupadas, $principais), 'area_fracoes' => round((float) ($c['area'] ?? 0), 2),
            'renda_referencia' => round((float) ($c['renda'] ?? 0), 2), 'acessorios' => (int) ($c['acessorios'] ?? 0),
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function contagens(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $linhas = Fracao::query()->whereIn('imovel_id', $ids)->selectRaw(
            "imovel_id, count(*) filter (where natureza = 'HABITACIONAL') as principais,"
            ." count(*) filter (where natureza = 'HABITACIONAL' and estado in ('ARRENDADA','VENDIDA')) as ocupadas,"
            ." count(*) filter (where natureza <> 'HABITACIONAL') as acessorios,"
            ." coalesce(sum(area_bruta) filter (where natureza = 'HABITACIONAL'), 0) as area,"
            ." coalesce(sum(renda_referencia) filter (where natureza = 'HABITACIONAL'), 0) as renda"
        )->groupBy('imovel_id')->get();

        return $linhas->keyBy('imovel_id')->map(fn ($l) => $l->only(['principais', 'ocupadas', 'acessorios', 'area', 'renda']))->all();
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private function normalizar(array $d, ?Imovel $actual): array
    {
        $nome = Normaliza::texto($d['nome'] ?? null);
        if ($nome === null) {
            throw new ErroNegocio('Dê uma designação ao imóvel.', 'IMOVEL_SEM_NOME');
        }
        $papel = (string) ($d['papel'] ?? 'PROPRIO');
        $tipoChave = (string) ($d['tipo'] ?? 'PREDIO');
        $tipo = CatalogoImobiliario::TIPOS_IMOVEL[$tipoChave] ?? throw new ErroNegocio('Escolha o tipo de imóvel.', 'IMOVEL_TIPO');
        $papelDef = CatalogoImobiliario::PAPEIS[$papel] ?? throw new ErroNegocio('Escolha o papel do imóvel.', 'IMOVEL_PAPEL');
        $proprietario = $papelDef['exige_proprietario'] ? Normaliza::inteiro($d['proprietario_terceiro_id'] ?? null, true) : null;
        if ($papelDef['exige_proprietario'] && $proprietario === null) {
            throw new ErroNegocio('Um imóvel «'.mb_strtolower($papelDef['rot']).'» tem de dizer de quem é: escolha o proprietário.', 'IMOVEL_SEM_PROPRIETARIO');
        }
        if ($proprietario !== null && ! Terceiro::query()->whereKey($proprietario)->exists()) {
            throw new ErroNegocio('O proprietário escolhido não existe nesta empresa.', 'IMOVEL_PROPRIETARIO');
        }
        $edificio = Normaliza::inteiro($d['ativo_edificio_id'] ?? null, true);
        if ($papel === 'MEDIACAO') {
            $edificio = null;
        }
        $reg = [
            'codigo' => Normaliza::texto($d['codigo'] ?? null), 'nome' => $nome, 'tipo' => $tipoChave, 'papel' => $papel,
            'n_pisos' => $tipo['pisos'] ? max(0, Normaliza::inteiro($d['n_pisos'] ?? 0)) : 0,
            'piso_inicial' => $tipo['pisos'] ? Normaliza::inteiro($d['piso_inicial'] ?? 0) : 0,
            'n_habitacoes' => $tipo['habitacoes'] ? max(0, Normaliza::inteiro($d['n_habitacoes'] ?? 0)) : 0,
            'proprietario_terceiro_id' => $proprietario, 'ativo_edificio_id' => $edificio,
            'morada' => Normaliza::texto($d['morada'] ?? null), 'municipio' => Normaliza::texto($d['municipio'] ?? null), 'provincia' => Normaliza::texto($d['provincia'] ?? null),
            'coordenadas' => Normaliza::texto($d['coordenadas'] ?? null), 'matriz_predial' => Normaliza::texto($d['matriz_predial'] ?? null),
            'registo_predial' => Normaliza::texto($d['registo_predial'] ?? null), 'licenca_utilizacao' => Normaliza::texto($d['licenca_utilizacao'] ?? null),
            'area_terreno' => Normaliza::r2($d['area_terreno'] ?? 0), 'area_bruta' => Normaliza::r2($d['area_bruta'] ?? 0), 'area_util' => Normaliza::r2($d['area_util'] ?? 0),
            'valor_patrimonial' => Normaliza::r2($d['valor_patrimonial'] ?? null, true), 'valor_patrimonial_data' => Normaliza::texto($d['valor_patrimonial_data'] ?? null),
            'isencao_detencao' => (string) ($d['isencao_detencao'] ?? 'NAO'), 'valor_aquisicao' => Normaliza::r2($d['valor_aquisicao'] ?? null, true),
            'data_aquisicao' => Normaliza::texto($d['data_aquisicao'] ?? null), 'estado' => (string) ($d['estado'] ?? $actual?->estado ?? 'ACTIVO'),
            'notas' => Normaliza::texto($d['notas'] ?? null),
        ];
        if (array_key_exists('documentos', $d) || $actual === null) {
            $reg['documentos'] = Normaliza::documentos($d['documentos'] ?? []);
        }

        return $reg;
    }

    /** @param  array<string, mixed>  $reg */
    private function conferir(array &$reg, ?Imovel $actual): void
    {
        if ($reg['area_util'] > 0 && $reg['area_bruta'] > 0 && $reg['area_util'] > $reg['area_bruta']) {
            throw new ErroNegocio('A área útil não pode ser maior do que a área bruta.', 'IMOVEL_AREAS');
        }
        $tipo = CatalogoImobiliario::TIPOS_IMOVEL[$reg['tipo']];
        if ($tipo['habitacoes'] && $reg['n_habitacoes'] === 0) {
            throw new ErroNegocio('Indique o número de habitações do condomínio: é contra esse número que se conferem as fracções registadas.', 'IMOVEL_HABITACOES');
        }
        if ($reg['codigo'] !== null) {
            $q = Imovel::query()->whereRaw('lower(codigo) = ?', [mb_strtolower($reg['codigo'])]);
            if ($actual) {
                $q->whereKeyNot($actual->id);
            }
            if ($q->exists()) {
                throw new ErroNegocio("Já existe um imóvel com o código {$reg['codigo']}.", 'IMOVEL_CODIGO');
            }
        }
        if ($reg['matriz_predial'] !== null) {
            $q = Imovel::query()->where('matriz_predial', $reg['matriz_predial']);
            if ($actual) {
                $q->whereKeyNot($actual->id);
            }
            if ($q->exists()) {
                throw new ErroNegocio("Já existe um imóvel com o artigo matricial {$reg['matriz_predial']}. Verifique: a matriz identifica o prédio.", 'IMOVEL_MATRIZ');
            }
        }
        if ($reg['ativo_edificio_id'] !== null) {
            $this->conferirEdificio((int) $reg['ativo_edificio_id'], $reg['papel'], $actual);
        }
    }

    /** O edifício tem de ser um bem EDIFICIO do Activos: próprio para um imóvel próprio, alheio arrendado para um arrendado. */
    private function conferirEdificio(int $id, string $papel, ?Imovel $actual): void
    {
        $bem = AtivoImobilizado::query()->find($id, ['id', 'tipo_ativo', 'propriedade', 'regime_alheio']);
        if ($bem === null || $bem->tipo_ativo !== 'EDIFICIO') {
            throw new ErroNegocio('O edifício escolhido não existe nos Activos ou não é um edifício.', 'IMOVEL_EDIFICIO');
        }
        $alheio = $bem->propriedade === AtivoImobilizado::ALHEIO;
        if ($papel === 'PROPRIO' && $alheio) {
            throw new ErroNegocio('Um imóvel próprio só se liga a um edifício próprio do imobilizado.', 'IMOVEL_EDIFICIO');
        }
        if ($papel === 'ARRENDADO' && (! $alheio || $bem->regime_alheio !== 'ARRENDADO')) {
            throw new ErroNegocio('Um imóvel arrendado a terceiros só se liga a um edifício alheio em regime «arrendado».', 'IMOVEL_EDIFICIO');
        }
        $q = Imovel::query()->where('ativo_edificio_id', $id);
        if ($actual) {
            $q->whereKeyNot($actual->id);
        }
        $outro = $q->first(['codigo']);
        if ($outro !== null) {
            throw new ErroNegocio("Este edifício já está ligado ao imóvel {$outro->codigo}.", 'IMOVEL_EDIFICIO_LIGADO');
        }
    }

    private function utilizador(): string
    {
        return mb_substr((string) (Auth::user()?->nome ?? 'sistema'), 0, 100);
    }
}
