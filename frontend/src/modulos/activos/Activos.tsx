import { Button, Card, Checkbox, Flex, Input, Modal, Popconfirm, Select, Space, Table, Tabs, Tag, Typography, message } from 'antd';
import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Route, Routes, useNavigate } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { NavActivos } from './comum/NavActivos';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux } from '@/modulos/contab/comum/Seletores';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { somarColunas } from '@/utilitarios/decimal';
import { useAccao } from '@/componentes/Accoes';
import { notificarErro } from '@/utilitarios/erros';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { pedidoTabela } from './comum/impressao';
import { SeletorActivo, SeletorCategoria, SeletorProjecto } from './comum/componentes';
import type { Activo, Afectacao, ResumoCategoria, Transferencia } from './comum/tipos';
import { DetalheActivo } from './DetalheActivo';
import { ModalActivo } from './ModalActivo';
import { ModalAfectacao, ModalEdicaoMassa, ModalImportarActivos, ModalTransferir } from './ModaisActivos';
import { AlertaReconciliacao } from './inventario/AlertaReconciliacao';
import { BotaoCartao } from './inventario/BotaoCartao';
import { bloqueioEliminar, corEstadoActivo, dicaEditar, iconeEditar, NOME_TEMPLATE_ACTIVOS, ouTracos, taxaPorVidaUtil, vidaUtilMeses } from './inventario/regrasInventario';

import { TabelaComModos } from '@/componentes/vistas';
/** Activos › Inventário de Activos (ecrã activos): cadastro, ficha com histórico, importação, edição em massa, transferências e afectações. */
export default function Activos() {
  return (
    <Routes>
      <Route index element={<Painel />} />
      <Route path=":id" element={<DetalheActivo />} />
    </Routes>
  );
}

function Painel() {
  return (
    <>
      <NavActivos actual="activos" />
      <Tabs
        destroyOnHidden
        items={[
          { key: 'cadastro', label: 'Inventário', children: <Cadastro /> },
          { key: 'transferencias', label: 'Transferências', children: <Transferencias /> },
          { key: 'afectacoes', label: 'Afectações a projectos', children: <Afectacoes /> },
          { key: 'categorias', label: 'Resumo por categoria', children: <ResumoCategorias /> },
        ]}
      />
    </>
  );
}

