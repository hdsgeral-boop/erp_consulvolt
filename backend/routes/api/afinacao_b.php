<?php

use App\Http\Controllers\Api\POS\LavandariaController;
use App\Http\Controllers\Api\Projetos\ExecucaoController;
use App\Http\Controllers\Api\RH\EstruturaController;
use App\Http\Controllers\Api\RH\PortalColaboradorController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Afinação da Fase 5 — RH, Projectos, Orçamento, Activos, Lavandaria e Estrutura (ADR-064).
// (Os restantes acertos desta ronda são nas rotas já existentes: formatos das respostas, nomes, filtros e paginação.)

Route::prefix('rh/portal')->name('rh.portal.')->controller(PortalColaboradorController::class)->group(function () {
    Route::get('ausencias', 'ausencias')->name('ausencias');          // o próprio colaborador
    Route::get('dependentes', 'dependentes')->name('dependentes');    // o próprio colaborador
    Route::get('avaliacoes', 'avaliacoes')->name('avaliacoes');       // o próprio colaborador
    Route::get('utilizadores', 'utilizadores')->name('utilizadores'); // rh_portal_gestao_view | rh_portal_aprovar
});

// est_mapa_view | est_mapa | est_estrutura_view; massa salarial só com est_ver_salarios
Route::get('rh/estrutura/mapa', [EstruturaController::class, 'mapa'])->name('rh.estrutura.mapa');

// leitura do projecto (ProjetoController::LER)
Route::get('projetos/{projeto}/equipamentos', [ExecucaoController::class, 'equipamentos'])->whereNumber('projeto')->name('projetos.equipamentos');

// permissões de consulta da lavandaria (inclui lav_ordens)
Route::get('pos/lavandaria/colaboradores', [LavandariaController::class, 'colaboradores'])->name('pos.lavandaria.colaboradores');
