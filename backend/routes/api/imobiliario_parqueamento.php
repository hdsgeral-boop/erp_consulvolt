<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api), a seguir a imobiliario_contratos.php.
// Gestão Imobiliária, Onda 3 (F4): parqueamento (vagas, afectação, veículos, mapa), áreas comuns (espaços, calendário) e reservas
// (conflitos, devedor, factura, recebimento, caução, vistoria, cancelamento e caducidade).

use App\Http\Controllers\Api\Imobiliario\AreasComunsController;
use App\Http\Controllers\Api\Imobiliario\ParqueamentoController;
use App\Http\Controllers\Api\Imobiliario\ReservasController;
use Illuminate\Support\Facades\Route;

Route::prefix('imobiliario')->name('imobiliario.')->group(function () {
    Route::controller(ParqueamentoController::class)->group(function () {
        Route::get('parque/listas', 'listas')->name('parque.listas');
        Route::get('parque/encargos-propostos', 'encargosPropostos')->name('parque.encargos_propostos');
        Route::post('parque/afectar', 'afectar')->name('parque.afectar');
        Route::post('parque/libertar', 'libertar')->name('parque.libertar');
        Route::put('parque/vagas/{vaga}', 'actualizar')->whereNumber('vaga')->name('parque.vagas.update');
        Route::delete('parque/vagas/{vaga}', 'eliminar')->whereNumber('vaga')->name('parque.vagas.destroy');
        Route::get('imoveis/{imovel}/parque/mapa', 'mapa')->whereNumber('imovel')->name('parque.mapa');
        Route::get('imoveis/{imovel}/parque/livres', 'livres')->whereNumber('imovel')->name('parque.livres');
        Route::post('imoveis/{imovel}/parque/gerar', 'gerar')->whereNumber('imovel')->name('parque.gerar');
        Route::post('imoveis/{imovel}/parque/vagas', 'criar')->whereNumber('imovel')->name('parque.vagas.store');
        Route::get('contratos/{contrato}/veiculos', 'veiculos')->whereNumber('contrato')->name('contratos.veiculos');
        Route::put('contratos/{contrato}/veiculos', 'gravarVeiculos')->whereNumber('contrato')->name('contratos.veiculos_gravar');
    });

    Route::controller(AreasComunsController::class)->group(function () {
        Route::get('areas/espacos', 'index')->name('areas.espacos.index');
        Route::post('areas/espacos', 'store')->name('areas.espacos.store');
        Route::put('areas/espacos/{espaco}', 'update')->whereNumber('espaco')->name('areas.espacos.update');
        Route::delete('areas/espacos/{espaco}', 'destroy')->whereNumber('espaco')->name('areas.espacos.destroy');
        Route::post('areas/espacos/{espaco}/activo', 'activo')->whereNumber('espaco')->name('areas.espacos.activo');
        Route::get('areas/espacos/{espaco}/dia', 'dia')->whereNumber('espaco')->name('areas.espacos.dia');
        Route::get('areas/resumo', 'resumo')->name('areas.resumo');
        Route::get('areas/avisos', 'avisos')->name('areas.avisos');
        Route::get('areas/calendario', 'calendario')->name('areas.calendario');
    });

    Route::controller(ReservasController::class)->group(function () {
        Route::get('reservas/verificar', 'verificar')->name('reservas.verificar');
        Route::get('reservas/contas', 'contas')->name('reservas.contas');
        Route::get('reservas/avisos', 'avisos')->name('reservas.avisos');
        Route::post('reservas/caducar', 'caducar')->name('reservas.caducar');
        Route::post('reservas/caucao/{movimento}/anular', 'anularCaucao')->whereNumber('movimento')->name('reservas.caucao_anular');
        Route::get('reservas', 'index')->name('reservas.index');
        Route::post('reservas', 'store')->name('reservas.store');
        Route::get('reservas/{reserva}', 'show')->whereNumber('reserva')->name('reservas.show');
        Route::put('reservas/{reserva}', 'update')->whereNumber('reserva')->name('reservas.update');
        Route::delete('reservas/{reserva}', 'destroy')->whereNumber('reserva')->name('reservas.destroy');
        Route::post('reservas/{reserva}/estado', 'estado')->whereNumber('reserva')->name('reservas.estado');
        Route::get('reservas/{reserva}/previsao-cancelamento', 'previsaoCancelamento')->whereNumber('reserva')->name('reservas.previsao_cancelamento');
        Route::post('reservas/{reserva}/cancelar', 'cancelar')->whereNumber('reserva')->name('reservas.cancelar');
        Route::post('reservas/{reserva}/facturar', 'facturar')->whereNumber('reserva')->name('reservas.facturar');
        Route::post('reservas/{reserva}/receber', 'receber')->whereNumber('reserva')->name('reservas.receber');
        Route::post('reservas/{reserva}/vistoria', 'vistoria')->whereNumber('reserva')->name('reservas.vistoria');
        Route::get('reservas/{reserva}/caucao', 'caucao')->whereNumber('reserva')->name('reservas.caucao');
        Route::post('reservas/{reserva}/caucao', 'movimentarCaucao')->whereNumber('reserva')->name('reservas.caucao_movimentar');
    });
});