/** Inventário de Imobilizados (renderAssetList do legado): colunas, botões do cabeçalho e acções por linha do antigo. */
function Cadastro() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const [modal, contextoModal] = Modal.useModal();
  const [texto, setTexto] = useState('');
  const [estado, setEstado] = useState<string>();
  const [categoria, setCategoria] = useState<number>();
  const [centro, setCentro] = useState<number>();
  const [semLancamento, setSemLancamento] = useState(false);
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const [novo, setNovo] = useState(false);
  const [editar, setEditar] = useState<Activo | null>(null);
  const [transferir, setTransferir] = useState<number | null>(null);
  const [importar, setImportar] = useState(false);
  const [massa, setMassa] = useState(false);
  const eliminar = useAccao<{ eliminados: number }>({ invalidar: [['activos']], aoSucesso: () => setSeleccao([]) });
  const eliminarUm = useAccao({ invalidar: [['activos']] });
  const gerir = pode('activos_gerir');
  const podeEliminar = pode('activos_eliminar');

  const pequeno = useEcraPequeno();
  const kz = (v: unknown) => formatarKz(v === null || v === undefined ? null : String(v));
  const colunas: ColunaApi<Activo>[] = [
    {
      title: 'Nº Inventário', dataIndex: 'codigo', fixed: pequeno ? undefined : 'left', valorImpressao: (r) => r.codigo ?? '—', valorFiltro: (r) => r.codigo ?? '', ocultavel: false,
      render: (v, r) => (
        <Button size="small" title="Ficha e histórico do activo" style={{ fontWeight: 700, color: 'var(--ant-color-primary, #2563eb)' }} icon={<IconeFa nome="history" />}
          onClick={(e) => { e.stopPropagation(); navegar(String(r.id)); }}>
          {v ?? '—'}
        </Button>
      ),
    },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 300, render: (v) => <strong>{v}</strong> },
    { title: 'Categoria', key: 'cat', valorFiltro: (r) => r.categoria_ativo?.nome ?? '', valorImpressao: (r) => r.categoria_ativo?.nome ?? 'N/A', render: (_, r) => <small>{r.categoria_ativo?.nome ?? 'N/A'}</small> },
    { title: 'Data Aquisição', dataIndex: 'data_aquisicao', render: formatarData, valorImpressao: (r) => formatarData(r.data_aquisicao) },
    { title: 'U. Negócio', key: 'un', valorFiltro: (r) => r.unidade_negocio?.codigo ?? '', valorImpressao: (r) => ouTracos(r.unidade_negocio?.codigo), render: (_, r) => <small>{ouTracos(r.unidade_negocio?.codigo)}</small> },
    { title: 'C. Custo', key: 'cc', valorFiltro: (r) => r.centro_custo?.codigo ?? '', valorImpressao: (r) => ouTracos(r.centro_custo?.codigo), render: (_, r) => <small>{ouTracos(r.centro_custo?.codigo)}</small> },
    { title: 'Vida útil', dataIndex: 'vida_util', align: 'right', render: (v) => vidaUtilMeses(v), valorImpressao: (r) => vidaUtilMeses(r.vida_util) },
    { title: 'Taxa %', key: 'taxa', align: 'right', render: (_, r) => taxaPorVidaUtil(r.vida_util), valorImpressao: (r) => taxaPorVidaUtil(r.vida_util) },
    { title: 'Aquisição', dataIndex: 'valor_aquisicao', align: 'right', total: true, render: (v) => <span style={{ fontWeight: 600 }}>{kz(v)}</span>, valorImpressao: (r) => kz(r.valor_aquisicao) },
    { title: 'Amort. Acum.', dataIndex: 'amortizacao_acumulada', align: 'right', total: true, render: (v) => <span style={{ color: '#dc2626' }}>{kz(v ?? 0)}</span>, valorImpressao: (r) => kz(r.amortizacao_acumulada ?? 0) },
    { title: 'V. C. Líquido', dataIndex: 'valor_liquido', align: 'right', total: true, render: (v) => <span style={{ fontWeight: 700, color: '#059669' }}>{kz(v)}</span>, valorImpressao: (r) => kz(r.valor_liquido) },
    { title: 'Status', dataIndex: 'estado', render: (v) => <Tag color={corEstadoActivo(v)}>{v}</Tag>, valorImpressao: (r) => String(r.estado) },
  ];

  /** Sem selecção o legado avisa («Seleccione pelo menos um activo para …»). */
  const exigirSeleccao = (accao: string) => {
    if (seleccao.length) return true;
    void message.warning(`Seleccione pelo menos um activo para ${accao}.`);
    return false;
  };

  const eliminarEmMassa = () => {
    if (!exigirSeleccao('eliminar')) return;
    void modal.confirm({
      title: 'Eliminar em Massa',
      content: `Tem a certeza que deseja eliminar ${seleccao.length} activo(s)? Esta acção é irreversível. Os activos com amortizações integradas não podem ser eliminados.`,
      okText: 'Eliminar', cancelText: 'Cancelar', okButtonProps: { danger: true },
      onOk: () => eliminar.mutateAsync({ url: '/ativos/bens/eliminar', dados: { ids: seleccao } }).catch(() => undefined),
    });
  };

  const template = () => void descarregar('/ativos/bens/importar/modelo', undefined, NOME_TEMPLATE_ACTIVOS).catch(notificarErro);

  return (
    <>
      {contextoModal}
      <AlertaReconciliacao />
      <Card
        title="Inventário de Imobilizados"
        extra={
          <Space wrap size={10}>
            {gerir && <BotaoCartao icone="file-excel" title="Baixar Modelo de Excel para migração" onClick={template}>Template Excel</BotaoCartao>}
            {gerir && <BotaoCartao icone="upload" title="Importar Imobilizados de Excel" onClick={() => setImportar(true)}>Importar Excel</BotaoCartao>}
            {podeEliminar && <BotaoCartao icone="trash" variante="perigo" title="Eliminar multiplos activos sem processamento" loading={eliminar.isPending} onClick={eliminarEmMassa}>Eliminar em Massa</BotaoCartao>}
            {gerir && <BotaoCartao icone="edit" variante="aviso" onClick={() => exigirSeleccao('editar') && setMassa(true)}>Edição em Massa</BotaoCartao>}
            {gerir && <BotaoCartao icone="plus" variante="primario" onClick={() => setNovo(true)}>Novo Activo</BotaoCartao>}
          </Space>
        }
      >
        <BarraFiltros>
          <Input.Search placeholder="Código ou descrição" allowClear onSearch={setTexto} style={{ width: 240 }} />
          <Select placeholder="Estado" allowClear value={estado} onChange={setEstado} style={{ width: 140 }}
            options={[{ value: 'ACTIVO', label: 'Activo' }, { value: 'INACTIVO', label: 'Inactivo' }, { value: 'ABATIDO', label: 'Abatido' }]} />
          <SeletorCategoria allowClear value={categoria} onChange={setCategoria} style={{ width: 220 }} />
          <SeletorAux tabela="centros-custo" placeholder="Centro de custo" value={centro} onChange={setCentro} style={{ width: 200 }} />
          <Checkbox checked={semLancamento} onChange={(e) => setSemLancamento(e.target.checked)} style={{ alignSelf: 'center' }}>
            Sem lançamento de compra
          </Checkbox>
        </BarraFiltros>
        <TabelaApi<Activo>
          url="/ativos/bens"
          chaveConsulta={['activos', 'bens']}
          filtros={{ texto, estado, categoria_ativo_id: categoria, centro_custo_id: centro, sem_lancamento: semLancamento ? 1 : undefined }}
          porPagina={50}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          scroll={scrollTabela()}
          rotuloTotal="TOTAIS (FILTRADOS):"
          impressao={{
            titulo: 'Inventário de Imobilizados',
            filtros: [texto && `Pesquisa: ${texto}`, estado && `Estado: ${estado}`, categoria !== undefined && `Categoria: #${categoria}`, centro !== undefined && `Centro de custo: #${centro}`, semLancamento && 'Sem lançamento de compra'],
            rotuloTotal: 'TOTAIS (FILTRADOS):',
            orientacao: 'paisagem',
          }}
          seleccao={gerir || podeEliminar ? {
            chaves: seleccao,
            aoMudar: (k) => setSeleccao(k as number[]),
            ajuda: 'Marque os activos para a Edição em Massa ou a Eliminação em Massa.',
          } : undefined}
          accoes={(r) => [
            gerir && { icone: iconeEditar(r), titulo: dicaEditar(r), onClick: () => setEditar(r) },
            gerir && { icone: 'exchange-alt', titulo: 'Transferir C.Custo', cor: '#64748b', onClick: () => setTransferir(r.id) },
            podeEliminar && {
              icone: 'trash', titulo: 'Apagar', variante: 'perigo' as const, desactivado: bloqueioEliminar(r),
              confirmar: { titulo: `Deseja realmente apagar o bem "${r.descricao}"?`, textoOk: 'Apagar', perigo: true },
              onClick: () => eliminarUm.mutateAsync({ metodo: 'delete', url: `/ativos/bens/${r.id}` }).catch(() => undefined),
            },
          ]}
          rowKey="id"
          onRow={(r) => ({ onDoubleClick: () => navegar(String(r.id)) })}
        />
      </Card>
      <ModalActivo aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(a) => navegar(String(a.id))} />
      <ModalActivo aberto={!!editar} activo={editar} aoFechar={() => setEditar(null)} />
      <ModalTransferir aberto={transferir !== null} activoId={transferir} aoFechar={() => setTransferir(null)} />
      <ModalImportarActivos aberto={importar} aoFechar={() => setImportar(false)} />
      <ModalEdicaoMassa aberto={massa} ids={seleccao} aoFechar={() => setMassa(false)} />
    </>
  );
}

