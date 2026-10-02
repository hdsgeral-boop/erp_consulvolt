<?php

namespace App\Jobs\Sistema;

use App\Support\Cache\Batimentos;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Batimento do worker (R11): agendado pelo scheduler na fila de menor prioridade; ao ser processado grava a hora em
 * cache. O /api/saude marca FALHA se o último batimento for antigo (worker parado ou fila encravada).
 */
final class BatimentoWorker implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function handle(): void
    {
        Batimentos::registar(Batimentos::WORKER);
    }
}
