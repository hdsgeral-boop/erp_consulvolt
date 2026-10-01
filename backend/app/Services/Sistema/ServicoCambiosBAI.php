<?php

namespace App\Services\Sistema;

use App\Exceptions\ErroNegocio;
use App\Models\Moeda;
use App\Models\TaxaCambio;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Câmbios do BAI (Moedas.obterCambiosBAI / interpretarPaginaBAI / abrirCambiosBAI / gravarCambiosBAI, js/moedas.js:600-787).
 *
 * Paridade:
 *  - lê a tabela "ExchangeRatesList" da página pública do BAI (colunas Moeda | Divisas: Compra/Venda | Notas);
 *  - câmbio gravado = média de divisas (compra + venda) / 2, 6 casas, para TODAS as empresas, origem "BAI", com a
 *    data de hoje (a página não indica a data da cotação); guarda também compra e venda;
 *  - pré-visualização com o último câmbio registado e a variação; alerta acima de 5%;
 *  - os câmbios de hoje já registados são substituídos, excepto os já usados em documentos (taxa diferente).
 *
 * Correcções face ao legado:
 *  - a leitura era feita pelo navegador (bloqueada por CORS na maior parte dos casos); passa a ser feita pelo
 *    servidor, com tempo limite de 20 s;
 *  - na gravação o servidor volta a ler a página: os valores gravados não vêm do cliente.
 */
final class ServicoCambiosBAI
{
    public const URL = 'https://www.bancobai.ao/pt/cambios-e-valores';

    public const LIMIAR_VARIACAO = 5.0;

    public function __construct(
        private readonly ServicoMoedas $moedas,
        private readonly ServicoAuditoria $auditoria,
    ) {}

    /** @return list<array{moeda: string, nome: string, compra: float, venda: float, media: float}> */
    public function obter(): array
    {
        try {
            $resposta = Http::timeout(20)->withHeaders(['Accept' => 'text/html'])->get(self::URL);
        } catch (ConnectionException) {
            throw new ErroNegocio('Não foi possível contactar o site do BAI (sem ligação ou o site não respondeu em 20 segundos).', 'BAI_INDISPONIVEL', 502);
        }
        if (! $resposta->successful()) {
            $s = $resposta->status();
            throw new ErroNegocio("O site do BAI respondeu com erro {$s}".(in_array($s, [403, 429, 503], true) ? ' (protecção anti-robô activa; tente mais tarde)' : '').'.',
                'BAI_INDISPONIVEL', 502);
        }

        return self::interpretar($resposta->body());
    }

    /** @return array{data: string, itens: list<array<string, mixed>>} uma linha por moeda estrangeira activa */
    public function previsualizar(): array
    {
        $porMoeda = collect($this->obter())->keyBy('moeda');
        $hoje = now()->toDateString();
        $itens = [];
        foreach (Moeda::query()->where('ativo', true)->where('codigo', '<>', ServicoCambios::BASE)->orderBy('codigo')->get() as $m) {
            $l = $porMoeda[$m->codigo] ?? null;
            $ultimo = TaxaCambio::query()->whereNull('empresa_id')->where('codigo_moeda', $m->codigo)->where('data_taxa', '<=', $hoje)->orderByDesc('data_taxa')->first();
            $variacao = $l && $ultimo && (float) $ultimo->taxa > 0 ? round(($l['media'] - (float) $ultimo->taxa) / (float) $ultimo->taxa * 100, 2) : null;
            $itens[] = ['codigo_moeda' => $m->codigo, 'nome' => $m->nome, 'disponivel' => $l !== null, 'compra' => $l['compra'] ?? null, 'venda' => $l['venda'] ?? null,
                'media' => $l['media'] ?? null, 'ultimo' => $ultimo ? ['taxa' => (float) $ultimo->taxa, 'data_taxa' => $ultimo->data_taxa->toDateString(), 'fonte_dados' => $ultimo->fonte_dados] : null,
                'variacao' => $variacao, 'alerta' => $variacao !== null && abs($variacao) > self::LIMIAR_VARIACAO];
        }

        return ['data' => $hoje, 'itens' => $itens];
    }

