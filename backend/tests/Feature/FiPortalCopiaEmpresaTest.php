<?php

namespace Tests\Feature;

use App\Models\Empresa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Clone e exportar/importar de empresa — fecho da Versão Actual (ADR-071): critérios de repartição por UN, unidades
 * alocadas (criterio_id, unidade_negocio_id / business_unit_id), configuração de RH, regras de IA (base e em ficheiro),
 * configuração dos empréstimos, modelos de cartas, responsáveis polimórficos dos assuntos 5W1H e andares/portas dos
 * inventários físicos de activos. Dados fictícios.
 */
final class FiPortalCopiaEmpresaTest extends TestCase
{
    private Empresa $empresa;

    private array $s;

    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->empresa = $this->criarEmpresa(['nif' => '5419100001', 'nome' => 'Origem Fecho, Lda']);
        $u = $this->criarUtilizador(['perfil_utilizador_id' => $this->criarPerfil(['_v2' => true, 'config_backup' => true, 'config_ferramentas' => true, 'config_empresas_gerir' => true])->id]);
        $u->empresas()->attach($this->empresa->id);
        $this->s = $this->entrar($u) + ['X-Empresa-Id' => $this->empresa->id];
    }

    private function inserir(string $tabela, array $dados): int
    {
        return (int) DB::table($tabela)->insertGetId(['empresa_id' => $this->empresa->id] + array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $dados));
    }

    private function dadosDeOrigem(): void
    {
        $i = fn (string $t, array $d) => $this->inserir($t, $d);
        $this->ids['sede'] = $i('unidades_negocio', ['codigo' => 'SEDE', 'nome' => 'Sede']);
        $this->ids['lda'] = $i('unidades_negocio', ['codigo' => 'LDA', 'nome' => 'Luanda']);
        $this->ids['criterio'] = $i('criterios_reparticao_un', ['codigo' => 'AREA', 'nome' => 'Por área', 'criterio' => 'PERCENTAGEM', 'ativo' => true,
            'linhas' => [['unidade_negocio_id' => $this->ids['sede'], 'peso' => '60'], ['business_unit_id' => $this->ids['lda'], 'peso' => '40']]]);
        $alocacao = ['criterio_id' => $this->ids['criterio'], 'unidades' => [['unidade_negocio_id' => $this->ids['sede'], 'peso' => '60'], ['unidade_negocio_id' => $this->ids['lda'], 'peso' => '40']]];
        $this->ids['colab'] = $i('colaboradores', ['nome_completo' => 'Colaborador Fictício', 'estado' => 'ACTIVO', 'unidades_alocadas' => $alocacao]);
        $this->ids['venda'] = $i('vendas', ['unidades_alocadas' => $alocacao]);
        $this->ids['diario'] = $i('diarios_contabeis', ['codigo' => 'SAL', 'descricao' => 'Salários']);
        $this->ids['infotipo'] = $i('infotipos_salariais', ['tipo' => 'DESCONTO', 'nome' => 'Desconto empréstimo']);
        $this->ids['fornecedor'] = $i('terceiros', ['nome' => 'Fornecedor Fictício', 'tipo' => 'FORNECEDOR']);
        $i('configuracoes_emprestimos', ['infotipo_desconto_id' => $this->ids['infotipo'], 'diario_id' => $this->ids['diario'], 'prestacoes_max' => 12, 'exige_chefia' => true]);
        $i('configuracoes_rh', ['chave' => 'subsidio_ferias_pct', 'valor' => json_encode(50)]);
        $i('regras_internas_ia', ['nome' => 'Água', 'palavras_chave' => 'agua', 'ativo' => true]);
        $i('ficheiros_regras_ia', ['nome_regra' => 'renda', 'nome' => 'Renda', 'conteudo' => '{}', 'ativo' => true]);
        $i('modelos_cartas_pagamento', ['tipo' => 'TRANSFERENCIA', 'nome' => 'Carta-modelo', 'assunto' => 'Pagamento', 'corpo' => 'Exmos. Senhores']);
        $this->ids['assunto'] = $i('assuntos_metodo_trabalho', ['titulo' => 'Assunto fictício', 'responsaveis' => [
            ['tipo' => 'COLABORADOR', 'ref_id' => $this->ids['colab'], 'nome' => 'Colaborador Fictício'],
            ['tipo' => 'FORNECEDOR', 'ref_id' => $this->ids['fornecedor'], 'nome' => 'Fornecedor Fictício'],
            ['tipo' => 'OUTRO', 'ref_id' => 999999, 'nome' => 'Tipo desconhecido'],
            ['tipo' => 'EXTERNO', 'ref_id' => null, 'nome' => 'Consultor externo'],
        ], 'responsavel_principal' => 'COLABORADOR:'.$this->ids['colab'], 'responsavel_principal_ref' => $this->ids['colab']]);
        $this->ids['edificio'] = $i('ativos_imobilizados', ['tipo_ativo' => 'EDIFICIO']);
        $this->ids['andar'] = $i('andares_edificios', ['edificio_id' => $this->ids['edificio'], 'numero' => '1', 'nome' => '1.º andar']);
        $this->ids['porta'] = $i('portas_edificios', ['edificio_id' => $this->ids['edificio'], 'andar_id' => $this->ids['andar'], 'numero' => '101', 'nome' => 'Sala 101']);
        $this->ids['porta2'] = $i('portas_edificios', ['edificio_id' => $this->ids['edificio'], 'andar_id' => $this->ids['andar'], 'numero' => '102', 'nome' => 'Sala 102']);
        $inv = $i('inventarios_fisicos_ativos', ['edificio_id' => $this->ids['edificio'], 'ambito' => 'PORTA', 'andar_id' => $this->ids['andar'], 'porta_id' => $this->ids['porta'], 'estado' => 'ABERTO']);
        $i('linhas_inventario_fisico_ativos', ['inventario_fisico_id' => $inv, 'andar_id' => $this->ids['andar'], 'porta_id' => $this->ids['porta'],
            'porta_encontrada_id' => $this->ids['porta2'], 'resultado' => 'DESLOCADO']);
    }

    private function idPorCodigo(int $empresa, string $tabela, string $codigo): int
    {
        return (int) DB::table($tabela)->where('empresa_id', $empresa)->where('codigo', $codigo)->value('id');
    }

    private function dec(?string $v): mixed
    {
        return $v === null ? null : json_decode($v, true);
    }

    #[Test]
    public function clone_copia_criterios_configuracoes_e_modelos_com_referencias_remapeadas(): void
    {
        $this->dadosDeOrigem();
        $r = $this->postJson('/api/sistema/copias/clonar', ['nome' => 'Clone Fecho, Lda', 'nif' => '5419100002'], $this->s)->assertCreated();
        $nova = (int) $r->json('dados.empresa_destino.id');

        $sede = $this->idPorCodigo($nova, 'unidades_negocio', 'SEDE');
        $lda = $this->idPorCodigo($nova, 'unidades_negocio', 'LDA');
        $this->assertNotSame($this->ids['sede'], $sede);
        $criterio = DB::table('criterios_reparticao_un')->where('empresa_id', $nova)->first();
        $this->assertNotNull($criterio);
        $this->assertSame('AREA', $criterio->codigo);
        $linhas = $this->dec($criterio->linhas);
        $this->assertSame($sede, $linhas[0]['unidade_negocio_id']);
        $this->assertSame($lda, $linhas[1]['business_unit_id']);

        $this->assertSame(1, DB::table('configuracoes_rh')->where('empresa_id', $nova)->where('chave', 'subsidio_ferias_pct')->count());
        $emp = DB::table('configuracoes_emprestimos')->where('empresa_id', $nova)->first();
        $this->assertSame((int) DB::table('infotipos_salariais')->where('empresa_id', $nova)->value('id'), (int) $emp->infotipo_desconto_id);
        $this->assertSame($this->idPorCodigo($nova, 'diarios_contabeis', 'SAL'), (int) $emp->diario_id);
        foreach (['regras_internas_ia', 'ficheiros_regras_ia', 'modelos_cartas_pagamento'] as $t) {
            $this->assertSame(1, DB::table($t)->where('empresa_id', $nova)->count(), $t);
        }
        // movimentos e registos operacionais não entram no clone
        foreach (['colaboradores', 'vendas', 'assuntos_metodo_trabalho', 'inventarios_fisicos_ativos'] as $t) {
            $this->assertSame(0, DB::table($t)->where('empresa_id', $nova)->count(), $t);
        }
        // a origem fica intacta
        $this->assertSame($this->ids['sede'], $this->dec(DB::table('criterios_reparticao_un')->where('id', $this->ids['criterio'])->value('linhas'))[0]['unidade_negocio_id']);
    }

    #[Test]
    public function exportar_e_importar_remapeia_unidades_alocadas_responsaveis_e_inventarios(): void
    {
        $this->dadosDeOrigem();
        $r = $this->get('/api/sistema/copias/exportar', $this->s)->assertOk();
        $ficheiro = storage_path('app/copias/teste_fi_portal_'.uniqid().'.json');
        copy($r->baseResponse->getFile()->getPathname(), $ficheiro);
        @unlink($r->baseResponse->getFile()->getPathname());
        try {
            $tmp = $ficheiro.'.'.uniqid();
            copy($ficheiro, $tmp);
            $imp = $this->post('/api/sistema/copias/importar', ['ficheiro' => new UploadedFile($tmp, 'copia.json', 'application/json', null, true), 'nif' => '5419100003'],
                $this->s + ['Accept' => 'application/json'])->assertCreated();
            $nova = (int) $imp->json('dados.empresa_destino.id');
        } finally {
            @unlink($ficheiro);
        }

        $sede = $this->idPorCodigo($nova, 'unidades_negocio', 'SEDE');
        $lda = $this->idPorCodigo($nova, 'unidades_negocio', 'LDA');
        $criterio = $this->idPorCodigo($nova, 'criterios_reparticao_un', 'AREA');
        $esperada = ['criterio_id' => $criterio, 'unidades' => [['unidade_negocio_id' => $sede, 'peso' => '60'], ['unidade_negocio_id' => $lda, 'peso' => '40']]];
        $colab = DB::table('colaboradores')->where('empresa_id', $nova)->first();
        $this->assertEquals($esperada, $this->dec($colab->unidades_alocadas));
        $this->assertEquals($esperada, $this->dec(DB::table('vendas')->where('empresa_id', $nova)->value('unidades_alocadas')));

        // responsáveis polimórficos: colaborador e fornecedor remapeados; tipo desconhecido sem referência; principal segue o colaborador
        $assunto = DB::table('assuntos_metodo_trabalho')->where('empresa_id', $nova)->first();
        $fornecedor = (int) DB::table('terceiros')->where('empresa_id', $nova)->value('id');
        $resp = $this->dec($assunto->responsaveis);
        $this->assertSame([(int) $colab->id, $fornecedor, null, null], array_column($resp, 'ref_id'));
        $this->assertSame('Tipo desconhecido', $resp[2]['nome']);
        $this->assertSame('COLABORADOR:'.$colab->id, $assunto->responsavel_principal);
        $this->assertSame((int) $colab->id, (int) $assunto->responsavel_principal_ref);

        // inventário físico: andar/porta (sem FK na base) apontam para os registos da empresa nova
        $andar = (int) DB::table('andares_edificios')->where('empresa_id', $nova)->value('id');
        $portas = DB::table('portas_edificios')->where('empresa_id', $nova)->pluck('id', 'numero')->map(fn ($v) => (int) $v);
        $inv = DB::table('inventarios_fisicos_ativos')->where('empresa_id', $nova)->first();
        $this->assertSame([$andar, $portas['101']], [(int) $inv->andar_id, (int) $inv->porta_id]);
        $linha = DB::table('linhas_inventario_fisico_ativos')->where('empresa_id', $nova)->first();
        $this->assertSame([$andar, $portas['101'], $portas['102']], [(int) $linha->andar_id, (int) $linha->porta_id, (int) $linha->porta_encontrada_id]);

        foreach (['configuracoes_rh', 'configuracoes_emprestimos', 'regras_internas_ia', 'ficheiros_regras_ia', 'modelos_cartas_pagamento'] as $t) {
            $this->assertSame(1, DB::table($t)->where('empresa_id', $nova)->count(), $t);
        }
    }
}
