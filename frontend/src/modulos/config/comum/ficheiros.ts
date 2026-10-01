import { nomeDoContentDisposition } from '@/api/cliente';

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
export const nomeDoCabecalho = nomeDoContentDisposition;
