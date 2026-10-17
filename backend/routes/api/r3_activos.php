<?php

use App\Http\Controllers\Api\Ativos\TarefasOrdemManutencaoController;
use Illuminate\Support\Facades\Route;

// Ronda 3 (agente r3_activos) — incluído por routes/api.php no grupo com empresa activa (prefixo /api).
// Manutenção: gestão das tarefas da ordem (concluir/reabrir com quem/quando, acrescentar, retirar).

Route::prefix('ativos/manutencao')->name('ativos.manutencao.')->controller(TarefasOrdemManutencaoController::class)->group(function () {
    Route::post('ordens/{ordem}/tarefas', 'acrescentar')->whereNumber('ordem')->name('ordens.tarefas.store');
    Route::post('ordens/{ordem}/tarefas/{tarefa}/estado', 'estado')->whereNumber('ordem')->where('tarefa', '[a-z0-9]{1,20}')->name('ordens.tarefas.estado');
    Route::delete('ordens/{ordem}/tarefas/{tarefa}', 'retirar')->whereNumber('ordem')->where('tarefa', '[a-z0-9]{1,20}')->name('ordens.tarefas.destroy');
});
