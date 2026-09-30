<?php

namespace App\Services\POS\Lavandaria;

use App\Exceptions\ErroNegocio;
use App\Models\ConfiguracaoLavandaria;
use App\Models\Produto;
use App\Services\Contabilidade\ServicoPlanoContas;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Definições da lavandaria (lav_settings, DEF_PADRAO e lavGravarDefinicoes, js/lavandaria.js:57-61, 1442-1486), com as correcções:
 *   - contas validadas no servidor (movimento; taxas na classe 62, indemnizações na classe 7) — o legado só filtrava a lista no ecrã;
 *   - sem conta das taxas, as taxas não se criam (erro claro); o legado criava o produto sem conta e a integração falhava depois.
 * Nota de esquema: configuracoes_lavandaria.fator_prazo_urgencia é inteiro (devia ser decimal): guarda-se em percentagem
 * (0,5 → 50) e lê-se tanto em percentagem como em fracção, para continuar certo depois de o tipo ser corrigido.
 */
final class ServicoConfigLavandaria
{
    public const PADRAO = [
        'taxa_armazenagem_ativa' => true, 'dias_armazenagem_gratis' => 30, 'percentagem_armazenagem_dia' => '2.00', 'percentagem_adiantamento' => '50.00',
        'percentagem_urgencia' => '50.00', 'fator_prazo_urgencia' => 0.5, 'valor_taxa_recolha' => '0.00', 'valor_taxa_entrega' => '0.00', 'faturar_no_adiantamento' => true,
        'conta_compensacao' => null, 'conta_extras' => null, 'taxa_extras' => '14.00', 'dias_reclamacao' => 7,
    ];

    public function __construct(private readonly ServicoPlanoContas $plano) {}

    /** @return array<string, mixed> */
    public function obter(): array
    {
        $c = ConfiguracaoLavandaria::query()->first();
        if (! $c) {
            return self::PADRAO;
        }
        $v = fn (string $k) => $c->{$k} ?? self::PADRAO[$k];
        $fator = $c->fator_prazo_urgencia === null ? self::PADRAO['fator_prazo_urgencia'] : (float) $c->fator_prazo_urgencia;

        return [
            'taxa_armazenagem_ativa' => (bool) $v('taxa_armazenagem_ativa'), 'dias_armazenagem_gratis' => (int) $v('dias_armazenagem_gratis'),
            'percentagem_armazenagem_dia' => RegrasLavandaria::dinheiro($v('percentagem_armazenagem_dia')),
            'percentagem_adiantamento' => RegrasLavandaria::dinheiro($v('percentagem_adiantamento')),
            'percentagem_urgencia' => RegrasLavandaria::dinheiro($v('percentagem_urgencia')),
            'fator_prazo_urgencia' => $fator > 1 ? $fator / 100 : $fator,
            'valor_taxa_recolha' => RegrasLavandaria::dinheiro($v('valor_taxa_recolha')), 'valor_taxa_entrega' => RegrasLavandaria::dinheiro($v('valor_taxa_entrega')),
            'faturar_no_adiantamento' => (bool) $v('faturar_no_adiantamento'), 'conta_compensacao' => $c->conta_compensacao ?: null,
            'conta_extras' => $c->conta_extras ?: null, 'taxa_extras' => RegrasLavandaria::dinheiro($v('taxa_extras')), 'dias_reclamacao' => (int) $v('dias_reclamacao'),
        ];
    }

    /** @param  array<string, mixed>  $d */
    public function guardar(array $d): array
    {
        $atual = $this->obter();
        $n = array_merge($atual, array_intersect_key($d, self::PADRAO));
        foreach (['dias_armazenagem_gratis', 'dias_reclamacao'] as $k) {
            if ((int) $n[$k] < 0) {
                throw new ErroNegocio('Os prazos não podem ser negativos.', 'DEFINICAO_INVALIDA', 422, ['campo' => $k]);
            }
        }
        foreach (['percentagem_armazenagem_dia', 'percentagem_urgencia', 'valor_taxa_recolha', 'valor_taxa_entrega', 'taxa_extras'] as $k) {
            if ((float) $n[$k] < 0) {
                throw new ErroNegocio('Percentagens e taxas não podem ser negativas.', 'DEFINICAO_INVALIDA', 422, ['campo' => $k]);
            }
        }
        if ((float) $n['percentagem_adiantamento'] < 0 || (float) $n['percentagem_adiantamento'] > 100) {
            throw new ErroNegocio('O adiantamento mínimo do Consumidor Final tem de estar entre 0 e 100 %.', 'DEFINICAO_INVALIDA', 422, ['campo' => 'percentagem_adiantamento']);
        }
        if ((float) $n['fator_prazo_urgencia'] < 0.1 || (float) $n['fator_prazo_urgencia'] > 1) {
            throw new ErroNegocio('O prazo do urgente é uma fracção do prazo normal entre 0,1 e 1.', 'DEFINICAO_INVALIDA', 422, ['campo' => 'fator_prazo_urgencia']);
        }
        $this->validarConta($n['conta_extras'] ?? null, '62', 'das taxas (proveitos, classe 62)', 'conta_extras');
        $this->validarConta($n['conta_compensacao'] ?? null, '7', 'das indemnizações por danos (custo, classe 7)', 'conta_compensacao');

        return DB::transaction(function () use ($n) {
            ConfiguracaoLavandaria::query()->updateOrCreate([], [
                'taxa_armazenagem_ativa' => (bool) $n['taxa_armazenagem_ativa'], 'dias_armazenagem_gratis' => (int) $n['dias_armazenagem_gratis'],
                'percentagem_armazenagem_dia' => $n['percentagem_armazenagem_dia'], 'percentagem_adiantamento' => $n['percentagem_adiantamento'],
                'percentagem_urgencia' => $n['percentagem_urgencia'], 'fator_prazo_urgencia' => (int) round((float) $n['fator_prazo_urgencia'] * 100),
                'valor_taxa_recolha' => $n['valor_taxa_recolha'], 'valor_taxa_entrega' => $n['valor_taxa_entrega'],
                'faturar_no_adiantamento' => (bool) $n['faturar_no_adiantamento'], 'conta_compensacao' => $n['conta_compensacao'] ?: null,
                'conta_extras' => $n['conta_extras'] ?: null, 'taxa_extras' => $n['taxa_extras'], 'dias_reclamacao' => (int) $n['dias_reclamacao'],
                'atualizado_por' => Auth::user()?->nome_utilizador,
            ]);
            // conta e IVA das taxas já criadas (lavandaria.js:1481-1483)
            $codigos = array_column(RegrasLavandaria::EXTRAS, 0);
            foreach (Produto::query()->whereIn('codigo', $codigos)->get() as $p) {
                $p->update(array_filter(['codigo_conta' => $n['conta_extras'] ?: null, 'taxa_imposto' => $n['taxa_extras']], fn ($x) => $x !== null));
            }

            return $this->obter();
        });
    }

    private function validarConta(?string $conta, string $classe, string $nome, string $campo): void
    {
        if (! $conta) {
            return;
        }
        $this->plano->contaDeMovimento($conta);
        if (! str_starts_with($conta, $classe)) {
            throw new ErroNegocio("A conta {$nome} tem de ser da classe {$classe}.", 'CONTA_CLASSE_INVALIDA', 422, ['campo' => $campo]);
        }
    }
}
