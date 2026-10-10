import { Button, Input, InputNumber, Modal, Result, Skeleton, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { SeletorArmazem, SeletorTerceiro } from '@/modulos/compras/comum/Seletores';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { adicionarAoCarrinho, alterarQuantidade, type ItemCarrinho } from '../comum/carrinho';
import type { GuiaSaida } from '../comum/tipos';
import {
  corStock, etiquetaStock, filtrarArtigos, formatarUnidades, totalPicking,
  type ArtigoPos, type DiferencaStock, type FiltroStock, type ModoArtigos,
} from './logica';
import './posArmazem.css';

const CHAVE_MODO = 'pos_wh_layout_mode';
const CHAVE_FILTRO = 'pos_wh_stock_filter';

function lerPreferencia<T extends string>(chave: string, validos: readonly T[], omissao: T): T {
  try {
    const v = localStorage.getItem(chave) as T | null;
    return v && validos.includes(v) ? v : omissao;
  } catch {
    return omissao;
  }
}
function gravarPreferencia(chave: string, valor: string) {
  try {
    localStorage.setItem(chave, valor);
  } catch {
    /* navegação privada: fica só nesta sessão */
  }
}

/**
 * «🖥 Atendimento ao Balcão» (renderPOSBalcaoTab, js/ui_pos_armazem.js:37-150): catálogo do armazém em cartões
 * (grelha/lista), filtros «Todos / Com Stock / Sem Stock», «Pesquisar…», aviso de stock divergente com «⚖ Acertar stock
 * deste armazém» e o «🛒 Carrinho de Picking» com «TOTAL DO PICKING» e «✔ Confirmar Saída e Expedir» (guia de saída).
 */
export function Balcao({ armazem, aoMudarArmazem }: { armazem: number; aoMudarArmazem: (id: number) => void }) {
  const { pode } = useSessao();
  const [pesquisa, setPesquisa] = useState('');
  const [filtro, setFiltroEstado] = useState<FiltroStock>(() => lerPreferencia(CHAVE_FILTRO, ['todos', 'com', 'sem'] as const, 'todos'));
  const [modo, setModoEstado] = useState<ModoArtigos>(() => lerPreferencia(CHAVE_MODO, ['grelha', 'lista'] as const, 'grelha'));
  const [carrinho, setCarrinho] = useState<ItemCarrinho[]>([]);
  const [cliente, setCliente] = useState<number>();
  const [observacoes, setObservacoes] = useState('');
  const [emitida, setEmitida] = useState<GuiaSaida | null>(null);
  const [acertoAberto, setAcertoAberto] = useState(false);

  const artigos = useQuery({ queryKey: ['logistica', 'pos-armazem', 'artigos', armazem], queryFn: () => obter<ArtigoPos[]>('/pos/armazem/artigos', { armazem_id: armazem }) });
  const diferencas = useQuery({ queryKey: ['logistica', 'pos-armazem', 'diferencas', armazem], queryFn: () => obter<DiferencaStock[]>('/pos/armazem/acerto-stock', { armazem_id: armazem }) });
  const vender = useAccao<GuiaSaida>({
    invalidar: [['logistica']],
    aoSucesso: (g) => {
      setEmitida(g);
      setCarrinho([]);
      setCliente(undefined);
      setObservacoes('');
    },
    tituloErro: 'Não foi possível confirmar a saída',
  });
  useEffect(() => setCarrinho([]), [armazem]);

  const setFiltro = (f: FiltroStock) => {
    setFiltroEstado(f);
    gravarPreferencia(CHAVE_FILTRO, f);
  };
  const setModo = (m: ModoArtigos) => {
    setModoEstado(m);
    gravarPreferencia(CHAVE_MODO, m);
  };

  const visiveis = useMemo(() => filtrarArtigos(artigos.data ?? [], filtro, pesquisa), [artigos.data, filtro, pesquisa]);
  const precos = useMemo(() => new Map((artigos.data ?? []).map((a) => [a.produto_id, Number(a.preco_unitario) || 0])), [artigos.data]);
  const total = totalPicking(carrinho, precos);
  const nDif = diferencas.data?.length ?? 0;

  const acrescentar = (a: ArtigoPos) =>
    setCarrinho((c) => adicionarAoCarrinho(c, { produto_id: a.produto_id, codigo: a.codigo, nome: a.nome ?? '', disponivel: Number(a.quantidade) || 0 }));

  const botaoAdd = (a: ArtigoPos, qtd: number) => (
    <Button type="primary" size="small" disabled={qtd <= 0 || a.bloqueado} onClick={() => acrescentar(a)} aria-label={`Acrescentar ${a.nome ?? ''}`}
      title={a.bloqueado ? 'Produto bloqueado' : undefined} style={{ borderRadius: 8 }}>
      <IconeFa nome="plus" /> Add
    </Button>
  );

  return (
    <div className="pa-balcao">
      <section className="pa-painel" aria-label="Catálogo do armazém">
        <div className="pa-barra">
          <div className="pa-armazem">
            <span className="pa-armazem-icone" aria-hidden><IconeFa nome="boxes" /></span>
            <SeletorArmazem style={{ flex: 1, minWidth: 0, fontWeight: 600 }} value={armazem} onChange={(v) => v && aoMudarArmazem(v)} allowClear={false} aria-label="Armazém" />
          </div>
          <div className="pa-barra-direita">
            <div className="pa-grupo" role="group" aria-label="Filtro de stock">
              {([['todos', 'Todos', ''], ['com', 'Com Stock', 'pa-com'], ['sem', 'Sem Stock', 'pa-sem']] as const).map(([k, rotulo, cls]) => (
                <button key={k} type="button" className={[filtro === k ? 'activo' : '', cls].filter(Boolean).join(' ')} aria-pressed={filtro === k} onClick={() => setFiltro(k)}>
                  {rotulo}
                </button>
              ))}
            </div>
            <Input className="pa-pesquisa" allowClear prefix={<IconeFa nome="search" style={{ color: '#94a3b8' }} />} placeholder="Pesquisar..." value={pesquisa}
              onChange={(e) => setPesquisa(e.target.value)} aria-label="Pesquisar artigos" style={{ height: 38 }} />
            <div className="pa-grupo pa-modos" role="group" aria-label="Disposição dos artigos">
              <button type="button" className={modo === 'grelha' ? 'activo' : ''} aria-pressed={modo === 'grelha'} title="Visualização em Grelha" aria-label="Visualização em Grelha" onClick={() => setModo('grelha')}>
                <IconeFa nome="th" />
              </button>
              <button type="button" className={modo === 'lista' ? 'activo' : ''} aria-pressed={modo === 'lista'} title="Visualização em Lista" aria-label="Visualização em Lista" onClick={() => setModo('lista')}>
                <IconeFa nome="list" />
              </button>
            </div>
          </div>
        </div>

        {nDif > 0 && (
          <div className="pa-aviso" role="alert">
            <span>
              <IconeFa nome="exclamation-triangle" /> {nDif} produto(s) têm stock global diferente da soma dos armazéns (ex.: stock lançado na ficha do produto, importado ou vendido fora do armazém).
            </span>
            {pode('pos_armazem_acerto') && (
              <Button size="small" style={{ background: '#f59e0b', borderColor: '#f59e0b', color: '#fff' }} onClick={() => setAcertoAberto(true)}>
                <IconeFa nome="balance-scale" /> Acertar stock deste armazém
              </Button>
            )}
          </div>
        )}

        {artigos.isLoading ? (
          <Skeleton active />
        ) : (
          <div className={modo === 'lista' ? 'pa-artigos lista' : 'pa-artigos'} aria-live="polite">
            {visiveis.length === 0 ? (
              <p className="pa-vazio">
                <IconeFa nome="search" style={{ fontSize: '1.5rem', display: 'block', margin: '0 auto 0.5rem' }} />
                Sem produtos correspondentes ao filtro.
              </p>
            ) : (
              visiveis.map((a) => {
                const qtd = Number(a.quantidade) || 0;
                const et = etiquetaStock(qtd);
                const stock = (
                  <div className="pa-stock">
                    <small>STOCK DISP.</small>
                    <strong style={{ color: corStock(qtd) }}>{formatarUnidades(qtd)}</strong>
                  </div>
                );
                return modo === 'lista' ? (
                  <div key={a.produto_id} className="pa-linha" data-testid="artigo-pos">
                    <div className="pa-linha-esq">
                      <span className="pa-cartao-icone" aria-hidden><IconeFa nome="box" /></span>
                      <span className="pa-linha-codigo" title={a.codigo ?? '-'}>{a.codigo || '-'}</span>
                      <h4 title={a.nome ?? ''}>{a.nome}</h4>
                    </div>
                    <div className="pa-linha-dir">
                      <span className={`pa-etiqueta ${et.tipo}`}>{et.texto}</span>
                      {stock}
                      {botaoAdd(a, qtd)}
                    </div>
                  </div>
                ) : (
                  <div key={a.produto_id} className="pa-cartao" data-testid="artigo-pos">
                    <span className={`pa-etiqueta ${et.tipo}`}>{et.texto}</span>
                    <div className="pa-cartao-icone" aria-hidden><IconeFa nome="box" /></div>
                    <h4 title={a.nome ?? ''}>{a.nome}</h4>
                    <p className="pa-codigo">{a.codigo || '-'}</p>
                    <div className="pa-cartao-rodape">
                      {stock}
                      {botaoAdd(a, qtd)}
                    </div>
                  </div>
                );
              })
            )}
          </div>
        )}
      </section>

      <aside className="pa-painel pa-carrinho" aria-label="Carrinho de Picking">
        <h3 className="pa-titulo-painel"><IconeFa nome="shopping-cart" /> Carrinho de Picking</h3>
        <label className="pa-rotulo" htmlFor="pa-cliente">Cliente</label>
        <SeletorTerceiro id="pa-cliente" papel="CLIENTE" style={{ width: '100%' }} placeholder="-- Cliente de Balcão (Geral) --" value={cliente} onChange={setCliente} />

        <div className="pa-itens">
          {carrinho.length === 0 ? (
            <div className="pa-itens-vazio">
              <IconeFa nome="shopping-basket" />
              <p style={{ fontSize: '0.8rem', margin: 0 }}>Carrinho de Picking vazio.</p>
            </div>
          ) : (
            carrinho.map((i) => {
              const preco = precos.get(i.produto_id) ?? 0;
              return (
                <div key={i.produto_id} className="pa-item">
                  <div className="pa-item-nome">
                    <strong title={i.nome}>{i.nome}</strong>
                    <span>{formatarKz(preco)} Kz / U · máx. {formatarNumero(i.disponivel)}</span>
                  </div>
                  <div className="pa-item-qtd">
                    <Button size="small" aria-label="Menos" onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade - 1))}><IconeFa nome="minus" /></Button>
                    <InputNumber size="small" min={0} max={i.disponivel} value={i.quantidade} style={{ width: 64 }} aria-label={`Quantidade de ${i.nome}`}
                      onChange={(v) => setCarrinho((c) => alterarQuantidade(c, i.produto_id, Number(v ?? 0)))} />
                    <Button size="small" aria-label="Mais" disabled={i.quantidade >= i.disponivel} onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, i.quantidade + 1))}><IconeFa nome="plus" /></Button>
                  </div>
                  <span className="pa-item-sub">{formatarKz(i.quantidade * preco)}</span>
                  <Button danger type="text" size="small" aria-label={`Retirar ${i.nome}`} onClick={() => setCarrinho((c) => alterarQuantidade(c, i.produto_id, 0))}><IconeFa nome="trash" /></Button>
                </div>
              );
            })
          )}
        </div>

        <Input.TextArea rows={2} maxLength={2000} style={{ marginBottom: 12 }} placeholder="Observações (opcional)" value={observacoes} onChange={(e) => setObservacoes(e.target.value)} aria-label="Observações" />

        <div className="pa-total">
          <span>TOTAL DO PICKING:</span>
          <span data-testid="total-picking">{formatarKz(total)} Kz</span>
        </div>

        {pode('pos_armazem_vender') && (
          <Button
            type="primary"
            className="pa-confirmar"
            loading={vender.isPending}
            onClick={() => {
              if (!carrinho.length) {
                Modal.info({ title: 'O carrinho de picking está vazio.' });
                return;
              }
              Modal.confirm({
                title: 'Confirmar saída',
                content: 'Deseja confirmar a saída física de stock destas mercadorias e emitir a Guia de Saída para o Armazém selecionado?',
                okText: 'Confirmar',
                cancelText: 'Cancelar',
                onOk: () =>
                  vender
                    .mutateAsync({
                      url: '/pos/armazem/vendas',
                      dados: { armazem_id: armazem, terceiro_id: cliente, observacoes: observacoes || undefined, linhas: carrinho.map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade })) },
                    })
                    .catch(() => undefined),
              });
            }}
          >
            <IconeFa nome="check-circle" /> Confirmar Saída e Expedir
          </Button>
        )}
      </aside>

      <Modal open={!!emitida} onCancel={() => setEmitida(null)} footer={<Button type="primary" onClick={() => setEmitida(null)}>Fechar</Button>}>
        {emitida && (
          <Result
            status={emitida.aviso_contabilizacao ? 'warning' : 'success'}
            title={`Guia ${emitida.numero_documento} emitida`}
            subTitle={emitida.aviso_contabilizacao ? `Custo das mercadorias por contabilizar: ${emitida.aviso_contabilizacao}` : 'Stock actualizado.'}
          />
        )}
      </Modal>

      {acertoAberto && <AcertoStock armazem={armazem} linhas={diferencas.data ?? []} aoFechar={() => setAcertoAberto(false)} />}
    </div>
  );
}

