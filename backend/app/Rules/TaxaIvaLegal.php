<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * M3: a taxa de IVA tem de ser uma das taxas legais (config erp.fiscal.taxas_iva — as do legado: 14 normal, 7 intermédia,
 * 5 reduzida, 0 isento; js/ui_sales.js:2319-2324, COMPRAS_TAXAS_IVA em js/ui_compras_v2.js:1174). Antes aceitava-se
 * qualquer valor entre 0 e 100 (que na AGT ia como «OUT»).
 */
final class TaxaIvaLegal implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }
        $taxas = array_map('floatval', (array) config('erp.fiscal.taxas_iva', [0, 5, 7, 14]));
        if (! is_numeric($value) || ! in_array(round((float) $value, 4), $taxas, true)) {
            $fail('A taxa de IVA tem de ser uma das taxas legais ('.implode(', ', array_map(fn ($t) => rtrim(rtrim(number_format($t, 2, '.', ''), '0'), '.').' %', $taxas)).').');
        }
    }
}
