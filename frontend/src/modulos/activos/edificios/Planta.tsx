import { Alert, Button, Card, Col, Empty, Input, Modal, Popconfirm, Row, Space, Spin, Table, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useState, type DragEvent } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { BotaoImprimir } from '@/componentes/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarKz } from '@/utilitarios/formatacao';
import { NavActivos } from '../comum/NavActivos';
import { CSS_EDIFICIOS, htmlPlanta } from './impressao';
import { ModalAndar, ModalGerarInventario, ModalPorta, ModalPortas, type PedidoInventario } from './Modais';
import { filtrarBens, nomeAndar } from './regras';
import type { AndarPlanta, BemNaPlanta, Planta, PortaPlanta } from './tipos';

/**
 * Planta do edifício (assetBuildingPlan, js/activos_edificios.js:158-330): andares › portas com os bens, «Bens sem porta»
 * ao lado, arrastar bens entre portas (ou para fora), detalhe da porta, andares, portas em série, inventário e impressão.
 */
export function PlantaEdificio() {
  const { id } = useParams();
  const edificioId = Number(id);
  const navegar = useNavigate();
  const { pode } = useSessao();
  const gerir = pode('activos_locais_gerir');
  const inventariar = pode('activos_inventario_fisico');
  const q = useQuery({ queryKey: ['activos', 'edificios', 'planta', edificioId], queryFn: () => obter<Planta>(`/ativos/edificios/${edificioId}/planta`), enabled: edificioId > 0 });
  const mover = useAccao({ invalidar: [['activos']] });
  const eliminarAndar = useAccao({ invalidar: [['activos']] });
  const [procura, setProcura] = useState('');
  const [alvo, setAlvo] = useState<string | null>(null);
  const [andar, setAndar] = useState<AndarPlanta | null | undefined>(undefined);
  const [portas, setPortas] = useState<{ aberto: boolean; andarId?: number }>({ aberto: false });
  const [porta, setPorta] = useState<PortaPlanta | null>(null);
  const [detalhe, setDetalhe] = useState<PortaPlanta | null>(null);
  const [inventario, setInventario] = useState<PedidoInventario | null>(null);

  const p = q.data;
  const largar = (portaId: number | null) => (e: DragEvent) => {
    e.preventDefault();
    setAlvo(null);
    const bem = Number(e.dataTransfer.getData('text/plain'));
    if (bem > 0 && gerir) mover.mutate({ url: `/ativos/bens/${bem}/localizacao`, dados: { porta_id: portaId } });
  };
  const zona = (chave: string, portaId: number | null) => (gerir ? {
    onDragOver: (e: DragEvent) => { e.preventDefault(); setAlvo(chave); },
    onDragLeave: () => setAlvo(null),
    onDrop: largar(portaId),
  } : {});

  const cartaoBem = (b: BemNaPlanta) => (
    <div key={b.id} draggable={gerir} onDragStart={(e) => e.dataTransfer.setData('text/plain', String(b.id))} onClick={(e) => e.stopPropagation()}
      title={`${b.descricao} · ${formatarKz(b.valor_aquisicao)}`}
      style={{ fontSize: 12, background: '#f1f5f9', border: '1px solid #e2e8f0', borderRadius: 3, padding: '2px 5px', cursor: gerir ? 'grab' : 'default', marginBottom: 2 }}>
      {gerir && <IconeFa nome="grip-vertical" />} {b.codigo} {b.descricao.slice(0, 22)}
    </div>
  );

  if (q.isLoading) return <Spin />;
  if (!p) return <Empty description="Edifício não encontrado." />;

  return (
    <>
      <NavActivos actual="activos_edificios" />
      <Card
        title={<Space wrap><Button size="small" icon={<IconeFa nome="arrow-left" />} onClick={() => navegar('/m/activos/activos_edificios')}>Edifícios</Button>
          <span>{p.edificio.codigo} — {p.edificio.descricao}</span></Space>}
        extra={
          <Space wrap>
            {gerir && <Button size="small" icon={<IconeFa nome="plus" />} onClick={() => setAndar(null)}>Andar</Button>}
            {gerir && <Button size="small" icon={<IconeFa nome="door-open" />} disabled={!p.andares.length} onClick={() => setPortas({ aberto: true })}>Portas</Button>}
            {inventariar && <Button size="small" icon={<IconeFa nome="clipboard-list" />} onClick={() => setInventario({ ambito: 'EDIFICIO' })}>Inventário</Button>}
            <BotaoImprimir texto="Imprimir planta" tamanho="small"
              obterPedido={() => ({ titulo: `Planta — ${p.edificio.codigo ?? ''} ${p.edificio.descricao}`, conteudo: htmlPlanta(p), cssExtra: CSS_EDIFICIOS })} />
          </Space>
        }
      >
        <Row gutter={16}>
          <Col xs={24} lg={17}>
            <div style={{ border: '2px solid #94a3b8', borderRadius: 4, background: '#f8fafc', padding: 8 }}>
              <div style={{ height: 14, background: '#475569', borderRadius: '4px 4px 0 0', margin: '-8px -8px 8px' }} />
              {!p.andares.length && <Empty description="Ainda não há andares. Comece por acrescentar um andar (ou o piso 0 para um local sem andares)." />}
              {p.andares.map((f) => (
                <Card key={f.id} size="small" style={{ marginBottom: 8 }}
                  title={<span><IconeFa nome="layer-group" /> {nomeAndar(f)} <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    {f.n_portas} porta(s) · {f.n_bens} bem(ns) · {formatarKz(f.valor)}</Typography.Text></span>}
                  extra={
                    <Space size={4}>
                      {gerir && <Button size="small" title="Acrescentar portas" icon={<IconeFa nome="door-open" />} onClick={() => setPortas({ aberto: true, andarId: f.id })} />}
                      {inventariar && <Button size="small" title="Inventariar este andar" icon={<IconeFa nome="clipboard-list" />} onClick={() => setInventario({ ambito: 'ANDAR', alvoId: f.id })} />}
                      {gerir && <Button size="small" title="Editar andar" icon={<IconeFa nome="edit" />} onClick={() => setAndar(f)} />}
                      {gerir && (
                        <Popconfirm title={`Eliminar «${nomeAndar(f)}»?`} description={`Isto apaga ${f.n_portas} porta(s); ${f.n_bens} bem(ns) ficam sem porta (não são apagados).`}
                          okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                          onConfirm={() => eliminarAndar.mutate({ metodo: 'delete', url: `/ativos/andares/${f.id}` })}>
                          <Button size="small" danger title="Eliminar andar" icon={<IconeFa nome="trash" />} />
                        </Popconfirm>
                      )}
                    </Space>
                  }>
                  {!f.portas.length && <Typography.Text type="secondary">Sem portas neste andar.</Typography.Text>}
                  <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(160px, 1fr))', gap: 8 }}>
                    {f.portas.map((pt) => (
                      <div key={pt.id} data-porta={pt.id} role="button" tabIndex={0} aria-label={`Porta ${pt.numero}`} onClick={() => setDetalhe(pt)}
                        onKeyDown={(e) => { if (e.key === 'Enter') setDetalhe(pt); }} {...zona(`p${pt.id}`, pt.id)}
                        style={{ border: '1px solid #cbd5e1', borderTop: '3px solid #b45309', borderRadius: 4, padding: '6px 8px', minHeight: 92, cursor: 'pointer',
                          background: alvo === `p${pt.id}` ? '#fef3c7' : '#fff' }}>
                        <div style={{ display: 'flex', justifyContent: 'space-between', fontWeight: 800 }}>
                          <span><IconeFa nome="door-closed" /> {pt.numero}</span>
                          <Space size={2}>
                            {inventariar && <Button size="small" type="text" title="Inventariar esta porta" icon={<IconeFa nome="clipboard-list" />}
                              onClick={(e) => { e.stopPropagation(); setInventario({ ambito: 'PORTA', alvoId: pt.id }); }} />}
                            {gerir && <Button size="small" type="text" title="Editar porta" icon={<IconeFa nome="edit" />} onClick={(e) => { e.stopPropagation(); setPorta(pt); }} />}
                          </Space>
                        </div>
                        <div style={{ fontSize: 12, color: '#64748b', marginBottom: 4 }}>{pt.nome || pt.tipo || 'Porta'}</div>
                        <div style={{ maxHeight: 120, overflow: 'auto' }}>{pt.bens.length ? pt.bens.map(cartaoBem) : <Typography.Text type="secondary" style={{ fontSize: 12 }}>Sem bens</Typography.Text>}</div>
                        {!!pt.n_bens && <div style={{ fontSize: 12, fontWeight: 700, marginTop: 4 }}>{pt.n_bens} bem(ns) · {formatarKz(pt.valor)}</div>}
                      </div>
                    ))}
                  </div>
                </Card>
              ))}
            </div>
            {gerir && <Typography.Text type="secondary" style={{ fontSize: 12 }}><IconeFa nome="hand-pointer" /> Arraste os bens entre portas, ou para «Bens sem porta» para os retirar do local.</Typography.Text>}
          </Col>
          <Col xs={24} lg={7}>
            <Card size="small" title={`Bens sem porta (${p.sem_local.length})`}>
              <Input placeholder="Procurar bem..." allowClear value={procura} onChange={(e) => setProcura(e.target.value)} style={{ marginBottom: 6 }} />
              <div {...zona('sem', null)} data-zona="sem-local"
                style={{ border: '1px dashed #cbd5e1', borderRadius: 4, padding: 6, minHeight: 90, maxHeight: 420, overflow: 'auto', background: alvo === 'sem' ? '#fef3c7' : undefined }}>
                {filtrarBens(p.sem_local, procura).map(cartaoBem)}
                {!p.sem_local.length && <Typography.Text type="secondary">Todos os bens estão localizados.</Typography.Text>}
              </div>
              <div style={{ borderTop: '1px solid #e2e8f0', marginTop: 8, paddingTop: 6, fontSize: 13 }}>
                <div><strong>{p.totais.bens}</strong> bem(ns) neste edifício</div>
                <div><strong>{formatarKz(p.totais.valor)}</strong> em valor de aquisição</div>
              </div>
            </Card>
          </Col>
        </Row>
      </Card>
      {mover.isError && <Alert type="error" showIcon message="Não foi possível mover o bem." style={{ marginTop: 8 }} />}
      <ModalAndar edificioId={edificioId} andar={andar} nAndares={p.andares.length} aoFechar={() => setAndar(undefined)} />
      <ModalPortas planta={p} aberto={portas.aberto} andarId={portas.andarId} aoFechar={() => setPortas({ aberto: false })} />
      <ModalPorta porta={porta} aoFechar={() => setPorta(null)} />
      <ModalGerarInventario planta={p} pedido={inventario} aoFechar={() => setInventario(null)} />
      <DetalhePorta porta={detalhe} andar={p.andares.find((a) => a.id === detalhe?.andar_id)} podeGerir={gerir}
        aoInventariar={(pt) => { setDetalhe(null); setInventario({ ambito: 'PORTA', alvoId: pt.id }); }}
        aoRetirar={(bem) => mover.mutate({ url: `/ativos/bens/${bem}/localizacao`, dados: { porta_id: null } }, { onSuccess: () => setDetalhe(null) })}
        aoFechar={() => setDetalhe(null)} podeInventariar={inventariar} />
    </>
  );
}

