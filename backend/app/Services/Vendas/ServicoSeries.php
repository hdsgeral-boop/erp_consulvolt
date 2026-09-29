<?php

namespace App\Services\Vendas;

use App\Exceptions\ErroNegocio;
use App\Models\SerieFaturacaoEletronica;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Séries e numeração dos documentos comerciais e recibos (FacturaAGT.numerar, js/facturacao_agt.js:175-213),
 * agora para TODOS os tipos e sem condição de corrida (lock Redis + SELECT … FOR UPDATE na série, na mesma
 * transacção que grava o documento — se o documento falhar, o número não é consumido).
 *
 * Formato: "<TIPO> <SÉRIE>/<n>" (ex.: "FT A2026/15"). Série por omissão: "A<ano>" por tipo, ano e origem
 * (GERAL ou T<terminal>), criada automaticamente como no legado.
 * Documentos fiscais (FT, FR, NC): data não futura e não anterior à do último documento da série.
 * Substitui a numeração antiga "<2 letras> <ano>/<max+1>", em que Factura e Factura-Recibo colidiam em "FA".
 */
final class ServicoSeries
{
    /**
     * @return array{serie: SerieFaturacaoEletronica, numero: int, numero_documento: string}
     */
    public function reservar(int $empresaId, string $tipo, string $data, bool $fiscal, string $origem = 'GERAL', bool $exigirAgt = false): array
    {
        $ano = (int) substr($data, 0, 4);
        $dia = substr($data, 0, 10);
        if ($fiscal && $dia > now()->toDateString()) {
            throw new ErroNegocio('A data de um documento fiscal não pode ser futura.', 'DATA_FUTURA', 422);
        }

        try {
            return Cache::lock("lock:serie:{$empresaId}:{$tipo}:{$ano}:{$origem}", 10)->block(5, fn () => DB::transaction(function () use ($tipo, $ano, $dia, $fiscal, $origem, $exigirAgt) {
                $serie = SerieFaturacaoEletronica::query()->where('tipo', $tipo)->where('ano', $ano)->where('origem', $origem)
                    ->where('estado', 'ATIVA')->lockForUpdate()->first();
                // "Exigir séries AGT" (configuração do regime): só séries com código atribuído pela AGT (facturacao_agt.js:191)
                if ($exigirAgt && (! $serie || ! $serie->agt_codigo)) {
                    throw new ErroNegocio("Não há série {$tipo} {$ano} atribuída pela AGT. Peça-a em Vendas › Facturação electrónica › Séries.", 'SERIE_AGT_EM_FALTA', 422);
                }
                $serie ??= $this->criar($tipo, $ano, $origem);

                if ($fiscal && $serie->ultima_data && $dia < $serie->ultima_data->toDateString()) {
                    throw new ErroNegocio("A data não pode ser anterior à do último documento da série {$serie->codigo} ({$serie->ultima_data->toDateString()}).",
                        'DATA_ANTERIOR_SERIE', 422);
                }
                if ($serie->agt_ultimo_numero && ($serie->proximo_numero ?? 1) > $serie->agt_ultimo_numero) {
                    throw new ErroNegocio("A série {$serie->codigo} esgotou os números autorizados pela AGT.", 'SERIE_ESGOTADA', 422);
                }

                $numero = max(1, (int) $serie->proximo_numero);
                $serie->update(['proximo_numero' => $numero + 1, 'ultima_data' => $fiscal ? $dia : $serie->ultima_data]);

                return ['serie' => $serie, 'numero' => $numero, 'numero_documento' => "{$tipo} {$serie->codigo}/{$numero}"];
            }));
        } catch (LockTimeoutException) {
            throw new ErroNegocio('Numeração ocupada por outra operação. Tente novamente.', 'NUMERACAO_OCUPADA', 409);
        }
    }

    private function criar(string $tipo, int $ano, string $origem): SerieFaturacaoEletronica
    {
        $base = ($origem === 'GERAL' ? 'A' : preg_replace('/[^A-Za-z0-9]/', '', $origem)).$ano;
        $codigo = $base;
        for ($i = 2; SerieFaturacaoEletronica::query()->where('tipo', $tipo)->where('ano', $ano)->where('codigo', $codigo)->exists(); $i++) {
            $codigo = $base.$i;
        }

        return SerieFaturacaoEletronica::create([
            'codigo' => $codigo, 'tipo' => $tipo, 'ano' => $ano, 'origem' => $origem, 'origem_nome' => $origem === 'GERAL' ? 'Geral' : $origem,
            'estado' => 'ATIVA', 'contingencia' => false, 'proximo_numero' => 1, 'criado_por' => Auth::user()?->nome_utilizador,
        ]);
    }
}
