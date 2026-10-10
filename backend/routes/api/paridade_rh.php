<?php

// Incluído por routes/api.php dentro do grupo autenticado com empresa activa (prefixo /api).
// Paridade visual/funcional de RH e Salários com o sistema anterior (secção 6 de verificacao_visual/RELATORIO.md).
// Cada área tem o seu ficheiro em routes/api/paridade_rh/ (incluídos por ordem alfabética).

$ficheirosParidadeRh = glob(__DIR__.'/paridade_rh/*.php') ?: [];
sort($ficheirosParidadeRh);
foreach ($ficheirosParidadeRh as $ficheiroParidadeRh) {
    require $ficheiroParidadeRh;
}
unset($ficheirosParidadeRh, $ficheiroParidadeRh);
