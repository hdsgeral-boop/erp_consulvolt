<?php

namespace App\Services\Integracoes\IA\Regras;

use RuntimeException;

/** Erro de leitura ou de cálculo de uma expressão de um ficheiro de regras (mensagem em português, para o utilizador). */
final class ErroExpressao extends RuntimeException {}
