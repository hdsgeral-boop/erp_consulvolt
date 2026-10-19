<?php

use App\Http\Controllers\Api\Projetos\OrganigramaMoverController;
use Illuminate\Support\Facades\Route;

// Ronda 4 — agente r4_rh_proj; incluído por routes/api.php no grupo autenticado com empresa activa.
// Organigrama do projecto: mover um membro entre posições (arrastar e largar, com «Desfazer»).
Route::post('projetos/{projeto}/organigrama/mover', [OrganigramaMoverController::class, 'mover'])->whereNumber('projeto')->name('projetos.r4.organigrama.mover');
