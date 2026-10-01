import { Alert, Button, Card, Checkbox, Col, Divider, Flex, Form, Input, Modal, Popconfirm, Row, Select, Space, Switch, Tag, Tooltip, Typography } from 'antd';
import { DeleteOutlined, EditOutlined, KeyOutlined, PlusOutlined, StopOutlined, CheckCircleOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import { TabelaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { useAccao } from '@/componentes/Accoes';

interface EmpresaUtilizador {
  empresa_id: number;
  nome: string;
  empresa_ativa: boolean;
  colaborador_id: number | null;
  colaborador_nome: string | null;
}

interface UtilizadorLinha {
  id: number;
  nome_utilizador: string;
  nome_completo: string | null;
  email: string | null;
  papel: 'SUPER_ADMINISTRADOR' | 'ADMINISTRADOR' | 'UTILIZADOR';
  perfil: { id: number; nome: string; acesso_total: boolean } | null;
  acesso_todas_empresas: boolean;
  ativo: boolean;
  credencial_por_migrar: boolean;
  ultimo_acesso_em: string | null;
  palavra_passe_alterada_em: string | null;
  empresas: EmpresaUtilizador[];
}

const PAPEIS = { SUPER_ADMINISTRADOR: 'Super-administrador', ADMINISTRADOR: 'Administrador', UTILIZADOR: 'Utilizador' } as const;
const MINIMO = 8;
const CHAVE = ['sistema', 'utilizadores'];

/** Configurações › Utilizadores (config_utilizadores): lista, criação/edição com empresas e colaborador, estado, palavra-passe. */
export default function Utilizadores() {
  const { pode, empresas } = useSessao();
  const gerir = pode('config_util_gerir');
  const [filtros, setFiltros] = useState<{ pesquisa?: string; ativo?: number; perfil_utilizador_id?: number; empresa_id?: number }>({});
  const [edicao, setEdicao] = useState<UtilizadorLinha | 'novo' | null>(null);
  const [palavra, setPalavra] = useState<UtilizadorLinha | null>(null);
  const perfis = usePerfis();
  const accao = useAccao({ invalidar: [CHAVE] });

  return (
    <>
      <CabecalhoPagina
        titulo="Utilizadores"
        subtitulo="Contas de acesso, perfil de permissões e empresas de cada utilizador"
        accoes={gerir && <Button type="primary" icon={<PlusOutlined />} onClick={() => setEdicao('novo')}>Novo utilizador</Button>}
      />
      <Card>
        <Flex gap={8} wrap style={{ marginBottom: 12 }}>
          <Input.Search placeholder="Nome, utilizador ou email" allowClear style={{ width: 260 }} onSearch={(v) => setFiltros({ ...filtros, pesquisa: v || undefined })} />
          <Select allowClear placeholder="Estado" style={{ width: 140 }} value={filtros.ativo} onChange={(v?: number) => setFiltros({ ...filtros, ativo: v })} options={[{ value: 1, label: 'Activos' }, { value: 0, label: 'Inactivos' }]} />
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            placeholder="Perfil"
            style={{ width: 220 }}
            value={filtros.perfil_utilizador_id}
            onChange={(v?: number) => setFiltros({ ...filtros, perfil_utilizador_id: v })}
            options={(perfis.data ?? []).map((p) => ({ value: p.id, label: p.nome }))}
          />
          <Select
            allowClear
            showSearch
            optionFilterProp="label"
            placeholder="Empresa"
            style={{ width: 240 }}
            value={filtros.empresa_id}
            onChange={(v?: number) => setFiltros({ ...filtros, empresa_id: v })}
            options={empresas.map((e) => ({ value: e.id, label: e.nome }))}
          />
        </Flex>
        <TabelaApi<UtilizadorLinha>
          url="/sistema/utilizadores"
          chaveConsulta={[...CHAVE, 'lista']}
          filtros={filtros}
          columns={[
            {
              title: 'Utilizador',
              dataIndex: 'nome_utilizador',
              render: (v: string, u) => (
                <>
                  <strong>{v}</strong>
                  {u.credencial_por_migrar && (
                    <Tooltip title="A palavra-passe ainda está no formato do sistema antigo; é convertida no próximo início de sessão.">
                      <Tag color="gold" style={{ marginLeft: 6 }}>legado</Tag>
                    </Tooltip>
                  )}
                  <div style={{ fontSize: 12, color: 'rgba(0,0,0,0.55)' }}>{[u.nome_completo, u.email].filter(Boolean).join(' · ')}</div>
                </>
              ),
            },
            { title: 'Papel', dataIndex: 'papel', render: (p: UtilizadorLinha['papel']) => <Tag color={p === 'UTILIZADOR' ? undefined : 'purple'}>{PAPEIS[p] ?? p}</Tag> },
            { title: 'Perfil', dataIndex: ['perfil', 'nome'], render: (v: string | undefined, u) => (v ? <>{v}{u.perfil?.acesso_total && <Tag color="purple" style={{ marginLeft: 6 }}>total</Tag>}</> : '—') },
            {
              title: 'Empresas',
              dataIndex: 'empresas',
              render: (es: EmpresaUtilizador[], u) =>
                u.acesso_todas_empresas ? (
                  <Tag color="blue">Todas</Tag>
                ) : (
                  <Tooltip title={es.map((e) => `${e.nome}${e.colaborador_nome ? ` (${e.colaborador_nome})` : ''}`).join('\n')}>
                    <span>{es.length} empresa(s)</span>
                  </Tooltip>
                ),
            },
            { title: 'Estado', dataIndex: 'ativo', render: (a: boolean) => (a ? <Tag color="green">Activo</Tag> : <Tag>Inactivo</Tag>) },
            { title: 'Último acesso', dataIndex: 'ultimo_acesso_em', render: formatarDataHora },
            ...(gerir
              ? [
                  {
                    title: '',
                    key: 'accoes',
                    width: 170,
                    render: (_: unknown, u: UtilizadorLinha) => (
                      <Space>
                        <Button size="small" type="text" icon={<EditOutlined />} aria-label="Editar" title="Editar" onClick={() => setEdicao(u)} />
                        <Button size="small" type="text" icon={<KeyOutlined />} aria-label="Repor palavra-passe" title="Repor palavra-passe" onClick={() => setPalavra(u)} />
                        <Popconfirm
                          title={u.ativo ? `Desactivar ${u.nome_utilizador}? As sessões abertas terminam.` : `Activar ${u.nome_utilizador}?`}
                          okText={u.ativo ? 'Desactivar' : 'Activar'}
                          cancelText="Cancelar"
                          onConfirm={() => accao.mutateAsync({ metodo: 'put', url: `/sistema/utilizadores/${u.id}/estado`, dados: { ativo: !u.ativo } })}
                        >
                          <Button size="small" type="text" icon={u.ativo ? <StopOutlined /> : <CheckCircleOutlined />} aria-label={u.ativo ? 'Desactivar' : 'Activar'} title={u.ativo ? 'Desactivar' : 'Activar'} />
                        </Popconfirm>
                        <Popconfirm
                          title={`Eliminar o utilizador ${u.nome_utilizador}?`}
                          description="O histórico e a auditoria mantêm-se."
                          okText="Eliminar"
                          cancelText="Cancelar"
                          okButtonProps={{ danger: true }}
                          onConfirm={() => accao.mutateAsync({ metodo: 'delete', url: `/sistema/utilizadores/${u.id}` })}
                        >
                          <Button size="small" type="text" danger icon={<DeleteOutlined />} aria-label="Eliminar" title="Eliminar" />
                        </Popconfirm>
                      </Space>
                    ),
                  },
                ]
              : []),
          ]}
        />
      </Card>
      <ModalUtilizador utilizador={edicao} aoFechar={() => setEdicao(null)} />
      <ModalPalavraPasse utilizador={palavra} aoFechar={() => setPalavra(null)} />
    </>
  );
}

function usePerfis() {
  return useQuery({ queryKey: ['sistema', 'perfis', 'lista'], queryFn: () => obter<{ id: number; nome: string; acesso_total: boolean }[]>('/sistema/perfis'), staleTime: 300_000 });
}

interface FormUtilizador {
  nome_utilizador: string;
  nome_completo?: string;
  email?: string;
  palavra_passe?: string;
  perfil_utilizador_id: number;
  papel: UtilizadorLinha['papel'];
  acesso_todas_empresas: boolean;
  ativo: boolean;
  empresas: number[];
  colaborador_id?: number | null;
}

function ModalUtilizador({ utilizador, aoFechar }: { utilizador: UtilizadorLinha | 'novo' | null; aoFechar: () => void }) {
  const { empresas, empresa, utilizador: eu } = useSessao();
  const [form] = Form.useForm<FormUtilizador>();
  const perfis = usePerfis();
  const novo = utilizador === 'novo';
  const u = utilizador && utilizador !== 'novo' ? utilizador : null;
  const colaboradores = useQuery({
    queryKey: ['sistema', 'utilizadores', 'colaboradores', u?.id ?? 0],
    queryFn: () => obter<{ id: number; nome: string; ligado_a: string | null }[]>('/sistema/utilizadores/colaboradores', { utilizador_id: u?.id }),
    enabled: utilizador !== null,
  });
  const souSuper = eu?.papel === 'SUPER_ADMINISTRADOR';
  const escolhidas = Form.useWatch('empresas', form) ?? [];
  const todas = Form.useWatch('acesso_todas_empresas', form);
  const accao = useAccao({ invalidar: [CHAVE], aoSucesso: () => aoFechar() });

  useEffect(() => {
    if (!utilizador) return;
    form.resetFields();
    if (u)
      form.setFieldsValue({
        nome_utilizador: u.nome_utilizador,
        nome_completo: u.nome_completo ?? '',
        email: u.email ?? '',
        perfil_utilizador_id: u.perfil?.id,
        papel: u.papel,
        acesso_todas_empresas: u.acesso_todas_empresas,
        ativo: u.ativo,
        empresas: u.empresas.map((e) => e.empresa_id),
        colaborador_id: u.empresas.find((e) => e.empresa_id === empresa?.id)?.colaborador_id ?? null,
      });
    else form.setFieldsValue({ papel: 'UTILIZADOR', ativo: true, acesso_todas_empresas: false, empresas: empresa ? [empresa.id] : [] });
  }, [utilizador, u, form, empresa]);

  // empresas que o utilizador já tem mas que quem edita não vê: mantêm-se (o servidor só altera as que o actor gere)
  const opcoesEmpresas = [
    ...empresas.map((e) => ({ value: e.id, label: e.nome })),
    ...(u?.empresas ?? []).filter((x) => !empresas.some((e) => e.id === x.empresa_id)).map((x) => ({ value: x.empresa_id, label: x.nome })),
  ];

  const submeter = (v: FormUtilizador) => {
    const anteriores = new Map((u?.empresas ?? []).map((e) => [e.empresa_id, e.colaborador_id]));
    const lista = v.empresas.map((id) => ({ empresa_id: id, colaborador_id: id === empresa?.id ? (v.colaborador_id ?? null) : (anteriores.get(id) ?? null) }));
    const dados: Record<string, unknown> = {
      nome_utilizador: v.nome_utilizador.trim(),
      nome_completo: v.nome_completo?.trim() || null,
      email: v.email?.trim() || null,
      perfil_utilizador_id: v.perfil_utilizador_id,
      acesso_todas_empresas: v.acesso_todas_empresas,
      ativo: v.ativo,
      empresas: lista,
      ...(souSuper ? { papel: v.papel } : {}),
      ...(v.palavra_passe ? { palavra_passe: v.palavra_passe } : {}),
    };
    accao.mutate({ metodo: novo ? 'post' : 'put', url: novo ? '/sistema/utilizadores' : `/sistema/utilizadores/${u!.id}`, dados });
  };

  return (
    <Modal
      title={novo ? 'Novo utilizador' : `Editar — ${u?.nome_utilizador ?? ''}`}
      open={utilizador !== null}
      onCancel={aoFechar}
      okText="Gravar"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      width={720}
      destroyOnClose
    >
      <Form form={form} layout="vertical" onFinish={submeter}>
        <Row gutter={16}>
          <Col span={12}>
            <Form.Item name="nome_utilizador" label="Nome de utilizador" rules={[{ required: true, message: 'Indique o nome de utilizador.' }]}>
              <Input maxLength={100} autoComplete="off" />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="nome_completo" label="Nome completo">
              <Input maxLength={200} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="email" label="Email" rules={[{ type: 'email', message: 'Email inválido.' }]}>
              <Input maxLength={150} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item
              name="palavra_passe"
              label={novo ? 'Palavra-passe' : 'Nova palavra-passe (opcional)'}
              rules={[{ required: novo, message: 'Indique a palavra-passe.' }, { min: MINIMO, message: `Pelo menos ${MINIMO} caracteres.` }]}
            >
              <Input.Password maxLength={200} autoComplete="new-password" />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="perfil_utilizador_id" label="Perfil" rules={[{ required: true, message: 'Escolha o perfil.' }]}>
              <Select showSearch optionFilterProp="label" loading={perfis.isLoading} options={(perfis.data ?? []).map((p) => ({ value: p.id, label: p.acesso_total ? `${p.nome} (acesso total)` : p.nome }))} />
            </Form.Item>
          </Col>
          <Col span={12}>
            <Form.Item name="papel" label="Papel" tooltip={souSuper ? undefined : 'Só um super-administrador altera o papel.'}>
              <Select disabled={!souSuper} options={Object.entries(PAPEIS).map(([value, label]) => ({ value, label }))} />
            </Form.Item>
          </Col>
        </Row>
        <Space size={24}>
          <Form.Item name="ativo" label="Activo" valuePropName="checked">
            <Switch />
          </Form.Item>
          <Form.Item name="acesso_todas_empresas" valuePropName="checked" label=" ">
            <Checkbox>Acesso a todas as empresas</Checkbox>
          </Form.Item>
        </Space>
        <Divider style={{ margin: '4px 0 12px' }} />
        {todas ? (
          <Alert type="info" showIcon message="Tem acesso a todas as empresas (actuais e futuras)." style={{ marginBottom: 12 }} />
        ) : (
          <Form.Item name="empresas" label="Empresas" rules={[{ required: true, type: 'array', min: 1, message: 'Escolha pelo menos uma empresa.' }]}>
            <Select mode="multiple" optionFilterProp="label" options={opcoesEmpresas} />
          </Form.Item>
        )}
        {(todas || escolhidas.includes(empresa?.id ?? -1)) && (
          <Form.Item name="colaborador_id" label={`Colaborador ligado em ${empresa?.nome ?? 'esta empresa'}`} extra="Liga a conta ao colaborador (portal do colaborador, aprovações). Só para a empresa activa.">
            <Select
              allowClear
              showSearch
              optionFilterProp="label"
              loading={colaboradores.isLoading}
              options={(colaboradores.data ?? []).map((c) => ({ value: c.id, label: c.nome, disabled: !!c.ligado_a && c.ligado_a !== u?.nome_utilizador, title: c.ligado_a ? `Ligado a ${c.ligado_a}` : undefined }))}
            />
          </Form.Item>
        )}
        {u && (
          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
            Palavra-passe alterada em {formatarDataHora(u.palavra_passe_alterada_em)} · último acesso {formatarDataHora(u.ultimo_acesso_em)}
          </Typography.Text>
        )}
      </Form>
    </Modal>
  );
}

function ModalPalavraPasse({ utilizador, aoFechar }: { utilizador: UtilizadorLinha | null; aoFechar: () => void }) {
  const [form] = Form.useForm<{ palavra_passe: string; palavra_passe_confirmation: string }>();
  const accao = useAccao({ invalidar: [CHAVE], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (utilizador) form.resetFields();
  }, [utilizador, form]);
  return (
    <Modal
      title={`Repor palavra-passe — ${utilizador?.nome_utilizador ?? ''}`}
      open={!!utilizador}
      onCancel={aoFechar}
      okText="Repor"
      cancelText="Cancelar"
      confirmLoading={accao.isPending}
      onOk={() => form.submit()}
      destroyOnClose
    >
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="As sessões abertas do utilizador são terminadas." />
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({ url: `/sistema/utilizadores/${utilizador!.id}/palavra-passe`, dados: v })}>
        <Form.Item name="palavra_passe" label="Nova palavra-passe" rules={[{ required: true, message: 'Indique a palavra-passe.' }, { min: MINIMO, message: `Pelo menos ${MINIMO} caracteres.` }]}>
          <Input.Password autoComplete="new-password" maxLength={200} />
        </Form.Item>
        <Form.Item
          name="palavra_passe_confirmation"
          label="Confirmar"
          dependencies={['palavra_passe']}
          rules={[
            { required: true, message: 'Confirme a palavra-passe.' },
            ({ getFieldValue }) => ({ validator: (_, v) => (v === getFieldValue('palavra_passe') ? Promise.resolve() : Promise.reject(new Error('As palavras-passe não coincidem.'))) }),
          ]}
        >
          <Input.Password autoComplete="new-password" maxLength={200} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
