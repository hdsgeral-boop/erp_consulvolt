<?php

// Extrai o esquema real (information_schema) da BD ligada para ferramentas/paridade/esquema_bd.json.
// Uso (dentro do contentor, só leitura): docker exec -i erp_app_e2e php artisan tinker < ferramentas/paridade/extrair_esquema_bd.php
// (ou copiar o ficheiro e executar com `php artisan tinker <ficheiro>`), depois `docker cp` do resultado.
$ligacao = DB::connection('pgsql_owner');
$colunas = $ligacao->select("select c.table_name t, c.column_name c, c.data_type d, c.is_nullable n, c.column_default df
    from information_schema.columns c
    join information_schema.tables x on x.table_name = c.table_name and x.table_schema = c.table_schema and x.table_type = 'BASE TABLE'
    where c.table_schema = 'public' order by c.table_name, c.ordinal_position");
$fks = $ligacao->select("select tc.table_name t, kcu.column_name c, ccu.table_name alvo
    from information_schema.table_constraints tc
    join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name and kcu.table_schema = tc.table_schema
    join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
    where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public'");
file_put_contents('/tmp/esquema_bd.json', json_encode(['colunas' => $colunas, 'fks' => $fks]));
echo count($colunas).' colunas, '.count($fks)." fks\n";
