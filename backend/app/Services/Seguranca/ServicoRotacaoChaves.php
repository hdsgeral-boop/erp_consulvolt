<?php

namespace App\Services\Seguranca;

use App\Services\Pagamentos\SegredosGateway;
use App\Support\Seguranca\EncrypterCompativel;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rotação de chaves dos segredos REVERSÍVEIS (ADR-085, docs/SEGURANCA_CRIPTOGRAFIA.md).
 *
 * Processo: 1) gerar uma APP_KEY nova (`php artisan key:generate --show`); 2) pôr a antiga em APP_PREVIOUS_KEYS e a nova em
 * APP_KEY (o Laravel decifra com qualquer uma e cifra com a primeira); 3) `php artisan erp:seguranca:rodar-chave` recifra tudo
 * com a chave e a cifra actuais; 4) confirmar com `--estado` (0 «a rodar», 0 «ilegíveis») e só então retirar a chave antiga de
 * APP_PREVIOUS_KEYS. O mesmo comando migra de AES-256-CBC para AES-256-GCM (`APP_CIPHER`).
 *
 * Nunca lê segredos para o log nem para o ecrã; só conta. Cada linha é actualizada com compare-and-swap sobre o valor lido,
 * por isso corre em segurança com a aplicação a funcionar (uma escrita concorrente não é perdida: a linha fica para a
 * execução seguinte).
 */
final class ServicoRotacaoChaves
{
    /**
     * Inventário dos segredos reversíveis. `prefixo`: o valor guarda-se como `enc:<payload>` (SegredosGateway) em vez de só
     * `<payload>` (casts `encrypted` do Eloquent). `onde`: filtro opcional por linha.
     *
     * @var list<array{tabela: string, pk: string, coluna: string, prefixo: bool, onde?: array<string, list<string>>}>
     */
    public const INVENTARIO = [
        ['tabela' => 'utilizadores', 'pk' => 'id', 'coluna' => 'mfa_segredo', 'prefixo' => false],
        ['tabela' => 'agt_ligacoes_empresa', 'pk' => 'id', 'coluna' => 'utilizador', 'prefixo' => false],
        ['tabela' => 'agt_ligacoes_empresa', 'pk' => 'id', 'coluna' => 'palavra_passe', 'prefixo' => false],
        ['tabela' => 'agt_ligacoes_empresa', 'pk' => 'id', 'coluna' => 'chave_privada', 'prefixo' => false],
        ['tabela' => 'agt_ligacoes_empresa', 'pk' => 'id', 'coluna' => 'webhook_segredo', 'prefixo' => false],
        ['tabela' => 'configuracoes_gateway', 'pk' => 'chave', 'coluna' => 'valor', 'prefixo' => true, 'onde' => ['chave' => SegredosGateway::CHAVES_SECRETAS]],
        ['tabela' => 'ia_fornecedores', 'pk' => 'id', 'coluna' => 'chave', 'prefixo' => true],
    ];

    /** Identificador CURTO da chave actual (não revela a chave: HMAC com um rótulo fixo). Aparece nos registos e no `--estado`. */
    public function versaoDaChave(): string
    {
        return substr(hash_hmac('sha256', 'erp:versao-da-chave:v1', Crypt::getKey()), 0, 12);
    }

    public function cifraActual(): string
    {
        return strtoupper((string) config('app.cipher', 'AES-256-CBC'));
    }

    /**
     * Estado de cada segredo: `actual` (chave e cifra actuais), `a_rodar` (decifra, mas com chave/cifra antigas), `texto_simples`
     * (valor legado sem cifra — só nas colunas com prefixo), `ilegivel` (nenhuma chave decifra) ou `vazio`.
     *
     * @return array{total: array<string, int>, por_coluna: array<string, array<string, int>>}
     */
    public function estado(): array
    {
        $total = ['actual' => 0, 'a_rodar' => 0, 'texto_simples' => 0, 'ilegivel' => 0];
        $porColuna = [];
        $soActual = $this->soActual();
        foreach (self::INVENTARIO as $item) {
            $chave = $item['tabela'].'.'.$item['coluna'];
            $porColuna[$chave] = ['actual' => 0, 'a_rodar' => 0, 'texto_simples' => 0, 'ilegivel' => 0];
            foreach ($this->linhas($item) as $linha) {
                $classe = $this->classificar($item, $linha->valor, $soActual);
                if ($classe === 'vazio') {
                    continue;
                }
                $total[$classe]++;
                $porColuna[$chave][$classe]++;
            }
        }

        return ['total' => $total, 'por_coluna' => $porColuna];
    }

