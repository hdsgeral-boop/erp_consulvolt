import { ArrowDownOutlined, ArrowUpOutlined, DeleteOutlined, EyeInvisibleOutlined, PlusOutlined, SaveOutlined, UndoOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Col, Empty, Form, Input, Modal, Row, Select, Skeleton, Space, Tag, Typography, message } from 'antd';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVE_COBERTURA, CHAVE_PROCESSOS, criarProcesso, gravarProcesso, listarProcessos, obterCobertura, ocultarProcesso, reporProcesso, type Processo } from './api';
import { ROTULO_ORIGEM } from './logica';

type ValoresEditor = { titulo: string; objetivo: string; intervenientes: string; etapas: Processo['etapas']; cruza: Processo['cruza'] };

const valoresDe = (p: Processo): ValoresEditor => ({ titulo: p.titulo, objetivo: p.objetivo, intervenientes: p.intervenientes.join('\n'), etapas: p.etapas, cruza: p.cruza });

function EditorProcesso({ processo, outros, podeEditar, aGravar, aoGravar, aoRepor, aoEsconder }: {
  processo: Processo; outros: Processo[]; podeEditar: boolean; aGravar: boolean;
  aoGravar: (chave: string, dados: Pick<Processo, 'titulo' | 'objetivo' | 'intervenientes' | 'etapas' | 'cruza'>) => void;
  aoRepor: (p: Processo) => void; aoEsconder: (p: Processo) => void;
}) {
  const [form] = Form.useForm<ValoresEditor>();
  const etapasForm = Form.useWatch('etapas', form) as Processo['etapas'] | undefined;
  const nomesEtapas = (etapasForm ?? processo.etapas).map((e) => e?.nome).filter((n): n is string => !!n);
  useEffect(() => { form.setFieldsValue(valoresDe(processo)); }, [form, processo]);
  const origem = ROTULO_ORIGEM[processo.origem];
  const proprio = processo.origem === 'PROPRIO';

  const gravar = () => {
    // antd 6: o onFinish só devolve subcampos de Form.List com Form.Item; lê-se a lista directamente do formulário
    const etapas = ((form.getFieldValue('etapas') as Processo['etapas'] | undefined) ?? []).map((e) => ({ nome: e?.nome ?? '', quem: e?.quem ?? '', descricao: e?.descricao ?? '', controlos: e?.controlos ?? '', resultado: e?.resultado ?? '' }));
    const cruza = ((form.getFieldValue('cruza') as Processo['cruza'] | undefined) ?? []).map((c) => ({ com: c?.com ?? '', onde: c?.onde ?? '', o_que: c?.o_que ?? '' })).filter((c) => c.com);
    aoGravar(processo.chave, {
      titulo: String(form.getFieldValue('titulo') ?? ''), objetivo: String(form.getFieldValue('objetivo') ?? ''),
      intervenientes: String(form.getFieldValue('intervenientes') ?? '').split(/\n+/), etapas, cruza,
    });
  };

  return (
    <Card
      title={<Space wrap><span>{processo.titulo || processo.chave}</span><Tag color={origem.cor}>{origem.rotulo}</Tag></Space>}
      extra={podeEditar && (
        <Space wrap>
          {processo.editado && <Button icon={<UndoOutlined />} onClick={() => aoRepor(processo)}>{proprio ? 'Apagar' : 'Repor'}</Button>}
          <Button icon={<EyeInvisibleOutlined />} onClick={() => aoEsconder(processo)}>Esconder</Button>
          <Button type="primary" icon={<SaveOutlined />} loading={aGravar} onClick={gravar}>Gravar</Button>
        </Space>
      )}
    >
      {processo.tem_painel && (
        <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Este processo tem painel de acompanhamento no Fluxo de Processos"
          description="O que escrever aqui muda o manual; o painel continua a medir os dados reais. Acrescentar ou retirar etapas faz o manual deixar de acompanhar o painel passo a passo." />
      )}
      <Form form={form} layout="vertical" disabled={!podeEditar} initialValues={valoresDe(processo)}>
        <Form.Item name="titulo" label="Título do processo" required><Input maxLength={200} /></Form.Item>
        <Form.Item name="objetivo" label="Objectivo" extra="Uma frase: para que existe este processo."><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
        <Form.Item name="intervenientes" label="Intervenientes" extra="Um papel por linha. Papéis, não pessoas — o manual não deve envelhecer a cada mudança."><Input.TextArea rows={3} /></Form.Item>

        <Typography.Title level={5}>Etapas</Typography.Title>
        <Form.List name="etapas">
          {(campos, { add, remove, move }) => (
            <>
              {campos.map((c, i) => (
                <div key={c.key} className="pm-etapa">
                  <div className="pm-etapa-cab">
                    <b>Etapa {i + 1}</b>
                    <Space size={4}>
                      <Button size="small" icon={<ArrowUpOutlined />} aria-label={`Subir a etapa ${i + 1}`} disabled={i === 0} onClick={() => move(i, i - 1)} />
                      <Button size="small" icon={<ArrowDownOutlined />} aria-label={`Descer a etapa ${i + 1}`} disabled={i === campos.length - 1} onClick={() => move(i, i + 1)} />
                      <Button size="small" danger icon={<DeleteOutlined />} aria-label={`Remover a etapa ${i + 1}`} onClick={() => remove(c.name)} />
                    </Space>
                  </div>
                  <Row gutter={8}>
                    <Col xs={24} md={12}><Form.Item name={[c.name, 'nome']} label="Nome da etapa" required><Input maxLength={200} /></Form.Item></Col>
                    <Col xs={24} md={12}><Form.Item name={[c.name, 'quem']} label="Quem executa (papel)"><Input maxLength={200} /></Form.Item></Col>
                  </Row>
                  <Form.Item name={[c.name, 'descricao']} label="O que se faz nesta etapa"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
                  <Row gutter={8}>
                    <Col xs={24} md={12}><Form.Item name={[c.name, 'controlos']} label="Controlos — o que tem de ser verdade"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
                    <Col xs={24} md={12}><Form.Item name={[c.name, 'resultado']} label="Resultado — o que fica feito"><Input.TextArea rows={2} maxLength={5000} /></Form.Item></Col>
                  </Row>
                </div>
              ))}
              <Button icon={<PlusOutlined />} onClick={() => add({ nome: '', quem: '', descricao: '', controlos: '', resultado: '' })}>Etapa</Button>
            </>
          )}
        </Form.List>

        <Typography.Title level={5} style={{ marginTop: 20 }}>Onde se cruza com outros processos</Typography.Title>
        <Typography.Paragraph type="secondary">O que este processo <b>entrega</b> a outro, e em que etapa. É daqui que sai a matriz geral da empresa.</Typography.Paragraph>
        <Form.List name="cruza">
          {(campos, { add, remove }) => (
            <>
              {campos.map((c, i) => (
                <div key={c.key} className="pm-etapa">
                  <Row gutter={8}>
                    <Col xs={24} md={12}>
                      <Form.Item name={[c.name, 'com']} label="Processo">
                        <Select showSearch optionFilterProp="label" placeholder="— processo —"
                          options={[...outros.filter((o) => o.chave !== processo.chave).map((o) => ({ value: o.chave, label: o.titulo })),
                            { value: 'estrutura_manual', label: 'Estrutura Orgânica (outro documento)' }, { value: 'estrategia_plano', label: 'Plano Estratégico (outro documento)' }]} />
                      </Form.Item>
                    </Col>
                    <Col xs={24} md={12}>
                      <Form.Item name={[c.name, 'onde']} label="Em que etapa deste">
                        <Select allowClear placeholder="— em que etapa deste —" options={nomesEtapas.map((n) => ({ value: n, label: n }))} />
                      </Form.Item>
                    </Col>
                  </Row>
                  <Row gutter={8} align="bottom">
                    <Col flex="auto"><Form.Item name={[c.name, 'o_que']} label="O que passa de um para o outro"><Input maxLength={5000} /></Form.Item></Col>
                    <Col><Form.Item><Button danger icon={<DeleteOutlined />} aria-label={`Remover o cruzamento ${i + 1}`} onClick={() => remove(c.name)} /></Form.Item></Col>
                  </Row>
                </div>
              ))}
              <Button icon={<PlusOutlined />} onClick={() => add({ com: undefined, onde: undefined, o_que: '' })}>Cruzamento</Button>
            </>
          )}
        </Form.List>
      </Form>
    </Card>
  );
}

