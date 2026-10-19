<?php

namespace App\Services\Vendas\Agt;

use App\Exceptions\ErroNegocio;
use App\Models\Empresa;
use App\Models\LigacaoAgtEmpresa;
use App\Support\Tenancy\ContextoEmpresa;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use OpenSSLAsymmetricKey;

/**
 * Ligação de cada empresa à facturação electrónica da AGT (ronda 4, r4_agt):
 *   - ambiente (homologação/produção) e, só se a AGT o mudar, o endereço base (HTTPS e domínio da AGT — anti-SSRF);
 *   - credenciais Basic dos serviços (doc-agt «api.html»): se a empresa não as tiver, usam-se as do produtor no servidor;
 *   - chave privada RSA do contribuinte (doc-agt «gestao.html»: emitida pela AGT no Portal do Contribuinte; assina
 *     jwsDocumentSignature e jwsSignature) e, opcionalmente, a chave pública ou o certificado para conferir o par;
 *   - webhook: identificador público + segredo HMAC (a AGT anuncia o «callback» como «Disponível nas próximas versões»).
 * Os segredos ficam cifrados com a APP_KEY (cast «encrypted») e NUNCA são devolvidos: a interface só vê «configurado
 * em … por …». Cada alteração fica na auditoria (Auditavel; os campos cifrados estão em $hidden).
 * A chave do produtor de software e os dados do software (productId, n.º de validação) são do produtor, não da empresa:
 * ficam no servidor (doc-agt «gestao.html»: «A chave privada nunca deve sair do ambiente do produtor»).
 */
final class ServicoLigacaoAgt
{
    public const AMBIENTES = ['homologacao', 'producao'];

    public function __construct(private readonly ContextoEmpresa $contexto, private readonly ChavesAgt $chaves) {}

    /** Estado da ligação da empresa activa, sem segredos. */
    public function obter(): array
    {
        $l = LigacaoAgtEmpresa::query()->first();
        $s = config('erp.agt.software');
        $produtor = false;
        try {
            $produtor = $this->chaves->temProdutor();
        } catch (ErroNegocio) {
        }
        $credServidor = (bool) (config('erp.agt.utilizador') && config('erp.agt.palavra_passe'));
        $credEmpresa = (bool) ($l?->utilizador && $l?->palavra_passe);
        $nif = $this->nif(false);
        $chaveFicheiro = $nif !== null && in_array($nif, $this->chaves->nifsComChave(), true);
        $diasValidade = $l?->certificado_valido_ate ? (int) floor(now()->diffInDays($l->certificado_valido_ate, false)) : null;

        return [
            'configurada' => (bool) $l,
            'ativo' => (bool) $l?->ativo,
            'ambiente' => $l?->ambiente ?? (string) config('erp.agt.ambiente'),
            'url_base' => $l?->url_base,
            'url_efectiva' => $this->urlBase($l?->ambiente ?? (string) config('erp.agt.ambiente'), $l?->url_base, false),
            'nif' => $nif,
            'credenciais' => [
                'configuradas' => $credEmpresa || $credServidor,
                'origem' => $credEmpresa ? 'empresa' : ($credServidor ? 'servidor' : null),
                'atualizadas_em' => $l?->credenciais_atualizadas_em?->toIso8601String(), 'atualizadas_por' => $l?->credenciais_atualizadas_por,
            ],
            'chave' => [
                'configurada' => (bool) $l?->chave_privada || $chaveFicheiro,
                'origem' => $l?->chave_privada ? 'empresa' : ($chaveFicheiro ? 'servidor' : null),
                'impressao_digital' => $l?->chave_impressao_digital, 'bits' => $l?->chave_bits, 'versao' => (int) ($l?->chave_versao ?? 0),
                'tem_certificado' => (bool) $l?->certificado, 'tem_chave_publica' => (bool) $l?->chave_publica,
                'certificado_valido_de' => $l?->certificado_valido_de?->toIso8601String(), 'certificado_valido_ate' => $l?->certificado_valido_ate?->toIso8601String(),
                'dias_para_expirar' => $diasValidade, 'expira_em_breve' => $diasValidade !== null && $diasValidade <= 30,
                'atualizada_em' => $l?->chave_atualizada_em?->toIso8601String(), 'atualizada_por' => $l?->chave_atualizada_por,
                'chave_publica' => $l?->chave_publica,   // não é segredo: registada pela AGT/para conferência
            ],
            'webhook' => [
                'ativo' => (bool) $l?->webhook_ativo, 'configurado' => (bool) $l?->webhook_token_hash,
                'atualizado_em' => $l?->webhook_atualizado_em?->toIso8601String(), 'atualizado_por' => $l?->webhook_atualizado_por,
                'nota' => 'A AGT anuncia as notificações («callback») como «Disponível nas próximas versões». O estado é sempre confirmado por consulta (obterEstado).',
            ],
            'software' => [
                'productId' => $s['productId'] ?? null, 'productVersion' => $s['productVersion'] ?? null,
                'softwareValidationNumber' => $s['softwareValidationNumber'] ?? null, 'signatureVersion' => $s['signatureVersion'] ?? null,
                'configurado' => (bool) (($s['productId'] ?? null) && ($s['productVersion'] ?? null) && ($s['softwareValidationNumber'] ?? null)),
                'chave_produtor' => $produtor,
            ],
            'ultimo_teste' => $l?->ultimo_teste_em ? ['em' => $l->ultimo_teste_em->toIso8601String(), 'ok' => (bool) $l->ultimo_teste_ok, 'mensagem' => $l->ultimo_teste_mensagem] : null,
            'atualizado_em' => $l?->atualizado_em?->toIso8601String(), 'atualizado_por' => $l?->atualizado_por,
        ];
    }

