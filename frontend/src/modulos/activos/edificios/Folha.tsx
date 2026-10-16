import { Alert, Button, Card, Empty, Input, Modal, Select, Space, Spin, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { BotaoImprimir } from '@/componentes/impressao';
import { CartaoKpi, GrelhaKpi } from '@/componentes/vistas';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { SeletorActivo } from '../comum/componentes';
import { NavActivos } from '../comum/NavActivos';
import { CSS_EDIFICIOS, htmlFolha } from './impressao';
import { marcarTodosEncontrados, RESULTADOS } from './regras';
import type { ComparacaoInventario, FolhaInventario, LinhaInventario, Resultado } from './tipos';

const BASE = '/m/activos/activos_edificios';

function Cabecalho({ f, titulo, voltar }: { f: FolhaInventario; titulo: string; voltar: () => void }) {
  const i = f.inventario;
  return (
    <Space wrap>
      <Button size="small" icon={<IconeFa nome="arrow-left" />} onClick={voltar}>Voltar</Button>
      <strong>{titulo} · {i.numero} · {i.edificio?.codigo} · {i.ambito_texto}</strong>
      <Tag color={i.estado === 'ABERTO' ? 'green' : i.estado === 'ANULADO' ? 'red' : 'default'}>{i.estado}</Tag>
      {i.aplicado_em && <Tag color="blue" title="Localizações actualizadas">aplicado</Tag>}
    </Space>
  );
}

/** Folha de contagem (assetFolhaInventario :680-778): resultado por bem, local onde foi encontrado, observações e não previstos. */
export function FolhaInventarioEcra() {
  const { id } = useParams();
  const inv = Number(id);
  const navegar = useNavigate();
  const { pode } = useSessao();
  const contar = pode('activos_inventario_fisico');
  const q = useQuery({ queryKey: ['activos', 'inventarios-fisicos', inv], queryFn: () => obter<FolhaInventario>(`/ativos/inventarios-fisicos/${inv}`), enabled: inv > 0 });
  const [linhas, setLinhas] = useState<LinhaInventario[]>([]);
  const [extraBem, setExtraBem] = useState<number>();
  const [extraPorta, setExtraPorta] = useState<number>();
  useEffect(() => { if (q.data) setLinhas(q.data.linhas.filter((l) => l.resultado !== 'EXTRA')); }, [q.data]);
  const gravar = useAccao({ invalidar: [['activos']], aoSucesso: () => navegar(`${BASE}/inventario/${inv}/comparacao`) });
  const extra = useAccao({ invalidar: [['activos']], aoSucesso: () => { setExtraBem(undefined); setExtraPorta(undefined); } });

  if (q.isLoading) return <Spin />;
  const f = q.data;
  if (!f) return <Empty description="Folha não encontrada." />;
  const aberto = f.inventario.estado === 'ABERTO' && contar;
  const extras = f.linhas.filter((l) => l.resultado === 'EXTRA');
  const portas = f.portas.map((p) => ({ value: p.id, label: p.texto }));
  const mudar = (lid: number, campos: Partial<LinhaInventario>) => setLinhas((ls) => ls.map((l) => (l.id === lid ? { ...l, ...campos } : l)));

  return (
    <>
      <NavActivos actual="activos_edificios" />
      <Card
        title={<Cabecalho f={f} titulo="Folha de inventário" voltar={() => navegar(`${BASE}?separador=inventarios`)} />}
        extra={
          <Space wrap>
            {aberto && <Button size="small" icon={<IconeFa nome="check-double" />} onClick={() => setLinhas(marcarTodosEncontrados)}>Marcar todos encontrados</Button>}
            <BotaoImprimir texto="Imprimir" tamanho="small" obterPedido={() => ({ titulo: `Folha de Inventário — ${f.inventario.numero}`, conteudo: htmlFolha(f, false), cssExtra: CSS_EDIFICIOS })} />
            <Button size="small" icon={<IconeFa nome="balance-scale" />} onClick={() => navegar(`${BASE}/inventario/${inv}/comparacao`)}>Comparar</Button>
            {aberto && (
              <Button size="small" type="primary" icon={<IconeFa nome="save" />} loading={gravar.isPending}
                onClick={() => gravar.mutate({ metodo: 'put', url: `/ativos/inventarios-fisicos/${inv}/contagem`,
                  dados: { linhas: linhas.map((l) => ({ id: l.id, resultado: l.resultado ?? '', porta_encontrada_id: l.resultado === 'DESLOCADO' ? l.porta_encontrada_id : null, notas: l.notas ?? '' })) } })}>
                Gravar contagem
              </Button>
            )}
          </Space>
        }
      >
        <Space wrap size="large" style={{ marginBottom: 12, color: '#475569' }}>
          <span><strong>Data:</strong> {formatarData(f.inventario.data)}</span>
          <span><strong>Responsável:</strong> {f.inventario.responsavel || '—'}</span>
          <span><strong>Esperados:</strong> {linhas.length} ({formatarKz(linhas.reduce((s, l) => s + Number(l.valor || 0), 0).toFixed(2))})</span>
          {f.inventario.notas && <span><strong>Notas:</strong> {f.inventario.notas}</span>}
          {f.inventario.motivo_anulacao && <span><strong>Anulada:</strong> {f.inventario.motivo_anulacao}</span>}
        </Space>
        <Table<LinhaInventario> rowKey="id" size="small" pagination={false} dataSource={linhas} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem bens esperados nesta folha.' }}
          columns={[
            { title: 'Nº Inventário', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
            { title: 'Descrição', dataIndex: 'descricao' },
            { title: 'Local esperado', dataIndex: 'local_esperado', render: (v) => <small>{v ?? '—'}</small> },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
            { title: 'Resultado', key: 'res', render: (_, l) => (
              <Select<Resultado | ''> aria-label={`Resultado ${l.codigo ?? ''}`} size="small" style={{ width: 150 }} disabled={!aberto} value={l.resultado ?? ''}
                onChange={(v) => mudar(l.id, { resultado: v || null, porta_encontrada_id: v === 'DESLOCADO' ? l.porta_encontrada_id : null })}
                options={[{ value: '', label: '— por contar —' }, ...(['ENCONTRADO', 'EM_FALTA', 'DESLOCADO'] as Resultado[]).map((r) => ({ value: r, label: RESULTADOS[r].rotulo }))]} />
            ) },
            { title: 'Local encontrado', key: 'loc', render: (_, l) => (
              <Select<number> size="small" style={{ width: 200 }} allowClear disabled={!aberto || l.resultado !== 'DESLOCADO'} value={l.porta_encontrada_id ?? undefined}
                onChange={(v) => mudar(l.id, { porta_encontrada_id: v ?? null })} options={portas} placeholder="—" />
            ) },
            { title: 'Observações', key: 'obs', render: (_, l) => (
              <Input size="small" disabled={!aberto} value={l.notas ?? ''} maxLength={2000} onChange={(e) => mudar(l.id, { notas: e.target.value })} />
            ) },
          ]} />
      </Card>
      {!!extras.length && (
        <Card size="small" title={`Bens encontrados não previstos (${extras.length})`} style={{ marginTop: 12 }}>
          <Table<LinhaInventario> rowKey="id" size="small" pagination={false} dataSource={extras}
            columns={[
              { title: 'Nº Inventário', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
              { title: 'Descrição', dataIndex: 'descricao' },
              { title: 'Local encontrado', dataIndex: 'local_encontrado', render: (v) => <small>{v ?? '—'}</small> },
              { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
              ...(aberto ? [{ title: 'Acções', key: 'a', align: 'right' as const, render: (_: unknown, l: LinhaInventario) => (
                <Button size="small" danger title="Retirar da folha" icon={<IconeFa nome="trash" />}
                  onClick={() => extra.mutate({ metodo: 'delete', url: `/ativos/inventarios-fisicos/linhas/${l.id}` })} />
              ) }] : []),
            ]} />
        </Card>
      )}
      {aberto && (
        <Card size="small" style={{ marginTop: 12 }}>
          <Space wrap align="end">
            <div><div>Bem encontrado e não previsto</div><SeletorActivo value={extraBem} onChange={setExtraBem} style={{ width: 320 }} /></div>
            <div><div>Onde foi encontrado</div><Select<number> value={extraPorta} onChange={setExtraPorta} options={portas} placeholder="-- Porta --" style={{ width: 240 }} /></div>
            <Button icon={<IconeFa nome="plus" />} disabled={!extraBem || !extraPorta} loading={extra.isPending}
              onClick={() => extra.mutate({ url: `/ativos/inventarios-fisicos/${inv}/extras`, dados: { ativo_imobilizado_id: extraBem, porta_encontrada_id: extraPorta } })}>
              Acrescentar à folha
            </Button>
          </Space>
        </Card>
      )}
    </>
  );
}

/** Comparação esperado × encontrado (assetCompararInventario :832-886), aplicar localizações e fechar o inventário. */
export function ComparacaoInventarioEcra() {
  const { id } = useParams();
  const inv = Number(id);
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, contexto] = Modal.useModal();
  const q = useQuery({ queryKey: ['activos', 'inventarios-fisicos', inv, 'comparacao'], queryFn: () => obter<ComparacaoInventario>(`/ativos/inventarios-fisicos/${inv}/comparacao`), enabled: inv > 0 });
  const accao = useAccao({ invalidar: [['activos']] });
  if (q.isLoading) return <Spin />;
  const c = q.data;
  if (!c) return <Empty description="Folha não encontrada." />;
  const aberto = c.inventario.estado === 'ABERTO' && pode('activos_inventario_fisico');
  const grupo = (r: Resultado) => c.linhas.filter((l) => l.resultado === r);
  const bloco = (titulo: string, cor: string, ls: LinhaInventario[], mostrarLocal: boolean) => (
    <Card size="small" style={{ marginTop: 12, borderLeft: `4px solid ${cor}` }} title={`${titulo} (${ls.length}) — ${formatarKz(ls.reduce((s, l) => s + Number(l.valor || 0), 0).toFixed(2))}`}>
      {ls.length ? (
        <Table<LinhaInventario> rowKey="id" size="small" pagination={false} dataSource={ls}
          columns={[
            { title: 'Nº Inventário', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
            { title: 'Descrição', dataIndex: 'descricao' },
            { title: 'Local esperado', key: 'esp', render: (_, l) => <small>{l.resultado === 'EXTRA' ? '— não previsto —' : l.local_esperado ?? '—'}</small> },
            ...(mostrarLocal ? [{ title: 'Local encontrado', dataIndex: 'local_encontrado', render: (v: string | null) => <small>{v ?? '—'}</small> }] : []),
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => formatarKz(v) },
            { title: 'Observações', dataIndex: 'notas', render: (v) => <small>{v ?? ''}</small> },
          ]} />
      ) : <Typography.Text type="secondary">Nenhum.</Typography.Text>}
    </Card>
  );
  const r = c.resumo;
  return (
    <>
      {contexto}
      <NavActivos actual="activos_edificios" />
      <Card
        title={<Cabecalho f={c} titulo="Comparação" voltar={() => navegar(`${BASE}/inventario/${inv}`)} />}
        extra={
          <Space wrap>
            {aberto && r.a_aplicar > 0 && (
              <Button size="small" icon={<IconeFa nome="map-marker-alt" />} loading={accao.isPending}
                onClick={() => void modal.confirm({ title: 'Aplicar localizações', content: `Actualizar a localização de ${r.a_aplicar} bem(ns) para a porta onde foram encontrados?`,
                  okText: 'Aplicar', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ url: `/ativos/inventarios-fisicos/${inv}/aplicar` }).catch(() => undefined) })}>
                Aplicar localizações ({r.a_aplicar})
              </Button>
            )}
            <BotaoImprimir texto="Imprimir comparação" tamanho="small" obterPedido={() => ({ titulo: `Comparação de Inventário — ${c.inventario.numero}`, conteudo: htmlFolha(c, true), cssExtra: CSS_EDIFICIOS })} />
            {aberto && (
              <Button size="small" type="primary" icon={<IconeFa nome="lock" />}
                onClick={() => void modal.confirm({ title: 'Fechar inventário',
                  content: `Fechar o inventário ${c.inventario.numero}?${r.por_contar ? ` Ainda há ${r.por_contar} bem(ns) por contar — ficam registados como não contados.` : ''} Depois de fechado deixa de poder ser alterado.`,
                  okText: 'Fechar inventário', cancelText: 'Cancelar', onOk: () => accao.mutateAsync({ url: `/ativos/inventarios-fisicos/${inv}/fechar` }).catch(() => undefined) })}>
                Fechar inventário
              </Button>
            )}
          </Space>
        }
      >
        {/* cartões do legado (assetComparacao, js/activos_edificios.js:873-879): barra à esquerda com a cor de cada resultado */}
        <GrelhaKpi larguraMinima={165}>
          <CartaoKpi barra="esquerda" cor="#16a34a" rotulo="Encontrados" valor={r.encontrados.n} secundaria={formatarKz(r.encontrados.valor)} />
          <CartaoKpi barra="esquerda" cor="#dc2626" rotulo="Em falta" valor={r.em_falta.n} corValor={r.em_falta.n ? '#dc2626' : undefined} secundaria={formatarKz(r.em_falta.valor)} />
          <CartaoKpi barra="esquerda" cor="#d97706" rotulo="Noutro local" valor={r.deslocados.n} secundaria={formatarKz(r.deslocados.valor)} />
          <CartaoKpi barra="esquerda" cor="#7c3aed" rotulo="Não previstos" valor={r.extras.n} secundaria={formatarKz(r.extras.valor)} />
          <CartaoKpi barra="esquerda" cor="#2563eb" rotulo="Conformidade" valor={`${Number(r.conformidade ?? 0).toFixed(1).replace('.', ',')}%`}
            secundaria={r.por_contar ? `${r.por_contar} por contar` : 'contagem completa'} />
        </GrelhaKpi>
        {!!r.por_contar && <Alert style={{ marginTop: 12 }} type="warning" showIcon message={`Ainda há ${r.por_contar} bem(ns) por contar — a comparação só fica completa depois de todos marcados.`} />}
      </Card>
      {bloco('Em falta', '#dc2626', grupo('EM_FALTA'), false)}
      {bloco('Encontrados noutro local', '#d97706', grupo('DESLOCADO'), true)}
      {bloco('Encontrados não previstos', '#7c3aed', grupo('EXTRA'), true)}
      {bloco('Conformes', '#16a34a', grupo('ENCONTRADO'), false)}
    </>
  );
}
