<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api), a seguir a imobiliario_obras.php.
// Gestão Imobiliária, Onda 5 (F6): negócios de compra e venda (fases, impostos da transmissão, encargos, pagamentos, conclusão, abate do bem vendido).

use App\Http\Controllers\Api\Imobiliario\NegociosController;
use Illuminate\Support\Facades\Route;

Route::prefix('imobiliario')->name('imobiliario.')->group(function () {
    Route::controller(NegociosController::class)->group(function () {
        Route::get('negocios/listas', 'listas')->name('negocios.listas');
        Route::get('negocios/categorias', 'categorias')->name('negocios.categorias');
        Route::get('negocios/resumo', 'resumo')->name('negocios.resumo');
        Route::get('negocios/avisos', 'avisos')->name('negocios.avisos');
        Route::get('negocios/simular', 'simular')->name('negocios.simular');
        Route::get('negocios', 'index')->name('negocios.index');
        Route::post('negocios', 'store')->name('negocios.store');
        Route::get('negocios/{negocio}', 'show')->whereNumber('negocio')->name('negocios.show');
        Route::put('negocios/{negocio}', 'update')->whereNumber('negocio')->name('negocios.update');
        Route::delete('negocios/{negocio}', 'destroy')->whereNumber('negocio')->name('negocios.destroy');
        Route::put('negocios/{negocio}/custos', 'custos')->whereNumber('negocio')->name('negocios.custos');
        Route::post('negocios/{negocio}/aprovar', 'aprovar')->whereNumber('negocio')->name('negocios.aprovar');
        Route::post('negocios/{negocio}/avancar', 'avancar')->whereNumber('negocio')->name('negocios.avancar');
        Route::post('negocios/{negocio}/cancelar', 'cancelar')->whereNumber('negocio')->name('negocios.cancelar');
        Route::post('negocios/{negocio}/contratos', 'contratos')->whereNumber('negocio')->name('negocios.contratos');
        Route::post('negocios/{negocio}/concluir', 'concluir')->whereNumber('negocio')->name('negocios.concluir');
        Route::post('negocios/{negocio}/abater', 'abater')->whereNumber('negocio')->name('negocios.abater');
        Route::post('negocios/{negocio}/pagamentos', 'pagar')->whereNumber('negocio')->name('negocios.pagamentos');
        Route::post('negocios/{negocio}/pagamentos/{pagamento}/anular', 'anularPagamento')->whereNumber(['negocio', 'pagamento'])->name('negocios.pagamentos_anular');
    });
});
