import { Alert, Button, Card, Flex, Input, InputNumber, Modal, Select, Space, Table, Tag, Typography } from 'antd';
import { DeleteOutlined, PlusOutlined, SplitCellsOutlined } from '@ant-design/icons';
import { IconeFa } from '@/componentes/fluxos';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { NavActivos } from './comum/NavActivos';
import { BotoesExportar } from '@/componentes/impressao';
import { CartaoKpi, GrelhaKpi, TabelaComModos } from '@/componentes/vistas';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { pedidoTabela } from './comum/impressao';
import { useSessao } from '@/sessao/SessaoContexto';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { useAccao } from '@/componentes/Accoes';
import { formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaActivos, SeletorCategoria, useCategorias } from './comum/componentes';
import { combinarPendentes, repartirValor, validarInventariacao } from './comum/regras';
import type { AquisicoesPendentes, LinhaPendente } from './comum/tipos';
import { larguraModal, scrollTabela } from '@/componentes/responsivo';
import { somar } from '@/utilitarios/decimal';

const VERDE = '#059669'; // var(--success) do legado
const VERMELHO = '#ef4444'; // var(--danger)
const kz = (v: unknown) => formatarKz(v === null || v === undefined ? null : String(v));

/** Activos › Aquisições pendentes (ecrã activos_pendentes): linhas 11/12 do Diário por inventariar e activos sem lançamento de compra. */
export default function Pendentes() {
  const { pode } = useSessao();
  const q = useQuery({ queryKey: ['activos', 'pendentes'], queryFn: () => obter<AquisicoesPendentes>('/ativos/aquisicoes-pendentes') });
  const [inventariar, setInventariar] = useState<LinhaPendente[] | null>(null);
  const [ligar, setLigar] = useState<LinhaPendente | null>(null);
  const [seleccao, setSeleccao] = useState<number[]>([]);
  const d = q.data;
  const gerir = pode('activos_inventariar');
  // caixas de selecção do legado (pending-check, ui_assets.js:2793/2810): só as linhas ainda pendentes
  const seleccionadas = (d?.linhas ?? []).filter((l) => seleccao.includes(l.id));
  useEffect(() => {
    if (d) setSeleccao((s) => s.filter((id) => d.linhas.some((l) => l.id === id)));
  }, [d]);

  // colunas, ordem e cores do legado (renderPendingAcquisitions, ui_assets.js:2786-2845); Diário, N.º lanç. e
  // Fornecedor são extras do sistema novo, escondidos por omissão («Colunas ▾»)
  const colunas: ColunaApi<LinhaPendente>[] = [
    { title: 'Data', dataIndex: 'data_documento', render: formatarData, valorImpressao: (l) => formatarData(l.data_documento) },
    { title: 'Diário', dataIndex: 'diario', ocultaPorOmissao: true, render: (v) => v ?? '—' },
    { title: 'Nº Doc', dataIndex: 'numero_documento', render: (v) => <Tag>{v ?? '—'}</Tag>, valorImpressao: (l) => l.numero_documento ?? '—' },
    { title: 'N.º lanç.', dataIndex: 'numero_lan', ocultaPorOmissao: true, render: (v) => v ?? '—' },
    { title: 'Conta', dataIndex: 'codigo_conta', render: (v) => <strong>{v}</strong> },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
    { title: 'Fornecedor', dataIndex: 'terceiro', ellipsis: true, width: 200, ocultaPorOmissao: true, render: (v) => v ?? '—' },
    { title: 'Valor Total', dataIndex: 'valor', align: 'right', total: true, render: (v) => kz(v), valorImpressao: (l) => kz(l.valor), totalImpressao: (ls) => kz(somar(ls.map((l) => l.valor))) },
    {
      title: 'Inventariado', dataIndex: 'inventariado', align: 'right', total: true, formatoTotal: (v) => <span style={{ color: VERDE }}>{kz(v)}</span>,
      render: (v) => <span style={{ color: VERDE, whiteSpace: 'nowrap' }}>{kz(v)}</span>, valorImpressao: (l) => kz(l.inventariado), totalImpressao: (ls) => kz(somar(ls.map((l) => l.inventariado))),
    },
    {
      title: 'Restante', dataIndex: 'por_inventariar', align: 'right', total: true, formatoTotal: (v) => <span style={{ color: VERMELHO }}>{kz(v)}</span>,
      render: (v) => <strong style={{ color: VERMELHO, whiteSpace: 'nowrap' }}>{kz(v)}</strong>, valorImpressao: (l) => kz(l.por_inventariar), totalImpressao: (ls) => kz(somar(ls.map((l) => l.por_inventariar))),
    },
    // badge-warning «PARCIAL» / badge-outline «PENDENTE»
    { title: 'Estado', dataIndex: 'estado', render: (v) => (v === 'PARCIAL' ? <Tag color="warning">PARCIAL</Tag> : <Tag>{v ?? 'PENDENTE'}</Tag>), valorImpressao: (l) => l.estado },
    {
      title: 'Acção', key: 'acc', valorImpressao: () => '',
      render: (_, l) => gerir && (
        <Space size={5} style={{ whiteSpace: 'nowrap' }}>
          <Button size="small" icon={<IconeFa nome="link" />} title="Ligar a Activos Existentes/Migrados" disabled={!d?.ativos_sem_lancamento.length} onClick={() => setLigar(l)}>Ligar</Button>
          <Button size="small" type="primary" icon={<IconeFa nome="plus" />} onClick={() => setInventariar([l])}>{l.estado === 'PARCIAL' ? 'Continuar' : 'Inventariar'}</Button>
        </Space>
      ),
    },
  ];

  return (
    <>
      <NavActivos actual="activos_pendentes" />
      {/* indicadores do sistema novo (o legado só tem a tabela): cartões KPI do tema */}
      <GrelhaKpi style={{ marginBottom: 16 }}>
        <CartaoKpi icone="list" cor="laranja" rotulo="Linhas por inventariar" valor={d?.linhas.length ?? 0} />
        <CartaoKpi icone="coins" cor="azul" rotulo="Valor por inventariar" valor={formatarKz(d?.total_por_inventariar)} unidade="Kz" />
        <CartaoKpi icone="unlink" cor="cinzento" rotulo="Activos sem lançamento" valor={d?.ativos_sem_lancamento.length ?? 0} />
      </GrelhaKpi>
      <Card title="Movimentos Contabilísticos Sem Activo Associado" style={{ marginBottom: 16 }}
        extra={(
          <Space wrap>
            {/* inventorySelectedPendingMovements (ui_assets.js:2784, 3208-3217): junta as linhas seleccionadas num só inventário */}
            {gerir && (
              <Button size="small" type="primary" icon={<IconeFa nome="layer-group" />} disabled={!seleccionadas.length} onClick={() => setInventariar(seleccionadas)}
                title={seleccionadas.length ? `${seleccionadas.length} linha(s) — ${formatarKz(combinarPendentes(seleccionadas).total)} Kz` : 'Seleccione pelo menos um movimento.'}>
                Inventariar Seleccionados (Combinar)
              </Button>
            )}
            <BotoesExportar tamanho="small" desactivado={!d?.linhas.length} obterPedido={() => pedidoTabela({ titulo: 'Aquisições pendentes de inventariação (contas 11/12)', colunas, linhas: d?.linhas ?? [], totais: 'Total' })} />
          </Space>
        )}>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Estes Lançamentos foram detectados no Diário (Classes 11 e 12). Pode inventariar o valor total ou parcial de cada linha." />
        <TabelaComModos<LinhaPendente>
          idVista="activos_pendentes"
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={d?.linhas}
          scroll={{ x: 'max-content' }}
          columns={colunas}
          rotuloTotal="TOTAIS (FILTRADOS):"
          rowSelection={gerir ? { selectedRowKeys: seleccao, onChange: (k) => setSeleccao(k as number[]) } : undefined}
        />
      </Card>
      <Card title="Activos sem lançamento de compra">
        <Table
          rowKey="id"
          size="small"
          loading={q.isFetching}
          dataSource={d?.ativos_sem_lancamento}
          scroll={scrollTabela()}
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
      <ModalInventariar linhas={inventariar} aoFechar={() => setInventariar(null)} aoConcluir={() => setSeleccao([])} />
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

/**
 * Inventaria uma linha 11/12 — ou várias combinadas (openAssetCreationModalFromJournal com "ID1,ID2", ui_assets.js:511-606) —
 * em um ou mais activos; a soma não pode exceder o valor por inventariar (somado, nas combinadas). O primeiro item vem
 * pré-preenchido com a descrição da primeira linha e o valor total.
 */
export function ModalInventariar({ linhas, aoFechar, aoConcluir }: { linhas: LinhaPendente[] | null; aoFechar: () => void; aoConcluir?: () => void }) {
  const [itens, setItens] = useState<ItemInventario[]>([]);
  const [partes, setPartes] = useState(2);
  const categorias = useCategorias();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: () => { aoConcluir?.(); aoFechar(); } });
  const combinada = (linhas?.length ?? 0) > 1;
  const comb = combinarPendentes(linhas ?? []);
  const linha = comb.ordenadas[0] ?? null;
  const porInventariar = comb.total;

  useEffect(() => {
    const l = linhas ? combinarPendentes(linhas) : null;
    const p = l?.ordenadas[0];
    if (l && p)
      setItens([{ chave: sequencia++, descricao: p.descricao ?? '', valor_aquisicao: Number(l.total), centro_custo_id: p.centro_custo_id ?? undefined, unidade_negocio_id: p.unidade_negocio_id ?? undefined }]);
  }, [linhas]);

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
    setItens(repartirValor(porInventariar, partes).map((v, i) => ({ ...base, chave: sequencia++, codigo: undefined, descricao: `${base.descricao}${partes > 1 ? ` (${i + 1}/${partes})` : ''}`, valor_aquisicao: Number(v) })));
  };

  const validacao = validarInventariacao(itens, porInventariar);
  const contas = [...new Set(comb.ordenadas.map((l) => l.codigo_conta))];
  const valido = itens.length > 0 && !validacao.excede && itens.every((i) => i.descricao.trim() && i.categoria_ativo_id && (i.valor_aquisicao ?? -1) >= 0);

  return (
    <Modal
      title={combinada
        ? `Inventariar ${comb.ids.length} linhas combinadas — ${contas.length > 1 ? `múltiplas contas (${contas.join(', ')})` : `conta ${contas[0]}`}`
        : `Inventariar ${linha?.numero_documento ?? ''} — conta ${linha?.codigo_conta ?? ''}`}
      open={!!linha}
      onCancel={aoFechar}
      width={larguraModal(1100)}
      okText={`Inventariar ${itens.length} activo(s)`}
      cancelText="Cancelar"
      okButtonProps={{ disabled: !valido }}
      confirmLoading={accao.isPending}
      onOk={() => {
        const dados = { itens: itens.map(({ chave: _c, ...r }) => ({ ...r, codigo: r.codigo || null })) };
        accao.mutate(combinada
          ? { url: '/ativos/aquisicoes-pendentes/combinar', dados: { linhas: comb.ids, ...dados } }
          : { url: `/ativos/aquisicoes-pendentes/${linha?.id}/inventariar`, dados });
      }}
      destroyOnHidden
    >
      {combinada && (
        <Alert type="info" showIcon style={{ marginBottom: 12 }}
          message={`Linhas combinadas: ${comb.ordenadas.map((l) => `${l.numero_documento ?? `#${l.id}`} (${l.codigo_conta}, ${formatarKz(l.por_inventariar)})`).join(' · ')}`}
          description="Data de aquisição e fornecedor da primeira linha; o valor dos activos é repartido pelas linhas por esta ordem." />
      )}
      <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 12 }}>
        <Space wrap>
          <Typography.Text>Dividir em</Typography.Text>
          <InputNumber min={1} max={1000} value={partes} onChange={(v) => setPartes(v ?? 1)} style={{ width: 80 }} />
          <Button icon={<SplitCellsOutlined />} onClick={dividir}>unidades iguais</Button>
          <Button icon={<PlusOutlined />} onClick={() => setItens((s) => [...s, { chave: sequencia++, descricao: linha?.descricao ?? '', centro_custo_id: linha?.centro_custo_id ?? undefined }])}>Linha</Button>
        </Space>
        <Space size="large" wrap>
          <span>Por inventariar: <ValorKz valor={porInventariar} forte /></span>
          <span>Itens: <ValorKz valor={validacao.total} forte /></span>
          <span>Resto: <ValorKz valor={validacao.restante} forte /></span>
        </Space>
      </Flex>
      {validacao.excede && <Alert type="error" showIcon style={{ marginBottom: 12 }} message={`A soma dos itens excede o valor por inventariar ${combinada ? 'das linhas seleccionadas' : 'da linha'}.`} />}
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
