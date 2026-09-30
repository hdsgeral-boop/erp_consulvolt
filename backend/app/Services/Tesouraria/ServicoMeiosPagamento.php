<?php

namespace App\Services\Tesouraria;

use App\Exceptions\ErroNegocio;
use App\Models\MeioPagamento;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\DB;

/**
 * Meios de pagamento (saveMeioPagamento, js/ui_tesouraria.js:6820-6897), corrigidos:
 *   - IBAN angolano validado a sério: AO + 23 dígitos e dígitos de controlo (ISO 13616, mod 97) — o legado só avisava;
 *   - SWIFT/BIC com formato válido; conta 43/45 de movimento; moeda da própria conta do plano;
 *   - um só predefinido por empresa, numa transacção; eliminação lógica (nunca apagar a conta de um meio usado).
 */
final class ServicoMeiosPagamento
{
    public function __construct(private readonly ServicoPlanoContas $plano) {}

    public function gravar(array $d, ?MeioPagamento $meio = null): MeioPagamento
    {
        $conta = $this->plano->contaDeMovimento($d['codigo_conta']);
        if (! preg_match('/^4[35]/', $d['codigo_conta'])) {
            throw new ErroNegocio('A conta tem de ser de bancos (43) ou de caixa (45).', 'CONTA_FINANCEIRA_INVALIDA', 422);
        }
        $iban = isset($d['iban']) ? strtoupper(preg_replace('/\s+/', '', (string) $d['iban'])) : null;
        if ($iban !== null && $iban !== '' && ! self::ibanValido($iban)) {
            throw new ErroNegocio('IBAN inválido: um IBAN angolano tem AO + 23 dígitos e dígitos de controlo correctos.', 'IBAN_INVALIDO', 422);
        }
        $swift = isset($d['swift']) ? strtoupper(trim((string) $d['swift'])) : null;
        if ($swift && ! preg_match('/^[A-Z]{4}[A-Z]{2}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swift)) {
            throw new ErroNegocio('Código SWIFT/BIC inválido (8 ou 11 caracteres).', 'SWIFT_INVALIDO', 422);
        }

        return DB::transaction(function () use ($d, $meio, $conta, $iban, $swift) {
            $predefinido = (bool) ($d['predefinido'] ?? $meio?->predefinido ?? false);
            if ($predefinido) {
                MeioPagamento::query()->when($meio, fn ($q) => $q->where('id', '<>', $meio->id))->update(['predefinido' => false]);
            }
            $dados = ['nome' => trim($d['nome']), 'codigo_conta' => $d['codigo_conta'], 'iban' => $iban ?: null, 'swift' => $swift ?: null,
                'ativo' => (bool) ($d['ativo'] ?? true), 'predefinido' => $predefinido, 'codigo_moeda' => $conta['codigo_moeda'] ?: 'AOA'];

            return $meio ? tap($meio)->update($dados) : MeioPagamento::create($dados);
        });
    }

    public function eliminar(MeioPagamento $meio): void
    {
        if ($meio->predefinido) {
            throw new ErroNegocio('Defina outro meio de pagamento como predefinido antes de eliminar este.', 'MEIO_PREDEFINIDO', 422);
        }
        $meio->delete();   // eliminação lógica (eliminado_em)
    }

    /** ISO 13616: move os 4 primeiros caracteres para o fim, letras → números (A=10…), resto da divisão por 97 = 1. */
    public static function ibanValido(string $iban): bool
    {
        if (! preg_match('/^AO\d{23}$/', $iban)) {
            return false;
        }
        $numerico = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $c) {
            $numerico .= ctype_alpha($c) ? (string) (ord($c) - 55) : $c;
        }

        return bcmod($numerico, '97') === '1';
    }
}
