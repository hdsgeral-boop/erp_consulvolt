<?php

namespace App\Services\Sistema;

use App\Models\TaxaCambio;

/**
 * Câmbios (Moedas.obterCambio, js/moedas.js:61-77 do legado): a taxa válida numa data é a última registada
 * até essa data; a taxa da empresa prevalece sobre a geral (empresa_id nulo) quando é igual ou mais recente.
 * Kz por 1 unidade da moeda. AOA = 1.
 */
final class ServicoCambios
{
    public const BASE = 'AOA';

    /** @return array{id: ?int, taxa: string, data: string, exata: bool, ambito: string}|null */
    public function obter(int $empresaId, string $moeda, string $data): ?array
    {
        $dia = substr($data, 0, 10);
        if ($moeda === self::BASE) {
            return ['id' => null, 'taxa' => '1', 'data' => $dia, 'exata' => true, 'ambito' => 'base'];
        }
        $procurar = fn (?int $empresa) => TaxaCambio::query()
            ->where('codigo_moeda', $moeda)->where('data_taxa', '<=', $dia)->where('taxa', '>', 0)
            ->when($empresa, fn ($q) => $q->where('empresa_id', $empresa), fn ($q) => $q->whereNull('empresa_id'))
            ->orderByDesc('data_taxa')->first();

        $daEmpresa = $procurar($empresaId);
        $geral = $procurar(null);
        $r = $daEmpresa && (! $geral || $daEmpresa->data_taxa >= $geral->data_taxa) ? $daEmpresa : $geral;
        if (! $r) {
            return null;
        }

        return ['id' => $r->id, 'taxa' => rtrim(rtrim((string) $r->taxa, '0'), '.'), 'data' => $r->data_taxa->toDateString(),
            'exata' => $r->data_taxa->toDateString() === $dia, 'ambito' => $r->empresa_id ? 'empresa' : 'todas'];
    }
}
