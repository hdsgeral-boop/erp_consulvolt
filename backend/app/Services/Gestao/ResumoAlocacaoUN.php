<?php

namespace App\Services\Gestao;

use App\Exceptions\ErroNegocio;
use App\Models\CriterioReparticaoUN;
use App\Services\Reparticao\ServicoReparticao;

/**
 * Bloco só de leitura «Unidades de negócio» dos ecrãs de detalhe (ADR-071, R5): critério, unidades alocadas e as
 * percentagens (e parcelas do valor do documento, se houver) calculadas pelo ServicoReparticao — o mesmo cálculo da
 * pré-visualização e da contabilização. Não altera nada; um critério apagado ou sem pesos devolve a mensagem em `erro`.
 */
final class ResumoAlocacaoUN
{
    public function __construct(private readonly ServicoReparticao $reparticao) {}

    /**
     * @return array{criterio: ?array{id: int, codigo: ?string, nome: ?string, criterio: ?string}, unidades: list<array{unidade_negocio_id: int, peso: ?string}>, linhas: list<array<string, mixed>>, total: ?string, erro: ?string}|null
     */
    public function resumo(mixed $alocacao, mixed $valor = null): ?array
    {
        if (ServicoReparticao::vazia($alocacao)) {
            return null;
        }
        $a = ServicoReparticao::ler($alocacao);
        $criterio = CriterioReparticaoUN::query()->find($a['criterio_id'], ['id', 'codigo', 'nome', 'criterio']);
        $valor = is_numeric($valor) ? number_format((float) $valor, 2, '.', '') : null;
        try {
            if ($valor !== null && bccomp($valor, '0', 2) > 0) {
                $r = $this->reparticao->repartir($valor, $a);
                [$linhas, $total] = [$r['linhas'], $r['total']];
            } else {
                [$linhas, $total] = [$this->reparticao->pesos($a), null];
            }
            $erro = null;
        } catch (ErroNegocio $e) {
            [$linhas, $total, $erro] = [[], null, $e->getMessage()];
        }

        return ['criterio' => $criterio?->only(['id', 'codigo', 'nome', 'criterio']), 'unidades' => $a['unidades'], 'linhas' => $linhas, 'total' => $total, 'erro' => $erro];
    }
}
