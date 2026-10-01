import { http } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';

/** Lê um ficheiro de imagem como data URL (base64), o formato que a API guarda nos logótipos. */
export function lerComoDataUrl(ficheiro: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const leitor = new FileReader();
    leitor.onload = () => resolve(String(leitor.result));
    leitor.onerror = () => reject(leitor.error);
    leitor.readAsDataURL(ficheiro);
  });
}

/** Nome do ficheiro no cabeçalho Content-Disposition (filename*=UTF-8'' ou filename=). */
export function nomeDoCabecalho(cabecalho: string | undefined | null, padrao: string): string {
  if (!cabecalho) return padrao;
  const utf = /filename\*=UTF-8''([^;]+)/i.exec(cabecalho);
  if (utf) return decodeURIComponent(utf[1].replace(/"/g, ''));
  const simples = /filename="?([^";]+)"?/i.exec(cabecalho);
  return simples ? simples[1] : padrao;
}

/**
 * GET de um ficheiro (blob) e descarga no navegador. Os erros chegam como JSON dentro do blob: lê-se a mensagem do servidor
 * (validateStatus aceita tudo para o interceptor não perder o corpo).
 */
export async function descarregarFicheiro(url: string, params: Record<string, unknown> | undefined, padrao: string): Promise<void> {
  const r = await http.get<Blob>(url, { params, responseType: 'blob', validateStatus: () => true });
  if (r.status >= 400) {
    let mensagem = `Não foi possível obter o ficheiro (${r.status}).`;
    let codigo: string | undefined;
    let erros: Record<string, unknown> | undefined;
    try {
      const j = JSON.parse(await r.data.text()) as { mensagem?: string; codigo?: string; erros?: Record<string, unknown> };
      mensagem = j.mensagem ?? mensagem;
      codigo = j.codigo;
      erros = j.erros;
    } catch {
      /* corpo não é JSON */
    }
    throw new ErroApi(mensagem, r.status, codigo, erros);
  }
  const nome = nomeDoCabecalho(r.headers['content-disposition'] as string | undefined, padrao);
  const ligacao = URL.createObjectURL(r.data);
  const a = document.createElement('a');
  a.href = ligacao;
  a.download = nome;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(ligacao);
}
