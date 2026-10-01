import { Alert, Button, Card, Col, Flex, Input, InputNumber, Modal, Row, Select, Space, Statistic, Table, Typography } from 'antd';
import { DeleteOutlined, LinkOutlined, PlusOutlined, SplitCellsOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos, SeletorCategoria, useCategorias } from './comum/componentes';
import { repartirValor, validarInventariacao } from './comum/regras';
import type { AquisicoesPendentes, LinhaPendente } from './comum/tipos';

/** Activos › Aquisições pendentes (ecrã activos_pendentes): linhas 11/12 do Diário por inventariar e activos sem lançamento de compra. */
export default function Pendentes() {
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['activos', 'pendentes'], queryFn: () => obter<AquisicoesPendentes>('/ativos/aquisicoes-pendentes') });
  const [inventariar, setInventariar] = useState<LinhaPendente | null>(null);
  const [ligar, setLigar] = useState<LinhaPendente | null>(null);
  const d = q.data;
  const gerir = pode('activos_inventariar');

  return (
    <>
      <CabecalhoPagina titulo="Aquisições pendentes" subtitulo="Lançamentos em contas 11/12 ainda não inventariados como activos" />
      <Row gutter={16} style={{ marginBottom: 16 }}>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Linhas por inventariar" value={d?.linhas.length ?? 0} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Valor por inventariar (Kz)" value={formatarKz(d?.total_por_inventariar)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Activos sem lançamento" value={d?.ativos_sem_lancamento.length ?? 0} /></Card></Col>
      </Row>
      <Card title="Linhas do Diário (contas 11/12)" style={{ marginBottom: 16 }}>
        <Table<LinhaPendente>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={d?.linhas}
          scroll={{ x: 'max-content' }}
          columns={[
            { title: 'Data', dataIndex: 'data_documento', render: formatarData },
            { title: 'Diário', dataIndex: 'diario' },
            { title: 'Documento', dataIndex: 'numero_documento' },
            { title: 'N.º lanç.', dataIndex: 'numero_lan', render: (v) => v ?? '—' },
            { title: 'Conta', dataIndex: 'codigo_conta' },
            { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 240 },
            { title: 'Fornecedor', dataIndex: 'terceiro', ellipsis: true, width: 200, render: (v) => v ?? '—' },
            { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Inventariado', dataIndex: 'inventariado', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
            { title: 'Por inventariar', dataIndex: 'por_inventariar', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
            {
              title: '', key: 'acc', fixed: 'right', align: 'right',
              render: (_, l) => gerir && (
                <Space>
                  <Button size="small" type="primary" onClick={() => setInventariar(l)}>Inventariar</Button>
                  <Button size="small" icon={<LinkOutlined />} disabled={!d?.ativos_sem_lancamento.length} onClick={() => setLigar(l)}>Ligar</Button>
                </Space>
              ),
            },
          ]}
        />
      </Card>
      <Card title="Activos sem lançamento de compra">
        <Table
          rowKey="id"
          size="small"
          loading={q.isFetching}
          dataSource={d?.ativos_sem_lancamento}
          pagination={{ pageSize: 10 }}
          columns={[
            { title: 'Código', dataIndex: 'codigo' },
            { title: 'Descrição', dataIndex: 'descricao' },
            { title: 'Categoria', dataIndex: 'categoria_nome', render: (v: string | null | undefined) => v ?? '—' },
            { title: 'Aquisição', dataIndex: 'data_aquisicao', render: formatarData },
            { title: 'Valor', dataIndex: 'valor_aquisicao', align: 'right', render: (v) => <ValorKz valor={v} /> },
            { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaActivos valor={v} /> },
          ]}
        />
      </Card>
      <ModalInventariar linha={inventariar} aoFechar={() => setInventariar(null)} />
      <ModalLigar linha={ligar} activos={d?.ativos_sem_lancamento ?? []} aoFechar={() => setLigar(null)} />
    </>
  );
}

interface ItemInventario {
  chave: number;
  codigo?: string;
  descricao: string;
  categoria_ativo_id?: number;
  valor_aquisicao?: number;
  vida_util?: number;
  centro_custo_id?: number;
  unidade_negocio_id?: number;
}

let sequencia = 1;

/** Inventaria uma linha 11/12 em um ou mais activos (a soma não pode exceder o valor por inventariar). */
function ModalInventariar({ linha, aoFechar }: { linha: LinhaPendente | null; aoFechar: () => void }) {
  const [itens, setItens] = useState<ItemInventario[]>([]);
  const [partes, setPartes] = useState(2);
  const categorias = useCategorias();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => aoFechar() });

  useEffect(() => {
    if (linha)
      setItens([{ chave: sequencia++, descricao: linha.descricao ?? '', valor_aquisicao: Number(linha.por_inventariar), centro_custo_id: linha.centro_custo_id ?? undefined, unidade_negocio_id: linha.unidade_negocio_id ?? undefined }]);
  }, [linha]);

  const mudar = (chave: number, campo: keyof ItemInventario, valor: unknown) =>
    setItens((s) => s.map((i) => {
      if (i.chave !== chave) return i;
      const novo = { ...i, [campo]: valor };
      if (campo === 'categoria_ativo_id' && !i.vida_util) novo.vida_util = categorias.data?.find((c) => c.id === valor)?.vida_util_padrao ?? undefined;
      return novo;
    }));

  const dividir = () => {
    if (!linha) return;
    const base = itens[0];
    setItens(repartirValor(linha.por_inventariar, partes).map((v, i) => ({ ...base, chave: sequencia++, codigo: undefined, descricao: `${base.descricao}${partes > 1 ? ` (${i + 1}/${partes})` : ''}`, valor_aquisicao: Number(v) })));
  };

  const validacao = validarInventariacao(itens, linha?.por_inventariar);
  const valido = itens.length > 0 && !validacao.excede && itens.every((i) => i.descricao.trim() && i.categoria_ativo_id && (i.valor_aquisicao ?? -1) >= 0);

  return (
    <Modal
      title={`Inventariar ${linha?.numero_documento ?? ''} — conta ${linha?.codigo_conta ?? ''}`}
      open={!!linha}
      onCancel={aoFechar}
      width={1100}
      okText={`Inventariar ${itens.length} activo(s)`}
      cancelText="Cancelar"
      okButtonProps={{ disabled: !valido }}
      confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/ativos/aquisicoes-pendentes/${linha?.id}/inventariar`, dados: { itens: itens.map(({ chave: _c, ...r }) => ({ ...r, codigo: r.codigo || null })) } })}
      destroyOnClose
    >
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }}>
        <Space>
          <Typography.Text>Dividir em</Typography.Text>
          <InputNumber min={1} max={1000} value={partes} onChange={(v) => setPartes(v ?? 1)} style={{ width: 80 }} />
          <Button icon={<SplitCellsOutlined />} onClick={dividir}>unidades iguais</Button>
          <Button icon={<PlusOutlined />} onClick={() => setItens((s) => [...s, { chave: sequencia++, descricao: linha?.descricao ?? '', centro_custo_id: linha?.centro_custo_id ?? undefined }])}>Linha</Button>
        </Space>
        <Space size="large">
          <span>Por inventariar: <ValorKz valor={linha?.por_inventariar} forte /></span>
          <span>Itens: <ValorKz valor={validacao.total} forte /></span>
          <span>Resto: <ValorKz valor={validacao.restante} forte /></span>
        </Space>
      </Flex>
      {validacao.excede && <Alert type="error" showIcon style={{ marginBottom: 12 }} message="A soma dos itens excede o valor por inventariar da linha." />}
      <Table<ItemInventario>
        size="small"
        rowKey="chave"
        dataSource={itens}
        pagination={false}
        scroll={{ x: 'max-content', y: 360 }}
        columns={[
          { title: 'Código', key: 'c', render: (_, i) => <Input size="small" placeholder="Auto" value={i.codigo} onChange={(e) => mudar(i.chave, 'codigo', e.target.value)} style={{ width: 100 }} /> },
          { title: 'Descrição', key: 'd', render: (_, i) => <Input size="small" value={i.descricao} onChange={(e) => mudar(i.chave, 'descricao', e.target.value)} style={{ width: 240 }} status={i.descricao.trim() ? undefined : 'error'} /> },
          { title: 'Categoria', key: 'cat', render: (_, i) => <SeletorCategoria size="small" value={i.categoria_ativo_id} onChange={(v) => mudar(i.chave, 'categoria_ativo_id', v)} style={{ width: 200 }} status={i.categoria_ativo_id ? undefined : 'error'} /> },
          { title: 'Valor (Kz)', key: 'v', render: (_, i) => <InputNumber size="small" min={0} precision={2} value={i.valor_aquisicao} onChange={(v) => mudar(i.chave, 'valor_aquisicao', v ?? undefined)} style={{ width: 140 }} /> },
          { title: 'Vida (meses)', key: 'vu', render: (_, i) => <InputNumber size="small" min={0} precision={0} value={i.vida_util} onChange={(v) => mudar(i.chave, 'vida_util', v ?? undefined)} style={{ width: 90 }} /> },
          { title: 'Centro de custo', key: 'cc', render: (_, i) => <SeletorAux tabela="centros-custo" value={i.centro_custo_id} onChange={(v) => mudar(i.chave, 'centro_custo_id', v)} style={{ width: 170 }} /> },
          { title: 'UN', key: 'un', render: (_, i) => <SeletorUnidade value={i.unidade_negocio_id} onChange={(v) => mudar(i.chave, 'unidade_negocio_id', v)} style={{ width: 150 }} /> },
          { title: '', key: 'x', render: (_, i) => <Button size="small" danger icon={<DeleteOutlined />} disabled={itens.length === 1} onClick={() => setItens((s) => s.filter((x) => x.chave !== i.chave))} /> },
        ]}
      />
    </Modal>
  );
}

function ModalLigar({ linha, activos, aoFechar }: { linha: LinhaPendente | null; activos: AquisicoesPendentes['ativos_sem_lancamento']; aoFechar: () => void }) {
  const [ids, setIds] = useState<number[]>([]);
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => { setIds([]); aoFechar(); } });
  return (
    <Modal
      title={`Ligar activos a ${linha?.numero_documento ?? ''}`}
      open={!!linha}
      onCancel={aoFechar}
      okText="Ligar"
      cancelText="Cancelar"
      okButtonProps={{ disabled: !ids.length }}
      confirmLoading={accao.isPending}
      onOk={() => accao.mutate({ url: `/ativos/aquisicoes-pendentes/${linha?.id}/ligar`, dados: { ids } })}
    >
      <Typography.Paragraph type="secondary">
        Associa activos já registados (sem lançamento de compra) a esta linha. Por inventariar: {formatarKz(linha?.por_inventariar, true)}.
      </Typography.Paragraph>
      <Select
        mode="multiple"
        style={{ width: '100%' }}
        placeholder="Activos sem lançamento"
        value={ids}
        onChange={setIds}
        optionFilterProp="label"
        options={activos.map((a) => ({ value: a.id, label: `${a.codigo} — ${a.descricao} (${formatarKz(a.valor_aquisicao)})` }))}
      />
    </Modal>
  );
}
