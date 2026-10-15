<?php

namespace App\Services\Ativos;

use App\Services\Sistema\ServicoNumeracao;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;

/**
 * Numeração da manutenção planeada (proximoNumero, js/activos_manutencao.js:104-114): «PM-AAAA/0001» nos planos,
 * «OM AAAA/0001» nas ordens e «RQ AAAA/0001» nas requisições, por empresa e por ano.
 * Correcção: o legado lia todos os registos e somava 1 ao maior (dois utilizadores ao mesmo tempo repetiam o número);
 * aqui usa a sequência da empresa (ServicoNumeracao: lock + FOR UPDATE), semeada pelo maior número já migrado.
 */
final class NumeracaoManutencao
{
    private const TABELAS = [
        'PM' => ['planos_manutencao_ativos', 'codigo', '-'],
        'OM' => ['ordens_manutencao_ativos', 'numero', ' '],
        'RQ' => ['requisicoes_manutencao', 'numero', ' '],
    ];

    public function __construct(
        private readonly ContextoEmpresa $contexto,
        private readonly ServicoNumeracao $numeracao,
    ) {}

    public function proximo(string $prefixo, ?string $data = null): string
    {
        [$tabela, $coluna, $separador] = self::TABELAS[$prefixo];
        $empresa = $this->contexto->obrigatorio();
        $ano = substr($data ?? now()->toDateString(), 0, 4);
        $marca = "{$prefixo}{$separador}{$ano}/";
        $n = $this->numeracao->proximo($empresa, "ativos:manutencao:{$prefixo}:{$ano}", fn () => (int) DB::table($tabela)->where('empresa_id', $empresa)
            ->where($coluna, 'like', $marca.'%')->selectRaw("MAX(NULLIF(substring({$coluna} from '/([0-9]+)$'), '')::int) AS m")->value('m'));

        return $marca.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}
