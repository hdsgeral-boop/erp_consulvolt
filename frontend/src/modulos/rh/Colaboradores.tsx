import {
  Alert, Button, Card, Checkbox, Col, DatePicker, Descriptions, Divider, Form, Input, InputNumber, Modal, Row, Select, Skeleton, Space, Table, Tabs, Tag,
} from 'antd';
import { ArrowLeftOutlined, DeleteOutlined, DownloadOutlined, EditOutlined, ImportOutlined, MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import type { ColumnsType } from 'antd/es/table';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useState, type ReactNode } from 'react';
import { Route, Routes, useNavigate, useParams } from 'react-router-dom';
import { descarregar, obter } from '@/api/cliente';
import { SeletorAux, SeletorUnidade } from '@/modulos/contab/comum/Seletores';
import { AlocacaoUN, alocacaoDeApi, alocacaoParaApi, gravarAlocacao, obterAlocacao, useCriterios, validarAlocacao, type Alocacao } from '@/componentes/reparticao';
import { useTabelaAux, useUnidadesNegocio } from '@/modulos/contab/comum/dados';
import { notificarErro } from '@/utilitarios/erros';
import { ImportarExcel } from './comum/ImportarExcel';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { pares, tabelaHtml, useImpressao } from '@/componentes/impressao';
import { calcularIdade, fichaIndividualHtml } from './colaboradores/fichaIndividual';
import { IconeFa } from '@/componentes/fluxos';
import type { Estrutura } from '@/modulos/estrutura/comum/arvore';
import { BarraFiltros, COLUNAS_DESCRICOES, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import { TabelaApi, type ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData, formatarKz } from '@/utilitarios/formatacao';
import {
  ESTADOS_CIVIS, ESTADOS_COLABORADOR, NIVEIS_HABILITACAO, PARENTESCOS, type Colaborador, type ContratoTrabalho, type FichaColaborador, type ReferenciaNome,
} from './api';
import { EstadoTag } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useCargos, useColaboradores, useTiposOrganizacao } from './comum/consultas';
import { seccaoHtml } from './comum/impressao';
import { formatarIban, semFim, totalContrato } from './comum/regras';

/** RH › Colaboradores (ecrã colaboradores): listagem, ficha, criação/edição e eliminação. */
export default function Colaboradores() {
  return (
    <Routes>
      <Route index element={<ListaColaboradores />} />
      <Route path="novo" element={<FormularioColaborador />} />
      <Route path=":id" element={<FichaDoColaborador />} />
      <Route path=":id/editar" element={<FormularioColaborador />} />
    </Routes>
  );
}

/** Regime do colaborador como na lista do antigo: Avençado · Aposentado · Normal. */
function tipoColaborador(c: Pick<Colaborador, 'avencado' | 'reformado'>): string {
  return c.avencado ? 'Avençado' : c.reformado ? 'Aposentado' : 'Normal';
}

const CORES_TIPO: Record<string, [string, string]> = {
  'Avençado': ['#dbeafe', '#1d4ed8'],
  'Aposentado': ['#fef3c7', '#92400e'],
  'Normal': ['#f1f5f9', '#64748b'],
};

/** Pílula `.nif-badge` do antigo (fundo claro, texto da cor primária). */
function Pilula({ children, fundo = '#e0e7ff', cor = '#2563eb' }: { children: ReactNode; fundo?: string; cor?: string }) {
  return <span className="rh-pilula" style={{ background: fundo, color: cor, padding: '2px 8px', borderRadius: 10, fontSize: '0.75rem', fontWeight: 600, whiteSpace: 'nowrap' }}>{children}</span>;
}

function PilulaTipo({ c }: { c: Pick<Colaborador, 'avencado' | 'reformado'> }) {
  const t = tipoColaborador(c);
  const [fundo, cor] = CORES_TIPO[t];
  return <Pilula fundo={fundo} cor={cor}>{t}</Pilula>;
}

