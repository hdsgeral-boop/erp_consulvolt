import { Button, Card, Checkbox, DatePicker, Flex, Input, Modal, Select, Space, Tag, Tooltip, Typography, message, theme } from 'antd';
import {
  ApartmentOutlined,
  CalendarOutlined,
  CheckOutlined,
  CloseOutlined,
  EditOutlined,
  EyeOutlined,
  HistoryOutlined,
  ImportOutlined,
  NumberOutlined,
  PlusOutlined,
  RobotOutlined,
  SearchOutlined,
  UserOutlined,
} from '@ant-design/icons';
import dayjs, { type Dayjs } from 'dayjs';
import { lazy, Suspense, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import type { LinhaLancamento } from '../api';
import { rotuloTerceiro } from '../comum/terceiro';
import { ValorKz } from '../comum/Componentes';
import { useDiarios, porId, useTabelaAux, useUnidadesNegocio } from '../comum/dados';
import { ModalImportar } from '../comum/ficheiros';
import { CampoFiltro, GrupoFiltros, PainelFiltros } from '../comum/PainelFiltros';
import { SeletorDiario, SeletorTerceiro } from '../comum/Seletores';
import { ModalSaldosHistoricos } from './ModalSaldosHistoricos';
import { ModalClassificacao, pedidoClassificacao, type CamposClassificacao } from './ModaisLancamento';
import { camposMassa, filtrosLancamentos, intervaloPeriodo, REMOVER, SEM_ROTULOS, type FiltrosLista, type SemCampo } from './filtros';

const ANO_ACTUAL = dayjs().year();
// «Agente IA (Ler Documento)» do legado → Assistente IA (M-03, ecrã do grupo Geral), carregado só quando se abre
const AssistenteIA = lazy(() => import('@/modulos/geral/integracoes/AssistenteIA').then((m) => ({ default: m.AssistenteIA })));

/**
 * Contabilidade › Lançamentos contabilísticos (legado: renderLancamentos, js/ui_lancamentos.js:66-950).
 * Painel de filtros por secções (período fiscal, conta e diário, identificação, terceiro), filtros rápidos «Mostrar linhas
 * sem» (nota DEMO, fluxo, UN, CC), alteração em massa das notas/UN/CC nas linhas seleccionadas ou em todas as filtradas
 * (A-05) e «Listagem de movimentos (Diário)» com selecção de linhas.
 */
export function ListaLancamentos() {
  const navegar = useNavigate();
  const { token } = theme.useToken();
  const { pode } = useSessao();
  const cliente = useQueryClient();
  const diarios = useDiarios();
  const nomesDiario = porId(diarios.data, (d) => d.codigo);
  const demo = useTabelaAux('notas-demonstracao');
  const fluxo = useTabelaAux('notas-fluxo-caixa');
  const centros = useTabelaAux('centros-custo');
  const unidades = useUnidadesNegocio();
  const codigo = (lista: { id: number; codigo: string | null }[] | undefined) => new Map((lista ?? []).map((x) => [x.id, x.codigo ?? String(x.id)]));
  const [cDemo, cFluxo, cCentro, cUnidade] = [codigo(demo.data), codigo(fluxo.data), codigo(centros.data), codigo(unidades.data)];

  // rascunho dos filtros (só se aplicam com «Filtrar», como no legado) e filtros aplicados
  const [rascunho, setRascunho] = useState<FiltrosLista>({});
  const [aplicados, setAplicados] = useState<FiltrosLista>({});
  const [sem, setSem] = useState<SemCampo[]>([]);
  const [selec, setSelec] = useState<number[]>([]);
  const [massa, setMassa] = useState<Record<string, number | undefined>>({});
  const [importar, setImportar] = useState(false);
  const [saldos, setSaldos] = useState(false);
  const [aceitarAvisos, setAceitarAvisos] = useState(false);
  const [editar, setEditar] = useState<LinhaLancamento | null>(null);
  const [assistente, setAssistente] = useState(false);
  const muda = (p: Partial<FiltrosLista>) => setRascunho((r) => ({ ...r, ...p }));
  const filtrar = () => setAplicados(rascunho);
  const filtros = useMemo(() => filtrosLancamentos(aplicados, sem), [aplicados, sem]);
  const podeMassa = pode('lancamentos_bulk_notes', 'lancamentos_editar');

  const aplicarMassa = useMutation({
    mutationFn: (alvo: { ids: number[] } | { filtros: Record<string, unknown> }) => pedidoClassificacao(alvo, camposMassa(massa) as CamposClassificacao),
    onSuccess: ({ mensagem }) => {
      message.success(mensagem);
      setSelec([]);
      setMassa({});
      void cliente.invalidateQueries({ queryKey: ['contab'] });
    },
    onError: (e) => notificarErro(e, 'Não foi possível aplicar a alteração em massa'),
  });
  const confirmarMassa = () => {
    if (!Object.keys(camposMassa(massa)).length) {
      void message.warning('Escolha pelo menos um campo para alterar.');
      return;
    }
    Modal.confirm({
      title: 'Alterar em massa',
      content: selec.length ? `Aplicar às ${selec.length} linha(s) seleccionada(s)?` : 'Nenhuma linha seleccionada. Aplicar a TODAS as linhas filtradas na tabela (até 5 000)?',
      okText: 'Aplicar',
      cancelText: 'Cancelar',
      onOk: () => aplicarMassa.mutateAsync(selec.length ? { ids: selec } : { filtros }),
    });
  };

  const colunas: ColunaApi<LinhaLancamento>[] = [
    { title: 'N.º lanç.', dataIndex: 'numero_lan', render: (v: string) => <Typography.Text strong style={{ color: token.colorPrimary }}>{v}</Typography.Text> },
    { title: 'Cd. diário', dataIndex: 'diario_id', render: (v: number) => <Tag>{nomesDiario.get(v) ?? v}</Tag>, responsive: ['md'] },
    { title: 'Data fiscal', dataIndex: 'data_documento', render: formatarData, width: 105 },
    { title: 'N.º doc.', dataIndex: 'numero_documento', render: (v: string | null) => v ?? '—', responsive: ['md'] },
    { title: 'Ref.', dataIndex: 'referencia', render: (v: string | null) => v ?? '—', responsive: ['xl'] },
    { title: 'Cd. conta', dataIndex: 'codigo_conta' },
    {
      title: 'Terceiro',
      key: 'terceiro',
      ellipsis: true,
      width: 180,
      responsive: ['lg'],
      valorImpressao: (r) => (r.terceiro_id ? rotuloTerceiro(r.terceiro, r.terceiro_id) : '—'),
      render: (_, r) => (r.terceiro_id ? <Tooltip title={r.terceiro?.nif ? `NIF ${r.terceiro.nif}` : undefined}>{rotuloTerceiro(r.terceiro, r.terceiro_id)}</Tooltip> : '—'),
    },
    { title: 'Descrição', dataIndex: 'descricao', ellipsis: true, width: 280, valorImpressao: (r) => r.descricao ?? '—', render: (v: string | null) => <Tooltip title={v}>{v ?? '—'}</Tooltip> },
    { title: 'Dem.', dataIndex: 'nota_demonstracao_id', responsive: ['xl'], render: (v: number | null) => (v ? cDemo.get(v) ?? v : '—') },
    { title: 'Fluxo', dataIndex: 'nota_fluxo_caixa_id', responsive: ['xl'], render: (v: number | null) => (v ? cFluxo.get(v) ?? v : '—') },
    { title: 'UN', dataIndex: 'unidade_negocio_id', responsive: ['xl'], render: (v: number | null) => (v ? cUnidade.get(v) ?? v : '—') },
    { title: 'CC', dataIndex: 'centro_custo_id', responsive: ['xl'], render: (v: number | null) => (v ? cCentro.get(v) ?? v : '—') },
    {
      title: 'Moeda',
      key: 'moeda',
      responsive: ['xxl'],
      valorImpressao: (r) => (r.codigo_moeda ? `${r.codigo_moeda} ${r.valor_moeda ?? ''}` : ''),
      render: (_, r) =>
        r.codigo_moeda ? (
          <Tooltip title={r.taxa_cambio ? `Câmbio ${r.taxa_cambio}` : undefined}>{`${r.codigo_moeda} ${Number(r.valor_moeda ?? 0).toLocaleString('pt-PT', { minimumFractionDigits: 2 })}`}</Tooltip>
        ) : (
          '—'
        ),
    },
    { title: 'Valor (Kz)', align: 'right', dataIndex: 'valor', render: (v: string) => <ValorKz valor={v} /> },
    { title: 'D/C', dataIndex: 'tipo_dc', align: 'center', render: (v: 'D' | 'C') => <Tag color={v === 'D' ? 'blue' : 'red'}>{v}</Tag> },
    {
      title: 'Situação',
      responsive: ['lg'],
      render: (_, r) => (r.estorno_de_id ? <Tag color="purple">Estorno</Tag> : r.estornado_por_id ? <Tag color="red">Estornado</Tag> : r.tipo_origem ? <Tag>{r.tipo_origem}</Tag> : null),
    },
    {
      key: 'accoes',
      width: 76,
      render: (_, r) => (
        <Space size={0} onClick={(e) => e.stopPropagation()}>
          {podeMassa && <Button type="text" size="small" icon={<EditOutlined />} aria-label={`Editar classificação da linha ${r.id}`} title="Editar classificação" onClick={() => setEditar(r)} />}
          <Button type="text" size="small" icon={<EyeOutlined />} aria-label={`Ver o lançamento ${r.numero_lan}`} title="Ver lançamento" onClick={() => navegar(String(r.id))} />
        </Space>
      ),
    },
  ];

  const opcoesMassa = (lista: { id: number; codigo: string | null; descricao?: string | null; nome?: string }[] | undefined, remover: string) => [
    { value: REMOVER, label: <Typography.Text type="danger">{remover}</Typography.Text>, texto: remover },
    ...(lista ?? []).map((x) => ({ value: x.id, label: `${x.codigo ?? ''} — ${x.descricao ?? x.nome ?? ''}`, texto: `${x.codigo ?? ''} ${x.descricao ?? x.nome ?? ''}` })),
  ];
  const selectMassa = (campo: string, placeholder: string, rotulo: string, lista: Parameters<typeof opcoesMassa>[0], remover: string) => (
    <Select
      size="small"
      allowClear
      placeholder={placeholder}
      aria-label={`${rotulo} (vazio = manter)`}
      style={{ width: 120 }}
      popupMatchSelectWidth={false}
      showSearch
      optionFilterProp="texto"
      value={massa[campo]}
      onChange={(v?: number) => setMassa((m) => ({ ...m, [campo]: v }))}
      options={opcoesMassa(lista, remover)}
    />
  );

  return (
    <>
      <CabecalhoPagina
        titulo="Lançamentos Contabilísticos"
        subtitulo={!pode('lancamentos_post') ? <Tag>Modo somente leitura</Tag> : undefined}
        accoes={
          <>
            {pode('lancamentos_post') && (
              <Button icon={<RobotOutlined />} onClick={() => setAssistente(true)}>
                Assistente IA
              </Button>
            )}
            {pode('lancamentos_import') && (
              <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>
                Importar lançamentos
              </Button>
            )}
            {pode('lancamentos_saldos') && (
              <Button icon={<HistoryOutlined />} onClick={() => setSaldos(true)}>
                Saldos históricos
              </Button>
            )}
            {pode('lancamentos_post') && (
              <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>
                Novo lançamento
              </Button>
            )}
          </>
        }
      />
      <PainelFiltros
        aria="Filtros dos lançamentos"
        rodape={
          <>
            <Flex wrap gap={8} align="center" style={{ flex: '1 1 auto', minWidth: 0 }}>
              <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                Mostrar linhas sem:
              </Typography.Text>
              {SEM_ROTULOS.map((s) => (
                <Tooltip key={s.chave} title={s.titulo}>
                  <Tag.CheckableTag
                    checked={sem.includes(s.chave)}
                    onChange={(on) => setSem((v) => (on ? [...v, s.chave] : v.filter((x) => x !== s.chave)))}
                    style={{ border: `1px solid ${token.colorBorder}`, padding: '1px 8px' }}
                  >
                    {s.rotulo}
                  </Tag.CheckableTag>
                </Tooltip>
              ))}
              <Button
                size="small"
                danger
                type="text"
                icon={<CloseOutlined />}
                onClick={() => {
                  setSem([]);
                  setRascunho({});
                  setAplicados({});
                  setSelec([]);
                }}
              >
                Limpar filtros
              </Button>
              {podeMassa && (
                <Flex wrap gap={6} align="center" role="group" aria-label="Alterar em massa">
                  <Typography.Text strong style={{ fontSize: 12 }} title="Aplica às linhas seleccionadas ou, sem selecção, a todas as filtradas">
                    Alterar em massa ({selec.length || 'filtradas'}):
                  </Typography.Text>
                  {selectMassa('nota_demonstracao_id', 'Nota Dem.', 'Nota às demonstrações', demo.data, '[Remover nota]')}
                  {selectMassa('nota_fluxo_caixa_id', 'Nota Fluxo', 'Nota de fluxo de caixa', fluxo.data, '[Remover nota]')}
                  {selectMassa('unidade_negocio_id', 'UN', 'Unidade de negócio', unidades.data, '[Remover UN]')}
                  {selectMassa('centro_custo_id', 'CC', 'Centro de custo', centros.data, '[Remover CC]')}
                  <Button size="small" icon={<CheckOutlined />} loading={aplicarMassa.isPending} onClick={confirmarMassa}>
                    Aplicar
                  </Button>
                </Flex>
              )}
            </Flex>
            <Button type="primary" icon={<SearchOutlined />} onClick={filtrar}>
              Filtrar
            </Button>
          </>
        }
      >
        <GrupoFiltros titulo="Período fiscal" icone={<CalendarOutlined />}>
          <CampoFiltro rotulo="Ano" htmlFor="filt-ano">
            <Select
              id="filt-ano"
              allowClear
              placeholder="—"
              style={{ width: '100%' }}
              value={rascunho.ano}
              options={[0, 1, 2, 3].map((d) => ({ value: ANO_ACTUAL - d, label: String(ANO_ACTUAL - d) }))}
              onChange={(ano?: number) => muda({ ano, mes: ano ? rascunho.mes : undefined, ...intervaloPeriodo(ano, ano ? rascunho.mes : undefined) })}
            />
          </CampoFiltro>
          <CampoFiltro rotulo="Período" htmlFor="filt-periodo">
            <Select
              id="filt-periodo"
              allowClear
              placeholder="—"
              style={{ width: '100%' }}
              value={rascunho.mes}
              disabled={!rascunho.ano}
              options={Array.from({ length: 12 }, (_, i) => ({ value: i + 1, label: String(i + 1) }))}
              onChange={(mes?: number) => muda({ mes, ...intervaloPeriodo(rascunho.ano, mes) })}
            />
          </CampoFiltro>
          <CampoFiltro rotulo="Data fiscal (início a fim)" largo>
            <DatePicker.RangePicker
              format="DD/MM/YYYY"
              style={{ width: '100%' }}
              aria-label="Data fiscal (início a fim)"
              value={[rascunho.inicio ? dayjs(rascunho.inicio) : null, rascunho.fim ? dayjs(rascunho.fim) : null]}
              onChange={(v) => muda({ inicio: dataApi(v?.[0] as Dayjs | null), fim: dataApi(v?.[1] as Dayjs | null), ano: undefined, mes: undefined })}
            />
          </CampoFiltro>
        </GrupoFiltros>
        <GrupoFiltros titulo="Conta e diário" icone={<ApartmentOutlined />}>
          <CampoFiltro rotulo="Conta(s)" htmlFor="filt-contas" largo>
            <Input id="filt-contas" placeholder="Ex.: 11, 21-25, 31*" allowClear value={rascunho.contas} onChange={(e) => muda({ contas: e.target.value })} onPressEnter={filtrar} />
          </CampoFiltro>
          <CampoFiltro rotulo="Diário" htmlFor="filt-diario" largo>
            <SeletorDiario id="filt-diario" placeholder="[Todos os diários]" allowClear style={{ width: '100%' }} value={rascunho.diario} onChange={(diario) => muda({ diario })} />
          </CampoFiltro>
        </GrupoFiltros>
        <GrupoFiltros titulo="Identificação" icone={<NumberOutlined />}>
          <CampoFiltro rotulo="N.º lançamento" htmlFor="filt-lan" largo>
            <Input id="filt-lan" placeholder="Ex.: SAL2026…" allowClear value={rascunho.numeroLan} onChange={(e) => muda({ numeroLan: e.target.value })} onPressEnter={filtrar} />
          </CampoFiltro>
          <CampoFiltro rotulo="Documento" htmlFor="filt-doc">
            <Input id="filt-doc" placeholder="N.º doc…" allowClear value={rascunho.numeroDoc} onChange={(e) => muda({ numeroDoc: e.target.value })} onPressEnter={filtrar} />
          </CampoFiltro>
          <CampoFiltro rotulo="Referência" htmlFor="filt-ref">
            <Input id="filt-ref" placeholder="Referência…" allowClear value={rascunho.referencia} onChange={(e) => muda({ referencia: e.target.value })} onPressEnter={filtrar} />
          </CampoFiltro>
        </GrupoFiltros>
        <GrupoFiltros titulo="Terceiro e descrição" icone={<UserOutlined />}>
          <CampoFiltro rotulo="Terceiro" htmlFor="filt-terceiro" largo>
            <SeletorTerceiro id="filt-terceiro" style={{ width: '100%' }} value={rascunho.terceiro} onChange={(terceiro) => muda({ terceiro })} />
          </CampoFiltro>
          <CampoFiltro rotulo="Descrição contém" htmlFor="filt-desc">
            <Input id="filt-desc" placeholder="Texto…" allowClear value={rascunho.pesquisa} onChange={(e) => muda({ pesquisa: e.target.value })} onPressEnter={filtrar} />
          </CampoFiltro>
          <CampoFiltro rotulo="Contas de ordem">
            <Checkbox checked={!!rascunho.classe9} onChange={(e) => muda({ classe9: e.target.checked })}>
              Incluir classe 9
            </Checkbox>
          </CampoFiltro>
        </GrupoFiltros>
      </PainelFiltros>
      <Card title="Listagem de movimentos (Diário)">
        <TabelaApi<LinhaLancamento>
          url="/contabilidade/lancamentos"
          chaveConsulta={['contab', 'lancamentos']}
          porPagina={50}
          filtros={filtros}
          columns={colunas}
          size="small"
          rowSelection={podeMassa ? { selectedRowKeys: selec.map(String), onChange: (k) => setSelec(k.map(Number)), preserveSelectedRowKeys: true } : undefined}
          impressao={{
            titulo: 'Lançamentos contabilísticos',
            periodo: aplicados.inicio && aplicados.fim ? `${formatarData(aplicados.inicio)} a ${formatarData(aplicados.fim)}` : undefined,
            filtros: [
              !!aplicados.diario && `Diário: ${nomesDiario.get(aplicados.diario) ?? aplicados.diario}`,
              aplicados.contas && `Contas: ${aplicados.contas}`,
              !!aplicados.terceiro && `Terceiro: n.º ${aplicados.terceiro}`,
              aplicados.numeroLan && `N.º lançamento: ${aplicados.numeroLan}`,
              aplicados.numeroDoc && `N.º documento: ${aplicados.numeroDoc}`,
              aplicados.referencia && `Referência: ${aplicados.referencia}`,
              aplicados.pesquisa && `Descrição: ${aplicados.pesquisa}`,
              aplicados.classe9 && 'Inclui classe 9',
              sem.length > 0 && `Sem: ${SEM_ROTULOS.filter((s) => sem.includes(s.chave)).map((s) => s.rotulo).join(', ')}`,
            ],
          }}
          onRow={(r) => ({
            // o clique na caixa de selecção não abre o lançamento
            onClick: (e) => {
              if (!(e.target as HTMLElement).closest('.ant-table-selection-column')) navegar(String(r.id));
            },
            style: { cursor: 'pointer' },
          })}
        />
      </Card>
      <ModalImportar
        aberto={importar}
        titulo="Importar lançamentos"
        url="/contabilidade/lancamentos/importar"
        comSimulacao
        campos={{ aceitar_avisos: aceitarAvisos }}
        ajuda="Use o template de importação de lançamentos (XLSX, XLS ou CSV). Valide primeiro com a simulação: nada é gravado se houver erros."
        extra={
          <Checkbox checked={aceitarAvisos} onChange={(e) => setAceitarAvisos(e.target.checked)}>
            Aceitar avisos (códigos inexistentes ficam em branco)
          </Checkbox>
        }
        aoFechar={() => setImportar(false)}
        aoConcluir={() => void cliente.invalidateQueries({ queryKey: ['contab'] })}
      />
      {saldos && <ModalSaldosHistoricos aberto={saldos} aoFechar={() => setSaldos(false)} />}
      <ModalClassificacao linha={editar} aoFechar={() => setEditar(null)} />
      {assistente && (
        <Suspense fallback={null}>
          <AssistenteIA aberto aoFechar={() => setAssistente(false)} />
        </Suspense>
      )}
    </>
  );
}