/** Detalhe da porta (assetPortaDetalhe :297-330): bens, categoria, valor e total. */
function DetalhePorta({ porta, andar, podeGerir, podeInventariar, aoInventariar, aoRetirar, aoFechar }: {
  porta: PortaPlanta | null; andar?: AndarPlanta; podeGerir: boolean; podeInventariar: boolean;
  aoInventariar: (p: PortaPlanta) => void; aoRetirar: (bem: number) => void; aoFechar: () => void;
}) {
  return (
    <Modal open={!!porta} onCancel={aoFechar} width={720} title={<span><IconeFa nome="door-open" /> Porta {porta?.numero} {porta?.nome ? `— ${porta.nome}` : ''}</span>}
      footer={[podeInventariar && porta && <Button key="i" icon={<IconeFa nome="clipboard-list" />} onClick={() => aoInventariar(porta)}>Inventariar esta porta</Button>,
        <Button key="f" type="primary" onClick={aoFechar}>Fechar</Button>]}>
      <p>{nomeAndar(andar)}{porta?.tipo ? ` · ${porta.tipo}` : ''}{porta?.capacidade ? ` · capacidade ${porta.capacidade}` : ''}</p>
      <Table<BemNaPlanta> rowKey="id" size="small" pagination={false} dataSource={porta?.bens ?? []} locale={{ emptyText: 'Sem bens nesta porta.' }}
        columns={[
          { title: 'Nº Inventário', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
          { title: 'Descrição', dataIndex: 'descricao' },
          { title: 'Categoria', dataIndex: 'categoria', render: (v) => <small>{v ?? '—'}</small> },
          { title: 'Valor', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => formatarKz(v) },
          ...(podeGerir ? [{ title: 'Acções', key: 'a', align: 'right' as const, render: (_: unknown, b: BemNaPlanta) => (
            <Button size="small" danger title="Retirar desta porta" icon={<IconeFa nome="sign-out-alt" />} onClick={() => aoRetirar(b.id)} />
          ) }] : []),
        ]}
        summary={() => (porta?.bens.length ? <Table.Summary.Row><Table.Summary.Cell index={0} colSpan={3} align="right"><strong>TOTAL</strong></Table.Summary.Cell>
          <Table.Summary.Cell index={1} align="right"><strong>{formatarKz(porta.valor)}</strong></Table.Summary.Cell></Table.Summary.Row> : null)}
      />
    </Modal>
  );
}