function ListaColaboradores() {
  const navegar = useNavigate();
  const { pode } = useSessao();
  const tipos = useTiposOrganizacao();
  const cargos = useCargos();
  const colaboradores = useColaboradores();
  const [estado, setEstado] = useState<string | undefined>('ACTIVO');
  const [tipo, setTipo] = useState<number>();
  const [pesquisa, setPesquisa] = useState('');
  const [importar, setImportar] = useState(false);
  const pequeno = useEcraPequeno();
  const uns = useUnidadesNegocio();
  const ccs = useTabelaAux('centros-custo');
  const codigoUn = (v: number | null) => (v ? uns.data?.find((x) => x.id === v)?.codigo ?? `#${v}` : '—');
  const codigoCc = (v: number | null) => (v ? ccs.data?.find((x) => x.id === v)?.codigo ?? `#${v}` : '—');

  const { imprimir } = useImpressao();
  const eliminar = useAccaoRh();
  const imprimirFicha = async (r: Colaborador) => {
    const [c, contratos] = await Promise.all([
      obter<FichaColaborador>(`/rh/colaboradores/${r.id}`),
      obter<ContratoTrabalho[]>('/rh/contratos', { colaborador_id: r.id }).catch(() => [] as ContratoTrabalho[]),
    ]);
    await imprimir({
      titulo: 'Ficha Individual do Colaborador',
      subtitulo: c.nome_completo,
      conteudo: fichaIndividualHtml(c, contratos, { funcao: cargos.nome(c.cargo_funcao_id), orgao: tipos.nome(c.tipo_organizacao_id), gestor: colaboradores.nome(c.colaborador_gestor_id) }),
    });
  };

  // colunas da lista do legado (renderColaboradores): nome, NIF, UN, CC, n.º INSS, função, tipo de órgão, estado, tipo, dias contratados
  const colunas: ColunaApi<Colaborador>[] = [
    { title: 'Nome completo', dataIndex: 'nome_completo', fixed: 'left', render: (v: string) => <strong>{v}</strong> },
    { title: 'NIF', dataIndex: 'nif' },
    { title: 'UN', dataIndex: 'unidade_negocio_id', responsive: ['lg'], render: (v: number | null) => codigoUn(v) },
    { title: 'CC', dataIndex: 'centro_custo_id', responsive: ['lg'], render: (v: number | null) => codigoCc(v) },
    { title: 'N.º INSS', dataIndex: 'numero_inss', responsive: ['md'], render: (v: string | null) => v ?? '—' },
    { title: 'Função', dataIndex: 'cargo_funcao_id', render: (v: number | null) => (v ? cargos.nome(v) : 'N/A') },
    { title: 'Tipo de órgão', dataIndex: 'tipo_organizacao_id', responsive: ['lg'], render: (v: number | null) => <Pilula>{v ? tipos.nome(v) : 'N/A'}</Pilula>, valorImpressao: (r) => (r.tipo_organizacao_id ? tipos.nome(r.tipo_organizacao_id) : 'N/A') },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <span style={{ fontSize: '0.75rem', color: e === 'ACTIVO' ? '#334155' : '#b91c1c' }}>{e ?? '—'}</span> }, // texto simples, como o legado
    { title: 'Tipo', align: 'center', responsive: ['md'], render: (_, r) => <PilulaTipo c={r} />, valorImpressao: (r) => tipoColaborador(r) },
    { title: 'Dias contratados', dataIndex: 'dias_uteis_mes', align: 'center', responsive: ['md'], render: (v: number | null) => v ?? 22 },
    { title: 'Admissão', dataIndex: 'data_admissao', responsive: ['xl'], ocultaPorOmissao: true, render: formatarData },
  ];

  const podeEditar = pode('colaboradores_detail');
  const podeEliminar = pode('rh_colab_del');

  return (
    <>
      <CabecalhoPagina
        titulo="Gestão de Colaboradores"
        subtitulo="Ficha dos colaboradores da empresa"
        accoes={
          <>
            {pode('colaboradores_import') && (
              <>
                <Button icon={<DownloadOutlined />} onClick={() => void descarregar('/rh/importacoes/modelos/colaboradores', undefined, 'Template_Colaboradores.xlsx').catch(notificarErro)}>Template</Button>
                <Button icon={<ImportOutlined />} onClick={() => setImportar(true)}>Importar Excel</Button>
              </>
            )}
            {pode('colaboradores_detail') && <Button type="primary" icon={<PlusOutlined />} onClick={() => navegar('novo')}>Novo Colaborador</Button>}
          </>
        }
      />
      <ImportarExcel aberto={importar} titulo="Importar colaboradores (Excel)" url="/rh/importacoes/colaboradores" modelo="colaboradores" comDecisao="ACTUALIZAR"
        ajuda="Folha «Colaboradores» do modelo: o NIF identifica o colaborador — se já existir, a ficha é actualizada (as células vazias não apagam dados); senão é criado. Banco e IBAN registam as coordenadas bancárias."
        aoFechar={() => setImportar(false)} />
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="Nome, NIF ou n.º INSS" allowClear style={{ width: 260 }} onSearch={setPesquisa} />
          <Select placeholder="Estado" allowClear style={{ width: 150 }} value={estado} onChange={setEstado} options={ESTADOS_COLABORADOR} />
          <Select placeholder="Tipo de organização" allowClear style={{ width: 220 }} value={tipo} onChange={setTipo} options={tipos.lista.map((t) => ({ value: t.id, label: t.nome }))} />
        </BarraFiltros>
        <TabelaApi<Colaborador>
          url="/rh/colaboradores"
          chaveConsulta={['rh', 'colaboradores', 'lista']}
          filtros={{ estado, tipo_organizacao_id: tipo, pesquisa }}
          columns={colunas}
          size={pequeno ? 'small' : 'middle'}
          scroll={scrollTabela()}
          impressao={{
            titulo: 'Lista de colaboradores',
            filtros: [estado && `Estado: ${ESTADOS_COLABORADOR.find((e) => e.value === estado)?.label ?? estado}`, tipo !== undefined && `Tipo de organização: ${tipos.nome(tipo)}`, pesquisa && `Pesquisa: ${pesquisa}`],
          }}
          accoes={(r) => [
            { icone: 'id-card', titulo: 'Imprimir ficha individual', onClick: () => imprimirFicha(r).catch((e) => notificarErro(e, 'Não foi possível gerar a ficha')) },
            podeEditar && { icone: 'edit', titulo: 'Editar', onClick: () => navegar(`${r.id}/editar`) },
            podeEliminar && {
              icone: 'trash', titulo: 'Excluir', variante: 'perigo',
              confirmar: { titulo: `Excluir ${r.nome_completo}?`, texto: 'Só é possível sem utilizações (contratos, processamentos…). Para quem saiu da empresa use o estado Inactivo.', textoOk: 'Excluir', perigo: true },
              onClick: () => eliminar.mutateAsync({ metodo: 'delete', url: `/rh/colaboradores/${r.id}` }),
            },
          ]}
          onRow={(r) => ({ onClick: () => navegar(String(r.id)), style: { cursor: 'pointer' } })}
        />
      </Card>
    </>
  );
}

