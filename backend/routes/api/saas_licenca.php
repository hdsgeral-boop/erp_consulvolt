<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// SaaS — licença da empresa e cobrança por plano e tempo (ADR-074). Os nomes `licenca.*` ficam acessíveis com a
// licença expirada (ver ResolverEmpresaAtiva): é por aqui que se renova.

use App\Http\Controllers\Api\Sistema\GatewayPagamentoController;
use App\Http\Controllers\Api\Sistema\LicencaController;
use Illuminate\Support\Facades\Route;

Route::prefix('licenca')->name('licenca.')->group(function () {
    Route::controller(LicencaController::class)->group(function () {
        Route::get('estado', 'estado')->name('estado');
        Route::get('planos', 'planos')->name('planos');
        Route::post('planos', 'criarPlano')->name('planos.criar');
        Route::put('planos/{plano}', 'actualizarPlano')->whereNumber('plano')->name('planos.actualizar');
        Route::get('subscricoes', 'subscricoes')->name('subscricoes');
        Route::post('renovacao', 'renovar')->name('renovacao');
        Route::delete('subscricoes/{subscricao}', 'cancelar')->whereNumber('subscricao')->name('subscricoes.cancelar');
        Route::post('subscricoes/{subscricao}/confirmar-pagamento', 'confirmarPagamento')->whereNumber('subscricao')->name('subscricoes.confirmar');

        // Suporte: qualquer utilizador autenticado da empresa (não só o administrador — ver /minha-conta/suporte).
        Route::get('suporte', 'suporte')->name('suporte.index');
        Route::post('suporte', 'abrirSuporte')->name('suporte.store');
        Route::get('suporte/{ticket}/mensagens', 'mensagens')->whereNumber('ticket')->name('suporte.mensagens');
        Route::post('suporte/{ticket}/mensagens', 'responder')->whereNumber('ticket')->name('suporte.responder');
        Route::post('suporte/{ticket}/avaliacao', 'avaliacao')->whereNumber('ticket')->name('suporte.avaliacao');
        Route::get('suporte/anexos/{anexo}', 'baixarAnexo')->whereNumber('anexo')->name('suporte.anexos.show');
    });

    Route::controller(GatewayPagamentoController::class)->group(function () {
        Route::post('subscricoes/{subscricao}/iniciar-pagamento', 'iniciarPagamento')->whereNumber('subscricao')->name('subscricoes.iniciar_pagamento');
        Route::post('subscricoes/{subscricao}/simular-confirmacao', 'simularConfirmacao')->whereNumber('subscricao')->name('subscricoes.simular_confirmacao');
    });
});
