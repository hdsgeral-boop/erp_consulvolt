<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade visual/funcional do POS e da Logística (Armazém e Inventário) com o sistema anterior
// (secções 2 e 3 de verificacao_visual/RELATORIO.md). Cada área tem o seu ficheiro em
// routes/api/paridade_pos_stock/ (incluídos por ordem alfabética).

$ficheirosParidadePosStock = glob(__DIR__.'/paridade_pos_stock/*.php') ?: [];
sort($ficheirosParidadePosStock);
foreach ($ficheirosParidadePosStock as $ficheiroParidadePosStock) {
    require $ficheiroParidadePosStock;
}
unset($ficheirosParidadePosStock, $ficheiroParidadePosStock);