function Transferencias() {
  const [activo, setActivo] = useState<number>();
  const [projecto, setProjecto] = useState<number>();
  const cc = (c?: { codigo: string; descricao: string | null } | null) => (c ? `${c.codigo} — ${c.descricao ?? ''}` : '—');
  return (
    <Card>
      <Flex gap={8} wrap style={{ marginBottom: 16 }}>
        <SeletorActivo allowClear value={activo} onChange={setActivo} style={{ width: 320, maxWidth: '100%' }} />
        <SeletorProjecto allowClear value={projecto} onChange={setProjecto} style={{ width: 260, maxWidth: '100%' }} />
      </Flex>
      <TabelaApi<Transferencia>
        url="/ativos/transferencias"
        chaveConsulta={['activos', 'transferencias']}
        filtros={{ ativo_imobilizado_id: activo, projeto_id: projecto }}
        scroll={scrollTabela()}
        impressao={{ titulo: 'Transferências de activos entre centros de custo' }}
        columns={[
          { title: 'Data', dataIndex: 'data', render: formatarData },
          { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
          { title: 'Origem', key: 'o', render: (_, r) => cc(r.centro_custo_origem) },
          { title: 'Destino', key: 'd', render: (_, r) => cc(r.centro_custo_destino) },
        ]}
      />
    </Card>
  );
}

function Afectacoes() {
  const { pode } = useSessao();
  const [projecto, setProjecto] = useState<number>();
  const [editar, setEditar] = useState<Afectacao | null | undefined>(undefined);
  const q = useQuery({ queryKey: ['activos', 'afetacoes', projecto], queryFn: () => obter<Afectacao[]>('/ativos/afetacoes', { projeto_id: projecto }) });
  const eliminar = useAccao({ invalidar: [['activos']] });
  const colunas: ColunaApi<Afectacao>[] = [
          { title: 'Activo', key: 'a', render: (_, r) => (r.ativo_imobilizado ? `${r.ativo_imobilizado.codigo} — ${r.ativo_imobilizado.descricao}` : r.ativo_imobilizado_id) },
          { title: 'Projecto', key: 'p', render: (_, r) => (r.projeto ? `${r.projeto.codigo ?? ''} — ${r.projeto.nome}` : r.projeto_id) },
          { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
          { title: 'Fim', dataIndex: 'data_fim', render: formatarData },
          {
            title: '', key: 'acc', align: 'right',
            render: (_, r) => pode('activos_gerir') && (
              <Space>
                <Button size="small" icon={<EditOutlined />} onClick={() => setEditar(r)} />
                <Popconfirm title="Eliminar a afectação?" okText="Eliminar" cancelText="Cancelar" onConfirm={() => eliminar.mutate({ metodo: 'delete', url: `/ativos/afetacoes/${r.id}` })}>
                  <Button size="small" danger icon={<DeleteOutlined />} />
                </Popconfirm>
              </Space>
            ),
          },
  ];
  return (
    <Card>
      <BarraFiltros accoes={
        <>
          <BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({ titulo: 'Afectações de activos a projectos', colunas, linhas: q.data ?? [] })} />
          {pode('activos_gerir') && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar(null)}>Nova afectação</Button>}
        </>
      }>
        <SeletorProjecto allowClear value={projecto} onChange={setProjecto} style={{ width: 300 }} />
      </BarraFiltros>
      <TabelaComModos<Afectacao> idVista="afectacoes"
        rowKey="id"
        size="middle"
        loading={q.isFetching}
        dataSource={q.data}
        scroll={scrollTabela()}
        columns={colunas}
      />
      <ModalAfectacao aberto={editar !== undefined} afectacao={editar} aoFechar={() => setEditar(undefined)} />
    </Card>
  );
}

