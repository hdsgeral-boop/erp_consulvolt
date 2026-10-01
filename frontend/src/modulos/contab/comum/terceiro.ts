/** Terceiro como vem nas respostas da Contabilidade (lançamentos e razão): {id, nome, nif}. */
export interface TerceiroLinha {
  id: number;
  nome: string | null;
  nif: string | null;
}

/** Nome do terceiro para mostrar (com o NIF quando pedido); cai para «#id» se o nome não vier e para «—» sem terceiro. */
export function rotuloTerceiro(terceiro: TerceiroLinha | null | undefined, terceiroId?: number | null, comNif = false): string {
  const nome = terceiro?.nome?.trim();
  if (nome) return comNif && terceiro?.nif ? `${nome} (NIF ${terceiro.nif})` : nome;
  const id = terceiro?.id ?? terceiroId;
  return id ? `#${id}` : '—';
}