    /** Ambiente, endereço e activação. Activar exige chave do contribuinte, credenciais e dados do software. */
    public function gravar(array $d): array
    {
        $ambiente = (string) ($d['ambiente'] ?? 'homologacao');
        if (! in_array($ambiente, self::AMBIENTES, true)) {
            throw new ErroNegocio('Ambiente inválido (homologacao ou producao).', 'AMBIENTE_INVALIDO', 422);
        }
        $url = isset($d['url_base']) && trim((string) $d['url_base']) !== '' ? trim((string) $d['url_base']) : null;
        if ($url !== null) {
            $this->urlBase($ambiente, $url, true);
        }

        DB::transaction(function () use ($d, $ambiente, $url) {
            $l = $this->registo(true);
            $l->fill(['ambiente' => $ambiente, 'url_base' => $url, 'ativo' => (bool) ($d['ativo'] ?? false), 'atualizado_por' => $this->quem()]);
            if ($l->ativo) {
                $faltas = [];
                if (! $l->chave_privada) {
                    $faltas[] = 'a chave privada do contribuinte';
                }
                if (! ($l->utilizador && $l->palavra_passe) && ! (config('erp.agt.utilizador') && config('erp.agt.palavra_passe'))) {
                    $faltas[] = 'as credenciais de acesso';
                }
                $s = config('erp.agt.software');
                if (! (($s['productId'] ?? null) && ($s['productVersion'] ?? null) && ($s['softwareValidationNumber'] ?? null))) {
                    $faltas[] = 'os dados do software certificado (servidor)';
                }
                if ($l->certificado_valido_ate && $l->certificado_valido_ate->isPast()) {
                    $faltas[] = 'um certificado válido (o actual expirou)';
                }
                if ($faltas) {
                    throw new ErroNegocio('Para activar a ligação à AGT falta: '.implode(', ', $faltas).'.', 'LIGACAO_INCOMPLETA', 422, ['faltas' => $faltas]);
                }
            }
            $l->save();
        });

        return $this->obter();
    }

