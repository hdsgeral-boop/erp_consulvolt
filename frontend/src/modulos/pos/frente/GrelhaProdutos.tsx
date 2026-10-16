import { Alert, Button, Checkbox, Input, type InputRef } from 'antd';
import { useMemo, useState, type CSSProperties, type Ref } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarKz } from '@/utilitarios/formatacao';
import { useImagemProduto } from '@/modulos/vendas/produtos/imagemProduto';
import { contarCategorias, corStock, eServico, filtrarPorStock, iconeCategoria, urlImagemSegura, stockDe, type FiltroStock, type PreferenciasFrente, type StockFrente } from '../comum/frente';
import { filtrarProdutos } from '../comum/produtos';
import { useCoresPos } from '../comum/cores';
import type { ProdutoPOS } from '../comum/tipos';

const MAX_GRELHA = 48;

const COR_ETIQUETA = { error: { fundo: '#fee2e2', texto: '#b91c1c' }, warning: { fundo: '#fef3c7', texto: '#b45309' }, success: { fundo: '#dcfce7', texto: '#15803d' } };

interface Props {
  produtos: ProdutoPOS[];
  categorias: { id: number; nome: string }[] | undefined;
  stock: StockFrente['stock'] | undefined;
  imagens: Record<string, string> | undefined;
  pesquisa: string;
  aoPesquisar: (texto: string) => void;
  aoLer: () => void;
  aoEscolher: (p: ProdutoPOS) => void;
  preferencias: PreferenciasFrente;
  aoMudarPreferencias: (p: Partial<PreferenciasFrente>) => void;
  refPesquisa: Ref<InputRef>;
  carregando?: boolean;
  erro?: boolean;
}

const pilula = (activo: boolean, cor: string, fundo: string): CSSProperties => ({
  border: 'none', borderRadius: 4, padding: '0 0.85rem', height: 38, fontWeight: 700, fontSize: '0.78rem', cursor: 'pointer',
  color: activo ? cor : '#64748b', background: activo ? fundo : 'transparent',
});

const caixaFiltro = (fundo: string, borda: string): CSSProperties => ({ display: 'flex', background: fundo, padding: 4, borderRadius: 30, border: `1px solid ${borda}`, gap: 4, height: 46, alignItems: 'center', flexShrink: 0 });

/**
 * Pesquisa, filtro de stock, «Ocultar Imagens», grelha/lista, chips de categoria com contagem e cartões de produto do
 * legado (renderPOSTab + renderPOSProductsGrid, js/ui_sales.js:6602-6650 e 6893-7044).
 */
