<?php

use App\Http\Controllers\Api\Portal\PortalAutonomoController;
use Illuminate\Support\Facades\Route;

// Fecho: portal autónomo e núcleo — ADR-071; incluído por routes/api.php no grupo com empresa activa.

// Portal autónomo do colaborador (portal.html do legado novo): guarda de entrada (colaborador associado, aprovações
// pendentes ou aprovador RH). Os dados vêm das rotas /rh/portal/*.
Route::get('portal/acesso', [PortalAutonomoController::class, 'acesso'])->name('portal.acesso');