    public function gravarCredenciais(string $utilizador, string $palavraPasse): array
    {
        $utilizador = trim($utilizador);
        if ($utilizador === '' || $palavraPasse === '' || mb_strlen($utilizador) > 200 || mb_strlen($palavraPasse) > 500) {
            throw new ErroNegocio('Indique o utilizador e a palavra-passe fornecidos pela AGT.', 'CREDENCIAIS_INVALIDAS', 422);
        }
        DB::transaction(function () use ($utilizador, $palavraPasse) {
            $this->registo(true)->fill(['utilizador' => $utilizador, 'palavra_passe' => $palavraPasse, 'credenciais_atualizadas_em' => now(),
                'credenciais_atualizadas_por' => $this->quem(), 'atualizado_por' => $this->quem()])->save();
        });

        return $this->obter();
    }

    public function removerCredenciais(): array
    {
        DB::transaction(function () {
            $l = $this->registo(false);
            $l?->fill(['utilizador' => null, 'palavra_passe' => null, 'credenciais_atualizadas_em' => now(), 'credenciais_atualizadas_por' => $this->quem()])->save();
        });

        return $this->obter();
    }

    /**
     * Grava (ou roda) a chave privada do contribuinte. Valida: PEM legível (com frase, se cifrada), RSA ≥ 2048 bits
     * (doc-agt «estrutura.html»), e, se vier, a correspondência com a chave pública ou o certificado X.509 e a validade
     * deste. A chave fica guardada sem frase-passe mas cifrada com a APP_KEY; a anterior deixa de ser usada de imediato.
     */
    public function gravarChave(string $privadaPem, ?string $publicaOuCertificado = null, ?string $frase = null): array
    {
        $chave = @openssl_pkey_get_private($privadaPem, $frase ?? '');
        if ($chave === false) {
            throw new ErroNegocio('A chave privada não é um PEM válido (ou a frase-passe está errada).', 'CHAVE_INVALIDA', 422);
        }
        $det = openssl_pkey_get_details($chave);
        if (($det['type'] ?? null) !== OPENSSL_KEYTYPE_RSA || ($det['bits'] ?? 0) < 2048) {
            throw new ErroNegocio('A chave tem de ser RSA com pelo menos 2048 bits (a AGT recomenda 4096).', 'CHAVE_INVALIDA', 422);
        }
        $publica = (string) $det['key'];
        $impressao = self::impressaoDigital($publica);
        $certificado = null;
        $de = $ate = null;
        $extra = trim((string) $publicaOuCertificado);
        if ($extra !== '') {
            if (str_contains($extra, 'BEGIN CERTIFICATE')) {
                $x509 = @openssl_x509_read($extra);
                if ($x509 === false) {
                    throw new ErroNegocio('O certificado não é um PEM X.509 válido.', 'CERTIFICADO_INVALIDO', 422);
                }
                if (! openssl_x509_check_private_key($x509, $chave)) {
                    throw new ErroNegocio('A chave privada não corresponde ao certificado.', 'CHAVE_NAO_CORRESPONDE', 422);
                }
                $info = openssl_x509_parse($x509) ?: [];
                $de = isset($info['validFrom_time_t']) ? now()->setTimestamp((int) $info['validFrom_time_t']) : null;
                $ate = isset($info['validTo_time_t']) ? now()->setTimestamp((int) $info['validTo_time_t']) : null;
                if ($ate && $ate->isPast()) {
                    throw new ErroNegocio('O certificado expirou em '.$ate->toDateString().'.', 'CERTIFICADO_EXPIRADO', 422);
                }
                if ($de && $de->isFuture()) {
                    throw new ErroNegocio('O certificado só é válido a partir de '.$de->toDateString().'.', 'CERTIFICADO_AINDA_NAO_VALIDO', 422);
                }
                openssl_x509_export($x509, $certificado);
            } else {
                $pub = @openssl_pkey_get_public($extra);
                if ($pub === false) {
                    throw new ErroNegocio('A chave pública não é um PEM válido.', 'CHAVE_PUBLICA_INVALIDA', 422);
                }
                if (self::impressaoDigital((string) openssl_pkey_get_details($pub)['key']) !== $impressao) {
                    throw new ErroNegocio('A chave privada não corresponde à chave pública indicada.', 'CHAVE_NAO_CORRESPONDE', 422);
                }
            }
        }
        // prova de posse: assina e verifica (detecta PEM corrompidos que o OpenSSL ainda aceita)
        if (! openssl_sign('teste-agt', $assinatura, $chave, OPENSSL_ALGO_SHA256) || openssl_verify('teste-agt', $assinatura, $publica, OPENSSL_ALGO_SHA256) !== 1) {
            throw new ErroNegocio('A chave não conseguiu assinar e verificar uma mensagem de teste.', 'CHAVE_INVALIDA', 422);
        }
        openssl_pkey_export($chave, $semFrase);

        DB::transaction(function () use ($semFrase, $publica, $impressao, $det, $certificado, $de, $ate) {
            $l = $this->registo(true);
            $anterior = $l->chave_impressao_digital;
            $l->fill([
                'chave_privada' => $semFrase, 'chave_publica' => $publica, 'certificado' => $certificado, 'chave_impressao_digital' => $impressao,
                'chave_anterior_impressao_digital' => $anterior && $anterior !== $impressao ? $anterior : $l->chave_anterior_impressao_digital,
                'chave_bits' => (int) $det['bits'], 'chave_versao' => (int) $l->chave_versao + ($anterior !== $impressao ? 1 : 0),
                'certificado_valido_de' => $de, 'certificado_valido_ate' => $ate,
                'chave_atualizada_em' => now(), 'chave_atualizada_por' => $this->quem(), 'atualizado_por' => $this->quem(),
            ])->save();
        });

        return $this->obter();
    }

