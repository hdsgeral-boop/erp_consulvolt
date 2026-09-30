<?php

namespace App\Services\POS;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoPOS;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\Auth;

/**
 * Definições do POS (pos_settings, js/pos_gestao.js:1081-1086): contas dos desvios de caixa, tolerância e diário.
 * As contas passam a ser validadas (movimento e classe: sobras 6, quebras 7, operador 3); o legado aceitava qualquer texto.
 */
final class ServicoConfigPOS
{
    public const DIARIO_PADRAO = 'GEPOS';

    private const CLASSES = ['conta_sobra' => ['6', 'sobras de caixa (proveito, classe 6)'], 'conta_quebra' => ['7', 'quebras de caixa (custo, classe 7)'],
        'conta_operador' => ['3', 'responsabilidade do operador (terceiros, classe 3)']];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /** @return array{conta_sobra: ?string, conta_quebra: ?string, conta_operador: ?string, tolerancia_desvio: string, codigo_diario: string} */
    public function obter(): array
    {
        $c = ConfiguracaoPOS::query()->first();

        return ['conta_sobra' => $c?->conta_sobra ?: null, 'conta_quebra' => $c?->conta_quebra ?: null, 'conta_operador' => $c?->conta_operador ?: null,
            'tolerancia_desvio' => number_format((float) ($c?->tolerancia_desvio ?? 0), 2, '.', ''), 'codigo_diario' => $c?->codigo_diario ?: self::DIARIO_PADRAO];
    }

    public function guardar(array $d): array
    {
        foreach (self::CLASSES as $campo => [$classe, $nome]) {
            if (! empty($d[$campo])) {
                $this->plano->contaDeMovimento((string) $d[$campo]);
                if (! str_starts_with((string) $d[$campo], $classe)) {
                    throw new ErroNegocio("A conta de {$nome} tem de ser da classe {$classe}.", 'CONTA_CLASSE_INVALIDA', 422, ['campo' => $campo]);
                }
            }
        }
        if ((float) ($d['tolerancia_desvio'] ?? 0) < 0) {
            throw new ErroNegocio('A tolerância de desvio não pode ser negativa.', 'TOLERANCIA_INVALIDA', 422);
        }
        $atual = $this->obter();
        ConfiguracaoPOS::query()->updateOrCreate([], [
            'conta_sobra' => array_key_exists('conta_sobra', $d) ? ($d['conta_sobra'] ?: null) : $atual['conta_sobra'],
            'conta_quebra' => array_key_exists('conta_quebra', $d) ? ($d['conta_quebra'] ?: null) : $atual['conta_quebra'],
            'conta_operador' => array_key_exists('conta_operador', $d) ? ($d['conta_operador'] ?: null) : $atual['conta_operador'],
            'tolerancia_desvio' => number_format((float) ($d['tolerancia_desvio'] ?? $atual['tolerancia_desvio']), 2, '.', ''),
            'codigo_diario' => strtoupper(trim((string) ($d['codigo_diario'] ?? '') ?: $atual['codigo_diario'])),
            'atualizado_por' => Auth::user()?->nome_utilizador,
        ]);

        return $this->obter();
    }

    public function exigirConta(string $campo): string
    {
        return $this->obter()[$campo] ?? throw new ErroNegocio('Configure a conta de '.self::CLASSES[$campo][1].' nas definições do POS.', 'CONFIG_POS_EM_FALTA', 422, ['campo' => $campo]);
    }
}
