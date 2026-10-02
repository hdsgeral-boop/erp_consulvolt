<?php

namespace Tests\Feature;

use App\Models\Terceiro;
use App\Models\Venda;
use App\Support\Tenancy\ContextoEmpresa;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** A-04: filtro «Estado AGT» na lista de documentos de venda (mesma regra dos contadores da Facturação electrónica). */
final class VendasFiltroEstadoAgtTest extends TestCase
{
    #[Test]
    public function filtra_documentos_pelo_estado_agt_e_expoe_a_ultima_resposta(): void
    {
        $empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($empresa->id, function () {
            $cliente = Terceiro::create(['nome' => 'Cliente AGT', 'nif' => '5000000099', 'tipo' => Terceiro::CLIENTE, 'codigo_conta' => '3111']);
            $n = 0;
            $criar = function (array $fe) use ($cliente, &$n) {
                $n++;
                (new Venda)->forceFill(['tipo_documento' => 'FT', 'numero_documento' => "FT T/{$n}", 'data_emissao' => now(), 'cliente_id' => $cliente->id,
                    'total_liquido' => 100, 'total_imposto' => 14, 'total_bruto' => 114, 'valor_pago' => 0, 'valor_pendente' => 114] + $fe)->save();
            };
            $criar(['fe_regime' => true, 'fe_estado' => 'COM_ERROS', 'fe_erros' => ['E02: número de documento com formato inválido (FT T/1).']]);
            $criar(['fe_regime' => true, 'fe_estado' => 'PRONTO']);                                                           // por enviar (sem envio)
            $criar(['fe_regime' => true, 'fe_estado' => 'PRONTO', 'fe_envio' => ['estado' => 'POR_ENVIAR']]);                 // por enviar
            $criar(['fe_regime' => true, 'fe_estado' => 'PRONTO', 'fe_envio' => ['estado' => 'VALIDO', 'requestID' => 'R-1',
                'historico' => [['em' => now()->toIso8601String(), 'accao' => 'consultar', 'resultado' => 'V']]]]);
            $criar(['fe_regime' => true, 'fe_estado' => 'PRONTO', 'fe_envio' => ['estado' => 'INVALIDO', 'erros' => [['codigo' => 'E40', 'mensagem' => 'NIF do cliente inválido', 'documentNo' => 'FT T/5']]]]);
            $criar(['fe_regime' => false, 'fe_estado' => null]);                                                              // fora do regime
        });
        $perfil = $this->criarPerfil(['_v2' => true, 'vendas_faturacao_view' => true]);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $perfil->id]);
        $u->empresas()->attach($empresa->id);
        $s = $this->entrar($u) + ['X-Empresa-Id' => $empresa->id];

        $numeros = fn (string $estado) => collect($this->getJson('/api/vendas/documentos?estado_fe='.$estado, $s)->assertOk()->json('dados'))
            ->pluck('numero_documento')->sort()->values()->all();
        $this->assertSame(['FT T/1'], $numeros('COM_ERROS_LOCAIS'));
        $this->assertSame(['FT T/2', 'FT T/3'], $numeros('POR_ENVIAR'));
        $this->assertSame(['FT T/4'], $numeros('VALIDO'));
        $this->assertSame(['FT T/5'], $numeros('INVALIDO'));
        $this->assertSame([], $numeros('REJEITADO'));
        $this->getJson('/api/vendas/documentos', $s)->assertOk()->assertJsonCount(6, 'dados');
        $this->getJson('/api/vendas/documentos?estado_fe=QUALQUER', $s)->assertStatus(422);

        $valido = collect($this->getJson('/api/vendas/documentos?estado_fe=VALIDO', $s)->json('dados'))->first();
        $this->assertSame('R-1', $valido['faturacao_eletronica']['envio_detalhe']['request_id']);
        $this->assertSame('consultar', $valido['faturacao_eletronica']['envio_detalhe']['historico'][0]['accao']);
        $invalido = collect($this->getJson('/api/vendas/documentos?estado_fe=INVALIDO', $s)->json('dados'))->first();
        $this->assertSame('E40', $invalido['faturacao_eletronica']['erros_agt'][0]['codigo']);
    }
}