/** Janela «⚖ Acertar stock — <armazém>» (posWhAcertarStock/posWhAplicarAcerto, js/ui_pos_armazem.js:154-214). */
function AcertoStock({ armazem, linhas, aoFechar }: { armazem: number; linhas: DiferencaStock[]; aoFechar: () => void }) {
  const comAjuste = linhas.filter((l) => Number(l.ajuste) !== 0);
  const [seleccionados, setSeleccionados] = useState<number[]>(() => comAjuste.map((l) => l.produto_id));
  const acertar = useAccao({ invalidar: [['logistica']], aoSucesso: aoFechar, tituloErro: 'Não foi possível acertar o stock' });
  const q = (v: string) => (Math.round(Number(v) * 1000) / 1000).toLocaleString('pt-PT');
  return (
    <Modal
      open
      width={larguraModal(860)}
      title={<><IconeFa nome="balance-scale" /> Acertar stock deste armazém</>}
      onCancel={aoFechar}
      footer={[
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <Button
          key="ok"
          type="primary"
          loading={acertar.isPending}
          disabled={!seleccionados.length}
          onClick={() =>
            Modal.confirm({
              title: `Aplicar o acerto de stock a ${seleccionados.length} produto(s) neste armazém?`,
              okText: 'Aplicar',
              cancelText: 'Cancelar',
              onOk: () => acertar.mutateAsync({ url: '/pos/armazem/acerto-stock', dados: { armazem_id: armazem, produtos: seleccionados } }).catch(() => undefined),
            })
          }
        >
          <IconeFa nome="check" /> Aplicar acerto
        </Button>,
      ]}
    >
      <Typography.Paragraph style={{ fontSize: '0.82rem', color: '#475569' }}>
        A diferença entre o stock global do produto e a soma de todos os armazéns é aplicada a <strong>este armazém</strong>. O stock global não muda; o acerto fica
        registado como movimento de ajuste do armazém (ao custo médio), para o histórico bater com o saldo.
      </Typography.Paragraph>
      <Table<DiferencaStock>
        size="small"
        rowKey="produto_id"
        pagination={false}
        scroll={scrollTabela()}
        dataSource={linhas}
        rowSelection={{ selectedRowKeys: seleccionados, onChange: (k) => setSeleccionados(k.map(Number)), getCheckboxProps: (l) => ({ disabled: Number(l.ajuste) === 0 }) }}
        columns={[
          { title: 'Produto', key: 'p', render: (_, l) => <><strong>{l.nome}</strong><br /><small style={{ color: '#64748b', fontFamily: 'monospace' }}>{l.codigo}</small></> },
          { title: 'Stock global', dataIndex: 'global', align: 'right', render: q },
          { title: 'Soma dos armazéns', dataIndex: 'armazens', align: 'right', render: q },
          { title: 'Neste armazém', dataIndex: 'local', align: 'right', render: q },
          {
            title: 'Ajuste',
            dataIndex: 'ajuste',
            align: 'right',
            render: (v: string, l) => (
              <span style={{ fontWeight: 700, color: Number(v) >= 0 ? '#059669' : '#dc2626' }}>
                {Number(v) >= 0 ? '+' : ''}{q(v)}
                {l.limitado && <><br /><small style={{ color: '#b45309', fontWeight: 400 }}>limitado ao stock deste armazém</small></>}
              </span>
            ),
          },
          { title: 'Fica neste armazém', dataIndex: 'fica', align: 'right', render: q },
        ]}
      />
    </Modal>
  );
}
