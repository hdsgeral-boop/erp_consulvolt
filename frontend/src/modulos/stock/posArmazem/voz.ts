/**
 * Voz do POS de armazém (legado speakSentence, js/ui_pos_armazem.js:794-801: síntese de voz em português, velocidade 1).
 * Usada pelo monitor de senhas e pelas conclusões do balcão e do picking. A preferência «Voz ligada/desligada» é do posto.
 */

export const CHAVE_SOM = 'pos_wh_senhas_som';

export function vozLigada(): boolean {
  try {
    return localStorage.getItem(CHAVE_SOM) !== 'nao';
  } catch {
    return true;
  }
}

export function gravarVoz(ligada: boolean): void {
  try {
    localStorage.setItem(CHAVE_SOM, ligada ? 'sim' : 'nao');
  } catch {
    /* sem armazenamento: vale só nesta sessão */
  }
}

/** Diz a frase em voz alta (pt-PT). Sem síntese de voz no navegador não faz nada. */
export function anunciar(texto: string): void {
  if (typeof window === 'undefined' || !('speechSynthesis' in window)) return;
  try {
    const frase = new SpeechSynthesisUtterance(texto);
    frase.lang = 'pt-PT';
    frase.rate = 1;
    window.speechSynthesis.speak(frase);
  } catch {
    /* sem voz disponível */
  }
}

/** Anuncia só se a voz do posto estiver ligada. */
export function anunciarSeLigada(texto: string): void {
  if (vozLigada()) anunciar(texto);
}
