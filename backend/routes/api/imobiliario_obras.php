<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api), a seguir a imobiliario_parqueamento.php.
// Gestão Imobiliária, Onda 4 (F5): fichas de apetrechamento, bens da fracção, ordens de trabalho (necessidades, pedido de material, recepção ligada,
// estados, débito ao inquilino, danos de reservas) e planos de manutenção e vistoria.

use App\Http\Controllers\Api\Imobiliario\ApetrechamentoController;
use App\Http\Controllers\Api\Imobiliario\ObrasController;
use App\Http\Controllers\Api\Imobiliario\PlanosManutencaoController;
use Illuminate\Support\Facades\Route;

Route::prefix('imobiliario')->name('imobiliario.')->group(function () {
    Route::controller(ApetrechamentoController::class)->group(function () {
        Route::get('fichas', 'index')->name('fichas.index');
        Route::post('fichas', 'store')->name('fichas.store');
        Route::get('fichas/{ficha}', 'show')->whereNumber('ficha')->name('fichas.show');
        Route::put('fichas/{ficha}', 'update')->whereNumber('ficha')->name('fichas.update');
        Route::delete('fichas/{ficha}', 'destroy')->whereNumber('ficha')->name('fichas.destroy');
        Route::get('fracoes/{fracao}/bens', 'bens')->whereNumber('fracao')->name('bens.index');
        Route::post('fracoes/{fracao}/bens', 'gravarBem')->whereNumber('fracao')->name('bens.store');
        Route::post('fracoes/{fracao}/bens/trazer-imobilizado', 'trazer')->whereNumber('fracao')->name('bens.trazer');
        Route::put('fracoes/{fracao}/bens/{bem}', 'actualizarBem')->whereNumber(['fracao', 'bem'])->name('bens.update');
        Route::delete('fracoes/{fracao}/bens/{bem}', 'apagarBem')->whereNumber(['fracao', 'bem'])->name('bens.destroy');
        Route::get('fracoes/{fracao}/comparar-ficha', 'comparar')->whereNumber('fracao')->name('bens.comparar');
    });

    Route::controller(ObrasController::class)->group(function () {
        Route::get('obras/listas', 'listas')->name('obras.listas');
        Route::get('obras/artigos', 'artigos')->name('obras.artigos');
        Route::get('obras/armazens', 'armazens')->name('obras.armazens');
        Route::get('obras/resumo', 'resumo')->name('obras.resumo');
        Route::get('obras/avisos', 'avisos')->name('obras.avisos');
        Route::get('obras/ordens', 'index')->name('obras.index');
        Route::post('obras/ordens', 'store')->name('obras.store');
        Route::get('obras/ordens/{ordem}', 'show')->whereNumber('ordem')->name('obras.show');
        Route::put('obras/ordens/{ordem}', 'update')->whereNumber('ordem')->name('obras.update');
        Route::delete('obras/ordens/{ordem}', 'destroy')->whereNumber('ordem')->name('obras.destroy');
        Route::post('obras/ordens/{ordem}/aprovar', 'aprovar')->whereNumber('ordem')->name('obras.aprovar');
        Route::get('obras/ordens/{ordem}/necessidades', 'necessidades')->whereNumber('ordem')->name('obras.necessidades');
        Route::put('obras/ordens/{ordem}/necessidades', 'gravarNecessidades')->whereNumber('ordem')->name('obras.necessidades_gravar');
        Route::get('obras/ordens/{ordem}/plano-pedido', 'planoPedido')->whereNumber('ordem')->name('obras.plano_pedido');
        Route::post('obras/ordens/{ordem}/pedir', 'pedir')->whereNumber('ordem')->name('obras.pedir');
        Route::post('obras/ordens/{ordem}/sincronizar', 'sincronizar')->whereNumber('ordem')->name('obras.sincronizar');
        Route::post('obras/ordens/{ordem}/iniciar', 'iniciar')->whereNumber('ordem')->name('obras.iniciar');
        Route::post('obras/ordens/{ordem}/concluir', 'concluir')->whereNumber('ordem')->name('obras.concluir');
        Route::post('obras/ordens/{ordem}/cancelar', 'cancelar')->whereNumber('ordem')->name('obras.cancelar');
        Route::post('obras/ordens/{ordem}/debitar', 'debitar')->whereNumber('ordem')->name('obras.debitar');
        Route::post('obras/ordens/{ordem}/desfazer-debito', 'desfazerDebito')->whereNumber('ordem')->name('obras.desfazer_debito');
        Route::post('obras/fracoes/{fracao}/abrir-apetrechamento', 'abrirApetrechamento')->whereNumber('fracao')->name('obras.abrir_apetrechamento');
        Route::post('obras/vistorias/{vistoria}/ordem', 'deVistoria')->whereNumber('vistoria')->name('obras.de_vistoria');
    });

    Route::controller(PlanosManutencaoController::class)->group(function () {
        Route::get('planos', 'index')->name('planos.index');
        Route::post('planos', 'store')->name('planos.store');
        Route::get('planos/propor-datas', 'propor')->name('planos.propor');
        Route::post('planos/gerar-todos', 'gerarTodos')->name('planos.gerar_todos');
        Route::get('planos/{plano}', 'show')->whereNumber('plano')->name('planos.show');
        Route::put('planos/{plano}', 'update')->whereNumber('plano')->name('planos.update');
        Route::delete('planos/{plano}', 'destroy')->whereNumber('plano')->name('planos.destroy');
        Route::get('planos/{plano}/calendario', 'calendario')->whereNumber('plano')->name('planos.calendario');
        Route::post('planos/{plano}/gerar', 'gerar')->whereNumber('plano')->name('planos.gerar');
    });
});
