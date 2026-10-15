import { filtrarMenu, moverFoco, normalizarTexto, posicaoRealce, semIcones, textoContador, type ItemMenu } from './filtroMenu';

const ARVORE: ItemMenu[] = [
  { key: '/', label: 'Início', title: 'Início', icon: 'i' as never },
  {
    key: 'geral',
    label: 'Menu Geral',
    icon: 'g' as never,
    children: [
      {
        key: 'teso',
        label: 'Tesouraria',
        children: [
          { key: '/m/teso/pag', label: 'Pagamentos', title: 'Pagamentos' },
          { key: '/m/teso/conc', label: 'Reconciliação Bancária', title: 'Reconciliação Bancária' },
        ],
      },
      { key: 'vendas', label: 'Vendas', children: [{ key: '/m/vendas/fat', label: 'Faturação', title: 'Faturação' }] },
    ],
  },
  { key: 'grupo:favoritos', label: 'Favoritos', children: [{ key: 'fav:/m/vendas/fat', label: 'Faturação', title: 'Faturação (Vendas)' }] },
];

describe('filtro em linha do menu (busca_menu.js)', () => {
  it('sem acentos nem maiúsculas, pelo rótulo', () => {
    expect(normalizarTexto('  Reconciliação   Bancária ')).toBe('reconciliacao bancaria');
    const r = filtrarMenu(ARVORE, 'reconciliacao');
    expect(r.folhas).toEqual([{ key: '/m/teso/conc', rotulo: 'Reconciliação Bancária' }]);
    expect(r.abertos).toEqual(['teso', 'geral']);
  });

  it('pelo caminho dos grupos: «tesouraria» traz tudo o que está dentro', () => {
    const r = filtrarMenu(ARVORE, 'TESOURARIA');
    expect(r.folhas.map((f) => f.key)).toEqual(['/m/teso/pag', '/m/teso/conc']);
    // com várias palavras, todas têm de aparecer (no rótulo ou no caminho)
    expect(filtrarMenu(ARVORE, 'tesouraria pag').folhas.map((f) => f.key)).toEqual(['/m/teso/pag']);
    expect(filtrarMenu(ARVORE, 'vendas fat').folhas.map((f) => f.key)).toEqual(['/m/vendas/fat']);
  });

  it('só filtra o que o menu tem (nada é revelado) e grupos vazios desaparecem', () => {
    const r = filtrarMenu(ARVORE, 'xyz');
    expect(r.itens).toEqual([]);
    expect(r.folhas).toEqual([]);
    const f = filtrarMenu(ARVORE, 'faturacao');
    expect(f.folhas.map((x) => x.key)).toEqual(['/m/vendas/fat', 'fav:/m/vendas/fat']);
    expect(f.itens.map((x) => x?.key)).toEqual(['geral', 'grupo:favoritos']);
  });

  it('sem texto devolve a árvore tal como está', () => {
    expect(filtrarMenu(ARVORE, '   ').itens).toBe(ARVORE);
  });

  it('marca o resultado escolhido com as setas e permite realçar o rótulo', () => {
    const r = filtrarMenu(ARVORE, 'tesouraria', '/m/teso/conc', (t) => `«${t}»`);
    const teso = ((r.itens[0] as { children: ItemMenu[] }).children[0] as { children: { className?: string; label: string }[] }).children;
    expect(teso[1].className).toBe('erp-menu-focado');
    expect(teso[0].className).toBeUndefined();
    expect(teso[0].label).toBe('«Pagamentos»');
  });

  it('realce, setas e contador', () => {
    expect(posicaoRealce('Reconciliação Bancária', 'bancaria')).toEqual({ inicio: 14, fim: 22 });
    expect(posicaoRealce('Pagamentos', 'xyz')).toBeNull();
    expect(moverFoco(-1, 1, 3)).toBe(0);
    expect(moverFoco(-1, -1, 3)).toBe(2);
    expect(moverFoco(2, 1, 3)).toBe(0);
    expect(moverFoco(0, 1, 0)).toBe(-1);
    expect(textoContador(0)).toMatch(/Nada encontrado/);
    expect(textoContador(1)).toBe('1 operação encontrada · Enter abre a operação');
    expect(textoContador(4)).toBe('4 operações encontradas · Enter abre a primeira');
  });

  it('«só nomes» retira os ícones de todos os níveis', () => {
    const s = semIcones(ARVORE) as { icon?: unknown; children?: { icon?: unknown }[] }[];
    expect(s[0].icon).toBeUndefined();
    expect(s[1].icon).toBeUndefined();
    expect(s[1].children?.[0].icon).toBeUndefined();
  });
});
