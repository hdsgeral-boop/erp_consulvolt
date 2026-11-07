<?php

namespace Tests\Support;

use App\Models\AtivoImobilizado;
use App\Models\CategoriaAtivo;
use App\Models\Fracao;
use App\Models\Imovel;
use App\Models\PlanoConta;

/**
 * Montagem comum dos testes da Onda 5 (F6) da Gestão Imobiliária: junta à da F4 (empresa, plano de contas, cliente, artigo isento, contrato) as contas do
 * imobilizado, da compra e da alienação, a categoria do activo, um imóvel próprio ligado a um edifício do imobilizado com amortizações, duas fracções
 * (60 e 40 m²) e as permissões do módulo dos negócios. A data é fixada em 2026-03-20.
 */
trait FixturesImobiliarioF6
{
    use FixturesImobiliarioF4;

    private int $categoria;

    /** @var list<string> */
    private const PERMS_NEGOCIOS = ['imo_negocios_view', 'imo_neg_editar', 'imo_neg_aprovar', 'imo_neg_fase', 'imo_neg_concluir', 'imo_neg_abater', 'imo_neg_pagamento', 'imo_contrato_terminar',
        'imo_neg_editar'];

    protected function montarNegocios(): void
    {
        $this->montar();
        $this->ctx(function () {
            foreach (['121' => 'Edifícios e outras construções', '181' => 'Amortizações acumuladas', '681' => 'Gastos de amortização', '3791' => 'Credores de imobilizado',
                '7921' => 'Mais-valias na alienação', '6921' => 'Menos-valias na alienação'] as $c => $d) {
                PlanoConta::create(['codigo' => $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->categoria = (int) CategoriaAtivo::create(['nome' => 'Edifícios', 'taxa_anual' => 2, 'vida_util_padrao' => 50, 'conta_ativo' => '121', 'conta_gasto' => '681',
                'conta_amortizacao_acumulada' => '181'])->id;
        });
        $this->s = $this->sessao(array_merge($this->perms, self::PERMS_NEGOCIOS));
    }

    private function configurarNegocios(bool $completa = true): void
    {
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => $this->artigo, 'produto_reserva_id' => $this->artigo, 'conta_caucao_passivo' => '3711', 'conta_caucao_receita' => '7911']
            + ($completa ? ['produto_venda_imovel_id' => $this->artigo, 'conta_mais_valia' => '7921', 'conta_menos_valia' => '6921', 'conta_compra_contrapartida' => '3791'] : []), $this->s)->assertOk();
    }

    /** Imóvel próprio (criado pelo cadastro) ligado a um edifício do imobilizado de 100 000 000 com 40 000 000 já amortizados, com duas fracções (60 e 40 m²). */
    private function imovelComActivo(string $nome = 'Edifício Sede'): array
    {
        $im = $this->imovel($nome);
        $f1 = $this->fracao($im, '101');
        $f2 = $this->fracao($im, '102');
        $ativo = $this->ctx(function () use ($im, $f1, $f2) {
            Fracao::query()->whereKey($f1)->update(['area_util' => 60, 'area_bruta' => 70]);
            Fracao::query()->whereKey($f2)->update(['area_util' => 40, 'area_bruta' => 50]);
            $a = AtivoImobilizado::create(['codigo' => 'EDF-1', 'descricao' => 'Edifício Sede', 'tipo_ativo' => 'EDIFICIO', 'propriedade' => 'PROPRIO', 'categoria_ativo_id' => $this->categoria,
                'data_aquisicao' => '2020-01-01', 'valor_aquisicao' => '100000000.00', 'valor_residual' => 0, 'vida_util' => 600, 'vida_util_restante' => 600,
                'amortizacao_acumulada_inicial' => '40000000.00', 'amortizacao_acumulada' => '40000000.00', 'estado' => 'ACTIVO']);
            Imovel::query()->whereKey($im)->update(['ativo_edificio_id' => $a->id]);

            return (int) $a->id;
        });

        return ['imovel' => $im, 'f1' => $f1, 'f2' => $f2, 'ativo' => $ativo];
    }

    /** @param  array<string, mixed>  $o @return array<string, mixed> o negócio criado */
    private function negocio(array $o): array
    {
        return $this->postJson('/api/imobiliario/negocios', $o, $this->s)->assertCreated()->json('dados');
    }

    private function aprovarNeg(int $id): void
    {
        $this->postJson("/api/imobiliario/negocios/{$id}/aprovar", [], $this->s)->assertOk();
    }

    /** Aprova, preenche notário, valor patrimonial e data de escritura e avança até à escritura. @param  array<string, mixed>  $extra */
    private function ateEscritura(int $id, array $extra = []): array
    {
        $this->aprovarNeg($id);
        $this->putJson("/api/imobiliario/negocios/{$id}", $extra + ['vpt' => 35000000, 'data_escritura' => '2026-03-18', 'notario' => 'Cartório Notarial n.º 1'], $this->s)->assertOk();

        return $this->postJson("/api/imobiliario/negocios/{$id}/avancar", ['fase' => 'ESCRITURA'], $this->s)->assertOk()->json('dados');
    }
}
