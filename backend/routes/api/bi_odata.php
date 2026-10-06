<?php

use App\Http\Controllers\Api\Integracoes\FeedODataController;
use Illuminate\Support\Facades\Route;

// Incluído por routes/api.php FORA do grupo autenticado (prefixo /api): feed OData de leitura do Power BI (decisão 25).
// A autenticação é feita no controlador com o token de leitura da empresa (tokens_bi), não com o Sanctum.
Route::get('bi/odata/{conjunto?}', FeedODataController::class)
    ->where('conjunto', '\$metadata|[a-z_]{1,40}')
    ->name('bi.odata');
