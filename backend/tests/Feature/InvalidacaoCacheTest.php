<?php

namespace Tests\Feature;

use App\Models\PlanoConta;
use App\Models\Produto;
use App\Services\Sistema\ServicoEmpresas;
use App\Support\Cache\ChaveCache;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * R2/R7 — invalidação em duplo tempo: um pedido concorrente que, dentro da janela da transacção, volta a guardar
 * em cache os dados antigos (ainda os confirmados) não os deixa ficar depois do COMMIT.
 */
final class InvalidacaoCacheTest extends TestCase
{
    #[Test]
    public function plano_de_contas_e_catalogo_invalidados_tambem_depois_do_commit(): void
    {
        $empresa = $this->criarEmpresa();
        $plano = ChaveCache::empresa($empresa->id, 'contabilidade', 'plano_contas');
        $catalogo = ChaveCache::empresa($empresa->id, 'logistica', 'catalogo_produtos');

        app(ContextoEmpresa::class)->executarComo($empresa->id, fn () => DB::transaction(function () use ($plano, $catalogo) {
            PlanoConta::create(['codigo' => '611', 'descricao' => 'Vendas', 'tipo' => 'M']);
            Produto::create(['codigo' => 'P1', 'nome' => 'Produto', 'preco_unitario' => 1, 'taxa_imposto' => 14]);
            // pedido concorrente na janela da transacção: volta a guardar a versão antiga
            Cache::put($plano, ['antigo'], 86400);
            Cache::put($catalogo, ['antigo'], 21600);
        }));

        $this->assertNull(Cache::get($plano));
        $this->assertNull(Cache::get($catalogo));
    }

    #[Test]
    public function acesso_retirado_e_versao_das_empresas_invalidados_depois_do_commit_com_incremento_atomico(): void
    {
        $empresa = $this->criarEmpresa();
        $u = $this->criarUtilizador();
        $u->empresas()->attach($empresa->id);
        $servico = app(ServicoEmpresas::class);
        $this->assertSame([$empresa->id], $servico->idsAcessiveis($u));
        $chave = ChaveCache::utilizador($u->id, 'empresas:v'.(int) Cache::get('empresas:versao', 1));

        DB::transaction(function () use ($u, $empresa, $chave) {
            $u->empresas()->detach($empresa->id);
            $servico = app(ServicoEmpresas::class);
            $servico->invalidarUtilizador($u->id);
            Cache::put($chave, [$empresa->id], 3600);   // concorrente ainda vê o acesso
        });
        $this->assertNull(Cache::get($chave));
        $this->assertSame([], $servico->idsAcessiveis($u->refresh()));

        // R7: versão sem valor → add(1) + increment; dentro de uma transacção sobe agora e outra vez depois do commit
        Cache::forget('empresas:versao');
        $servico->invalidarTodos();
        $this->assertSame(3, (int) Cache::get('empresas:versao'));   // sem transacção da aplicação, o «depois do commit» corre logo
        DB::transaction(fn () => $servico->invalidarTodos());
        $this->assertSame(5, (int) Cache::get('empresas:versao'));
    }
}
