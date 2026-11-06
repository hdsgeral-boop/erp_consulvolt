<?php

namespace App\Support\Api;

use App\Exceptions\ErroNegocio;
use Throwable;

/**
 * Camada central de mensagens de erro para o cliente: garante que nenhum texto técnico (SQL, caminhos, classes,
 * traços de pilha, nomes de tabelas/restrições, mensagens cruas do PHP/PostgreSQL/Redis/Laravel) chega ao utilizador.
 *
 * Regra de ouro: só uma `ErroNegocio` (mensagem escrita para o utilizador) ou um texto de validação pode ser mostrada
 * tal como está, e mesmo essas passam por `sanitizar()`. A mensagem de qualquer outra excepção NUNCA vai para a resposta:
 * usar `de($e, 'texto genérico da categoria')`.
 */
final class MensagemSegura
{
    /** Padrões que denunciam texto técnico do servidor (qualquer correspondência torna a mensagem insegura). */
    private const PADROES_PROIBIDOS = [
        '/SQLSTATE/i',
        '/\bSQL\b/',
        '/\brelation\s+["“]/i',
        '/\b(violates|duplicate key|unique constraint|foreign key constraint|check constraint|not-null constraint|null value in column)\b/i',
        '/\bcolumn\s+["“]?\w+["”]?\s+(of relation|does not exist|cannot)/i',
        '/\b(SELECT|INSERT\s+INTO|UPDATE|DELETE\s+FROM|ALTER\s+TABLE|CREATE\s+TABLE|DROP\s+TABLE)\b[^.]{0,200}\b(FROM|SET|VALUES|WHERE|INTO|TABLE)\b/',
        '#(/var/www|/home/|/usr/|/app/|/srv/|/opt/|/etc/|vendor/|storage/|bootstrap/|[A-Za-z]:\\\\)#',
        '/\.php\b/i',
        '/\bon line \d+/i',
        '/Stack trace|#\d+ \S+\.php/i',
        '/\b(Illuminate|Symfony|Doctrine|Predis|Carbon|Monolog|GuzzleHttp|League)\\\\/',
        '/\bApp\\\\/',
        '/\b\w*(Exception|Error)\b(?=[:\s(]|$)/',
        '/\b(PDO|PDOException|QueryException|TypeError|ErrorException|ParseError|ArgumentCountError)\b/',
        '/\b(Undefined (variable|index|array key|property|offset)|Call to (undefined|a member)|Class ["“]?\S+["”]? not found|Trying to access|must be of type|Argument #\d+|Attempt to read)/i',
        '/\b(Connection refused|could not connect|connection to server|server closed the connection|timeout expired|cURL error|SSL certificate|getaddrinfo|Name or service not known)\b/i',
        '/\b(redis|predis|pgsql|postgres(ql)?|pg_[a-z_]+|mysql|sqlite)\b/i',
        '/\b(APP_KEY|DB_PASSWORD|DB_HOST|api[_-]?key|secret|bearer\s+[A-Za-z0-9._-]{10,})\b/i',
        '/\b(?:\d{1,3}\.){3}\d{1,3}(:\d+)?\b/',
    ];

    public static function eSegura(string $mensagem): bool
    {
        foreach (self::PADROES_PROIBIDOS as $padrao) {
            if (preg_match($padrao, $mensagem) === 1) {
                return false;
            }
        }

        return true;
    }

    /** Devolve a mensagem se for segura; senão o texto genérico da categoria do estado HTTP. */
    public static function sanitizar(string $mensagem, int $estado = 422): string
    {
        $mensagem = trim($mensagem);

        return $mensagem !== '' && self::eSegura($mensagem) ? $mensagem : self::generica($estado);
    }

    /**
     * Mensagem de uma excepção para mostrar ao utilizador: só a de uma `ErroNegocio` (e se for segura);
     * para qualquer outra devolve `$generica`.
     */
    public static function de(Throwable $e, string $generica): string
    {
        return $e instanceof ErroNegocio ? self::sanitizar($e->getMessage(), $e->estadoHttp) : $generica;
    }

    /** Texto genérico, em português, por categoria do estado HTTP (nunca descreve a causa técnica). */
    public static function generica(int $estado): string
    {
        return match (true) {
            $estado === 401 => 'A sua sessão terminou. Entre novamente.',
            $estado === 403 => 'Não tem permissão para executar esta operação.',
            $estado === 404 => 'O registo pedido não foi encontrado.',
            $estado === 405 => 'Esta operação não está disponível neste endereço.',
            $estado === 409 => 'Este registo entra em conflito com outro já existente ou foi alterado entretanto.',
            $estado === 413 => 'O pedido ou ficheiro enviado é demasiado grande.',
            $estado === 415 => 'O tipo de ficheiro enviado não é aceite.',
            $estado === 419 => 'A sua sessão de segurança expirou. Recarregue a página e tente novamente.',
            $estado === 422 => 'Não foi possível concluir a operação com os dados indicados. Reveja-os e tente novamente.',
            $estado === 429 => 'Demasiados pedidos em pouco tempo. Aguarde um momento e tente novamente.',
            in_array($estado, [502, 503, 504], true) => 'Serviço temporariamente indisponível. Tente novamente dentro de instantes.',
            $estado >= 500 => 'Ocorreu um erro interno. Tente novamente; se persistir, contacte o suporte indicando a referência.',
            default => 'Não foi possível processar o pedido.',
        };
    }

    /** Referência curta (ex.: «E-7K3QX9PD») que liga a resposta ao registo completo no log do servidor. */
    public static function novaReferencia(): string
    {
        $alfabeto = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // sem 0/O/1/I para ditar ao telefone
        $ref = '';
        for ($i = 0; $i < 8; $i++) {
            $ref .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }

        return 'E-'.$ref;
    }
}
