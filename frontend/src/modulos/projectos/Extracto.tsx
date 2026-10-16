import { Button, Card, DatePicker, Flex, Input, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import type dayjs from 'dayjs';
import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { IconeFa } from '@/componentes/fluxos';
import { tabelaHtml } from '@/componentes/impressao';
import { CartaoKpi, GrelhaKpi, TabelaComModos, type ColunaLegado } from '@/componentes/vistas';
import { BotaoCsv, ValorKz } from '@/modulos/contab/comum/Componentes';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { SeletorProjecto } from '@/modulos/activos/comum/componentes';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import { EtiquetaProjectos, rotuloProjectos } from './comum/componentes';
import { rotuloRubrica } from './comum/regras';
import type { Extracto as DadosExtracto, MovimentoExtracto } from './comum/tipos';
import { ModalProjecto } from './ModalProjecto';

type Periodo = [dayjs.Dayjs | null, dayjs.Dayjs | null] | null;
type Linha = MovimentoExtracto & { _chave: string };

/**
 * Projectos › Extracto analítico (ecrã projectos_extracto): custos, proveitos e compromissos por projecto, tarefa e rubrica.
 * Em cima, o formulário de consulta do antigo (ui_projects.js:28-56): projecto, período, «Consultar» e «Novo Projecto».
 */
export default function Extracto() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  // rascunho do formulário de consulta; só passa à consulta com «Consultar»
  const [projectoForm, setProjectoForm] = useState<number>();
  const [periodoForm, setPeriodoForm] = useState<Periodo>(null);
  const [consulta, setConsulta] = useState<{ projecto?: number; periodo: Periodo }>({ periodo: null });
  const [natureza, setNatureza] = useState<string>();
  const [texto, setTexto] = useState('');
  const [novo, setNovo] = useState(false);
  const projecto = consulta.projecto;
  const inicio = dataApi(consulta.periodo?.[0]);
  const fim = dataApi(consulta.periodo?.[1]);
  const q = useQuery({ queryKey: ['projectos', 'extracto', projecto, inicio, fim], queryFn: () => obter<DadosExtracto>('/projetos/extracto', { projeto_id: projecto, inicio, fim }) });
  const linhas = useMemo(
    () => (q.data?.movimentos ?? []).filter((m) => (!natureza || m.natureza === natureza) && contemTexto(texto, m.documento, m.descricao, m.codigo_projeto, m.tarefa, m.rubrica)),
    [q.data, natureza, texto],
  );
  // chave estável por linha (o índice no rowKey está obsoleto no Ant Design 5)
  const linhasComChave = useMemo<Linha[]>(() => linhas.map((m, i) => ({ ...m, _chave: `${m.fonte}-${m.fonte_id}-${i}` })), [linhas]);
  const t = q.data?.totais;
  const resultado = t ? (Number(t.proveitos) - Number(t.custos)).toFixed(2) : undefined;

  // colunas pela ordem do legado (loadProjectExtract): Data, Projecto, WBS / Tarefa, Natureza, Rubrica, Origem, Documento, Descrição, Valor
  const colunas: ColunaLegado<Linha>[] = [
    { title: 'Data', dataIndex: 'data', render: formatarData, valorFiltro: (m) => formatarData(m.data) },
    { title: 'Projecto', dataIndex: 'codigo_projeto' },
    { title: 'WBS / Tarefa', dataIndex: 'tarefa', render: (v, m) => (m.marco && !v ? <Typography.Text type="danger"><IconeFa nome="flag" /> {m.marco}</Typography.Text> : v ?? '—') },
    { title: 'Natureza', dataIndex: 'natureza', responsive: ['sm'], valorFiltro: (m) => rotuloProjectos(m.natureza), render: (v) => <EtiquetaProjectos valor={v} /> },
    { title: 'Rubrica', key: 'r', responsive: ['md'], valorFiltro: (m) => rotuloRubrica(m.categoria ?? m.rubrica), render: (_, m) => rotuloRubrica(m.categoria ?? m.rubrica) },
    { title: 'Origem', dataIndex: 'modulo', responsive: ['lg'] },
    { title: 'Documento', key: 'doc', valorFiltro: (m) => [m.tipo_documento, m.documento].filter(Boolean).join(' · '), render: (_, m) => [m.tipo_documento, m.documento].filter(Boolean).join(' · ') || '—' },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 260 },
    // proveitos e custos não se somam: o total só aparece com a natureza filtrada (como na impressão)
    { title: 'Valor (Kz)', dataIndex: 'valor', align: 'right', total: natureza ? true : undefined,
      render: (v, m) => <Typography.Text strong type={m.natureza === 'CUSTO' ? 'danger' : m.natureza === 'PROVEITO' ? 'success' : m.natureza === 'COMPROMISSO' ? 'warning' : undefined}>{formatarKz(v)}</Typography.Text> },
    { title: 'Pendente', dataIndex: 'valor_pendente', align: 'right', total: natureza ? true : undefined, render: (v) => <ValorKz valor={v} discretoSeZero /> },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={<><IconeFa nome="hard-hat" /> Extracto Analítico de Projectos</>}
        subtitulo="Consulta transversal de compromissos, custos, proveitos e facturação."
        impressaoDesactivada={!linhas.length}
        impressao={() => ({
          titulo: 'Extracto analítico de projectos',
          periodo: inicio || fim ? `${inicio ? formatarData(inicio) : '…'} a ${fim ? formatarData(fim) : '…'}` : undefined,
          filtros: [
            projecto ? `Projecto: ${linhas[0]?.codigo_projeto ?? `#${projecto}`}` : 'Todos os projectos',
            natureza && `Natureza: ${rotuloProjectos(natureza)}`,
            texto && `Pesquisa: ${texto}`,
            t && `Proveitos ${formatarKz(t.proveitos)} Kz · Custos ${formatarKz(t.custos)} Kz · Resultado ${formatarKz(resultado)} Kz · Compromissos ${formatarKz(t.compromissos)} Kz`,
          ],
          conteudo: tabelaHtml({
            colunas: [
              { titulo: 'Data', valor: (m: MovimentoExtracto) => m.data, formato: 'data' },
              { titulo: 'Projecto', valor: (m) => m.codigo_projeto },
              { titulo: 'Natureza', valor: (m) => rotuloProjectos(m.natureza) },
              { titulo: 'Rubrica', valor: (m) => rotuloRubrica(m.categoria ?? m.rubrica) },
              { titulo: 'Módulo', valor: (m) => m.modulo },
              { titulo: 'Documento', valor: (m) => [m.tipo_documento, m.documento].filter(Boolean).join(' · ') },
              { titulo: 'Descrição', valor: (m) => m.descricao, quebrar: true },
              { titulo: 'Tarefa', valor: (m) => m.tarefa ?? '' },
              { titulo: 'Valor (Kz)', valor: (m) => m.valor, formato: 'moeda', somar: !!natureza },
              { titulo: 'Pendente (Kz)', valor: (m) => m.valor_pendente, formato: 'moeda', somar: !!natureza },
            ],
            linhas,
            agrupar: natureza ? undefined : { chave: (m) => rotuloProjectos(m.natureza), subtotais: true },
            totais: natureza ? 'Total' : false,
          }),
        })}
      />
      <Card style={{ marginBottom: 16 }} className="imp-nao-imprimir">
        <Flex gap={16} wrap align="end">
          <label style={{ display: 'flex', flexDirection: 'column', gap: 2, flex: '1 1 260px', minWidth: 0 }}>
            <span>Selecione o Projecto</span>
            <SeletorProjecto allowClear value={projectoForm} onChange={setProjectoForm} style={{ width: '100%' }} placeholder="-- Todos os Projectos --" />
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <span>Período</span>
            <DatePicker.RangePicker format="DD/MM/YYYY" allowEmpty={[true, true]} value={periodoForm} onChange={(v) => setPeriodoForm(v)} />
          </label>
          <Button type="primary" icon={<IconeFa nome="search" />} loading={q.isFetching} onClick={() => setConsulta({ projecto: projectoForm, periodo: periodoForm })}>Consultar</Button>
          {pode('proj_gerir') && (
            <Button icon={<IconeFa nome="plus" />} style={{ background: '#10b981', borderColor: '#10b981', color: '#fff' }} onClick={() => setNovo(true)}>Novo Projecto</Button>
          )}
        </Flex>
      </Card>
      {/* legado (loadProjectExtract, ui_projects.js:28-208): Compromissos (âmbar), Custos reais (vermelho), Proveitos (verde), barra à esquerda; «Resultado» é do sistema novo */}
      <GrelhaKpi style={{ marginBottom: 16 }}>
        <CartaoKpi barra="esquerda" cor="laranja" icone="file-contract" rotulo="Total compromissos" valor={formatarKz(t?.compromissos)} unidade="Kz" corValor="#d97706" />
        <CartaoKpi barra="esquerda" cor="vermelho" icone="arrow-down" rotulo="Custos reais (execução)" valor={formatarKz(t?.custos)} unidade="Kz" corValor="#dc2626" />
        <CartaoKpi barra="esquerda" cor="verde" icone="arrow-up" rotulo="Proveitos (rédito)" valor={formatarKz(t?.proveitos)} unidade="Kz" corValor="#059669" />
        <CartaoKpi barra="esquerda" cor="azul" icone="balance-scale" rotulo="Resultado" valor={formatarKz(resultado)} unidade="Kz" corValor={Number(resultado) < 0 ? '#dc2626' : undefined} />
      </GrelhaKpi>
      <Card>
        <Flex gap={8} wrap justify="space-between" style={{ marginBottom: 16 }}>
          <Flex gap={8} wrap>
            <Select placeholder="Natureza" allowClear value={natureza} onChange={setNatureza} style={{ width: 150 }}
              options={[{ value: 'CUSTO', label: 'Custos' }, { value: 'PROVEITO', label: 'Proveitos' }, { value: 'COMPROMISSO', label: 'Compromissos' }]} />
            <Input.Search placeholder="Documento, descrição, tarefa" allowClear onSearch={setTexto} style={{ width: 240, maxWidth: '100%' }} />
          </Flex>
          <BotaoCsv nome="extracto-projectos" linhas={linhas} colunas={[
            { titulo: 'Data', valor: (m) => formatarData(m.data) }, { titulo: 'Projecto', valor: (m) => m.codigo_projeto }, { titulo: 'Natureza', valor: (m) => m.natureza },
            { titulo: 'Rubrica', valor: (m) => rotuloRubrica(m.categoria ?? m.rubrica) }, { titulo: 'Módulo', valor: (m) => m.modulo }, { titulo: 'Tipo', valor: (m) => m.tipo_documento },
            { titulo: 'Documento', valor: (m) => m.documento }, { titulo: 'Descrição', valor: (m) => m.descricao }, { titulo: 'Tarefa', valor: (m) => m.tarefa }, { titulo: 'Milestone', valor: (m) => m.marco },
            { titulo: 'Valor', valor: (m) => m.valor, numerico: true }, { titulo: 'Pendente', valor: (m) => m.valor_pendente, numerico: true }, { titulo: 'Contabilizado', valor: (m) => m.contabilizado },
          ]} />
        </Flex>
        <TabelaComModos<Linha>
          rowKey="_chave"
          size="small"
          modos={false}
          loading={q.isFetching}
          dataSource={linhasComChave}
          columns={colunas}
          vazio={{ icone: 'hard-hat', titulo: 'Sem movimentos', texto: 'Seleccione um projecto e clique em consultar.' }}
        />
      </Card>
      <ModalProjecto aberto={novo} aoFechar={() => setNovo(false)} aoGravar={(p) => navegar(`/m/projectos/projectos_carteira/${p.id}`)} />
    </>
  );
}