    /**
     * @param  list<string>  $moedas
     * @return array{novos: int, substituidos: int, bloqueados: list<string>, gravados: list<array{codigo_moeda: string, taxa: float}>}
     */
    public function gravar(array $moedas): array
    {
        $porMoeda = collect($this->obter())->keyBy('moeda');
        $hoje = now()->toDateString();
        $r = ['novos' => 0, 'substituidos' => 0, 'bloqueados' => [], 'gravados' => []];
        DB::transaction(function () use ($moedas, $porMoeda, $hoje, &$r) {
            foreach (array_unique(array_map('strtoupper', $moedas)) as $codigo) {
                $l = $porMoeda[$codigo] ?? null;
                if (! $l) {
                    throw new ErroNegocio("A moeda {$codigo} não está disponível na página do BAI.", 'BAI_MOEDA_INDISPONIVEL', 422);
                }
                $media = number_format($l['media'], 6, '.', '');
                $ex = TaxaCambio::query()->whereNull('empresa_id')->where('codigo_moeda', $codigo)->whereDate('data_taxa', $hoje)->first();
                if ($ex && bccomp((string) $ex->taxa, $media, 6) !== 0 && $this->moedas->emUso($ex->id)) {
                    $r['bloqueados'][] = $codigo;

                    continue;
                }
                $this->moedas->gravarCambio(['data_taxa' => $hoje, 'codigo_moeda' => $codigo, 'taxa' => $media, 'ambito' => ServicoMoedas::AMBITO_TODAS,
                    'fonte_dados' => 'BAI', 'taxa_compra_bai' => $l['compra'], 'taxa_venda_bai' => $l['venda'], 'substituir' => true]);
                $ex ? $r['substituidos']++ : $r['novos']++;
                $r['gravados'][] = ['codigo_moeda' => $codigo, 'taxa' => (float) $media];
            }
        });
        $this->auditoria->registar('Sistema/Moedas', 'Câmbios do BAI', "Câmbios do BAI de {$hoje}: {$r['novos']} novo(s), {$r['substituidos']} substituído(s)"
            .($r['bloqueados'] ? ', não substituídos (em uso): '.implode(', ', $r['bloqueados']) : '').'.');

        return $r;
    }

    /**
     * interpretarPaginaBAI (js/moedas.js:620-672): tabela a seguir a "ExchangeRatesList"; colunas Moeda, Divisas
     * (Venda/Compra) — as Notas, se existirem, vêm depois das Divisas. Lança erro se a estrutura mudar.
     *
     * @return list<array{moeda: string, nome: string, compra: float, venda: float, media: float}>
     */
    public static function interpretar(string $html): array
    {
        $i = strpos($html, 'ExchangeRatesList');
        $inicio = stripos($html, '<table', $i !== false ? $i : 0);
        $fim = $inicio !== false ? stripos($html, '</table>', $inicio) : false;
        if ($inicio === false || $fim === false) {
            throw new ErroNegocio('Não foi encontrada a tabela de câmbios na página do BAI (a página pode ter mudado ou o acesso foi bloqueado).', 'BAI_ESTRUTURA', 502);
        }
        $tabela = substr($html, $inicio, $fim - $inicio);
        $texto = fn (string $h) => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        preg_match_all('#<th[^>]*>(.*?)</th>#is', $tabela, $th);
        $cab = array_map(fn ($c) => ServicoPermissoes::norm($texto($c)), $th[1]);
        $iMoeda = array_search('moeda', $cab, true);
        $iDivisas = self::procurar($cab, fn ($c) => str_contains($c, 'divisas'));
        $iNotas = self::procurar($cab, fn ($c) => str_contains($c, 'notas'));
        $depois = $iMoeda !== false ? array_values(array_slice($cab, $iMoeda + 1)) : [];
        $colVenda = self::procurar($depois, fn ($c) => str_starts_with($c, 'venda'));
        $colCompra = self::procurar($depois, fn ($c) => str_starts_with($c, 'compra'));
        if ($iMoeda === false || $iDivisas === null || $colVenda === null || $colCompra === null || ($iNotas !== null && $iNotas < $iDivisas)) {
            throw new ErroNegocio('A estrutura da tabela de câmbios do BAI mudou (colunas Divisas / Venda / Compra não encontradas).', 'BAI_ESTRUTURA', 502);
        }
        preg_match_all('#<tr[^>]*>(.*?)</tr>#is', $tabela, $trs);
        $linhas = [];
        foreach ($trs[1] as $tr) {
            preg_match_all('#<td[^>]*>(.*?)</td>#is', $tr, $td);
            $tds = array_map($texto, $td[1]);
            if (count($tds) < 3 || ! preg_match('/^([A-Z]{3})\b/', $tds[0], $m)) {
                continue;
            }
            $venda = ServicoMoedas::lerNumero($tds[1 + $colVenda] ?? '');
            $compra = ServicoMoedas::lerNumero($tds[1 + $colCompra] ?? '');
            if (! $venda || ! $compra || $venda <= 0 || $compra <= 0) {
                continue;
            }
            $linhas[] = ['moeda' => $m[1], 'nome' => trim(substr($tds[0], 3)), 'compra' => $compra, 'venda' => $venda, 'media' => round(($venda + $compra) / 2, 6)];
        }
        if (! $linhas) {
            throw new ErroNegocio('A tabela de câmbios do BAI não tem valores legíveis.', 'BAI_ESTRUTURA', 502);
        }

        return $linhas;
    }

    /** @param  list<string>  $lista */
    private static function procurar(array $lista, callable $teste): ?int
    {
        foreach ($lista as $i => $v) {
            if ($teste($v)) {
                return $i;
            }
        }

        return null;
    }
}