function ResumoCategorias() {
  const q = useQuery({ queryKey: ['activos', 'resumo-categorias'], queryFn: () => obter<ResumoCategoria[]>('/ativos/mapas/categorias') });
  const totais = somarColunas(q.data ?? [], ['bruto', 'acumulada', 'liquido']);
  return (
    <Card extra={<Space wrap><BotoesExportar desactivado={!q.data?.length} obterPedido={() => pedidoTabela({
      titulo: 'Imobilizado por categoria',
      colunas: [
        { title: 'Categoria', dataIndex: 'categoria' },
        { title: 'Activos', dataIndex: 'ativos', align: 'right', totalImpressao: (ls: ResumoCategoria[]) => String(ls.reduce((s, l) => s + Number(l.ativos ?? 0), 0)) },
        { title: 'Valor bruto (Kz)', align: 'right', valorImpressao: (l: ResumoCategoria) => formatarKz(String(l.bruto)), totalImpressao: () => formatarKz(totais.bruto) },
        { title: 'Amortização acumulada', align: 'right', valorImpressao: (l: ResumoCategoria) => formatarKz(String(l.acumulada)), totalImpressao: () => formatarKz(totais.acumulada) },
        { title: 'Valor líquido', align: 'right', valorImpressao: (l: ResumoCategoria) => formatarKz(String(l.liquido)), totalImpressao: () => formatarKz(totais.liquido) },
      ] as ColunaApi<ResumoCategoria>[],
      linhas: q.data ?? [],
    })} /><BotaoCsv nome="imobilizado-por-categoria" linhas={q.data} colunas={[
      { titulo: 'Categoria', valor: (l) => l.categoria }, { titulo: 'Activos', valor: (l) => l.ativos },
      { titulo: 'Valor bruto', valor: (l) => l.bruto, numerico: true }, { titulo: 'Amortização acumulada', valor: (l) => l.acumulada, numerico: true },
      { titulo: 'Valor líquido', valor: (l) => l.liquido, numerico: true },
    ]} /></Space>}>
      <Table<ResumoCategoria>
        rowKey="categoria_ativo_id"
        size="middle"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={false}
        scroll={scrollTabela()}
        columns={[
          { title: 'Categoria', dataIndex: 'categoria' },
          { title: 'Activos', dataIndex: 'ativos', align: 'right' },
          { title: 'Valor bruto (Kz)', dataIndex: 'bruto', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Amortização acumulada', dataIndex: 'acumulada', align: 'right', render: (v) => <ValorKz valor={v} /> },
          { title: 'Valor líquido', dataIndex: 'liquido', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
        ]}
        summary={() => (
          <Table.Summary.Row>
            <Table.Summary.Cell index={0} colSpan={2}><Typography.Text strong>Total</Typography.Text></Table.Summary.Cell>
            <Table.Summary.Cell index={2} align="right"><ValorKz valor={totais.bruto} forte /></Table.Summary.Cell>
            <Table.Summary.Cell index={3} align="right"><ValorKz valor={totais.acumulada} forte /></Table.Summary.Cell>
            <Table.Summary.Cell index={4} align="right"><ValorKz valor={totais.liquido} forte /></Table.Summary.Cell>
          </Table.Summary.Row>
        )}
      />
    </Card>
  );
}
