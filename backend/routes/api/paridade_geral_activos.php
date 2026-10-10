<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade visual e funcional com o sistema anterior (verificação de 2026-10-08): Geral, CRM, Estrutura,
// Orçamento, Projectos, Activos e Configurações. Um ficheiro por módulo para manter as rotas separadas.

foreach (['geral', 'fluxos', 'crm', 'estrutura', 'orcamento', 'projectos', 'activos', 'activos_fiscal', 'config'] as $moduloParidade) {
    $ficheiroParidade = __DIR__.'/paridade_geral_activos/'.$moduloParidade.'.php';
    if (is_file($ficheiroParidade)) {
        require $ficheiroParidade;
    }
}
unset($moduloParidade, $ficheiroParidade);
