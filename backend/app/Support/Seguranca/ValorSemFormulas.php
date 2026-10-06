<?php

namespace App\Support\Seguranca;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * OWASP A03 — injecção de fórmulas em folhas de cálculo (CSV/Excel injection). Com o binder por omissão do
 * PhpSpreadsheet, um texto começado por «=» gravado com setCellValue/fromArray vira FÓRMULA no .xlsx gerado (ex.: a
 * descrição «=HYPERLINK("http://…")» de uma nota, escrita por um utilizador, executava-se no Excel de quem abre o
 * ficheiro). Este binder grava como TEXTO qualquer valor começado por = + - @ TAB ou CR que não seja um número
 * (os negativos «-5» continuam números). Registado globalmente no AppServiceProvider. O ERP não gera fórmulas
 * intencionais; se um dia precisar, usar setValueExplicit(..., DataType::TYPE_FORMULA).
 */
final class ValorSemFormulas extends DefaultValueBinder
{
    private const INICIOS_PERIGOSOS = ['=', '+', '-', '@', "\t", "\r"];

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if (self::perigoso($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public static function perigoso(mixed $valor): bool
    {
        return is_string($valor) && $valor !== '' && in_array($valor[0], self::INICIOS_PERIGOSOS, true) && ! is_numeric($valor);
    }

    /** Para CSV (fputcsv): neutraliza o valor com um apóstrofo inicial, como recomenda a OWASP. */
    public static function paraCsv(mixed $valor): mixed
    {
        return self::perigoso($valor) ? "'".$valor : $valor;
    }
}