function FichaDoColaborador() {
  const { id } = useParams();
  const navegar = useNavigate();
  const { pode } = useSessao();
  const cargos = useCargos();
  const tipos = useTiposOrganizacao();
  const colaboradores = useColaboradores();
  const ficha = useQuery({ queryKey: ['rh', 'colaborador', id], queryFn: () => obter<FichaColaborador>(`/rh/colaboradores/${id}`) });
  const contratos = useQuery({ queryKey: ['rh', 'contratos', { colaborador_id: id }], queryFn: () => obter<ContratoTrabalho[]>('/rh/contratos', { colaborador_id: id }) });
  useAvisarErro(ficha.error, 'Erro ao carregar o colaborador');
  const eliminar = useAccaoRh(() => navegar('..'));

  if (ficha.isLoading) return <Skeleton active />;
  const c = ficha.data;
  if (!c) return <Alert type="error" message="Colaborador não encontrado." />;

  const colContratos: ColumnsType<ContratoTrabalho> = [
    { title: 'Início', dataIndex: 'data_inicio', render: formatarData },
    { title: 'Fim', dataIndex: 'data_fim', render: (v: string | null) => (semFim(v) ? 'Sem fim' : formatarData(v)) },
    { title: 'Dias/mês', dataIndex: 'dias_contrato_mes' },
    { title: 'Horas/dia', dataIndex: 'horas_por_dia' },
    { title: 'Remuneração mensal', align: 'right', render: (_, r) => `${formatarKz(totalContrato(r))} ${r.codigo_moeda ?? 'AOA'}` },
    { title: 'Estado', dataIndex: 'estado', render: (e: string | null) => <EstadoTag estado={e} /> },
  ];

  const sexo = c.sexo === 'M' ? 'Masculino' : c.sexo === 'F' ? 'Feminino' : '—';
  const imprimirFicha = () => ({
    titulo: 'Ficha do colaborador',
    subtitulo: `${c.nome_completo} — NIF ${c.nif}`,
    conteudo: [
      seccaoHtml('Dados profissionais', pares([
        ['Estado', c.estado ?? '—'], ['Regime', c.avencado ? 'Avençado' : c.reformado ? 'Reformado' : 'Normal'], ['N.º INSS', c.numero_inss ?? '—'],
        ['Função', cargos.nome(c.cargo_funcao_id)], ['Tipo de organização', tipos.nome(c.tipo_organizacao_id)], ['Admissão', formatarData(c.data_admissao)],
        ['Dias úteis/mês', c.dias_uteis_mes ?? '—'], ['Gestor directo', colaboradores.nome(c.colaborador_gestor_id)], ['Habilitação máxima', c.habilitacao_maxima ?? '—'],
        ['Unidade orgânica', refNome(c.unidade_organica, c.unidade_organica_id)], ['UN / CC', `${refNome(c.unidade_negocio, c.unidade_negocio_id)} / ${refNome(c.centro_custo, c.centro_custo_id)}`],
      ])),
      seccaoHtml('Dados pessoais e contactos', pares([
        ['Sexo', sexo], ['Nascimento', formatarData(c.data_nascimento)], ['Estado civil', ESTADOS_CIVIS.find((e) => e.value === c.estado_civil)?.label ?? c.estado_civil ?? '—'],
        ['Nacionalidade', c.nacionalidade ?? '—'], ['Naturalidade', [c.naturalidade, c.provincia_naturalidade].filter(Boolean).join(', ') || '—'],
        ['Documento', `${c.documento_identificacao ?? '—'}${c.documento_validade ? ` (válido até ${formatarData(c.documento_validade)})` : ''}`],
        ['Morada', [c.endereco, c.bairro, c.municipio, c.provincia].filter(Boolean).join(', ') || '—'], ['Telefone', [c.telefone, c.telefone_alternativo].filter(Boolean).join(' / ') || '—'],
        ['E-mail', c.email ?? '—'], ['Emergência', c.emergencia_nome ? `${c.emergencia_nome} (${c.emergencia_parentesco ?? '—'}) ${c.emergencia_telefone ?? ''}` : '—'],
        ['IBAN', c.coordenada_bancaria ? `${c.coordenada_bancaria.banco?.nome ?? ''} ${formatarIban(c.coordenada_bancaria.iban)}`.trim() : 'Sem IBAN registado'],
      ])),
      seccaoHtml(`Agregado familiar (${c.dependentes.length})`, tabelaHtml({ linhas: c.dependentes, vazio: 'Sem dependentes.', colunas: [
        { titulo: 'Nome', valor: (d) => d.nome },
        { titulo: 'Parentesco', valor: (d) => PARENTESCOS.find((p) => p.value === d.parentesco)?.label ?? d.parentesco ?? '—' },
        { titulo: 'Nascimento', valor: (d) => d.data_nascimento, formato: 'data' },
        { titulo: 'Sexo', valor: (d) => d.sexo ?? '—' },
        { titulo: 'Dependente fiscal', valor: (d) => Boolean(d.dependente_fiscal) },
      ] })),
      seccaoHtml(`Habilitações (${c.habilitacoes.length})`, tabelaHtml({ linhas: c.habilitacoes, vazio: 'Sem habilitações registadas.', colunas: [
        { titulo: 'Nível', valor: (h) => h.nivel },
        { titulo: 'Curso', valor: (h) => h.curso ?? '—' },
        { titulo: 'Instituição', valor: (h) => h.instituicao ?? '—' },
        { titulo: 'Conclusão', valor: (h) => h.ano_conclusao ?? '—' },
        { titulo: 'Estado', valor: (h) => h.estado ?? '—' },
      ] })),
      seccaoHtml(`Contratos (${contratos.data?.length ?? 0})`, tabelaHtml({ linhas: contratos.data ?? [], vazio: 'Sem contratos.', colunas: [
        { titulo: 'Início', valor: (r) => r.data_inicio, formato: 'data' },
        { titulo: 'Fim', valor: (r) => (semFim(r.data_fim) ? 'Sem fim' : formatarData(r.data_fim)) },
        { titulo: 'Dias/mês', valor: (r) => r.dias_contrato_mes, alinhamento: 'direita' },
        { titulo: 'Horas/dia', valor: (r) => r.horas_por_dia, alinhamento: 'direita' },
        { titulo: 'Remuneração mensal', valor: (r) => `${formatarKz(totalContrato(r))} ${r.codigo_moeda ?? 'AOA'}`, alinhamento: 'direita' },
        { titulo: 'Estado', valor: (r) => r.estado ?? '—' },
      ] })),
    ].join(''),
  });

  return (
    <>
      <CabecalhoPagina
        impressao={imprimirFicha}
        impressaoDesactivada={contratos.isLoading}
        titulo={c.nome_completo}
        subtitulo={<Space wrap>NIF {c.nif}<EstadoTag estado={c.estado} />{c.avencado && <Tag color="purple">Avençado</Tag>}{c.reformado && <Tag>Reformado</Tag>}</Space>}
        accoes={
          <>
            <Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..')}>Voltar</Button>
            {pode('colaboradores_detail') && <Button type="primary" icon={<EditOutlined />} onClick={() => navegar('editar')}>Editar</Button>}
            {pode('rh_colab_del') && (
              <Button danger icon={<DeleteOutlined />} loading={eliminar.isPending} onClick={() => Modal.confirm({
                title: `Eliminar ${c.nome_completo}?`,
                content: 'Só é possível sem utilizações (contratos, processamentos…). Para quem saiu da empresa use o estado Inactivo.',
                okText: 'Eliminar', okButtonProps: { danger: true }, cancelText: 'Cancelar',
                onOk: () => eliminar.mutateAsync({ metodo: 'delete', url: `/rh/colaboradores/${c.id}` }),
              })}>Eliminar</Button>
            )}
          </>
        }
      />
      <Card>
        <Tabs
          items={[
            {
              key: 'geral',
              label: 'Dados profissionais',
              children: (
                <Descriptions column={COLUNAS_DESCRICOES} size="small">
                  <Descriptions.Item label="N.º INSS">{c.numero_inss ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Função">{cargos.nome(c.cargo_funcao_id)}</Descriptions.Item>
                  <Descriptions.Item label="Tipo de organização">{tipos.nome(c.tipo_organizacao_id)}</Descriptions.Item>
                  <Descriptions.Item label="Admissão">{formatarData(c.data_admissao)}</Descriptions.Item>
                  <Descriptions.Item label="Dias úteis/mês">{c.dias_uteis_mes ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Gestor directo">{colaboradores.nome(c.colaborador_gestor_id)}</Descriptions.Item>
                  <Descriptions.Item label="Habilitação máxima">{c.habilitacao_maxima ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Unidade orgânica">{refNome(c.unidade_organica, c.unidade_organica_id)}</Descriptions.Item>
                  <Descriptions.Item label="UN / CC">{refNome(c.unidade_negocio, c.unidade_negocio_id)} / {refNome(c.centro_custo, c.centro_custo_id)}</Descriptions.Item>
                </Descriptions>
              ),
            },
            {
              key: 'pessoal',
              label: 'Dados pessoais e contactos',
              children: (
                <Descriptions column={COLUNAS_DESCRICOES} size="small">
                  <Descriptions.Item label="Sexo">{c.sexo === 'M' ? 'Masculino' : c.sexo === 'F' ? 'Feminino' : '—'}</Descriptions.Item>
                  <Descriptions.Item label="Nascimento">{formatarData(c.data_nascimento)}</Descriptions.Item>
                  <Descriptions.Item label="Estado civil">{ESTADOS_CIVIS.find((e) => e.value === c.estado_civil)?.label ?? c.estado_civil ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Nacionalidade">{c.nacionalidade ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Naturalidade">{[c.naturalidade, c.provincia_naturalidade].filter(Boolean).join(', ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Documento">{c.documento_identificacao ?? '—'}{c.documento_validade ? ` (válido até ${formatarData(c.documento_validade)})` : ''}</Descriptions.Item>
                  <Descriptions.Item label="Morada" span="filled">{[c.endereco, c.bairro, c.municipio, c.provincia].filter(Boolean).join(', ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="Telefone">{[c.telefone, c.telefone_alternativo].filter(Boolean).join(' / ') || '—'}</Descriptions.Item>
                  <Descriptions.Item label="E-mail">{c.email ?? '—'}</Descriptions.Item>
                  <Descriptions.Item label="Emergência">{c.emergencia_nome ? `${c.emergencia_nome} (${c.emergencia_parentesco ?? '—'}) ${c.emergencia_telefone ?? ''}` : '—'}</Descriptions.Item>
                </Descriptions>
              ),
            },
            {
              key: 'agregado',
              label: `Agregado (${c.dependentes.length})`,
              children: (
                <Table rowKey={(d) => String(d.id ?? d.nome)} size="small" scroll={scrollTabela()} pagination={false} dataSource={c.dependentes} columns={[
                  { title: 'Nome', dataIndex: 'nome' },
                  { title: 'Parentesco', dataIndex: 'parentesco', render: (v: string | null) => PARENTESCOS.find((p) => p.value === v)?.label ?? v ?? '—' },
                  { title: 'Nascimento', dataIndex: 'data_nascimento', render: formatarData },
                  { title: 'Sexo', dataIndex: 'sexo', render: (v: string | null) => v ?? '—' },
                  { title: 'Dependente fiscal', dataIndex: 'dependente_fiscal', render: (v: boolean | null) => (v ? 'Sim' : 'Não') },
                ]} />
              ),
            },
            {
              key: 'habilitacoes',
              label: `Habilitações (${c.habilitacoes.length})`,
              children: (
                <Table rowKey={(h) => String(h.id ?? `${h.nivel}${h.curso}`)} size="small" scroll={scrollTabela()} pagination={false} dataSource={c.habilitacoes} columns={[
                  { title: 'Nível', dataIndex: 'nivel' },
                  { title: 'Curso', dataIndex: 'curso', render: (v: string | null) => v ?? '—' },
                  { title: 'Instituição', dataIndex: 'instituicao', render: (v: string | null) => v ?? '—' },
                  { title: 'Conclusão', dataIndex: 'ano_conclusao', render: (v: string | null) => v ?? '—' },
                  { title: 'Estado', dataIndex: 'estado', render: (v: string | null) => v ?? '—' },
                ]} />
              ),
            },
            {
              key: 'contratos',
              label: `Contratos (${contratos.data?.length ?? 0})`,
              children: <Table rowKey="id" size="small" scroll={scrollTabela()} pagination={false} loading={contratos.isFetching} dataSource={contratos.data ?? []} columns={colContratos} />,
            },
            {
              key: 'banco',
              label: 'Coordenadas bancárias',
              children: c.coordenada_bancaria ? (
                <Descriptions size="small" column={1}>
                  <Descriptions.Item label="Banco">{c.coordenada_bancaria.banco?.nome ?? `#${c.coordenada_bancaria.banco_id}`}</Descriptions.Item>
                  <Descriptions.Item label="IBAN"><code>{formatarIban(c.coordenada_bancaria.iban)}</code></Descriptions.Item>
                </Descriptions>
              ) : (
                <Alert type="warning" showIcon message="Sem IBAN registado (gere-se no ecrã Coordenadas bancárias)." />
              ),
            },
          ]}
        />
      </Card>
    </>
  );
}

type LinhaLista = Record<string, unknown>;
type ValoresFormulario = Record<string, unknown> & { dependentes?: LinhaLista[]; habilitacoes?: LinhaLista[] };

const CAMPOS_DATA = ['data_nascimento', 'data_admissao', 'documento_validade'] as const;

/** Criação e edição (PUT substitui o agregado e as habilitações, como no legado). */
function FormularioColaborador() {
  const { id } = useParams();
  const navegar = useNavigate();
  const [form] = Form.useForm();
  const tipos = useTiposOrganizacao();
  const cargos = useCargos();
  const colaboradores = useColaboradores();
  const ficha = useQuery({ queryKey: ['rh', 'colaborador', id], queryFn: () => obter<FichaColaborador>(`/rh/colaboradores/${id}`), enabled: Boolean(id) });
  // Fase C (R2/R3, app_v2.js:9598-9679): repartição do custo do colaborador por várias unidades de negócio. Grava-se à parte
  // (PUT /reparticao/alocacoes/colaborador/{id}) depois da ficha; `undefined` = não mexida.
  const criteriosRep = useCriterios();
  const [alocacao, setAlocacao] = useState<Alocacao | null | undefined>(undefined);
  const alocGravada = useQuery({ queryKey: ['reparticao', 'alocacao', 'colaborador', id], queryFn: () => obterAlocacao('colaborador', Number(id)), enabled: Boolean(id), retry: false });
  const valorAlocacao = alocacao !== undefined ? alocacao : alocacaoDeApi(alocGravada.data?.unidades_alocadas);
  const gravar = useAccaoRh<FichaColaborador>(async (d) => {
    if (alocacao !== undefined) {
      try {
        await gravarAlocacao('colaborador', d.id, alocacaoParaApi(alocacao));
      } catch (e) {
        notificarErro(e, 'A ficha foi gravada, mas não a repartição por unidades de negócio');
      }
    }
    navegar(id ? '..' : `../${d.id}`, { relative: 'path' });
  });

  useEffect(() => {
    const d = ficha.data;
    if (!id || !d) return;
    const valores = {
      ...d,
      ...Object.fromEntries(CAMPOS_DATA.map((k) => [k, d[k] ? dayjs(d[k]) : null])),
      dependentes: d.dependentes.map((x) => ({ ...x, data_nascimento: x.data_nascimento ? dayjs(x.data_nascimento) : null }) as LinhaLista),
    };
    form.setFieldsValue(valores);
  }, [id, ficha.data, form]);

  const estrutura = useQuery({ queryKey: ['rh', 'estrutura'], queryFn: () => obter<Estrutura>('/rh/estrutura'), staleTime: 5 * 60_000, retry: false });
  const unidades = estrutura.data?.unidades ?? [];
  const unidadeEscolhida = Form.useWatch('unidade_organica_id', form) as number | null | undefined;
  const nascimento = Form.useWatch('data_nascimento', form) as Dayjs | null | undefined;
  const nDependentes = (Form.useWatch('dependentes', form) as unknown[] | undefined)?.length ?? 0;
  const nHabilitacoes = (Form.useWatch('habilitacoes', form) as unknown[] | undefined)?.length ?? 0;
  const idade = nascimento ? calcularIdade(nascimento.format('YYYY-MM-DD')) : null;

  if (id && ficha.isLoading) return <Skeleton active />;

  const enviarFormulario = (v: ValoresFormulario) => {
    const erroAlocacao = alocacao ? validarAlocacao(alocacao, criteriosRep.data?.find((c) => c.id === alocacao.criterio_id)?.criterio) : null;
    if (erroAlocacao) {
      notificarErro(new Error(erroAlocacao), 'Repartição por unidades de negócio');
      return;
    }
    const dados = {
      ...v,
      ...Object.fromEntries(CAMPOS_DATA.map((k) => [k, dataApi((v[k] as Dayjs | null | undefined) ?? null) ?? null])),
      dependentes: (v.dependentes ?? []).map((x) => ({ ...x, data_nascimento: dataApi((x.data_nascimento as Dayjs | null | undefined) ?? null) ?? null })),
      habilitacoes: (v.habilitacoes ?? []).map((h) => ({ ...h, ano_conclusao: h.ano_conclusao ? String(h.ano_conclusao) : null })),
    };
    delete (dados as Record<string, unknown>).coordenada_bancaria;
    gravar.mutate(id ? { metodo: 'put', url: `/rh/colaboradores/${id}`, dados } : { metodo: 'post', url: '/rh/colaboradores', dados });
  };

  // Separadores internos da ficha do antigo (js/modules/rh/ficha_colaborador.js htmlSeccoes). forceRender: todos os
  // campos ficam registados no formulário, mesmo nos separadores que não foram abertos (senão o PUT perdia-os).
  const separadores = [
    {
      key: 'pessoais',
      forceRender: true,
      label: <span><IconeFa nome="id-card" /> Dados pessoais</span>,
      children: (
        <Row gutter={16}>
          <Col xs={24} md={8}><Form.Item name="sexo" label="Sexo"><Select allowClear placeholder="Seleccionar..." options={[{ value: 'M', label: 'Masculino' }, { value: 'F', label: 'Feminino' }]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="data_nascimento" label="Data de nascimento" extra={idade !== null ? `${idade} anos` : undefined}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabledDate={(d) => d.isAfter(dayjs())} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="estado_civil" label="Estado civil"><Select allowClear options={ESTADOS_CIVIS} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="nacionalidade" label="Nacionalidade"><Input maxLength={20} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="naturalidade" label="Naturalidade (local de nascimento)"><Input maxLength={20} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="provincia_naturalidade" label="Província de naturalidade"><Input maxLength={20} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="documento_identificacao" label="Nº do BI / Passaporte"><Input maxLength={30} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="documento_validade" label="Validade do documento"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="data_admissao" label="Data de admissão"><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
        </Row>
      ),
    },
    {
      key: 'contactos',
      forceRender: true,
      label: <span><IconeFa nome="map-marker-alt" /> Endereço e contactos</span>,
      children: (
        <Row gutter={16}>
          <Col xs={24}><Form.Item name="endereco" label="Endereço (rua, nº, casa)"><Input.TextArea rows={2} maxLength={1000} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="bairro" label="Bairro"><Input maxLength={150} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="municipio" label="Município"><Input maxLength={150} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="provincia" label="Província"><Input maxLength={150} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="email" label="E-mail" rules={[{ type: 'email', message: 'E-mail inválido.' }]}><Input maxLength={255} autoComplete="off" /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="telefone_alternativo" label="Telefone alternativo"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24}><Divider orientation="left" plain style={{ margin: '4px 0 12px' }}>Contacto de emergência</Divider></Col>
          <Col xs={24} md={8}><Form.Item name="emergencia_nome" label="Nome"><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="emergencia_telefone" label="Telefone"><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="emergencia_parentesco" label="Parentesco"><Input maxLength={100} /></Form.Item></Col>
        </Row>
      ),
    },
    {
      key: 'agregado',
      forceRender: true,
      label: <span><IconeFa nome="users" /> Agregado familiar{nDependentes ? ` (${nDependentes})` : ''}</span>,
      children: (
        <Form.List name="dependentes">
          {(campos, { add, remove }) => (
            <>
              {campos.map(({ key, name }) => (
                <Row gutter={8} key={key} align="middle">
                  <Col xs={24} md={7}><Form.Item name={[name, 'nome']} rules={[{ required: true, message: 'Nome.' }]}><Input placeholder="Nome" /></Form.Item></Col>
                  <Col xs={12} md={4}><Form.Item name={[name, 'parentesco']}><Select allowClear placeholder="Parentesco" options={PARENTESCOS} /></Form.Item></Col>
                  <Col xs={12} md={4}><Form.Item name={[name, 'data_nascimento']}><DatePicker format="DD/MM/YYYY" placeholder="Data de nascimento" style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={12} md={3}><Form.Item name={[name, 'sexo']}><Select allowClear placeholder="Sexo" options={[{ value: 'M', label: 'M' }, { value: 'F', label: 'F' }]} /></Form.Item></Col>
                  <Col xs={10} md={5}><Form.Item name={[name, 'dependente_fiscal']} valuePropName="checked"><Checkbox>Dependente fiscal</Checkbox></Form.Item></Col>
                  <Col xs={2} md={1}><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Remover" /></Form.Item></Col>
                </Row>
              ))}
              <Space wrap>
                <Button size="small" icon={<PlusOutlined />} onClick={() => add({ dependente_fiscal: false })}>Adicionar membro</Button>
                <small style={{ color: '#64748b' }}>As alterações são gravadas com o colaborador.</small>
              </Space>
            </>
          )}
        </Form.List>
      ),
    },
    {
      key: 'habilitacoes',
      forceRender: true,
      label: <span><IconeFa nome="graduation-cap" /> Habilitações{nHabilitacoes ? ` (${nHabilitacoes})` : ''}</span>,
      children: (
        <>
          <Row gutter={16}>
            <Col xs={24} md={8}><Form.Item name="habilitacao_maxima" label="Habilitação máxima"><Select allowClear placeholder="Calcular das habilitações concluídas" options={NIVEIS_HABILITACAO.map((n) => ({ value: n, label: n }))} /></Form.Item></Col>
          </Row>
          <Form.List name="habilitacoes">
            {(campos, { add, remove }) => (
              <>
                {campos.map(({ key, name }) => (
                  <Row gutter={8} key={key} align="middle">
                    <Col xs={24} md={5}><Form.Item name={[name, 'nivel']} rules={[{ required: true, message: 'Nível.' }]}><Select placeholder="Nível" options={NIVEIS_HABILITACAO.map((n) => ({ value: n, label: n }))} /></Form.Item></Col>
                    <Col xs={24} md={6}><Form.Item name={[name, 'curso']}><Input placeholder="Curso / área" maxLength={255} /></Form.Item></Col>
                    <Col xs={24} md={6}><Form.Item name={[name, 'instituicao']}><Input placeholder="Instituição" maxLength={255} /></Form.Item></Col>
                    <Col xs={12} md={3}><Form.Item name={[name, 'ano_conclusao']} rules={[{ pattern: /^\d{4}$/, message: 'AAAA' }]}><Input placeholder="Ano de conclusão" maxLength={4} /></Form.Item></Col>
                    <Col xs={10} md={3}><Form.Item name={[name, 'estado']}><Select allowClear placeholder="Estado" options={['Concluído', 'Em curso', 'Interrompido'].map((e) => ({ value: e, label: e }))} /></Form.Item></Col>
                    <Col xs={2} md={1}><Form.Item><MinusCircleOutlined onClick={() => remove(name)} aria-label="Remover" /></Form.Item></Col>
                  </Row>
                ))}
                <Space wrap>
                  <Button size="small" icon={<PlusOutlined />} onClick={() => add({ estado: 'Concluído' })}>Adicionar habilitação</Button>
                  <small style={{ color: '#64748b' }}>As alterações são gravadas com o colaborador.</small>
                </Space>
              </>
            )}
          </Form.List>
        </>
      ),
    },
    {
      key: 'estrutura',
      forceRender: true,
      label: <span><IconeFa nome="sitemap" /> Estrutura orgânica</span>,
      children: (
        <Row gutter={16}>
          <Col xs={24}>
            <Form.Item name="unidade_organica_id" label="Unidade orgânica">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— sem unidade —" loading={estrutura.isLoading}
                options={unidades.map((u) => ({ value: u.id, label: [u.codigo, u.nome].filter(Boolean).join(' — ') }))}
                onChange={() => form.setFieldValue('posto_trabalho_id', null)} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="posto_trabalho_id" label="Cargo" extra={unidadeEscolhida ? undefined : 'Escolha primeiro a unidade orgânica.'}>
              <Select allowClear placeholder="— sem cargo —" disabled={!unidadeEscolhida}
                options={(unidades.find((u) => u.id === unidadeEscolhida)?.postos ?? []).map((p) => ({ value: p.id, label: `${p.titulo || cargos.nome(p.cargo_funcao_id)}${p.chefia ? ' (chefia)' : ''} · ${p.ocupados}/${p.vagas ?? '∞'}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="colaborador_gestor_id" label="Chefia directa" extra="Vazio: o responsável da unidade.">
              <Select allowClear showSearch optionFilterProp="label" placeholder="— responsável da unidade —" options={colaboradores.lista.filter((x) => String(x.id) !== id).map((x) => ({ value: x.id, label: x.nome_completo }))} />
            </Form.Item>
          </Col>
          {estrutura.isError && <Col xs={24}><Alert type="info" showIcon message="Não foi possível ler a estrutura orgânica (permissões ou ainda sem estrutura definida)." /></Col>}
          {!estrutura.isError && !estrutura.isLoading && !unidades.length && <Col xs={24}><Alert type="info" showIcon message="Ainda não há estrutura orgânica definida (menu Estrutura Orgânica)." /></Col>}
        </Row>
      ),
    },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo={id ? `Editar Colaborador — ${ficha.data?.nome_completo ?? ''}` : 'Adicionar Novo Colaborador'}
        accoes={<Button icon={<ArrowLeftOutlined />} onClick={() => navegar('..', { relative: 'path' })}>Cancelar</Button>}
      />
      <Form form={form} layout="vertical" onFinish={enviarFormulario} initialValues={{ estado: 'ACTIVO', dias_uteis_mes: 22, reformado: false, avencado: false, nacionalidade: 'Angolana', dependentes: [], habilitacoes: [] }}>
        <Card>
          {/* Campos principais do formulário embutido do antigo (renderColaboradores), na mesma ordem */}
          <Row gutter={16}>
            <Col xs={24} md={12}><Form.Item name="nome_completo" label="Nome Completo" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="nif" label="NIF" rules={[{ required: true, message: 'Indique o NIF.' }, { max: 30 }]}><Input /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="numero_inss" label="Número INSS"><Input maxLength={50} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="cargo_funcao_id" label="Função"><Select allowClear showSearch optionFilterProp="label" options={cargos.lista.map((t) => ({ value: t.id, label: t.nome }))} /></Form.Item></Col>
            <Col xs={24}>
              <Form.Item name="tipo_organizacao_id" label="Tipo de Órgão" rules={[{ required: true, message: 'Escolha o tipo de órgão.' }]}>
                <Select options={tipos.lista.map((t) => ({ value: t.id, label: t.nome }))} loading={tipos.isLoading} />
              </Form.Item>
            </Col>
            <Col xs={24} md={12}><Form.Item name="unidade_negocio_id" label="Unidade de Negócio" extra="Imputação analítica dos salários."><SeletorUnidade style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={24} md={12}><Form.Item name="centro_custo_id" label="Centro de Custo"><SeletorAux tabela="centros-custo" placeholder="Nenhum" style={{ width: '100%' }} /></Form.Item></Col>
            {!!criteriosRep.data?.length && (
              <Col xs={24} style={{ marginBottom: 12 }}>
                <AlocacaoUN value={valorAlocacao} onChange={setAlocacao} titulo="Repartir o custo salarial por várias unidades de negócio" />
              </Col>
            )}
            <Col xs={24} md={12}><Form.Item name="estado" label="Estado"><Select options={ESTADOS_COLABORADOR} /></Form.Item></Col>
            <Col xs={24} md={12} style={{ display: 'flex', alignItems: 'center' }}>
              <Form.Item name="reformado" valuePropName="checked" style={{ marginBottom: 12 }}>
                <Checkbox onChange={(e) => e.target.checked && form.setFieldValue('avencado', false)}>Aposentado (Isento de INSS)</Checkbox>
              </Form.Item>
            </Col>
            <Col xs={24} md={12} style={{ display: 'flex', alignItems: 'center' }}>
              <Form.Item name="avencado" valuePropName="checked" style={{ marginBottom: 12 }}>
                <Checkbox onChange={(e) => e.target.checked && form.setFieldValue('reformado', false)}>Avençado (Sem INSS · IRT 6,5% fixo)</Checkbox>
              </Form.Item>
            </Col>
            <Col xs={24} md={12}><Form.Item name="dias_uteis_mes" label="Dias de Trab. Contratados"><InputNumber min={1} max={31} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
          <Tabs className="rh-ficha-separadores" items={separadores} />
          <Space style={{ marginTop: 16 }}>
            <Button type="primary" loading={gravar.isPending} onClick={() => form.submit()}>Salvar Colaborador</Button>
            <Button onClick={() => navegar('..', { relative: 'path' })}>Cancelar</Button>
          </Space>
        </Card>
      </Form>
    </>
  );
}

/** Unidade orgânica, unidade de negócio ou centro de custo por código e nome (ficha, ADR-064). */
function refNome(r: ReferenciaNome | null | undefined, id: number | null | undefined): string {
  if (r) return [r.codigo, r.nome].filter(Boolean).join(' — ') || `#${r.id}`;
  return id ? `#${id}` : '—';
}