    /**
     * Recifra com a chave e a cifra actuais tudo o que está com chaves/cifra antigas (e cifra o texto simples legado das
     * colunas com prefixo). Com `$simular` só conta.
     *
     * @return array{rodados: int, ja_actuais: int, texto_simples_cifrado: int, ilegiveis: int}
     */
    public function rodar(bool $simular = false): array
    {
        $r = ['rodados' => 0, 'ja_actuais' => 0, 'texto_simples_cifrado' => 0, 'ilegiveis' => 0];
        $soActual = $this->soActual();
        foreach (self::INVENTARIO as $item) {
            foreach ($this->linhas($item) as $linha) {
                $classe = $this->classificar($item, $linha->valor, $soActual);
                if ($classe === 'vazio') {
                    continue;
                }
                if ($classe === 'actual') {
                    $r['ja_actuais']++;

                    continue;
                }
                if ($classe === 'ilegivel') {
                    $r['ilegiveis']++;

                    continue;
                }
                $claro = $classe === 'texto_simples' ? (string) $linha->valor : $this->decifrar($item, (string) $linha->valor);
                $novo = $item['prefixo'] ? SegredosGateway::PREFIXO.Crypt::encryptString($claro) : Crypt::encryptString($claro);
                if (! $simular) {
                    DB::table($item['tabela'])->where($item['pk'], $linha->pk)->where($item['coluna'], $linha->valor)->update([$item['coluna'] => $novo]);
                }
                $classe === 'texto_simples' ? $r['texto_simples_cifrado']++ : $r['rodados']++;
            }
        }

        if (! $simular) {
            Log::info('Rotação de chaves dos segredos', $r + ['versao_da_chave' => $this->versaoDaChave(), 'cifra' => $this->cifraActual()]);
        }

        return $r;
    }

    // ----------------------------------------------------------------------------------------------------------------

    private function soActual(): Encrypter
    {
        $e = Crypt::getFacadeRoot();

        return $e instanceof EncrypterCompativel ? $e->soChaveActual() : new Encrypter($e->getKey(), $this->cifraActual());
    }

    /** @return iterable<object{pk: mixed, valor: mixed}> */
    private function linhas(array $item): iterable
    {
        $q = DB::table($item['tabela'])->whereNotNull($item['coluna'])->where($item['coluna'], '!=', '');
        foreach ($item['onde'] ?? [] as $coluna => $valores) {
            $q->whereIn($coluna, $valores);
        }

        return $q->orderBy($item['pk'])->get([$item['pk'].' as pk', $item['coluna'].' as valor']);
    }

    private function payload(array $item, string $valor): string
    {
        return $item['prefixo'] && str_starts_with($valor, SegredosGateway::PREFIXO) ? substr($valor, strlen(SegredosGateway::PREFIXO)) : $valor;
    }

    private function decifrar(array $item, string $valor): string
    {
        return Crypt::decryptString($this->payload($item, $valor));
    }

    private function classificar(array $item, mixed $valor, Encrypter $soActual): string
    {
        $valor = (string) $valor;
        if ($valor === '') {
            return 'vazio';
        }
        $payload = $this->payload($item, $valor);
        if (! SegredosGateway::pareceUmPayloadCifrado($payload) && ! $this->pareceGcm($payload)) {
            return $item['prefixo'] ? 'texto_simples' : 'ilegivel';
        }
        try {
            $soActual->decryptString($payload);

            return 'actual';
        } catch (DecryptException) {
            // não decifra só com a chave/cifra actuais: vê se alguma anterior serve
        }
        try {
            Crypt::decryptString($payload);

            return 'a_rodar';
        } catch (DecryptException) {
            return 'ilegivel';
        }
    }

    /** O payload GCM não tem `mac` (tem `tag`): `pareceUmPayloadCifrado` (forma CBC/legado) não o reconhece. */
    private function pareceGcm(string $payload): bool
    {
        if (! str_starts_with($payload, 'eyJ')) {
            return false;
        }
        $json = base64_decode($payload, true);
        $d = $json === false ? null : json_decode($json, true);

        return is_array($d) && isset($d['iv'], $d['value'], $d['tag']);
    }
}
