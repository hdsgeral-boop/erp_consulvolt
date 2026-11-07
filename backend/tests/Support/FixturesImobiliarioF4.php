<?php

namespace Tests\Support;

use App\Models\Empresa;
use App\Models\PlanoConta;
use App\Models\Produto;
use App\Models\Terceiro;
use App\Support\Tenancy\ContextoEmpresa;
use Carbon\Carbon;

/**
 * Montagem comum dos testes da Onda 3 (F4) da Gestão Imobiliária: empresa, plano de contas mínimo, cliente, artigo de serviço isento,
 * sessões com permissões, imóvel, fracções, contratos e configuração. A data é fixada em 2026-03-20 (sexta-feira).
 */
trait FixturesImobiliarioF4
{
    private Empresa $empresa;

    /** @var array<string, mixed> */
    private array $s;

    /** @var list<string> */
    private array $perms = [];

    private int $cliente;

    private int $cliente2;

    private int $artigo;

    private int $artigoComIva;

    protected function montar(): void
    {
        $this->travelTo(Carbon::parse('2026-03-20 10:00:00'));
        $this->empresa = $this->criarEmpresa(['nif' => '5417000555']);
        $this->ctx(function () {
            foreach (['311' => 'Clientes', '3452' => 'IVA liquidado', '4311' => 'Banco', '3711' => 'Cauções de terceiros', '611' => 'Vendas', '7911' => 'Outros proveitos'] as $c => $d) {
                PlanoConta::create(['codigo' => (string) $c, 'descricao' => $d, 'tipo' => 'M']);
            }
            $this->cliente = (int) Terceiro::create(['nome' => 'Inquilino Fictício', 'nif' => '5000000555', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->cliente2 = (int) Terceiro::create(['nome' => 'Segundo Inquilino', 'nif' => '5000000556', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '311'])->id;
            $this->artigo = (int) Produto::create(['codigo' => 'ESPACO', 'nome' => 'Utilização de espaço comum', 'preco_unitario' => 0, 'taxa_imposto' => 0, 'codigo_isencao_fe' => 'M14',
                'codigo_conta' => '611', 'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
            $this->artigoComIva = (int) Produto::create(['codigo' => 'ESPIVA', 'nome' => 'Espaço com IVA', 'preco_unitario' => 0, 'taxa_imposto' => 14, 'codigo_conta' => '611',
                'conta_iva_liquidado' => '3452', 'movimenta_stock' => false])->id;
        });
        $this->perms = ['imo_imoveis_view', 'imo_ocupacao_view', 'imo_contratos_view', 'imo_rendas_view', 'imo_parque_view', 'imo_areas_view', 'imo_modelos_view', 'imo_imovel_editar',
            'imo_fraccao_editar', 'imo_estado_mudar', 'imo_contrato_editar', 'imo_contrato_activar', 'imo_contrato_terminar', 'imo_renda_gerar', 'imo_renda_aprovar', 'imo_renda_facturar',
            'imo_vaga_editar', 'imo_vaga_afectar', 'imo_vaga_veiculos', 'imo_area_editar', 'imo_reserva_marcar', 'imo_reserva_aprovar', 'imo_reserva_devedor', 'imo_reserva_facturar',
            'imo_reserva_receber', 'imo_reserva_vistoria', 'imo_reserva_caucao', 'imo_reserva_cancelar', 'imo_taxas_editar'];
        $this->s = $this->sessao($this->perms);
    }

    /** @template T @param  callable(): T  $f  @return T */
    private function ctx(callable $f, ?int $empresa = null): mixed
    {
        return app(ContextoEmpresa::class)->executarComo($empresa ?? $this->empresa->id, $f);
    }

    /** @param  list<string>  $permissoes  @return array<string, mixed> */
    private function sessao(array $permissoes, ?Empresa $empresa = null): array
    {
        $empresa ??= $this->empresa;
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true] + array_fill_keys($permissoes, true))->id]);
        $u->empresas()->attach($empresa->id);

        return $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];
    }

    private function imovel(string $nome = 'Edifício Girassol', ?array $h = null): int
    {
        return $this->postJson('/api/imobiliario/imoveis', ['nome' => $nome, 'tipo' => 'PREDIO', 'papel' => 'PROPRIO', 'n_pisos' => 2], $h ?? $this->s)->assertCreated()->json('dados.id');
    }

    private function fracao(int $imovel, string $nome = '101', ?array $h = null): int
    {
        return $this->postJson("/api/imobiliario/imoveis/{$imovel}/fracoes", ['designacao' => $nome, 'renda_referencia' => 100000], $h ?? $this->s)->assertCreated()->json('dados.id');
    }

    /** @param  array<string, mixed>  $o */
    private function corpo(int $fracao, array $o = []): array
    {
        return $o + ['tipo' => 'ARRENDAMENTO', 'ambito' => 'FRACCAO', 'fracao_id' => $fracao, 'terceiro_id' => $this->cliente, 'inicio' => '2026-01-16', 'renda' => 100000,
            'periodicidade' => 'MENSAL', 'dia_vencimento' => 5, 'antecipada' => true, 'finalidade_fiscal' => 'HABITACAO', 'retencao_ip' => true, 'caucao' => 0];
    }

    /** @param  array<string, mixed>  $corpo  @return array<string, mixed> */
    private function contrato(array $corpo, bool $activar = true): array
    {
        $c = $this->postJson('/api/imobiliario/contratos', $corpo, $this->s)->assertCreated()->json('dados');
        if ($activar) {
            $this->postJson("/api/imobiliario/contratos/{$c['id']}/activar", [], $this->s)->assertOk();
        }

        return $c;
    }

    private function configurar(?int $artigo = null): void
    {
        $this->putJson('/api/imobiliario/configuracao', ['produto_renda_id' => $this->artigo, 'produto_reserva_id' => $artigo ?? $this->artigo, 'conta_caucao_passivo' => '3711',
            'conta_caucao_receita' => '7911'], $this->s)->assertOk();
    }
}
