import { Alert, Button, Card, Form, Input, Select, Space, Tag } from 'antd';
import { PlusOutlined, WarningOutlined } from '@ant-design/icons';
import { BotoesCadastroLegado } from './comum/BotoesCadastroLegado';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { ModalFormulario, type CampoFormulario } from '@/componentes/formularios';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Banco, Colaborador, CoordenadaBancaria } from './api';
import { CadastroSimples, useDestinoAccoes } from './comum/CadastroSimples';
import { SeletorConta } from '@/modulos/contab/comum/Seletores';
import { contem, EstadoTag, PesquisaLocal, SeletorColaborador } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useBancos, useColaboradores } from './comum/consultas';
import { pedidoTabela } from './comum/impressao';
import { formatarIban, ibanValido } from './comum/regras';

import { TabelaComModos } from '@/componentes/vistas';
import { opcoesColaboradores } from './comum/linhasRh';

/** URL e corpo do PUT das coordenadas bancárias (grade e linhas): o IBAN vai sem espaços. */
export const pedidoIban = (v: Record<string, unknown>) => ({
  url: `/rh/colaboradores/${v.colaborador_id}/coordenada-bancaria`,
  dados: { banco_id: v.banco_id, iban: String(v.iban ?? '').replace(/\s+/g, '') },
});

/** Vista Linhas de «Adicionar IBAN» (um colaborador por linha). */
export function camposIban(colaboradores: CampoFormulario['opcoes'], bancos: CampoFormulario['opcoes']): CampoFormulario[] {
  return [
    { nome: 'colaborador_id', rotulo: 'Colaborador', tipo: 'select', obrigatorio: true, largura: 260, opcoes: colaboradores, aliases: ['Nome', 'NIF', 'Funcionário'] },
    { nome: 'banco_id', rotulo: 'Banco', tipo: 'select', obrigatorio: true, largura: 180, opcoes: bancos },
    { nome: 'iban', rotulo: 'IBAN', obrigatorio: true, largura: 260, maxLength: 50, aliases: ['NIB', 'Conta'],
      validar: (v) => (v && !ibanValido(String(v)) ? 'IBAN inválido (dígitos de controlo).' : null) },
  ];
}

export interface Linha {
  colaborador: Colaborador;
  coordenada: CoordenadaBancaria | null;
}

/** Ordem do antigo (DataTables sem `order` → 1.ª coluna «Nome» ascendente). */
const porNome = (a: Banco, b: Banco) => a.nome.localeCompare(b.nome, 'pt') || a.id - b.id;

/**
 * RH › Coordenadas bancárias (ecrã bancario): IBAN dos colaboradores e tabela de bancos.
 * Como no antigo (js/app_v2.js renderConfiguracoesBancarias): título sem subtítulo com Template · Importar Excel à
 * direita (aqui também «Copiar de outra empresa», como nas Funções/Infotipos); «Adicionar Banco» no cartão dos bancos.
 */