export function GrelhaProdutos({ produtos, categorias, stock, imagens, pesquisa, aoPesquisar, aoLer, aoEscolher, preferencias, aoMudarPreferencias, refPesquisa, carregando, erro }: Props) {
  const cores = useCoresPos();
  const caixa = caixaFiltro(cores.fundo, cores.borda);
  const [categoria, setCategoria] = useState<number | null>(null);
  const [limite, setLimite] = useState(MAX_GRELHA);
  const lista = preferencias.disposicao === 'list';
  const nomesCategoria = useMemo(() => new Map((categorias ?? []).map((c) => [c.id, c.nome])), [categorias]);
  const chips = useMemo(() => contarCategorias(produtos, categorias ?? []), [produtos, categorias]);
  const visiveis = useMemo(
    () => filtrarPorStock(filtrarProdutos(produtos, pesquisa, categoria), preferencias.filtroStock, stock),
    [produtos, pesquisa, categoria, preferencias.filtroStock, stock],
  );

  const filtroStock = (f: FiltroStock, rotulo: string, cor: string, fundo: string) => (
    <button type="button" aria-pressed={preferencias.filtroStock === f} onClick={() => aoMudarPreferencias({ filtroStock: f })} style={pilula(preferencias.filtroStock === f, cor, fundo)}>
      {rotulo}
    </button>
  );

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: '0.85rem', minWidth: 0 }}>
      <div style={{ display: 'flex', gap: '0.5rem', alignItems: 'center', flexWrap: 'wrap' }}>
        <Input
          ref={refPesquisa}
          size="large"
          autoFocus
          allowClear
          prefix={<IconeFa nome="search" style={{ color: '#94a3b8' }} />}
          placeholder="Procurar produtos por nome, Código ou categoria... (F2)"
          aria-label="Procurar produtos"
          title="Enter adiciona · 3*CÓDIGO para a quantidade"
          style={{ flex: '1 1 240px', minWidth: 0, borderRadius: 30, height: 46 }}
          value={pesquisa}
          onChange={(e) => {
            aoPesquisar(e.target.value);
            setLimite(MAX_GRELHA);
          }}
          onPressEnter={aoLer}
        />
        <div role="group" aria-label="Filtro de stock" style={caixa}>
          {filtroStock('all', 'Todos', '#2563eb', 'rgba(59,130,246,0.1)')}
          {filtroStock('in', 'Com Stock', '#059669', 'rgba(16,185,129,0.1)')}
          {filtroStock('out', 'Sem Stock', '#dc2626', 'rgba(239,68,68,0.1)')}
        </div>
        <label style={{ ...caixa, padding: '0 1rem', fontSize: '0.8rem', fontWeight: 700, color: '#64748b', cursor: 'pointer', userSelect: 'none' }}>
          <Checkbox checked={preferencias.ocultarImagens} onChange={(e) => aoMudarPreferencias({ ocultarImagens: e.target.checked })} />
          Ocultar Imagens
        </label>
        <div role="group" aria-label="Disposição" style={{ ...caixa, gap: 2 }}>
          {(['grid', 'list'] as const).map((d) => (
            <button
              key={d}
              type="button"
              aria-pressed={preferencias.disposicao === d}
              title={d === 'grid' ? 'Visualização em Grelha' : 'Visualização em Lista'}
              aria-label={d === 'grid' ? 'Visualização em Grelha' : 'Visualização em Lista'}
              onClick={() => aoMudarPreferencias({ disposicao: d })}
              style={{ ...pilula(preferencias.disposicao === d, '#2563eb', 'rgba(59,130,246,0.1)'), width: 38, padding: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}
            >
              <IconeFa nome={d === 'grid' ? 'th' : 'list'} />
            </button>
          ))}
        </div>
      </div>

      {chips.length > 0 && (
        <div role="group" aria-label="Categorias" style={{ display: 'flex', gap: '0.5rem', overflowX: 'auto', paddingBottom: '0.4rem' }}>
          {[{ id: null as number | null, nome: 'Todos', total: produtos.length, icone: 'border-all' }, ...chips.map((c) => ({ ...c, icone: iconeCategoria(c.nome) }))].map((c) => {
            const activo = categoria === c.id;
            return (
              <button
                key={c.id ?? 'todos'}
                type="button"
                aria-pressed={activo}
                onClick={() => {
                  setCategoria(c.id);
                  setLimite(MAX_GRELHA);
                }}
                style={{ fontSize: '0.8rem', padding: '0.45rem 1rem', borderRadius: 4, display: 'flex', alignItems: 'center', gap: '0.35rem', whiteSpace: 'nowrap', cursor: 'pointer', fontWeight: 600,
                  border: `1px solid ${activo ? '#2563eb' : '#cbd5e1'}`, background: activo ? '#2563eb' : cores.fundo, color: activo ? '#fff' : '#475569' }}
              >
                <IconeFa nome={c.icone} /> {c.nome}
                <span style={{ background: activo ? 'rgba(255,255,255,0.25)' : 'rgba(0,0,0,0.06)', fontSize: '0.7rem', borderRadius: 4, padding: '1px 6px', marginLeft: 3 }}>{c.total}</span>
              </button>
            );
          })}
        </div>
      )}

      {erro && <Alert type="error" showIcon message="Não foi possível carregar o catálogo de produtos." />}
      {!carregando && visiveis.length === 0 ? (
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', color: '#94a3b8', padding: '5rem 0' }}>
          <IconeFa nome="box-open" style={{ fontSize: '3rem', marginBottom: '1rem', color: '#cbd5e1' }} />
          <span style={{ fontWeight: 600 }}>Nenhum Produto Encontrado</span>
          <span style={{ fontSize: '0.75rem', marginTop: 4 }}>Tente outra categoria ou outro termo na pesquisa.</span>
        </div>
      ) : (
        <div
          className="pos-grelha-produtos"
          style={lista ? { display: 'flex', flexDirection: 'column', gap: '0.5rem' } : { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(170px, 1fr))', gap: '1rem', alignContent: 'start' }}
        >
          {visiveis.slice(0, limite).map((p) => (
            <CartaoProduto
              key={p.id}
              produto={p}
              lista={lista}
              stock={stockDe(p, stock)}
              urlImagem={preferencias.ocultarImagens ? null : urlImagemSegura(imagens?.[String(p.id)])}
              ocultarAvatar={preferencias.ocultarImagens}
              icone={iconeCategoria(p.categoria_produto_id ? nomesCategoria.get(p.categoria_produto_id) : null)}
              aoEscolher={aoEscolher}
            />
          ))}
        </div>
      )}
      {visiveis.length > limite && (
        <div style={{ display: 'flex', justifyContent: 'center' }}>
          <Button onClick={() => setLimite((l) => l + MAX_GRELHA)}>Mostrar mais ({visiveis.length - limite})</Button>
        </div>
      )}
    </div>
  );
}

