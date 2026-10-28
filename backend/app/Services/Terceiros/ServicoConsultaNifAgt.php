<?php

namespace App\Services\Terceiros;

use App\Exceptions\ErroNegocio;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Validação de NIF no Portal do Contribuinte da AGT (consultar-nif-do-contribuinte), servidor-a-servidor — nunca
 * chamada directa do browser (CORS, e para não expor a nossa origem ao portal). Confirmado manualmente em 2026-10-28:
 * o portal é uma aplicação Oracle ADF/JSF com PrimeFaces; a pesquisa é o pedido AJAX parcial que o botão «Pesquisar»
 * dispara (PrimeFaces.ab), que exige, na MESMA sessão (cookies), primeiro um GET da página (para ler o
 * `javax.faces.ViewState` do momento) e só depois o POST com esse ViewState — sem isto o portal devolve sempre
 * «NIF não encontrado». A resposta é XML, com o fragmento HTML do resultado dentro de CDATA. Sem CAPTCHA (confirmado
 * pelo utilizador).
 *
 * O servidor nem sempre envia o intermédio da cadeia TLS (DigiCert Global G2 TLS RSA SHA256 2020 CA1) e a imagem
 * Docker do backend não confia na raiz DigiCert Global Root G2 — por isso usa-se um ficheiro CA próprio
 * (resources/certs/agt-portal-ca.pem, obtido directamente de cacerts.digicert.com) em vez de desligar a verificação
 * do certificado (nunca `verify => false`).
 */
final class ServicoConsultaNifAgt
{
    private const URL_PAGINA = 'https://portaldocontribuinte.minfin.gov.ao/consultar-nif-do-contribuinte';

    private const URL_CONSULTA = 'https://portaldocontribuinte.minfin.gov.ao/consultar-headNifId-do-contribuinte';

    private const ID_BOTAO = 'j_id_2x:j_id_34';

    private const ID_FORM = 'j_id_2x';

    private const ID_CAMPO_NIF = 'j_id_2x:txtNIFNumber';

    private const ID_PAINEL = 'showpanelNIF';

    /**
     * @return array{encontrado: bool, nif?: string, nome?: string, tipo?: string, estado?: string, inadimplente?: string,
     *     regime_iva?: string, residente_fiscal?: string, mensagem?: string}
     */
    public function consultar(string $nif): array
    {
        $nif = trim($nif);
        if ($nif === '') {
            throw new ErroNegocio('Indique o NIF a validar.', 'NIF_OBRIGATORIO', 422);
        }

        $opcoes = [
            'cookies' => new CookieJar(),   // partilhado entre o GET e o POST — o ViewState é por sessão
            'verify' => resource_path('certs/agt-portal-ca.pem'),
            'timeout' => 15,
            'connect_timeout' => 8,
        ];

        try {
            $pagina = Http::withOptions($opcoes)->get(self::URL_PAGINA);
            $viewState = $this->viewState($pagina->body());
            if ($viewState === null) {
                throw new ErroNegocio('O portal da AGT devolveu uma página inesperada (estrutura mudou?).', 'AGT_RESPOSTA_INESPERADA', 502);
            }

            $resposta = Http::withOptions($opcoes)->asForm()->withHeaders([
                'Faces-Request' => 'partial/ajax',
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/xml, text/xml, */*; q=0.01',
                'Origin' => 'https://portaldocontribuinte.minfin.gov.ao',
                'Referer' => self::URL_PAGINA,
            ])->post(self::URL_CONSULTA, [
                'javax.faces.partial.ajax' => 'true',
                'javax.faces.source' => self::ID_BOTAO,
                'javax.faces.partial.execute' => self::ID_FORM,
                'javax.faces.partial.render' => self::ID_PAINEL,
                self::ID_BOTAO => self::ID_BOTAO,
                self::ID_CAMPO_NIF => $nif,
                self::ID_FORM.'_SUBMIT' => '1',
                'javax.faces.ViewState' => $viewState,
            ]);
        } catch (ErroNegocio $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning('Consulta de NIF na AGT falhou', ['nif' => $nif, 'erro' => $e->getMessage()]);

            throw new ErroNegocio('Não foi possível contactar o portal da AGT. Tente novamente dentro de momentos.', 'AGT_INDISPONIVEL', 502);
        }

        return $this->interpretar($resposta->body());
    }

    private function viewState(string $html): ?string
    {
        return preg_match('/name="javax\.faces\.ViewState"[^>]*value="([^"]*)"/', $html, $m) ? $m[1] : null;
    }

    /** @return array<string, mixed> */
    private function interpretar(string $xml): array
    {
        if (str_contains($xml, '"validationFailed":true')) {
            throw new ErroNegocio('O portal da AGT recusou o pedido.', 'AGT_RESPOSTA_INESPERADA', 502);
        }
        $painel = $this->cdata($xml, self::ID_PAINEL);
        if ($painel === null) {
            throw new ErroNegocio('O portal da AGT devolveu uma resposta inesperada (estrutura mudou?).', 'AGT_RESPOSTA_INESPERADA', 502);
        }
        if (str_contains($painel, 'taxPayerNidId')) {
            return ['encontrado' => true] + $this->camposDoPainel($painel);
        }
        $mensagem = preg_match('/detail:"([^"]*)"/', $xml, $m) && $m[1] !== '' ? $m[1] : 'NIF não encontrado.';

        return ['encontrado' => false, 'mensagem' => $mensagem];
    }

    /** Conteúdo (HTML) do CDATA de «<update id="$id">…</update>» na resposta parcial do JSF. */
    private function cdata(string $xml, string $id): ?string
    {
        $padrao = '/<update id="'.preg_quote($id, '/').'"><!\[CDATA\[(.*?)\]\]><\/update>/s';

        return preg_match($padrao, $xml, $m) ? $m[1] : null;
    }

    /**
     * Cada linha do painel é `<div class="form-group"><label>Título: </label><div><label>Valor</label>…</div></div>`.
     * A linha «Residente Fiscal» tem o título comentado no próprio portal (fica sem texto) — por isso o título vazio
     * é tratado como esse campo, já que é o único na grelha sem rótulo visível.
     *
     * @return array<string, string>
     */
    private function camposDoPainel(string $html): array
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($doc);
        $campos = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " form-group ")]') as $grupo) {
            $titulo = $xpath->query('./label[1]', $grupo)->item(0);
            $valorDiv = $xpath->query('./div[1]', $grupo)->item(0);
            $valor = $valorDiv ? $xpath->query('./label[1]', $valorDiv)->item(0) : null;
            if (! $titulo || ! $valor) {
                continue;
            }
            $texto = trim($titulo->textContent);
            $chave = $texto === '' ? 'residente_fiscal' : $this->chaveDoRotulo($texto);
            if ($chave !== null) {
                $campos[$chave] = trim($valor->textContent);
            }
        }

        return $campos;
    }

    private function chaveDoRotulo(string $rotulo): ?string
    {
        return match (rtrim(trim($rotulo), ': ')) {
            'NIF' => 'nif',
            'Nome' => 'nome',
            'Tipo' => 'tipo',
            'Estado' => 'estado',
            'Inadimplente' => 'inadimplente',
            'Regime de IVA' => 'regime_iva',
            default => null,
        };
    }
}
