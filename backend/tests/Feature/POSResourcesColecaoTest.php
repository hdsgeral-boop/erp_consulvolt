<?php

namespace Tests\Feature;

use App\Http\Resources\POS\SessaoPOSResource;
use App\Http\Resources\POS\TerminalPOSResource;
use App\Http\Resources\POS\VendaPOSResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `JsonResource::collection()` instancia `new static([])` para ler `preserveKeys`: os Resources do POS (construtor
 * tipado) rebentavam com 500 em TODAS as listagens (GET /api/pos/sessoes…) quando a F0 os introduziu.
 */
class POSResourcesColecaoTest extends TestCase
{
    public function test_as_colecoes_dos_resources_do_pos_instanciam_sem_rebentar_e_vazias_resolvem(): void
    {
        foreach ([SessaoPOSResource::class, TerminalPOSResource::class, VendaPOSResource::class] as $recurso) {
            $colecao = $recurso::collection(collect());

            $this->assertSame([], $colecao->resolve(Request::create('/')), $recurso);
        }
    }

    public function test_os_resources_do_pos_aceitam_o_argumento_de_arranque_do_laravel(): void
    {
        foreach ([SessaoPOSResource::class, TerminalPOSResource::class, VendaPOSResource::class] as $recurso) {
            $this->assertInstanceOf($recurso, new $recurso([]), $recurso);
        }
    }
}