    public function removerChave(): array
    {
        DB::transaction(function () {
            $l = $this->registo(false);
            if ($l?->ativo) {
                throw new ErroNegocio('Desactive a ligação à AGT antes de retirar a chave do contribuinte.', 'LIGACAO_ATIVA', 422);
            }
            $l?->fill(['chave_privada' => null, 'chave_publica' => null, 'certificado' => null, 'chave_anterior_impressao_digital' => $l->chave_impressao_digital,
                'chave_impressao_digital' => null, 'chave_bits' => null, 'certificado_valido_de' => null, 'certificado_valido_ate' => null,
                'chave_atualizada_em' => now(), 'chave_atualizada_por' => $this->quem()])->save();
        });

        return $this->obter();
    }

    /**
     * Gera (ou roda) o endereço e o segredo do webhook. Devolve-os UMA vez: depois só fica o hash do identificador e o
     * segredo cifrado.
     *
     * @return array{url: string, segredo: string, cabecalhos: array<string, string>}
     */
    public function gerarWebhook(): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $segredo = bin2hex(random_bytes(32));
        DB::transaction(function () use ($token, $segredo) {
            $this->registo(true)->fill(['webhook_ativo' => true, 'webhook_token_hash' => hash('sha256', $token), 'webhook_segredo' => $segredo,
                'webhook_atualizado_em' => now(), 'webhook_atualizado_por' => $this->quem()])->save();
        });

