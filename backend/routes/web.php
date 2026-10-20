<?php

use App\Http\Controllers\FrontendController;
use Illuminate\Support\Facades\Route;

// O frontend é um SPA React (frontend/, compilado com o Vite para backend/public/build). O Laravel entrega a página de
// entrada certa por host (ERP ou plataforma SaaS) para qualquer caminho que não seja /api, /up ou /build; o react-router
// trata do resto. As rotas /api vivem em routes/api.php. Este ficheiro é carregado SEM o grupo de middleware `web`
// (ver bootstrap/app.php): a página não precisa de sessão nem de cookies.
// Os activos compilados ficam em public/build (directório por omissão do Vite do Laravel).

Route::get('/{caminho?}', FrontendController::class)
    ->where('caminho', '(?!api(?:/|$)|up$|build(?:/|$)).*');
