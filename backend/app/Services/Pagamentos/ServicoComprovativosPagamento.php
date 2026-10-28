<?php

namespace App\Services\Pagamentos;

use App\Exceptions\ErroNegocio;
use App\Models\AnexoComprovativoPagamento;
use App\Models\SubscricaoEmpresa;
use App\Models\TransacaoGateway;
use App\Models\Utilizador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Comprovativo de transferência bancária da subscrição SaaS (motor de precificação, Módulo C). Mesmo padrão de
 * segurança de ServicoAnexosSuporte (OWASP A01/A04/A05/A08): tipo pelo conteúdo (finfo), nome UUID, disco privado,
 * servido só por um GET autenticado do próprio tenant.
 *
 * Ao anexar, cria (ou reutiliza, se já pendente) a TransacaoGateway MANUAL desta subscrição — é essa transacção que
 * o backoffice confirma/rejeita (TransferenciasBancariasController, Fase 4).
 */
final class ServicoComprovativosPagamento
{
    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 5120;

    /** Tipos aceites (detectados pelo conteúdo) → extensão gravada. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    public function anexar(SubscricaoEmpresa $subscricao, UploadedFile $ficheiro, ?Utilizador $utilizador, ?string $ip): AnexoComprovativoPagamento
    {
        if ($subscricao->estado !== SubscricaoEmpresa::PENDENTE) {
            throw new ErroNegocio('Só se pode anexar comprovativo a um pedido pendente.', 'SUBSCRICAO_NAO_PENDENTE', 422);
        }
        if ($ficheiro->getSize() > self::TAMANHO_MAXIMO_KB * 1024) {
            throw new ErroNegocio('O comprovativo não pode exceder 5 MB.', 'ANEXO_GRANDE', 422);
        }
        $mime = $this->tipoReal($ficheiro);
        $extensao = self::TIPOS[$mime] ?? throw new ErroNegocio('Só são aceites imagens (JPEG, PNG) ou PDF.', 'ANEXO_TIPO_INVALIDO', 422);

        $transacao = TransacaoGateway::query()->where('subscricao_id', $subscricao->id)
            ->where('gateway', TransacaoGateway::GATEWAY_MANUAL)->where('estado', TransacaoGateway::ESTADO_PENDENTE)->first()
            ?? TransacaoGateway::query()->create([
                'subscricao_id' => $subscricao->id, 'gateway' => TransacaoGateway::GATEWAY_MANUAL,
                'valor' => $subscricao->valor, 'moeda' => $subscricao->moeda, 'estado' => TransacaoGateway::ESTADO_PENDENTE,
            ]);

        $pasta = "comprovativos/{$subscricao->id}";
        $nome = Str::uuid()->toString().'.'.$extensao;
        $caminho = Storage::disk(self::DISCO)->putFileAs($pasta, $ficheiro, $nome);
        if (! $caminho) {
            throw new ErroNegocio('Não foi possível guardar o comprovativo.', 'ANEXO_NAO_GRAVADO', 500);
        }

        return AnexoComprovativoPagamento::query()->create([
            'subscricao_id' => $subscricao->id, 'transacao_id' => $transacao->id, 'empresa_id' => $subscricao->empresa_id,
            'nome_original' => mb_substr($ficheiro->getClientOriginalName(), 0, 255),
            'caminho' => $caminho, 'tamanho_bytes' => $ficheiro->getSize(), 'tipo_mime' => $mime,
            'enviado_por' => $utilizador?->getKey(), 'ip' => $ip,
        ]);
    }

    /** @return array{conteudo: string, mime: string}|null */
    public function conteudo(AnexoComprovativoPagamento $anexo): ?array
    {
        if (! $this->caminhoValido($anexo->caminho, (int) $anexo->subscricao_id) || ! Storage::disk(self::DISCO)->exists($anexo->caminho)) {
            return null;
        }

        return ['conteudo' => (string) Storage::disk(self::DISCO)->get($anexo->caminho), 'mime' => $anexo->tipo_mime];
    }

    private function tipoReal(UploadedFile $ficheiro): string
    {
        $caminho = $ficheiro->getRealPath();

        return $caminho ? ((new \finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '') : '';
    }

    /** O caminho tem de estar na pasta da própria subscrição (nunca se lê fora dela, nem com «..»). */
    private function caminhoValido(string $caminho, int $subscricaoId): bool
    {
        return str_starts_with($caminho, "comprovativos/{$subscricaoId}/") && ! str_contains($caminho, '..');
    }
}