export default function Bancario() {
  const { pode } = useSessao();
  const [destino, elementoDestino] = useDestinoAccoes();
  return (
    <>
      <CabecalhoPagina titulo="Configurações Bancárias" accoes={elementoDestino} />
      {/* Como no antigo: as duas tabelas na mesma página, Bancos primeiro */}
      <CadastroSimples<Banco>
        titulo="1. Gestão de Bancos"
        url="/rh/bancos"
        chave={['rh', 'bancos']}
        nomeItem="banco"
        rotuloNovo="Adicionar Banco"
        tituloNovo="Adicionar Banco"
        accoesExtra={pode('rh_bancario_gerir') ? <BotoesCadastroLegado entidade="bancos" semCopiar /> : undefined}
        accoesCabecalho={destino}
        ordemInicial={porNome}
        camposEmLinha={
          <div className="rh-grelha-bancos">
            <Form.Item name="nome" label="Nome do Banco" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input placeholder="Ex: BFA" /></Form.Item>
            <Form.Item name="codigo" label="Código"><Input placeholder="Ex: 0006" maxLength={50} /></Form.Item>
            <Form.Item name="nif" label="NIF do Banco"><Input placeholder="NIF" maxLength={30} /></Form.Item>
            <Form.Item name="endereco" label="Endereço"><Input placeholder="Endereço" maxLength={1000} /></Form.Item>
            <Form.Item name="codigo_conta" label="Conta Contabilística (Plano de Contas)" className="rh-grelha-largo">
              <SeletorConta allowClear placeholder="-- Seleccione uma opção --" style={{ width: '100%' }} />
            </Form.Item>
          </div>
        }
        tituloImpressao="Lista de bancos"
        podeGerir={pode('rh_bancario_gerir')}
        podeEliminar={pode('rh_banco_del')}
        pesquisa={(r) => `${r.nome} ${r.codigo ?? ''} ${r.nif ?? ''}`}
        colunas={[
          { title: 'Nome', dataIndex: 'nome', sorter: porNome, defaultSortOrder: 'ascend', render: (v: string) => <strong>{v}</strong> },
          { title: 'Código', dataIndex: 'codigo', render: (v: string | null) => v ?? '—' },
          { title: 'NIF', dataIndex: 'nif', render: (v: string | null) => v ?? '—' },
          { title: 'Endereço', dataIndex: 'endereco', render: (v: string | null) => v ?? '—' },
          { title: 'Conta Contab.', dataIndex: 'codigo_conta', render: (v: string | null) => (v ? <code style={{ color: 'var(--ant-color-primary, #2563eb)' }}>{v}</code> : '-') },
        ]}
        campos={
          <>
            <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input autoFocus /></Form.Item>
            <Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item>
            <Form.Item name="nif" label="NIF"><Input maxLength={30} /></Form.Item>
            <Form.Item name="endereco" label="Endereço"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
            <Form.Item name="codigo_conta" label="Conta Contab." extra="Conta de movimento do plano (ex.: 43…)."><Input maxLength={20} /></Form.Item>
          </>
        }
      />
      <div style={{ height: 16 }} />
      <IbanColaboradores />
    </>
  );
}

/** Filtro da lista de IBANs: como no antigo (app_v2.js:10003) só os colaboradores com IBAN, por omissão. */
export type FiltroIban = 'com' | 'todos' | 'sem' | 'invalido';

/** Linhas visíveis da lista de IBANs (pesquisa + filtro). */
export function filtrarIbans(linhas: Linha[], termo: string, filtro: FiltroIban): Linha[] {
  return linhas.filter((l) => {
    if (!contem(`${l.colaborador.nome_completo} ${l.colaborador.nif} ${l.coordenada?.iban ?? ''}`, termo)) return false;
    if (filtro === 'com') return Boolean(l.coordenada);
    if (filtro === 'sem') return !l.coordenada;
    if (filtro === 'invalido') return Boolean(l.coordenada && !ibanValido(l.coordenada.iban));
    return true;
  });
}

const regrasIban = [
  { required: true, message: 'Indique o IBAN.' },
  { validator: (_: unknown, v: string) => (!v || ibanValido(v) ? Promise.resolve() : Promise.reject(new Error('IBAN inválido (dígitos de controlo).'))) },
];

const rotuloFiltro: Record<FiltroIban, string | null> = { com: null, todos: 'Todos (incluindo sem IBAN)', sem: 'Só sem IBAN', invalido: 'Só IBAN com formato inválido' };

