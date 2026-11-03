<?php

namespace App\Support\Idempotencia;

use RuntimeException;

/** Outro worker tem a execução em curso (lease válido): o job deve fazer `release()` e a fila repete mais tarde (ADR-080). */
final class ExecucaoEmCursoException extends RuntimeException {}