        return [
            'url' => rtrim((string) config('app.url'), '/').'/api/agt/webhook/'.$token,
            'segredo' => $segredo,
            'cabecalhos' => ['X-AGT-Carimbo' => 'segundos UNIX', 'X-AGT-Nonce' => '8 a 100 caracteres únicos', 'X-AGT-Assinatura' => 'sha256=HMAC_SHA256(segredo, carimbo + "." + nonce + "." + corpo)'],
        ];
    }

    public function desativarWebhook(): array
    {
        DB::transaction(function () {
            $this->registo(false)?->fill(['webhook_ativo' => false, 'webhook_token_hash' => null, 'webhook_segredo' => null,
                'webhook_atualizado_em' => now(), 'webhook_atualizado_por' => $this->quem()])->save();
        });

        return $this->obter();
    }

    public function registarTeste(bool $ok, string $mensagem): void
    {
        $this->registo(true)->forceFill(['ultimo_teste_em' => now(), 'ultimo_teste_ok' => $ok, 'ultimo_teste_mensagem' => mb_substr($mensagem, 0, 1000)])->save();
    }

    /**
     * Ligação efectiva da empresa activa: a da empresa (se activa) ou a do servidor (AGT_* e ficheiros PEM).
     * Devolve null quando a empresa não tem ligação activa (o driver do servidor decide).
     */
    public function resolverDaEmpresa(): ?LigacaoAgt
    {
        $l = LigacaoAgtEmpresa::query()->where('ativo', true)->first();
        if (! $l) {
            return null;
        }
        $chave = $l->chave_privada ? openssl_pkey_get_private($l->chave_privada) : null;
        if ($chave === false) {
            throw new ErroNegocio('A chave do contribuinte guardada não é legível (APP_KEY alterada?). Volte a carregá-la.', 'CHAVE_INVALIDA', 503);
        }
        $credEmpresa = $l->utilizador && $l->palavra_passe;

        return new LigacaoAgt($l->empresa_id, 'empresa', $l->ambiente, $this->urlBase($l->ambiente, $l->url_base, true),
            $credEmpresa ? $l->utilizador : config('erp.agt.utilizador'), $credEmpresa ? $l->palavra_passe : config('erp.agt.palavra_passe'),
            $chave instanceof OpenSSLAsymmetricKey ? $chave : null);
    }

    /** Ligação do servidor (modo anterior): credenciais do produtor e chave do contribuinte em <pasta>/<NIF>.pem. */
    public function resolverDoServidor(): LigacaoAgt
    {
        $ambiente = (string) config('erp.agt.ambiente', 'homologacao');

        return new LigacaoAgt($this->contexto->obrigatorio(), 'servidor', $ambiente, $this->urlBase($ambiente, config('erp.agt.url_base'), true),
            config('erp.agt.utilizador'), config('erp.agt.palavra_passe'), null);
    }

    public function empresaTemLigacaoAtiva(): bool
    {
        return LigacaoAgtEmpresa::query()->where('ativo', true)->exists();
    }

    /** Endereço base com «/» final; só HTTPS e domínios da AGT (ou os de AGT_HOSTS_PERMITIDOS) — impede SSRF. */
    public function urlBase(string $ambiente, ?string $override, bool $exigir): string
    {
        $url = rtrim((string) ($override ?: config("erp.agt.urls.{$ambiente}")), '/').'/';
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $permitidos = array_filter(array_map('trim', explode(',', (string) config('erp.agt.hosts_permitidos', 'minfin.gov.ao'))));
        $ok = str_starts_with($url, 'https://') && $host !== '' && collect($permitidos)->contains(fn ($p) => $host === $p || str_ends_with($host, '.'.$p));
        if (! $ok && $exigir) {
            throw new ErroNegocio('O endereço da AGT tem de usar HTTPS e um domínio da AGT ('.implode(', ', $permitidos).').', 'CONFIG_AGT_INVALIDA', 422);
        }

        return $url;
    }

    public static function impressaoDigital(string $publicaPem): string
    {
        $der = base64_decode(preg_replace('/-----[^-]+-----|\s+/', '', $publicaPem));

        return hash('sha256', $der);
    }

    private function registo(bool $criar): ?LigacaoAgtEmpresa
    {
        $l = LigacaoAgtEmpresa::query()->lockForUpdate()->first();
        if (! $l && $criar) {
            $l = new LigacaoAgtEmpresa(['ambiente' => (string) config('erp.agt.ambiente', 'homologacao') === 'producao' ? 'producao' : 'homologacao', 'ativo' => false, 'chave_versao' => 0]);
        }

        return $l;
    }

    private function nif(bool $exigir): ?string
    {
        $nif = preg_replace('/\s+/', '', (string) Empresa::query()->whereKey($this->contexto->obrigatorio())->value('nif'));
        if (! preg_match('/^[0-9A-Za-z]{9,15}$/', $nif)) {
            if ($exigir) {
                throw new ErroNegocio('A empresa não tem um NIF válido.', 'NIF_INVALIDO', 422);
            }

            return null;
        }

        return $nif;
    }

    private function quem(): ?string
    {
        return Auth::user()?->nome_utilizador;
    }
}
