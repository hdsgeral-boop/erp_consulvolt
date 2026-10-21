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

/**
 * Logótipo pronto a gravar: reduzido no browser para no máximo `maximo` px no lado maior (os logótipos são mostrados a
 * ≤ 120 px e nas impressões a poucos cm) e reescrito em PNG (mantém a transparência) ou JPEG (fotografias). Uma imagem
 * de 500 KB fica tipicamente com 10–40 KB, o que acelera a entrada, o menu e a escolha de empresa. Se o browser não a
 * conseguir desenhar, devolve o ficheiro tal como está.
 */
export async function lerLogotipoReduzido(ficheiro: Blob, maximo = 400): Promise<string> {
  const original = await lerComoDataUrl(ficheiro);
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const i = new Image();
      i.onload = () => resolve(i);
      i.onerror = reject;
      i.src = original;
    });
    const escala = Math.min(1, maximo / Math.max(img.naturalWidth, img.naturalHeight));
    const tela = document.createElement('canvas');
    tela.width = Math.max(1, Math.round(img.naturalWidth * escala));
    tela.height = Math.max(1, Math.round(img.naturalHeight * escala));
    const ctx = tela.getContext('2d');
    if (!ctx) return original;
    ctx.imageSmoothingQuality = 'high';
    ctx.drawImage(img, 0, 0, tela.width, tela.height);
    const reduzido = ficheiro.type === 'image/jpeg' ? tela.toDataURL('image/jpeg', 0.85) : tela.toDataURL('image/png');
    return reduzido.length < original.length ? reduzido : original;
  } catch {
    return original;
  }
}

/** Nome do ficheiro no cabeçalho Content-Disposition (filename*=UTF-8'' ou filename=). */
export const nomeDoCabecalho = nomeDoContentDisposition;
