import { Alert, Button, Card, Form, Input, Modal, Popconfirm, Select, Space, Table, Tabs, Tag } from 'antd';
import { DeleteOutlined, EditOutlined, WarningOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useMemo, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { BotoesExportar } from '@/componentes/impressao';
import { BarraFiltros, scrollTabela, useEcraPequeno } from '@/componentes/responsivo';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import type { Banco, Colaborador, CoordenadaBancaria } from './api';
import { CadastroSimples } from './comum/CadastroSimples';
import { contem, EstadoTag, PesquisaLocal } from './comum/componentes';
import { useAccaoRh, useAvisarErro, useBancos, useColaboradores } from './comum/consultas';
import { pedidoTabela } from './comum/impressao';
import { formatarIban, ibanValido } from './comum/regras';

interface Linha {
  colaborador: Colaborador;
  coordenada: CoordenadaBancaria | null;
}

/** RH › Coordenadas bancárias (ecrã bancario): IBAN dos colaboradores e tabela de bancos. */
export default function Bancario() {
  const { pode } = useSessao();
  return (
    <>
      <CabecalhoPagina titulo="Configurações Bancárias" subtitulo="IBAN dos colaboradores (usado na ordem de pagamento e nas cartas) e bancos" />
      <Tabs
        items={[
          { key: 'iban', label: 'IBAN dos colaboradores', children: <IbanColaboradores /> },
          {
            key: 'bancos',
            label: 'Bancos',
            children: (
              <CadastroSimples<Banco>
                url="/rh/bancos"
                chave={['rh', 'bancos']}
                nomeItem="banco"
                tituloImpressao="Lista de bancos"
                podeGerir={pode('rh_bancario_gerir')}
                podeEliminar={pode('rh_banco_del')}
                pesquisa={(r) => `${r.nome} ${r.codigo ?? ''} ${r.nif ?? ''}`}
                colunas={[
                  { title: 'Nome', dataIndex: 'nome', render: (v: string) => <strong>{v}</strong> },
                  { title: 'Código', dataIndex: 'codigo', render: (v: string | null) => v ?? '—' },
                  { title: 'NIF', dataIndex: 'nif', render: (v: string | null) => v ?? '—' },
                  { title: 'Conta (plano)', dataIndex: 'codigo_conta', render: (v: string | null) => v ?? '—' },
                ]}
                campos={
                  <>
                    <Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }, { max: 255 }]}><Input autoFocus /></Form.Item>
                    <Form.Item name="codigo" label="Código"><Input maxLength={50} /></Form.Item>
                    <Form.Item name="nif" label="NIF"><Input maxLength={30} /></Form.Item>
                    <Form.Item name="endereco" label="Endereço"><Input.TextArea rows={2} maxLength={1000} /></Form.Item>
                    <Form.Item name="codigo_conta" label="Conta do plano" extra="Conta de movimento (ex.: 43…)."><Input maxLength={20} /></Form.Item>
                  </>
                }
              />
            ),
          },
        ]}
      />
    </>
  );
}

