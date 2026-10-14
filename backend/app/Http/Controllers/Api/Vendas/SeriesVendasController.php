<?php

namespace App\Http\Controllers\Api\Vendas;

use App\Http\Controllers\Controller;
use App\Models\SerieFaturacaoEletronica;
use App\Models\TerminalPOS;
use App\Models\Venda;
use App\Services\Vendas\ServicoConfigFaturacaoEletronica;
use App\Support\Api\RespostaApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * /api/vendas/series — Vendas › Séries de numeração (js/vendas_series.js:63 renderVendasSeriesTab do legado novo).
 * Séries FT / FR / NC por ano e origem (facturação geral ou cada terminal POS), com o número inicial editável enquanto
 * a série não tem documentos. A gravação é a do motor (ServicoConfigFaturacaoEletronica::gravarSerie), a mesma da
 * Facturação electrónica: quando o regime AGT arrancar, a numeração continua na mesma série, sem saltos.
 *
 * Permissões: a chave nova vendas_series_gerir (catálogo) ou, até lá, vendas_fe_config.
 */
final class SeriesVendasController extends Controller
{
    /** Tipos geridos neste ecrã (TIPOS de vendas_series.js). Os restantes (OR, PF, NE, GR, GD, RE) numeram-se sozinhos. */
    public const TIPOS = ['FT' => 'Factura', 'FR' => 'Factura-Recibo', 'NC' => 'Nota de Crédito'];

    public function __construct(private readonly ServicoConfigFaturacaoEletronica $config) {}

