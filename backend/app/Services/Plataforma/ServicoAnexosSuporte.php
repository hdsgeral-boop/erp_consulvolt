<?php

namespace App\Services\Plataforma;

use App\Exceptions\ErroNegocio;
use App\Models\AnexoSuporte;
use App\Models\MensagemSuporte;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Anexos das mensagens de um ticket de suporte (conversa cliente ↔ equipa).
 *
 * Segurança (mesmo padrão de ServicoImagemProduto — OWASP A01/A04/A05/A08):
 *   - só imagens raster (JPEG, PNG, WebP) ou PDF, até 5 MB, no máximo 3 por mensagem — o tipo é o detectado pelo
 *     conteúdo (finfo), nunca o nome nem o Content-Type enviados;
 *   - nome gerado no servidor (UUID); o nome original só é guardado para mostrar, nunca usado como caminho;
 *   - disco privado («local», fora do public/), numa pasta por ticket: suporte/{ticket_id}/{uuid}.{ext};
 *   - servido só por um GET autenticado e autorizado (quem pode ver a conversa), nunca por URL directa.
 */
final class ServicoAnexosSuporte
{
    public const DISCO = 'local';

    public const TAMANHO_MAXIMO_KB = 5120;

    public const MAX_FICHEIROS = 3;

    /** Tipos aceites (detectados pelo conteúdo) → extensão gravada. */
    public const TIPOS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function guardar(MensagemSuporte $mensagem, UploadedFile $ficheiro): AnexoSuporte
    {
        if ($ficheiro->getSize() > self::TAMANHO_MAXIMO_KB * 1024) {
            throw new ErroNegocio('Cada anexo não pode exceder 5 MB.', 'ANEXO_GRANDE', 422);
        }
        $mime = $this->tipoReal($ficheiro);
        $extensao = self::TIPOS[$mime] ?? throw new ErroNegocio('Só são aceites imagens (JPEG, PNG, WebP) ou PDF.', 'ANEXO_TIPO_INVALIDO', 422);
        $pasta = "suporte/{$mensagem->ticket_id}";
        $nome = Str::uuid()->toString().'.'.$extensao;
        $caminho = Storage::disk(self::DISCO)->putFileAs($pasta, $ficheiro, $nome);
        if (! $caminho) {
            throw new ErroNegocio('Não foi possível guardar o anexo.', 'ANEXO_NAO_GRAVADO', 500);
        }

        return AnexoSuporte::create([
            'mensagem_id' => $mensagem->id,
            'nome_original' => mb_substr($ficheiro->getClientOriginalName(), 0, 255),
            'caminho' => $caminho, 'tamanho_bytes' => $ficheiro->getSize(), 'tipo_mime' => $mime,
        ]);
    }

    /** @return array{conteudo: string, mime: string}|null */
    public function conteudo(AnexoSuporte $anexo): ?array
    {
        if (! $this->caminhoValido($anexo->caminho, (int) $anexo->mensagem->ticket_id) || ! Storage::disk(self::DISCO)->exists($anexo->caminho)) {
            return null;
        }

        return ['conteudo' => (string) Storage::disk(self::DISCO)->get($anexo->caminho), 'mime' => $anexo->tipo_mime];
    }

    private function tipoReal(UploadedFile $ficheiro): string
    {
        $caminho = $ficheiro->getRealPath();

        return $caminho ? ((new \finfo(FILEINFO_MIME_TYPE))->file($caminho) ?: '') : '';
    }

    /** O caminho tem de estar na pasta do próprio ticket (nunca se lê fora dela, nem com «..»). */
    private function caminhoValido(string $caminho, int $ticketId): bool
    {
        return str_starts_with($caminho, "suporte/{$ticketId}/") && ! str_contains($caminho, '..');
    }
}