/** Separador «Processos»: lista (fábrica, adaptados, próprios), cobertura, escondidos e o editor do processo escolhido. */
export function SeparadorProcessos({ podeEditar }: { podeEditar: boolean }) {
  const qc = useQueryClient();
  const lista = useQuery({ queryKey: CHAVE_PROCESSOS, queryFn: listarProcessos });
  const cobertura = useQuery({ queryKey: CHAVE_COBERTURA, queryFn: obterCobertura });
  const [chave, setChave] = useState<string | null>(null);
  const [novo, setNovo] = useState(false);
  const [confirmar, setConfirmar] = useState<{ tipo: 'repor' | 'esconder'; processo: Processo } | null>(null);
  const [formNovo] = Form.useForm<{ titulo: string }>();
  useEffect(() => { if (lista.error) notificarErro(lista.error, 'Não foi possível carregar os processos'); }, [lista.error]);
  useEffect(() => { if (cobertura.error) notificarErro(cobertura.error, 'Não foi possível carregar a cobertura'); }, [cobertura.error]);

  const refrescar = () => Promise.all([qc.invalidateQueries({ queryKey: ['procedimentos'] }), qc.invalidateQueries({ queryKey: ['manuais'] })]);
  const aoErro = (e: unknown) => notificarErro(e);
  const gravar = useMutation({ mutationFn: (v: { chave: string; dados: Parameters<typeof gravarProcesso>[1] }) => gravarProcesso(v.chave, v.dados), onError: aoErro, onSuccess: async () => { message.success('Processo gravado.'); await refrescar(); } });
  const criar = useMutation({ mutationFn: (titulo: string) => criarProcesso(titulo), onError: aoErro, onSuccess: async (p) => { message.success('Processo criado. Preencha as etapas.'); setNovo(false); formNovo.resetFields(); setChave(p.chave); await refrescar(); } });
  const repor = useMutation({ mutationFn: (p: Processo) => reporProcesso(p.chave), onError: aoErro, onSuccess: async (_, p) => { message.success(p.origem === 'PROPRIO' ? 'Processo apagado.' : 'Texto reposto.'); setConfirmar(null); if (p.origem === 'PROPRIO') setChave(null); await refrescar(); } });
  const esconder = useMutation({ mutationFn: (v: { chave: string; ocultar: boolean }) => ocultarProcesso(v.chave, v.ocultar), onError: aoErro, onSuccess: async (_, v) => { message.success(v.ocultar ? 'Escondido do manual.' : 'Voltou a incluir-se no manual.'); setConfirmar(null); if (v.ocultar) setChave(null); await refrescar(); } });

  if (lista.isLoading) return <Skeleton active />;
  const processos = lista.data?.processos ?? [];
  const ocultos = lista.data?.ocultos ?? [];
  const sel = processos.find((p) => p.chave === chave) ?? null;
  const cb = cobertura.data;

  return (
    <Row gutter={[12, 12]}>
      <Col xs={24} lg={9}>
        <Space orientation="vertical" size={12} style={{ width: '100%' }}>
          <Card size="small" title={`Processos (${processos.length})`} extra={podeEditar && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setNovo(true)}>Novo</Button>}>
            <div className="pm-lista">
              {processos.map((p) => (
                <button key={p.chave} type="button" className="pm-proc" aria-current={p.chave === chave} onClick={() => setChave(p.chave)}>
                  <b>{p.titulo || p.chave}</b>
                  <small>
                    {p.etapas.length} etapa(s) · {p.etapas.filter((e) => e.controlos.trim()).length} controlo(s){' '}
                    <Tag color={ROTULO_ORIGEM[p.origem].cor}>{ROTULO_ORIGEM[p.origem].rotulo}</Tag>
                    {p.tem_painel && <Tag color="blue">com painel</Tag>}
                  </small>
                </button>
              ))}
            </div>
          </Card>
          {cb && (cb.painel_sem_narrativa.length > 0 || cb.modulos_sem_processo.length > 0) && (
            <Card size="small" title="Cobertura">
              {cb.painel_sem_narrativa.length > 0 && (
                <Alert type="warning" showIcon style={{ marginBottom: 8 }} title={`Com painel e sem procedimento escrito: ${cb.painel_sem_narrativa.join(', ')}.`}
                  description="O ERP já acompanha estes processos, mas ninguém escreveu como se fazem." />
              )}
              {cb.modulos_sem_processo.length > 0 && (
                <Typography.Paragraph style={{ margin: 0 }}><b>Módulos por cobrir:</b>{' '}
                  {cb.modulos_sem_processo.map((m) => `${m.nome}${m.em_falta.length ? ` (${m.em_falta.join(', ')})` : ''}`).join(' · ')}</Typography.Paragraph>
              )}
            </Card>
          )}
          {ocultos.length > 0 && (
            <Card size="small" title={`Escondidos do manual (${ocultos.length})`}>
              {ocultos.map((o) => (
                <Space key={o.chave} style={{ width: '100%', justifyContent: 'space-between' }} align="center">
                  <span>{o.titulo}</span>
                  {podeEditar && <Button size="small" loading={esconder.isPending} onClick={() => esconder.mutate({ chave: o.chave, ocultar: false })}>Voltar a incluir</Button>}
                </Space>
              ))}
            </Card>
          )}
        </Space>
      </Col>
      <Col xs={24} lg={15}>
        {sel ? (
          <EditorProcesso processo={sel} outros={processos} podeEditar={podeEditar} aGravar={gravar.isPending}
            aoGravar={(c, dados) => gravar.mutate({ chave: c, dados })} aoRepor={(p) => setConfirmar({ tipo: 'repor', processo: p })} aoEsconder={(p) => setConfirmar({ tipo: 'esconder', processo: p })} />
        ) : (
          <Card><Empty description="Escolha um processo à esquerda para ver e adaptar as suas etapas." /></Card>
        )}
      </Col>

      <Modal open={novo} title="Novo processo" okText="Criar" cancelText="Cancelar" width={larguraModal(480)} confirmLoading={criar.isPending} onCancel={() => setNovo(false)} onOk={() => formNovo.submit()} destroyOnHidden>
        <Form form={formNovo} layout="vertical" onFinish={(v) => criar.mutate(v.titulo)}>
          <Form.Item name="titulo" label="Como se chama? (ex.: «Recrutamento e selecção»)" rules={[{ required: true, whitespace: true, message: 'Dê um título ao processo.' }]}>
            <Input maxLength={200} autoFocus />
          </Form.Item>
        </Form>
      </Modal>
      <Modal
        open={!!confirmar} width={larguraModal(480)} destroyOnHidden onCancel={() => setConfirmar(null)} cancelText="Manter"
        title={confirmar?.tipo === 'esconder' ? 'Esconder do manual' : confirmar?.processo.origem === 'PROPRIO' ? 'Apagar o processo' : 'Repor o texto do ERP'}
        okText={confirmar?.tipo === 'esconder' ? 'Esconder' : confirmar?.processo.origem === 'PROPRIO' ? 'Apagar' : 'Repor'}
        okButtonProps={{ danger: confirmar?.tipo === 'repor', loading: repor.isPending || esconder.isPending }}
        onOk={() => { if (!confirmar) return; if (confirmar.tipo === 'repor') repor.mutate(confirmar.processo); else esconder.mutate({ chave: confirmar.processo.chave, ocultar: true }); }}
      >
        {confirmar?.tipo === 'esconder'
          ? `«${confirmar.processo.titulo}» deixa de sair no manual. Continua a existir no ERP e pode voltar a incluí-lo quando quiser.`
          : confirmar?.processo.origem === 'PROPRIO'
            ? `Apagar «${confirmar.processo.titulo}»? O que escreveu perde-se.`
            : confirmar ? `«${confirmar.processo.titulo}» volta ao texto que vem com o ERP e as suas alterações perdem-se.` : null}
      </Modal>
    </Row>
  );
}


