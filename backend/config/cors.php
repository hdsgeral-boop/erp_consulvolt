<?php

/*
| CORS (OWASP A05). O SPA é servido pelo mesmo nginx que encaminha /api (mesma origem) e o Power BI chama o feed OData
| servidor-a-servidor: nenhum navegador precisa de CORS. Por omissão NENHUMA origem é autorizada (antes: «*», o valor do
| Laravel). Se um dia houver um cliente noutra origem, listá-la em ERP_CORS_ORIGENS (separadas por vírgulas, com esquema).
*/

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('ERP_CORS_ORIGENS', ''))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Empresa-Id', 'X-Requested-With'],
    'exposed_headers' => ['Content-Disposition', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