function CartaoProduto({ produto: p, lista, stock, urlImagem, ocultarAvatar, icone, aoEscolher }: { produto: ProdutoPOS; lista: boolean; stock: number | null; urlImagem: string | null; ocultarAvatar: boolean; icone: string; aoEscolher: (p: ProdutoPOS) => void }) {
  const cores = useCoresPos();
  const servico = eServico(p);
  // imagem do produto (módulo de Vendas): lida com autenticação e mostrada como blob; sem imagem fica o ícone da categoria
  const imagem = useImagemProduto(urlImagem);
  // como no legado, um produto de inventário esgotado não se adiciona (o servidor também recusa a venda)
  const esgotado = !servico && stock !== null && stock <= 0;
  const etiqueta = servico ? (
    <span style={{ fontSize: '0.65rem', padding: '2px 8px', borderRadius: 4, background: 'rgba(124,58,237,0.1)', color: '#7c3aed', border: '1px solid rgba(124,58,237,0.2)', fontWeight: 700 }}>Serviço</span>
  ) : stock !== null ? (
    <span style={{ fontSize: '0.65rem', padding: '2px 8px', borderRadius: 4, fontWeight: 700, background: COR_ETIQUETA[corStock(stock)].fundo, color: COR_ETIQUETA[corStock(stock)].texto }}>
      Stock: {Number.isInteger(stock) ? stock : stock.toLocaleString('pt-PT')}
    </span>
  ) : null;
  const avatar = ocultarAvatar ? null : (
    <div style={{ width: lista ? 36 : 50, height: lista ? 36 : 50, borderRadius: lista ? 4 : 4, background: 'rgba(59,130,246,0.06)', display: 'flex', alignItems: 'center', justifyContent: 'center', overflow: 'hidden', flexShrink: 0 }}>
      {imagem ? <img src={imagem} alt="" style={{ width: '100%', height: '100%', objectFit: 'cover' }} /> : <IconeFa nome={icone} style={{ fontSize: lista ? '1.1rem' : '1.5rem', color: '#2563eb' }} />}
    </div>
  );
  const comum: CSSProperties = {
    background: cores.fundo, border: `1px solid ${cores.borda}`, cursor: esgotado ? 'not-allowed' : 'pointer', position: 'relative', overflow: 'hidden', textAlign: 'left',
    boxShadow: '0 4px 6px -1px rgba(0,0,0,0.02)', opacity: esgotado ? 0.65 : 1, font: 'inherit', color: 'inherit', width: '100%',
  };
  const codigo = <small style={{ fontSize: '0.65rem', color: '#94a3b8', display: 'block', marginTop: 2 }}>Cd: {p.codigo?.trim() || '—'}</small>;
  const preco = <strong style={{ fontSize: lista ? '0.9rem' : '1rem', color: '#2563eb', fontWeight: 800 }}>{formatarKz(p.preco_unitario)} Kz</strong>;

  return (
    <button type="button" className="pos-cartao-produto" title={p.nome} aria-disabled={esgotado} onClick={() => !esgotado && aoEscolher(p)}
      style={lista ? { ...comum, borderRadius: 4, padding: '0.6rem 1rem', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '1rem' } : { ...comum, borderRadius: 4, padding: '1rem', display: 'flex', flexDirection: 'column', gap: '0.5rem' }}
    >
      {lista ? (
        <>
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', flex: 1, minWidth: 0 }}>
            {avatar}
            <div style={{ flex: 1, minWidth: 0 }}>
              <strong style={{ fontSize: '0.82rem', color: cores.texto, display: 'block', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>{p.nome}</strong>
              {codigo}
            </div>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: '1rem', flexShrink: 0 }}>
            {etiqueta}
            <span style={{ minWidth: 85, textAlign: 'right' }}>{preco}</span>
          </div>
        </>
      ) : (
        <>
          {etiqueta && <span style={{ position: 'absolute', top: '0.5rem', right: '0.5rem' }}>{etiqueta}</span>}
          {avatar && <div style={{ marginBottom: '0.25rem' }}>{avatar}</div>}
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', width: '100%', marginTop: avatar ? 0 : 18 }}>
            <div>
              <strong style={{ fontSize: '0.8rem', color: cores.texto, display: '-webkit-box', WebkitLineClamp: 2, WebkitBoxOrient: 'vertical', overflow: 'hidden', lineHeight: 1.3, height: '2.6em' }}>{p.nome}</strong>
              {codigo}
            </div>
            <div style={{ marginTop: '0.75rem', textAlign: 'right' }}>{preco}</div>
          </div>
        </>
      )}
    </button>
  );
}