    /** GET — séries, cartões por tipo (o que acontece hoje), origens possíveis e regime. */
    public function index(Request $request): JsonResponse
    {
        $this->exigir('vendas_series_view', 'vendas_series_gerir', 'vendas_fe_config');
        $f = $request->validate(['ano' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $anoActual = (int) now()->year;

        $series = SerieFaturacaoEletronica::query()->whereIn('tipo', array_keys(self::TIPOS))
            ->when($f['ano'] ?? null, fn ($q, $v) => $q->where('ano', $v))
            ->orderByDesc('ano')->orderBy('tipo')->orderBy('origem')->orderBy('codigo')->get();
        $ids = $series->pluck('id')->all();
        $emitidos = $this->config->emitidos($ids);
        // último documento de cada série (maior número), numa só consulta
        $ultimos = $ids ? Venda::query()->whereIn('serie_faturacao_eletronica_id', $ids)
            ->selectRaw('DISTINCT ON (serie_faturacao_eletronica_id) serie_faturacao_eletronica_id, numero_documento')
            ->orderBy('serie_faturacao_eletronica_id')->orderByDesc('fe_numero')->orderByDesc('id')
            ->pluck('numero_documento', 'serie_faturacao_eletronica_id') : collect();
        $origens = $this->origens();
        $rotulo = fn (?string $o) => collect($origens)->firstWhere('valor', $o ?? 'GERAL')['rotulo'] ?? $o;

        $linhas = $series->map(fn (SerieFaturacaoEletronica $s) => [
            'id' => $s->id, 'tipo' => $s->tipo, 'tipo_nome' => self::TIPOS[$s->tipo] ?? $s->tipo, 'ano' => $s->ano,
            'origem' => $s->origem ?: 'GERAL', 'origem_rotulo' => $rotulo($s->origem) ?: ($s->origem_nome ?? $s->origem),
            'codigo' => $s->codigo, 'estabelecimento' => $s->estabelecimento, 'proximo_numero' => max(1, (int) $s->proximo_numero),
            'proximo_documento' => "{$s->tipo} {$s->codigo}/".max(1, (int) $s->proximo_numero),
            'emitidos' => $emitidos[$s->id] ?? 0, 'usada' => ($emitidos[$s->id] ?? 0) > 0,
            'ultima_data' => $s->ultima_data?->toDateString(), 'ultimo_documento' => $ultimos[$s->id] ?? null,
            'estado' => $s->estado, 'contingencia' => (bool) $s->contingencia, 'agt_codigo' => $s->agt_codigo,
            'agt_primeiro_numero' => $s->agt_primeiro_numero, 'agt_ultimo_numero' => $s->agt_ultimo_numero,
        ])->values();

        $cfg = $this->config->obter();
        $resumo = collect(self::TIPOS)->map(function (string $nome, string $tipo) use ($series, $anoActual) {
            $s = $series->first(fn ($x) => $x->estado === 'ATIVA' && $x->tipo === $tipo && (int) $x->ano === $anoActual && ($x->origem ?: 'GERAL') === 'GERAL');

            return ['tipo' => $tipo, 'nome' => $nome, 'ano' => $anoActual, 'serie_id' => $s?->id, 'codigo' => $s?->codigo,
                'proximo_documento' => $s ? "{$tipo} {$s->codigo}/".max(1, (int) $s->proximo_numero) : null];
        })->values();

        return RespostaApi::sucesso([
            'ano' => $anoActual,
            'regime' => ['ativo' => $cfg['ativo'], 'data_inicio' => $cfg['data_inicio'], 'exigir_series_agt' => $cfg['servico']['exigir_series_agt']],
            'estabelecimentos' => $cfg['estabelecimentos'], 'origens' => $origens, 'tipos' => self::TIPOS,
            'resumo' => $resumo, 'series' => $linhas,
        ], 'Séries obtidas com sucesso.');
    }

    public function store(Request $request): JsonResponse
    {
        $this->exigir('vendas_series_gerir', 'vendas_fe_config');
        $s = $this->config->gravarSerie($this->validar($request));

        return RespostaApi::criado($s, "Série {$s->tipo} {$s->codigo} criada: o próximo documento será {$s->tipo} {$s->codigo}/{$s->proximo_numero}.");
    }

    public function update(Request $request, int $serie): JsonResponse
    {
        $this->exigir('vendas_series_gerir', 'vendas_fe_config');
        $atual = SerieFaturacaoEletronica::query()->whereIn('tipo', array_keys(self::TIPOS))->findOrFail($serie);
        $s = $this->config->gravarSerie($this->validar($request, $atual), $atual);

        return RespostaApi::sucesso($s, "Série {$s->tipo} {$s->codigo} actualizada.");
    }

    public function destroy(int $serie): JsonResponse
    {
        $this->exigir('vendas_series_gerir', 'vendas_fe_config');
        $this->config->eliminarSerie(SerieFaturacaoEletronica::query()->whereIn('tipo', array_keys(self::TIPOS))->findOrFail($serie));

        return RespostaApi::sucesso(null, 'Série eliminada.');
    }

    private function validar(Request $request, ?SerieFaturacaoEletronica $atual = null): array
    {
        $origens = $this->origens();
        // uma série antiga pode ter uma origem que já não é terminal activo: continua a poder ser fechada/reaberta
        if ($atual && $atual->origem && ! in_array($atual->origem, array_column($origens, 'valor'), true)) {
            $origens[] = ['valor' => $atual->origem, 'rotulo' => $atual->origem_nome ?: $atual->origem];
        }
        $d = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(self::TIPOS))],
            'ano' => ['required', 'integer', 'min:2000', 'max:2100'],
            'codigo' => ['required', 'string', 'regex:/^[A-Za-z0-9]{1,30}$/'],
            'origem' => ['nullable', 'string', Rule::in(array_column($origens, 'valor'))],
            'estabelecimento' => ['nullable', 'string', 'max:20'],
            'proximo_numero' => ['nullable', 'integer', 'min:1', 'max:'.ServicoConfigFaturacaoEletronica::NUMERO_MAXIMO],
            'contingencia' => ['nullable', 'boolean'],
            'estado' => ['nullable', Rule::in(['ATIVA', 'FECHADA'])],
        ], [], ['codigo' => 'código da série (letras e números, sem espaços nem "/", até 30)', 'proximo_numero' => 'número inicial', 'origem' => 'origem']);
        $d['origem'] = $d['origem'] ?? 'GERAL';
        // o nome da origem vem do servidor (lista de terminais da empresa), não do pedido
        $d['origem_nome'] = collect($origens)->firstWhere('valor', $d['origem'])['rotulo'] ?? $d['origem'];

        return $d;
    }

    /**
     * Facturação geral + terminais POS da empresa activa. A origem de uma série de terminal é o CÓDIGO do terminal
     * (ServicoVendasPOS reserva com origem_serie = código do terminal).
     *
     * @return list<array{valor: string, rotulo: string}>
     */
    private function origens(): array
    {
        $lista = [['valor' => 'GERAL', 'rotulo' => 'Facturação (geral)']];
        foreach (TerminalPOS::query()->whereNotNull('codigo')->orderBy('codigo')->get(['id', 'codigo', 'nome']) as $t) {
            $codigo = mb_substr((string) $t->codigo, 0, 30);
            if ($codigo !== '' && $codigo !== 'GERAL') {
                $lista[] = ['valor' => $codigo, 'rotulo' => 'POS '.$codigo.($t->nome ? ' — '.$t->nome : '')];
            }
        }

        return $lista;
    }
}
