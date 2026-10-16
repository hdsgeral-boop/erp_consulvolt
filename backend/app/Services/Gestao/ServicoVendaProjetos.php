<?php

namespace App\Services\Gestao;

use App\Models\AditamentoAlteracaoProjeto;
use App\Models\Projeto;
use App\Models\Venda;
use App\Services\Projetos\ServicoAnaliticoProjetos;
use Illuminate\Support\Collection;

/**
 * Venda contratada, aditamentos, venda global e facturação acumulada por projecto, em lote (sem N+1) — para o relatório
 * de gestão (G1, relatorios_gestao.js:470-542) e o painel de Projectos (G2, ui_painel_modulos.js:445-526).
 * É o mesmo cálculo do painel do projecto (ServicoAnaliticoProjetos::resumo) feito para vários projectos de uma vez:
 *   - venda contratada = encomenda do projecto (vendas.id = projetos.encomenda_venda_id), sem IVA, se não estiver anulada;
 *   - aditamentos = aditamentos APROVADOS; venda global = venda + aditamentos;
 *   - facturado acumulado = facturas e facturas-recibo do projecto (via encomenda ou projeto_id) até à data, sem IVA,
 *     menos as notas de crédito que as rectificam (ServicoAnaliticoProjetos::vendasDosProjetos — usado tal como está);
 *   - por facturar = venda global − facturado; % facturado = facturado ÷ venda global.
 * Não altera ServicoAnaliticoProjetos (usa só os métodos públicos vendasDosProjetos e semIva).
 */
final class ServicoVendaProjetos
{
    public function __construct(private readonly ServicoAnaliticoProjetos $analitico) {}

    /**
     * @param  Collection<int, Projeto>|list<int>  $projetos  projectos (ou ids)
     * @param  string|null  $ate  data (Y-m-d) até à qual conta a facturação; null = tudo
     * @return array<int, array{venda: string, aditamentos: string, venda_global: string, faturado: string, por_faturar: string, faturado_pct: ?float}>
     */
    public function porProjeto(Collection|array $projetos, ?string $ate = null): array
    {
        $projetos = $projetos instanceof Collection && $projetos->first() instanceof Projeto
            ? $projetos->keyBy('id')
            : Projeto::query()->whereIn('id', collect($projetos)->map(fn ($p) => $p instanceof Projeto ? $p->id : (int) $p)->all())->get()->keyBy('id');
        if ($projetos->isEmpty()) {
            return [];
        }
        $ids = $projetos->keys()->all();

        $encomendas = Venda::query()->whereIn('id', $projetos->pluck('encomenda_venda_id')->filter()->unique()->values())
            ->where(fn ($q) => $q->whereNull('estado')->orWhere('estado', '<>', 'ANULADO'))->get()->keyBy('id');
        $aditamentos = AditamentoAlteracaoProjeto::query()->whereIn('projeto_id', $ids)->where('estado', 'APROVADO')
            ->groupBy('projeto_id')->selectRaw('projeto_id, COALESCE(SUM(montante), 0) AS v')->pluck('v', 'projeto_id');
        $faturado = [];
        foreach ($this->analitico->vendasDosProjetos($projetos) as $m) {
            if ($ate === null || ($m['data'] && $m['data'] <= $ate)) {
                $faturado[$m['projeto_id']] = bcadd($faturado[$m['projeto_id']] ?? '0.00', $m['valor'], 2);
            }
        }

        $saida = [];
        foreach ($projetos as $id => $p) {
            $venda = $p->encomenda_venda_id ? ServicoAnaliticoProjetos::semIva($encomendas[$p->encomenda_venda_id] ?? null) : '0.00';
            $extras = ServicoAnaliticoProjetos::dinheiro($aditamentos[$id] ?? 0);
            $global = bcadd($venda, $extras, 2);
            $fat = $faturado[$id] ?? '0.00';
            $saida[$id] = ['venda' => $venda, 'aditamentos' => $extras, 'venda_global' => $global, 'faturado' => $fat,
                'por_faturar' => bcsub($global, $fat, 2), 'faturado_pct' => self::pct($fat, $global)];
        }

        return $saida;
    }

    /** Somas das colunas de valor (para os KPIs). @param  array<int, array<string, mixed>>  $porProjeto */
    public static function totais(array $porProjeto): array
    {
        $t = ['venda' => '0.00', 'aditamentos' => '0.00', 'venda_global' => '0.00', 'faturado' => '0.00', 'por_faturar' => '0.00'];
        foreach ($porProjeto as $l) {
            foreach (array_keys($t) as $k) {
                $t[$k] = bcadd($t[$k], (string) $l[$k], 2);
            }
        }

        return $t + ['faturado_pct' => self::pct($t['faturado'], $t['venda_global'])];
    }

    public static function pct(string $parte, string $todo): ?float
    {
        return bccomp($todo, '0', 2) > 0 ? round((float) bcdiv(bcmul($parte, '100', 6), $todo, 6), 2) : null;
    }
}
