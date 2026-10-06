<?php

/*
| Assistente IA para lançamentos (decisão 26 — ServicoAssistenteIA). A chave da Anthropic vem SÓ do ambiente do servidor
| (ANTHROPIC_API_KEY): nunca é gravada na base de dados, devolvida pela API ou enviada ao navegador. Sem chave, o assistente
| funciona apenas com as regras internas (motor local, sem envio de dados a terceiros).
*/

return [
    'chave' => env('ANTHROPIC_API_KEY'),
    'url' => env('ERP_IA_URL', 'https://api.anthropic.com/v1/messages'),
    'versao_api' => '2023-06-01',
    // Modelo Claude mais recente e adequado à extracção estruturada (skill claude-api, 2026-10): Claude Opus 5.5.
    'modelo' => env('ERP_IA_MODELO', 'claude-opus-5-5'),
    // Esforço (output_config.effort): medium por omissão no Opus 5.5 — equilíbrio custo/qualidade para propostas curtas.
    'esforco' => env('ERP_IA_ESFORCO', 'medium'),
    'max_tokens' => (int) env('ERP_IA_MAX_TOKENS', 16000),
    'tempo_limite' => (int) env('ERP_IA_TEMPO_LIMITE', 120),
    // Preços por milhão de tokens (USD) para a estimativa de custo no registo de utilização (Opus 5.5: 4 / 20).
    'preco_entrada_mtok' => (float) env('ERP_IA_PRECO_ENTRADA', 4.0),
    'preco_saida_mtok' => (float) env('ERP_IA_PRECO_SAIDA', 20.0),
    // Limites de entrada (minimização e custo)
    'max_ficheiro_kb' => (int) env('ERP_IA_MAX_FICHEIRO_KB', 10240),
    'max_contas_contexto' => (int) env('ERP_IA_MAX_CONTAS', 3000),
];
