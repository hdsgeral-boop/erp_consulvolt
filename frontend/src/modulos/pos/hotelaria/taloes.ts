/**
 * Talão do check-out da hotelaria. O legado imprimia UM talão com as linhas de todas as facturas (docNumber = documentos
 * separados por vírgula, «mesa» = quartos; js/hotelaria.js:690-697); o sistema novo emite uma factura-recibo por quarto (ou
 * uma única) e por omissão imprime um talão por documento. O talão único (F-181, Q10) é opção do operador: junta as facturas
 * numa só vista (linhas, totais e pagamentos somados) com todos os números de documento no título.
 */
import { deCentimos, paraCentimos } from '@/utilitarios/decimal';
import type { VendaEmitida } from '../comum/tipos';

/** Soma os pagamentos de várias facturas por meio (o troco só existe na última factura, por isso também se soma). */
function somarPagamentos(vendas: VendaEmitida[]): VendaEmitida['pos_pagamentos'] {
  const porMeio = new Map<string, NonNullable<VendaEmitida['pos_pagamentos']>[number]>();
  for (const v of vendas) {
    for (const p of v.pos_pagamentos ?? []) {
      const chave = `${p.meio_id ?? p.tipo}|${p.referencia ?? ''}`;
      const actual = porMeio.get(chave);
      porMeio.set(chave, actual ? { ...actual, valor: deCentimos(paraCentimos(actual.valor) + paraCentimos(p.valor)) } : { ...p, valor: deCentimos(paraCentimos(p.valor)) });
    }
  }
  return [...porMeio.values()];
}

/** Uma só «venda» para o talão: linhas concatenadas, totais somados, documentos e quartos juntos. Com uma factura devolve-a tal e qual. */
export function juntarVendas(vendas: VendaEmitida[]): VendaEmitida {
  if (vendas.length === 1) return vendas[0];
  const soma = (campo: 'total_liquido' | 'total_imposto' | 'total_bruto' | 'desconto' | 'pos_troco' | 'arredondamento_agt') =>
    deCentimos(vendas.reduce((t, v) => t + paraCentimos(v[campo] as string | null | undefined), 0));
  const quartos = [...new Set(vendas.flatMap((v) => (v.nome_tabela ?? '').split(',').map((q) => q.trim()).filter(Boolean)))];
  return {
    ...vendas[0],
    numero_documento: vendas.map((v) => v.numero_documento).join(', '),
    total_liquido: soma('total_liquido'),
    total_imposto: soma('total_imposto'),
    total_bruto: soma('total_bruto'),
    desconto: soma('desconto'),
    pos_troco: soma('pos_troco'),
    arredondamento_agt: soma('arredondamento_agt'),
    nome_tabela: quartos.join(', ') || null,
    pos_pagamentos: somarPagamentos(vendas),
    itens_venda: vendas.flatMap((v) => v.itens_venda ?? []),
  };
}
