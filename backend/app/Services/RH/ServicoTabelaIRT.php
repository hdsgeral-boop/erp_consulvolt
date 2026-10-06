<?php

namespace App\Services\RH;

use App\Exceptions\ErroNegocio;
use App\Services\Sistema\ServicoAuditoria;
use Illuminate\Support\Facades\DB;

/**
 * Tabela de IRT (Grupo A) configurável — decisão 1 do utilizador (2026-10-06).
 * Por omissão é a tabela do js/engine_v2.js (MotorSalarial::TABELA_IRT, decisão de 2026-09-29), que isenta até
 * 150 000 Kz e aplica a parcela fixa de 12 500 Kz a partir de 150 000,01 Kz (degrau de 12 500 Kz — D-SAL-2).
 * NOTA LEGAL: confirmar a tabela com o Código do IRT em vigor antes de cada processamento anual; se a lei mudar,
 * altera-se aqui (RH › Rubricas › Tabela de IRT) sem alterar código.
 * A tabela é nacional: guarda-se uma só vez em configuracoes_sistema (chave rh_tabela_irt), para todas as empresas.
 * O modo LEGADO (reprodução dos períodos migrados) usa sempre a tabela original.
 */
final class ServicoTabelaIRT
{
    public const CHAVE = 'rh_tabela_irt';

    public function __construct(private readonly ServicoAuditoria $auditoria) {}

    /** @return list<array{max: ?string, taxa: string, fixo: string, excesso: string}> */
    public function atual(): array
    {
        $json = DB::table('configuracoes_sistema')->where('chave', self::CHAVE)->value('valor');
        $tabela = $json ? json_decode((string) $json, true) : null;

        return is_array($tabela) && $tabela !== [] ? $tabela : MotorSalarial::TABELA_IRT;
    }

    public function personalizada(): bool
    {
        return DB::table('configuracoes_sistema')->where('chave', self::CHAVE)->exists();
    }

    /**
     * @param  list<array{max: mixed, taxa: mixed, fixo: mixed, excesso: mixed}>  $escaloes  taxa em percentagem (ex.: 16)
     * @return list<array{max: ?string, taxa: string, fixo: string, excesso: string}>
     */
    public function gravar(array $escaloes): array
    {
        $tabela = [];
        $anterior = '0';
        foreach (array_values($escaloes) as $i => $e) {
            $ultimo = $i === count($escaloes) - 1;
            $max = ($e['max'] ?? null) === null || $e['max'] === '' ? null : number_format((float) $e['max'], 2, '.', '');
            if ($max === null && ! $ultimo) {
                throw new ErroNegocio('Só o último escalão pode não ter limite superior.', 'TABELA_IRT_INVALIDA', 422);
            }
            if ($ultimo && $max !== null) {
                throw new ErroNegocio('O último escalão não pode ter limite superior (aplica-se a todos os rendimentos acima).', 'TABELA_IRT_INVALIDA', 422);
            }
            if ($max !== null && bccomp($max, $anterior, 2) <= 0) {
                throw new ErroNegocio('Os limites dos escalões têm de ser crescentes (escalão '.($i + 1).').', 'TABELA_IRT_INVALIDA', 422);
            }
            $taxa = (float) ($e['taxa'] ?? 0);
            if ($taxa < 0 || $taxa > 100) {
                throw new ErroNegocio('A taxa tem de estar entre 0 e 100 % (escalão '.($i + 1).').', 'TABELA_IRT_INVALIDA', 422);
            }
            $tabela[] = ['max' => $max === null ? null : rtrim(rtrim($max, '0'), '.'), 'taxa' => rtrim(rtrim(number_format($taxa / 100, 5, '.', ''), '0'), '.') ?: '0',
                'fixo' => rtrim(rtrim(number_format((float) ($e['fixo'] ?? 0), 2, '.', ''), '0'), '.') ?: '0',
                'excesso' => rtrim(rtrim(number_format((float) ($e['excesso'] ?? 0), 2, '.', ''), '0'), '.') ?: '0'];
            $anterior = $max ?? $anterior;
        }
        if (count($tabela) < 2) {
            throw new ErroNegocio('A tabela tem de ter pelo menos dois escalões.', 'TABELA_IRT_INVALIDA', 422);
        }
        DB::table('configuracoes_sistema')->updateOrInsert(['chave' => self::CHAVE], ['valor' => json_encode($tabela), 'atualizado_em' => now()]);
        $this->auditoria->registar('RH', 'Alterou a tabela de IRT', json_encode($tabela, JSON_UNESCAPED_UNICODE), 'configuracoes_sistema');

        return $tabela;
    }

    /** Volta à tabela do engine_v2.js. */
    public function repor(): array
    {
        DB::table('configuracoes_sistema')->where('chave', self::CHAVE)->delete();
        $this->auditoria->registar('RH', 'Repôs a tabela de IRT', 'Tabela do engine_v2.js (MotorSalarial::TABELA_IRT).', 'configuracoes_sistema');

        return MotorSalarial::TABELA_IRT;
    }

    /** Tabela para o ecrã: taxa em percentagem. */
    public static function paraEcra(array $tabela): array
    {
        return array_map(fn ($e) => ['max' => $e['max'] === null ? null : (float) $e['max'], 'taxa' => round((float) $e['taxa'] * 100, 3),
            'fixo' => (float) $e['fixo'], 'excesso' => (float) $e['excesso']], $tabela);
    }
}
