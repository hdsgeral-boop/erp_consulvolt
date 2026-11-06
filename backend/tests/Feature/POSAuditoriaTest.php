<?php

namespace Tests\Feature;

use App\Models\ConfiguracaoPOS;
use App\Models\Empresa;
use App\Models\EstadiaHotel;
use App\Models\PedidoLavandaria;
use App\Models\SenhaPOSArmazem;
use App\Models\SessaoPOS;
use App\Models\TerminalPOS;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * F0 do POS: terminais, sessões, definições, estadias e ordens de lavandaria ficam na auditoria automática (trait Auditavel nos
 * modelos). As senhas do armazém (linhas de alta frequência) ficam de fora de propósito; os itens de venda também não são modelos POS.
 */
final class POSAuditoriaTest extends TestCase
{
    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5417000055']);
    }

    private function acoes(string $tabela): array
    {
        return DB::table('logs_auditoria')->where('tabela', $tabela)->where('empresa_id', $this->empresa->id)->orderBy('id')->pluck('acao')->all();
    }

    #[Test]
    public function os_modelos_pos_registam_criar_e_alterar_na_auditoria(): void
    {
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, function () {
            $t = TerminalPOS::create(['codigo' => 'T01', 'nome' => 'Caixa 1', 'tipo' => 'LOJA', 'ativo' => true]);
            $t->update(['nome' => 'Caixa principal']);
            $s = SessaoPOS::create(['terminal_pos_id' => $t->id, 'codigo_terminal' => 'T01', 'codigo_sessao' => 'T01-2026-0001', 'estado' => 'ABERTA']);
            $s->update(['estado' => 'FECHADA', 'numero_z' => 'Z-T01-2026-0001']);
            $c = ConfiguracaoPOS::create(['tolerancia_desvio' => 5]);
            $c->update(['tolerancia_desvio' => 10]);
            $e = EstadiaHotel::create(['terminal_pos_id' => $t->id, 'estado' => 'ABERTA', 'modo' => 'DIA']);
            $e->update(['estado' => 'FECHADA']);
            $o = PedidoLavandaria::create(['numero_encomenda' => 'OS/T01/2026/00001', 'estado' => 'RECEBIDA']);
            $o->update(['estado' => 'PRONTA']);
            SenhaPOSArmazem::create(['armazem_id' => $this->armazemId(), 'data' => now()->toDateString(), 'numero' => 1, 'codigo' => 'P-001', 'estado' => 'AGUARDA', 'chamadas' => 0]);
        });

        foreach (['terminais_pos', 'sessoes_pos', 'configuracoes_pos', 'estadias_hotel', 'pedidos_lavandaria'] as $tabela) {
            $this->assertSame(['Criou', 'Atualizou'], $this->acoes($tabela), $tabela);
        }
        $this->assertSame([], $this->acoes('senhas_pos_armazem'), 'senhas: alta frequência, sem auditoria');
        $this->assertSame('POS', DB::table('logs_auditoria')->where('tabela', 'sessoes_pos')->value('modulo'));
    }

    private function armazemId(): int
    {
        return (int) DB::table('armazens')->insertGetId(['empresa_id' => $this->empresa->id, 'nome' => 'Armazém', 'criado_em' => now(), 'atualizado_em' => now()]);
    }
}
