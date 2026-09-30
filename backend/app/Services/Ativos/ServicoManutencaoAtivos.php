<?php

namespace App\Services\Ativos;

use App\Exceptions\ErroNegocio;
use App\Models\AtivoImobilizado;
use App\Models\RegistoManutencaoAtivo;
use Illuminate\Support\Facades\DB;

/**
 * Manutenções de activos (renderMaintenanceView / saveMaintenance / executeMaintenanceResolution / deleteMaintenanceRecord,
 * js/ui_assets.js:1119-1267, 2768-2780). Regras do legado: tipo PREVENTIVA ou CORRECTIVA; nasce PLANEADA, ou CONCLUIDA se já
 * tiver custo; a execução exige a descrição do trabalho feito, grava o custo final e a data de execução (hoje).
 * Correcções: o estado passa ao código normalizado CONCLUIDA (o legado gravava «concluida» e comparava «PLANEADA», ADR-004);
 * o activo tem de existir e não estar abatido; custo ≥ 0; uma manutenção concluída não se executa de novo.
 * A manutenção não gera lançamento (o custo é registado na compra/factura do fornecedor), como no legado.
 */
final class ServicoManutencaoAtivos
{
    public function registar(array $d): RegistoManutencaoAtivo
    {
        if (! in_array($d['tipo'] ?? null, RegistoManutencaoAtivo::TIPOS, true)) {
            throw new ErroNegocio('Tipo de manutenção inválido (PREVENTIVA ou CORRECTIVA).', 'DADOS_INVALIDOS', 422);
        }
        $a = AtivoImobilizado::query()->find($d['ativo_imobilizado_id'] ?? 0) ?? throw new ErroNegocio('Activo inexistente.', 'DADOS_INVALIDOS', 422);
        if ($a->estado === AtivoImobilizado::ESTADO_ABATIDO) {
            throw new ErroNegocio("O activo {$a->codigo} está abatido.", 'ATIVO_ABATIDO', 422);
        }
        $custo = $this->custo($d['custo'] ?? 0);

        return RegistoManutencaoAtivo::create(['ativo_imobilizado_id' => $a->id, 'tipo' => $d['tipo'], 'data' => $d['data'] ?? now()->toDateString(),
            'descricao' => $d['descricao'] ?? null, 'custo' => $custo,
            'estado' => bccomp($custo, '0', 2) > 0 ? RegistoManutencaoAtivo::CONCLUIDA : RegistoManutencaoAtivo::PLANEADA]);
    }

    public function executar(RegistoManutencaoAtivo $r, string $resolucao, mixed $custo): RegistoManutencaoAtivo
    {
        if (trim($resolucao) === '') {
            throw new ErroNegocio('Indique o trabalho efectuado.', 'DADOS_INVALIDOS', 422);
        }

        return DB::transaction(function () use ($r, $resolucao, $custo) {
            $r = RegistoManutencaoAtivo::query()->lockForUpdate()->findOrFail($r->id);
            if ($r->estado === RegistoManutencaoAtivo::CONCLUIDA) {
                throw new ErroNegocio('A manutenção já está concluída.', 'MANUTENCAO_CONCLUIDA', 422);
            }
            $r->update(['resolucao' => trim($resolucao), 'custo' => $this->custo($custo), 'estado' => RegistoManutencaoAtivo::CONCLUIDA, 'data_execucao' => now()->toDateString()]);

            return $r->refresh();
        });
    }

    private function custo(mixed $v): string
    {
        $c = CalculadoraAmortizacoes::d($v);
        if (bccomp($c, '0', 2) < 0) {
            throw new ErroNegocio('O custo não pode ser negativo.', 'DADOS_INVALIDOS', 422);
        }

        return $c;
    }
}
