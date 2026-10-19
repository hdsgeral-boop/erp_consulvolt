<?php

namespace App\Support\Ids;

use InvalidArgumentException;

/**
 * Identificadores opacos (anti-enumeração) — ADR-072.
 *
 * O id interno (bigint) mantém-se na base de dados; a codificação acontece só na fronteira (URL e API). A referência:
 *  - é reversível, mas só com a chave do servidor (derivada da APP_KEY, ou ERP_IDS_CHAVE para a poder rodar à parte);
 *  - depende do TIPO do recurso: o «72» dos colaboradores e o «72» das facturas dão referências diferentes, e uma
 *    referência de um tipo não é aceite noutro (a etiqueta de autenticação não bate certo);
 *  - não é sequencial: alterar uma letra não dá o registo vizinho, dá (com probabilidade 1 em 2^26) um id aleatório
 *    e, na prática, «não encontrado». Mesmo esse acerto só mostra registos a que o utilizador já tem direito
 *    (isolamento por empresa e permissões, no servidor): a referência é uma segunda camada, não a defesa.
 *
 * Construção: carga de 62 bits = id (36 bits) + etiqueta HMAC (26 bits); uma rede de Feistel de 4 rondas (HMAC-SHA256,
 * chave por tipo) mistura a carga; o resultado escreve-se em 15 letras minúsculas sem i, l, o (nunca se confunde com
 * um número, por isso a aceitação transitória dos numéricos é inequívoca).
 */
final class CodificadorIds
{
    public const ALFABETO = 'abcdefghjkmnpqrstuvwxyz';

    public const COMPRIMENTO = 15;

    /** Maior id codificável (36 bits ≈ 68,7 mil milhões). */
    public const ID_MAXIMO = (1 << 36) - 1;

    private const BITS_ETIQUETA = 26;

    private const MASCARA_META = (1 << 31) - 1;

    private const RONDAS = 4;

    /** Expressão que reconhece a FORMA de uma referência (não a valida). */
    public const PADRAO = '/^[a-hjkmnp-z]{15}$/';

    private readonly string $mestra;

    public function __construct(string $segredo)
    {
        if ($segredo === '') {
            throw new InvalidArgumentException('A chave dos identificadores opacos não pode ser vazia.');
        }
        $this->mestra = hash('sha256', 'erp-ids-opacos:v1|'.$segredo, true);
    }

    /** Codificador com a chave do servidor: ERP_IDS_CHAVE, ou a APP_KEY. */
    public static function daAplicacao(): self
    {
        static $instancia = [];
        $chave = (string) (config('erp.ids.chave') ?: config('app.key'));

        return $instancia[$chave] ??= new self(str_starts_with($chave, 'base64:') ? (string) base64_decode(substr($chave, 7), true) : $chave);
    }

    public static function pareceReferencia(string $valor): bool
    {
        return preg_match(self::PADRAO, $valor) === 1;
    }

    public function codificar(string $tipo, int $id): string
    {
        if ($id < 1 || $id > self::ID_MAXIMO) {
            throw new InvalidArgumentException('Identificador fora do intervalo codificável.');
        }
        $chave = $this->chaveDoTipo($tipo);
        $carga = ($id << self::BITS_ETIQUETA) | $this->etiqueta($chave, $id);
        $esq = $carga >> 31;
        $dir = $carga & self::MASCARA_META;
        for ($r = 0; $r < self::RONDAS; $r++) {
            [$esq, $dir] = [$dir, $esq ^ $this->funcao($chave, $r, $dir)];
        }

        return $this->escrever(($esq << 31) | $dir);
    }

    /** O id interno, ou null se a referência não for válida para este tipo (forma, tamanho, etiqueta). */
    public function descodificar(string $tipo, string $referencia): ?int
    {
        if (! self::pareceReferencia($referencia)) {
            return null;
        }
        $carga = $this->ler($referencia);
        if ($carga === null) {
            return null;
        }
        $chave = $this->chaveDoTipo($tipo);
        $esq = $carga >> 31;
        $dir = $carga & self::MASCARA_META;
        for ($r = self::RONDAS - 1; $r >= 0; $r--) {
            [$esq, $dir] = [$dir ^ $this->funcao($chave, $r, $esq), $esq];
        }
        $meio = ($esq << 31) | $dir;
        $id = $meio >> self::BITS_ETIQUETA;
        if ($id < 1 || ! hash_equals(pack('N', $this->etiqueta($chave, $id)), pack('N', $meio & ((1 << self::BITS_ETIQUETA) - 1)))) {
            return null;
        }

        return $id;
    }

    private function chaveDoTipo(string $tipo): string
    {
        return hash_hmac('sha256', 'tipo:'.strtolower($tipo), $this->mestra, true);
    }

    private function etiqueta(string $chave, int $id): int
    {
        return (int) (unpack('N', substr(hash_hmac('sha256', 'etiqueta'.pack('J', $id), $chave, true), 0, 4))[1] & ((1 << self::BITS_ETIQUETA) - 1));
    }

    private function funcao(string $chave, int $ronda, int $metade): int
    {
        return (int) (unpack('N', substr(hash_hmac('sha256', chr($ronda).pack('N', $metade), $chave, true), 0, 4))[1] & self::MASCARA_META);
    }

    private function escrever(int $valor): string
    {
        $base = strlen(self::ALFABETO);
        $texto = '';
        for ($i = 0; $i < self::COMPRIMENTO; $i++) {
            $texto = self::ALFABETO[$valor % $base].$texto;
            $valor = intdiv($valor, $base);
        }

        return $texto;
    }

    /** Valor de 62 bits da referência; null se exceder o intervalo (referência forjada). */
    private function ler(string $referencia): ?int
    {
        $base = strlen(self::ALFABETO);
        $limite = 1 << 62;
        $valor = 0;
        for ($i = 0; $i < self::COMPRIMENTO; $i++) {
            $d = strpos(self::ALFABETO, $referencia[$i]);
            if ($d === false || $valor > intdiv($limite - 1 - $d, $base)) {
                return null;
            }
            $valor = $valor * $base + $d;
        }

        return $valor;
    }
}
