import type { AccaoLinha } from '@/componentes/vistas';

/** Edição de um produto: novo, editar ou copiar (copyProduct do legado). */
export interface EdicaoProduto {
  modo: 'novo' | 'editar' | 'copiar';
  id?: number;
}

/** Cor do distintivo da coluna «STOCK» (legado: ≤ 0 vermelho, < 5 laranja, senão verde). */
export function corStock(q: number): 'red' | 'orange' | 'green' {
  if (q <= 0) return 'red';
  if (q < 5) return 'orange';
  return 'green';
}

/**
 * Acções por linha do catálogo, pela ordem e com os ícones do legado (renderProdutosTab, js/ui_sales.js:569-572):
 * Editar (fa-edit), Copiar (fa-copy), Bloquear/Desbloquear Artigo (fa-unlock / fa-lock, amarelo quando bloqueado)
 * e Apagar (fa-trash, vermelho cheio, com confirmação). Editar/Copiar/Bloquear exigem vendas_produtos_gerir; Apagar
 * exige vendas_dados_del (o servidor recusa se o produto tiver documentos ou movimentos).
 */
export function accoesProduto(
  p: { nome: string; bloqueado: boolean },
  permissoes: { podeGerir: boolean; podeEliminar: boolean },
  fazer: { editar: () => void; copiar: () => void; bloquear: () => unknown; eliminar: () => unknown },
): AccaoLinha[] {
  const lista: AccaoLinha[] = [];
  if (permissoes.podeGerir) {
    lista.push(
      { chave: 'editar', icone: 'fa-edit', titulo: 'Editar', onClick: fazer.editar },
      { chave: 'copiar', icone: 'fa-copy', titulo: 'Copiar', onClick: fazer.copiar },
      {
        chave: 'bloquear',
        icone: p.bloqueado ? 'fa-lock' : 'fa-unlock',
        titulo: p.bloqueado ? 'Desbloquear Artigo' : 'Bloquear Artigo',
        variante: p.bloqueado ? 'aviso' : 'normal',
        cheio: p.bloqueado,
        onClick: fazer.bloquear,
      },
    );
  }
  if (permissoes.podeEliminar) {
    lista.push({
      chave: 'apagar',
      icone: 'fa-trash',
      titulo: 'Apagar',
      variante: 'perigo',
      cheio: true,
      confirmar: { titulo: `Eliminar ${p.nome}?`, texto: 'É recusado se o produto tiver documentos ou movimentos (nesse caso, bloqueie-o).', textoOk: 'Eliminar', perigo: true },
      onClick: fazer.eliminar,
    });
  }
  return lista;
}