function IbanColaboradores() {
  const { pode } = useSessao();
  const colaboradores = useColaboradores();
  const bancos = useBancos();
  const coordenadas = useQuery({ queryKey: ['rh', 'coordenadas'], queryFn: () => obter<CoordenadaBancaria[]>('/rh/coordenadas-bancarias') });
  useAvisarErro(coordenadas.error);
  const [termo, setTermo] = useState('');
  const [filtro, setFiltro] = useState<'todos' | 'sem' | 'invalido'>('todos');
  const [edicao, setEdicao] = useState<Linha | null>(null);
  const [form] = Form.useForm<{ banco_id: number; iban: string }>();
  const accao = useAccaoRh(() => setEdicao(null));

  const linhas = useMemo<Linha[]>(() => {
    const porColab = new Map((coordenadas.data ?? []).map((c) => [c.colaborador_id, c]));
    return colaboradores.lista.map((c) => ({ colaborador: c, coordenada: porColab.get(c.id) ?? null }));
  }, [colaboradores.lista, coordenadas.data]);

  const visiveis = linhas.filter((l) => {
    if (!contem(`${l.colaborador.nome_completo} ${l.colaborador.nif} ${l.coordenada?.iban ?? ''}`, termo)) return false;
    if (filtro === 'sem') return !l.coordenada;
    if (filtro === 'invalido') return Boolean(l.coordenada && !ibanValido(l.coordenada.iban));
    return true;
  });
  const semIban = linhas.filter((l) => !l.coordenada && l.colaborador.estado === 'ACTIVO').length;

  const pequeno = useEcraPequeno();
  const colunas: ColunaApi<Linha>[] = [
    { title: 'Colaborador', render: (_, l) => <strong>{l.colaborador.nome_completo}</strong> },
    { title: 'NIF', render: (_, l) => l.colaborador.nif },
    { title: 'Estado', responsive: ['md'], render: (_, l) => <EstadoTag estado={l.colaborador.estado} /> },
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
    {
      title: '',
      key: 'accoes',
      align: 'right',
      render: (_, l) => (
        <Space size={4}>
          {pode('rh_bancario_gerir') && (
            <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar IBAN" onClick={() => {
              form.setFieldsValue({ banco_id: l.coordenada?.banco_id, iban: l.coordenada?.iban ?? '' });
              setEdicao(l);
            }} />
          )}
          {l.coordenada && pode('rh_banco_del') && (
            <Popconfirm title="Eliminar as coordenadas bancárias?" okText="Eliminar" okButtonProps={{ danger: true }} cancelText="Cancelar"
              onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/rh/colaboradores/${l.colaborador.id}/coordenada-bancaria` })}>
              <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar IBAN" />
            </Popconfirm>
          )}
        </Space>
      ),
    },
  ];

  return (
    <Card>
      {semIban > 0 && <Alert type="warning" showIcon style={{ marginBottom: 16 }} message={`${semIban} colaborador(es) activo(s) sem IBAN: não podem entrar em cartas de pagamento.`} />}
      <BarraFiltros
        accoes={
          <BotoesExportar
            desactivado={!visiveis.length}
            obterPedido={() => pedidoTabela({
              titulo: 'Coordenadas bancárias dos colaboradores',
              filtros: [filtro === 'sem' ? 'Só sem IBAN' : filtro === 'invalido' ? 'Só IBAN com formato inválido' : null, termo ? `Pesquisa: ${termo}` : null],
              colunas,
              linhas: visiveis,
            })}
          />
        }
      >
        <PesquisaLocal aoMudar={setTermo} placeholder="Nome, NIF ou IBAN" />
        <Select value={filtro} onChange={setFiltro} style={{ width: 200 }} options={[
          { value: 'todos', label: 'Todos' },
          { value: 'sem', label: 'Sem IBAN' },
          { value: 'invalido', label: 'IBAN com formato inválido' },
        ]} />
      </BarraFiltros>
      <Table<Linha> rowKey={(l) => l.colaborador.id} size={pequeno ? 'small' : 'middle'} loading={colaboradores.isFetching || coordenadas.isFetching} columns={colunas} dataSource={visiveis}
        scroll={scrollTabela()} pagination={{ pageSize: 25, showSizeChanger: true, showTotal: (t) => `${t} colaborador(es)` }} />
      <Modal title={`Coordenadas bancárias — ${edicao?.colaborador.nome_completo ?? ''}`} open={edicao !== null} onCancel={() => setEdicao(null)}
        okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
        <Form form={form} layout="vertical" onFinish={(v) => edicao && accao.mutate({ metodo: 'put', url: `/rh/colaboradores/${edicao.colaborador.id}/coordenada-bancaria`, dados: { ...v, iban: v.iban.replace(/\s+/g, '') } })}>
          <Form.Item name="banco_id" label="Banco" rules={[{ required: true, message: 'Escolha o banco.' }]}>
            <Select showSearch optionFilterProp="label" loading={bancos.isLoading} options={(bancos.data ?? []).map((b) => ({ value: b.id, label: b.nome }))} />
          </Form.Item>
          <Form.Item name="iban" label="IBAN" extra="AO + 23 dígitos (o NIB de 21 dígitos é convertido para AO06) ou IBAN estrangeiro."
            rules={[{ required: true, message: 'Indique o IBAN.' }, { validator: (_, v: string) => (!v || ibanValido(v) ? Promise.resolve() : Promise.reject(new Error('IBAN inválido (dígitos de controlo).'))) }]}>
            <Input maxLength={50} style={{ fontFamily: 'monospace' }} />
          </Form.Item>
        </Form>
      </Modal>
    </Card>
  );
}
