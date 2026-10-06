<?php

namespace Tests\Feature;

use App\Models\Empresa;
use App\Models\PecaLavandaria;
use App\Models\PlanoConta;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** M-16: importação das tabelas da lavandaria (peças, serviços e preços por peça e serviço) com simulação. */
final class POSLavandariaImportacaoTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa();
        app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PlanoConta::create(['codigo' => '6211', 'descricao' => 'Prestações de serviços', 'tipo' => 'M']));
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'lav_tabelas' => true, 'pos_lavandaria_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function csv(array $linhas): UploadedFile
    {
        $conteudo = implode("\n", array_map(fn ($l) => implode(';', $l), $linhas));

        return UploadedFile::fake()->createWithContent('tabela.csv', $conteudo);
    }

    private function importar(string $tipo, array $linhas, bool $simular)
    {
        return $this->post('/api/pos/lavandaria/importar', ['tipo' => $tipo, 'simular' => $simular ? '1' : '0', 'ficheiro' => $this->csv($linhas)], $this->s + ['Accept' => 'application/json']);
    }

    #[Test]
    public function simula_e_importa_pecas_e_servicos_ignorando_linhas_com_erros(): void
    {
        $pecas = [['Código', 'Designação', 'Tecido', 'Cor', 'Unidade', 'Preço', 'Activa'], ['', 'Camisa', 'Algodão', 'Branca', 'Peça', '1500', 'Sim'],
            ['', 'Edredão', '', '', 'Kg', '2000', 'Sim'], ['', 'Fato', '', '', 'Metro', '3000', 'Sim'], ['PC9999', 'Calça', '', '', 'Peça', '1000', 'Sim']];
        $r = $this->importar('pecas', $pecas, true)->assertOk()->json('dados');
        $this->assertSame([4, 2, 2], [$r['lidas'], $r['validas'], $r['ignoradas']]);
        $this->assertSame(0, app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PecaLavandaria::query()->count()));   // simulação não grava

        $r = $this->importar('pecas', $pecas, false)->assertOk()->json('dados');
        $this->assertSame(2, $r['criadas']);
        $camisa = app(ContextoEmpresa::class)->executarComo($this->empresa->id, fn () => PecaLavandaria::query()->where('nome', 'Camisa')->first());
        $this->assertSame('1500.00', (string) $camisa->preco);

        // reimportar a mesma designação actualiza (não duplica)
        $r = $this->importar('pecas', [$pecas[0], ['', 'Camisa', 'Algodão', 'Branca', 'Peça', '1700', 'Sim']], false)->assertOk()->json('dados');
        $this->assertSame([0, 1], [$r['criadas'], $r['actualizadas']]);

        $serv = [['Código', 'Designação', 'Grupo', 'Conta', 'IVA', 'Prazo', 'Exige orçamento', 'Activo'], ['', 'Lavagem a seco', 'Lavandaria', '6211', '14', '2', 'Não', 'Sim'],
            ['', 'Bainha', 'Alfaiataria', '7111', '14', '3', 'Sim', 'Sim']];
        $r = $this->importar('servicos', $serv, false)->assertOk()->json('dados');
        $this->assertSame([1, 1], [$r['criadas'], $r['ignoradas']]);
        $this->assertStringContainsString('classe 62', implode(' ', $r['linhas'][1]['erros']));
        $codigoServico = $this->getJson('/api/pos/lavandaria/servicos', $this->s)->json('dados.0.codigo');

        // preço específico por peça e serviço
        $r = $this->importar('precos', [['Peça', 'Serviço', 'Preço'], [$camisa->codigo, $codigoServico, '900']], false)->assertOk()->json('dados');
        $this->assertSame(1, $r['criadas']);
        $this->assertSame('900.00', (string) $camisa->refresh()->precos_servico[0]['preco']);
    }

    #[Test]
    public function exige_lav_tabelas(): void
    {
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'pos_lavandaria_view' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->post('/api/pos/lavandaria/importar', ['tipo' => 'pecas', 'ficheiro' => $this->csv([['Código'], ['']])], $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id, 'Accept' => 'application/json'])
            ->assertForbidden();
    }
}