function IbanColaboradores() {
  const { pode } = useSessao();
  const podeGerir = pode('rh_bancario_gerir');
  const colaboradores = useColaboradores();
  const bancos = useBancos();
  const coordenadas = useQuery({ queryKey: ['rh', 'coordenadas'], queryFn: () => obter<CoordenadaBancaria[]>('/rh/coordenadas-bancarias') });
  useAvisarErro(coordenadas.error);
  const [termo, setTermo] = useState('');
  const [filtro, setFiltro] = useState<FiltroIban>('com');
  // formulário embutido na página, como no antigo (app_v2.js:9963): adicionar ou, ao editar, actualizar
  const [emEdicao, setEmEdicao] = useState<Linha | null>(null);
  const [form] = Form.useForm<{ colaborador_id: number; banco_id: number; iban: string }>();
  const [linhasAberto, setLinhasAberto] = useState(false);
  const [formLinhas] = Form.useForm<{ colaborador_id: number; banco_id: number; iban: string }>();
  const limpar = () => { form.resetFields(); setEmEdicao(null); };
  const accao = useAccaoRh(() => { limpar(); setLinhasAberto(false); });
  const opcoesBancos = useMemo(() => (bancos.data ?? []).map((b) => ({ value: b.id, label: b.nome })), [bancos.data]);
  const camposLinhas = useMemo(
    () => camposIban(opcoesColaboradores(colaboradores.lista), (bancos.data ?? []).map((b) => ({ value: b.id, label: b.codigo ? `${b.nome} (${b.codigo})` : b.nome }))),
    [colaboradores.lista, bancos.data],
  );

  const linhas = useMemo<Linha[]>(() => {
    const porColab = new Map((coordenadas.data ?? []).map((c) => [c.colaborador_id, c]));
    return colaboradores.lista.map((c) => ({ colaborador: c, coordenada: porColab.get(c.id) ?? null }));
  }, [colaboradores.lista, coordenadas.data]);

  const visiveis = filtrarIbans(linhas, termo, filtro);
  const semIban = linhas.filter((l) => !l.coordenada && l.colaborador.estado === 'ACTIVO').length;

  const editar = (l: Linha) => {
    form.setFieldsValue({ colaborador_id: l.colaborador.id, banco_id: l.coordenada?.banco_id, iban: l.coordenada?.iban ?? '' });
    setEmEdicao(l);
  };

  const pequeno = useEcraPequeno();
  const colunas: ColunaApi<Linha>[] = [
    { title: 'Colaborador', render: (_, l) => <strong>{l.colaborador.nome_completo}</strong> },
    { title: 'NIF', render: (_, l) => <small>{l.colaborador.nif}</small>, valorImpressao: (l) => l.colaborador.nif },
    // colunas do antigo: Colaborador · NIF · Banco · IBAN · Ações; o estado do colaborador fica em «Colunas ▾»
    { title: 'Estado', responsive: ['md'], ocultaPorOmissao: true, valorImpressao: (l) => l.colaborador.estado, render: (_, l) => <EstadoTag estado={l.colaborador.estado} /> },
    { title: 'Banco', render: (_, l) => l.coordenada?.banco?.nome ?? (l.coordenada ? `#${l.coordenada.banco_id}` : '—') },
    {
      title: 'IBAN',
      valorImpressao: (l) => (l.coordenada ? `${formatarIban(l.coordenada.iban)}${ibanValido(l.coordenada.iban) ? '' : ' (formato inválido)'}` : 'Sem IBAN'),
      render: (_, l) =>
        l.coordenada ? (
          <Space wrap>
            <code>{formatarIban(l.coordenada.iban)}</code>
            {!ibanValido(l.coordenada.iban) && <Tag icon={<WarningOutlined />} color="warning">Formato inválido</Tag>}
          </Space>
        ) : (
          <Tag color="volcano">Sem IBAN</Tag>
        ),
    },
  ];

  return (
    <Card title="2. IBANs dos Colaboradores"
      extra={podeGerir ? <Button icon={<PlusOutlined />} onClick={() => { formLinhas.resetFields(); setLinhasAberto(true); }}>Vários IBAN</Button> : undefined}>
      {semIban > 0 && (
        <Alert type="warning" showIcon style={{ marginBottom: 16 }} message={`${semIban} colaborador(es) activo(s) sem IBAN: não podem entrar em cartas de pagamento.`}
          action={filtro !== 'sem' ? <Button size="small" onClick={() => setFiltro('sem')}>Ver</Button> : undefined} />
      )}
      {podeGerir && (
        <Form form={form} layout="vertical" className="rh-form-em-linha" name="iban-colaborador"
          onFinish={(v) => { const p = pedidoIban(v); accao.mutate({ metodo: 'put', url: p.url, dados: p.dados }); }}>
          <div className="rh-grelha-bancos">
            <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
              <SeletorColaborador style={{ width: '100%' }} disabled={emEdicao !== null} />
            </Form.Item>
            <Form.Item name="banco_id" label="Banco" rules={[{ required: true, message: 'Escolha o banco.' }]}>
              <Select showSearch optionFilterProp="label" loading={bancos.isLoading} options={opcoesBancos} placeholder="-- Seleccionar Banco --" />
            </Form.Item>
            <Form.Item name="iban" label="IBAN" extra="AO + 23 dígitos (o NIB de 21 dígitos é convertido para AO06) ou IBAN estrangeiro." rules={regrasIban}>
              <Input maxLength={50} placeholder="0000 0000 ..." style={{ fontFamily: 'monospace' }} />
            </Form.Item>
          </div>
          <div style={{ display: 'flex', gap: 8, marginBottom: 16, justifyContent: 'flex-end' }}>
            <Button type="primary" htmlType="submit" icon={emEdicao ? undefined : <PlusOutlined />} loading={accao.isPending}>
              {emEdicao ? 'Actualizar IBAN' : 'Adicionar IBAN'}
            </Button>
            {emEdicao && <Button onClick={limpar}>Cancelar</Button>}
          </div>
        </Form>
      )}
      <BarraFiltros
        accoes={
          <BotoesExportar
            desactivado={!visiveis.length}
            obterPedido={() => pedidoTabela({
              titulo: 'Coordenadas bancárias dos colaboradores',
              filtros: [rotuloFiltro[filtro], termo ? `Pesquisa: ${termo}` : null],
              colunas,
              linhas: visiveis,
            })}
          />
        }
      >
        <PesquisaLocal aoMudar={setTermo} placeholder="Nome, NIF ou IBAN" />
        <Select aria-label="Filtro de IBAN" value={filtro} onChange={setFiltro} style={{ width: 230 }} options={[
          { value: 'com', label: 'Com IBAN' },
          { value: 'todos', label: 'Todos (incluindo sem IBAN)' },
          { value: 'sem', label: 'Sem IBAN' },
          { value: 'invalido', label: 'IBAN com formato inválido' },
        ]} />
      </BarraFiltros>
      <TabelaComModos<Linha> rowKey={(l) => l.colaborador.id} size={pequeno ? 'small' : 'middle'} loading={colaboradores.isFetching || coordenadas.isFetching} columns={colunas} dataSource={visiveis}
        scroll={scrollTabela()}
        accoes={(l) => [
          podeGerir && { icone: 'edit', titulo: l.coordenada ? 'Editar IBAN' : 'Adicionar IBAN', onClick: () => editar(l) },
          l.coordenada && pode('rh_banco_del') && { icone: 'trash', titulo: 'Apagar IBAN', variante: 'perigo', confirmar: { titulo: 'Eliminar as coordenadas bancárias?', textoOk: 'Eliminar', perigo: true },
            onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/rh/colaboradores/${l.colaborador.id}/coordenada-bancaria` }) },
        ]} />
      {/* vários IBAN de uma vez (Grade | Linhas, colar do Excel) */}
      <ModalFormulario chave="rh.bancario.iban" titulo="Adicionar IBAN" aberto={linhasAberto} aoFechar={() => setLinhasAberto(false)} criacao form={formLinhas} campos={camposLinhas}
        nomeRegistos="IBAN" invalidar={[['rh', 'coordenadas'], ['rh']]} gravarLinha={(r) => { const p = pedidoIban(r); return enviar('put', p.url, p.dados); }}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => formLinhas.submit()}>
        <Form form={formLinhas} layout="vertical" onFinish={(v) => { const p = pedidoIban(v); accao.mutate({ metodo: 'put', url: p.url, dados: p.dados }); }}>
          <Form.Item name="colaborador_id" label="Colaborador" rules={[{ required: true, message: 'Escolha o colaborador.' }]}>
            <SeletorColaborador style={{ width: '100%' }} />
          </Form.Item>
          <Form.Item name="banco_id" label="Banco" rules={[{ required: true, message: 'Escolha o banco.' }]}>
            <Select showSearch optionFilterProp="label" loading={bancos.isLoading} options={opcoesBancos} />
          </Form.Item>
          <Form.Item name="iban" label="IBAN" extra="AO + 23 dígitos (o NIB de 21 dígitos é convertido para AO06) ou IBAN estrangeiro." rules={regrasIban}>
            <Input maxLength={50} style={{ fontFamily: 'monospace' }} />
          </Form.Item>
        </Form>
      </ModalFormulario>
    </Card>
  );
}
