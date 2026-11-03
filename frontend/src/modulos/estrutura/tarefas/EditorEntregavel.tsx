import { Alert, AutoComplete, Checkbox, Col, Form, Input, InputNumber, Modal, Row, Select, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { obter } from '@/api/cliente';
import { SeccaoFormulario } from '@/componentes/formularios';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import type { ItemProdutividade } from '@/modulos/rh/api';
import { SeletorColaborador } from '@/modulos/rh/comum/componentes';
import { useInfotipos } from '@/modulos/rh/comum/consultas';
import { useConfigCRM, useFunis } from '@/modulos/crm/comum/dados';
import { actualizarEntregavel, copiarEntregavel, criarEntregavel, criarItemDoEntregavel } from './api';
import { useMutacao } from './comum';
import { chaveUnidade, METRICAS, opcoesMes, PERIODICIDADES } from './regras';
import type { DadosEntregavel, Entregavel, MetricaEntregavel, OrigemDados, TarefaUnidade } from './tipos';

type ModoRemuneracao = '' | 'EXISTE' | 'NOVO';

interface Campos {
  nome: string;
  metrica: MetricaEntregavel;
  unidade: string;
  meta?: number | null;
  ativo: boolean;
  origem_dados: OrigemDados;
  crm_tipos?: string[];
  crm_funil?: number | null;
  crm_so_ganhas?: boolean;
  colaborador_id?: number | null;
  prazo_dia?: number | null;
  prazo_mes: number;
  paga_fora_prazo: boolean;
  alerta: boolean;
  multa_valor?: number | null;
  multa_descricao?: string;
  modo: ModoRemuneracao;
  item_produtividade_id?: number | null;
  item_codigo?: string;
  item_preco?: number | null;
  item_infotipo?: number | null;
  avaliacao: boolean;
  peso_avaliacao?: number | null;
  descricao?: string;
}

export function useItensProdutividade(activo = true) {
  return useQuery({ queryKey: ['rh', 'produtividade', 'itens'], queryFn: () => obter<ItemProdutividade[]>('/rh/produtividade/itens'), enabled: activo, retry: false });
}

/**
 * Editor do entregável (estrutura_tarefas_ui.js:242-438): o que se mede, meta, prazo «dia D do mês M do período», alerta e
 * multa, remuneração (item de produtividade existente ou criado agora), avaliação de desempenho e origem das entregas (CRM).
 */
export function EditorEntregavel({ alvo, aoFechar }: { alvo: { tarefa: TarefaUnidade; entregavel: Entregavel | null } | null; aoFechar: () => void }) {
  // as consultas (itens, rubricas, CRM) só correm com o editor aberto
  return alvo ? <EditorAberto alvo={alvo} aoFechar={aoFechar} /> : null;
}

function EditorAberto({ alvo, aoFechar }: { alvo: { tarefa: TarefaUnidade; entregavel: Entregavel | null }; aoFechar: () => void }) {
  const { pode } = useSessao();
  const [form] = Form.useForm<Campos>();
  const e = alvo.entregavel;
  const tarefa = alvo.tarefa;
  const itens = useItensProdutividade(true);
  const infotipos = useInfotipos();
  const origem = Form.useWatch('origem_dados', form);
  const metrica = Form.useWatch('metrica', form);
  const unidade = Form.useWatch('unidade', form);
  const modo = Form.useWatch('modo', form);
  const alerta = Form.useWatch('alerta', form);
  const avaliacao = Form.useWatch('avaliacao', form);
  const crm = useConfigCRM();
  const funis = useFunis();
  const podeCriarItem = pode('rh_prod_config');
  const criarItem = useMutacao((p: { id: number; d: { codigo?: string | null; preco_unitario: number; infotipo_salarial_id: number } }) => criarItemDoEntregavel(p.id, p.d), { aoSucesso: aoFechar });
  const gravar = useMutacao(
    (p: { d: DadosEntregavel; item?: { codigo?: string | null; preco_unitario: number; infotipo_salarial_id: number } }) => (e ? actualizarEntregavel(e.id, p.d) : criarEntregavel(tarefa.id, p.d)),
    { aoSucesso: (ent, p) => (p.item ? criarItem.mutate({ id: ent.id, d: p.item }) : aoFechar()) },
  );

  useEffect(() => {
    form.resetFields();
    const ent = alvo.entregavel;
    form.setFieldsValue(ent
      ? { nome: ent.nome, metrica: ent.metrica, unidade: ent.unidade, meta: ent.meta != null ? Number(ent.meta) : null, ativo: ent.ativo, origem_dados: ent.origem_dados,
        crm_tipos: ent.crm_filtro?.tipos ?? [], crm_funil: ent.crm_filtro?.funil_vendas_crm_id ?? null, crm_so_ganhas: !!ent.crm_filtro?.so_ganhas, colaborador_id: ent.colaborador_id,
        prazo_dia: ent.prazo_dia, prazo_mes: ent.prazo_mes || 1, paga_fora_prazo: ent.paga_fora_prazo !== false, alerta: !!ent.alerta, multa_valor: ent.multa_valor != null ? Number(ent.multa_valor) : null,
        multa_descricao: ent.multa_descricao ?? undefined, modo: ent.item_produtividade_id ? 'EXISTE' : '', item_produtividade_id: ent.item_produtividade_id, avaliacao: !!ent.avaliacao,
        peso_avaliacao: ent.peso_avaliacao != null ? Number(ent.peso_avaliacao) : 1, descricao: ent.descricao ?? undefined }
      : { metrica: 'QUANTIDADE', unidade: '', ativo: true, origem_dados: 'MANUAL', prazo_mes: 1, paga_fora_prazo: true, alerta: false, modo: '', avaliacao: false, peso_avaliacao: 1, crm_so_ganhas: false });
  }, [alvo, form]);

  const periodicidade = tarefa.periodicidade;
  // só itens activos com a mesma métrica e a mesma unidade (chaveUnidade) — o servidor recusa os outros (ITEM_INCOMPATIVEL)
  const compativeis = (itens.data ?? []).filter((i) => i.ativo && i.metrica === metrica && chaveUnidade(i.unidade) === chaveUnidade(unidade));
  const sugestoes = (METRICAS[metrica as MetricaEntregavel]?.unidades ?? []).map((u) => ({ value: u }));
  const rubricas = infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }));

  const submeter = (v: Campos) => {
    const d: DadosEntregavel = {
      nome: v.nome.trim(), descricao: v.descricao?.trim() || null, metrica: v.metrica, unidade: v.unidade.trim(), meta: v.meta ?? null,
      item_produtividade_id: v.modo === 'EXISTE' ? v.item_produtividade_id ?? null : null, colaborador_id: v.colaborador_id ?? null, origem_dados: v.origem_dados,
      crm_filtro: v.origem_dados === 'CRM' ? { tipos: v.crm_tipos ?? [], funil_vendas_crm_id: v.crm_funil ?? null, so_ganhas: !!v.crm_so_ganhas } : null,
      prazo_dia: v.prazo_dia ?? null, prazo_mes: v.prazo_mes || 1, paga_fora_prazo: !!v.paga_fora_prazo, alerta: !!v.alerta, multa_valor: v.multa_valor ?? null,
      multa_descricao: v.multa_descricao?.trim() || null, avaliacao: !!v.avaliacao, peso_avaliacao: v.peso_avaliacao ?? 1, ativo: !!v.ativo,
    };
    const item = v.modo === 'NOVO' && v.item_preco && v.item_infotipo ? { codigo: v.item_codigo?.trim() || null, preco_unitario: v.item_preco, infotipo_salarial_id: v.item_infotipo } : undefined;
    gravar.mutate({ d, item });
  };

  return (
    <Modal open title={e ? `Editar entregável — ${e.nome}` : `Novo entregável — ${tarefa.codigo ? `${tarefa.codigo} · ` : ''}${tarefa.nome}`} onCancel={aoFechar} okText="Gravar" cancelText="Cancelar"
      confirmLoading={gravar.isPending || criarItem.isPending} onOk={() => form.submit()} width={larguraModal(820)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={submeter}>
        <Row gutter={[16, 0]}>
          <Col xs={24}><Form.Item name="nome" label="Nome do entregável" rules={[{ required: true, message: 'Indique o nome do entregável.' }]}><Input maxLength={255} placeholder="Ex.: Viaturas revistas" /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="metrica" label="Como se mede" rules={[{ required: true }]}><Select options={Object.entries(METRICAS).map(([value, m]) => ({ value, label: m.nome }))} /></Form.Item></Col>
          <Col xs={24} sm={8}><Form.Item name="unidade" label="Unidade de medida" rules={[{ required: true, message: 'Indique a unidade de medida.' }]}><AutoComplete options={sugestoes} placeholder="Ex.: viaturas, m³, relatórios" maxLength={20} /></Form.Item></Col>
          <Col xs={12} sm={4}><Form.Item name="meta" label={`Meta por período (${PERIODICIDADES[periodicidade].toLowerCase()})`}><InputNumber min={0} style={{ width: '100%' }} placeholder="opcional" /></Form.Item></Col>
          <Col xs={12} sm={4}><Form.Item name="ativo" valuePropName="checked" label=" "><Checkbox>Activo</Checkbox></Form.Item></Col>
          <Col xs={24} sm={12}>
            <Form.Item name="origem_dados" label="De onde vêm as entregas">
              <Select options={[{ value: 'MANUAL', label: 'Registadas à mão (aqui ou no portal)' }, { value: 'CRM', label: 'Actividades concluídas do CRM' }]} />
            </Form.Item>
          </Col>
          <Col xs={24} sm={12}>
            <Form.Item name="colaborador_id" label="Quem o executa" extra="Atribuído: aparece no Portal do Colaborador desta pessoa.">
              <SeletorColaborador apenasActivos placeholder="— qualquer pessoa da unidade —" style={{ width: '100%' }} />
            </Form.Item>
          </Col>
        </Row>
        {origem === 'CRM' && (
          <SeccaoFormulario titulo="Filtro das actividades do CRM">
            <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Cada actividade concluída que cumpra o filtro vale uma entrega de 1 unidade, em nome de quem a concluiu, e fica por validar. Indique pelo menos os tipos ou o funil." />
            <Row gutter={[16, 0]}>
              <Col xs={24} sm={12}>
                <Form.Item name="crm_tipos" label="Tipos de actividade">
                  <Select mode="multiple" allowClear placeholder="Todos os tipos" options={Object.entries(crm.data?.tipos_atividade ?? {}).map(([value, label]) => ({ value, label }))} />
                </Form.Item>
              </Col>
              <Col xs={24} sm={8}>
                <Form.Item name="crm_funil" label="Funil (pipeline) da oportunidade">
                  <Select allowClear placeholder="Qualquer funil" loading={funis.isLoading} options={(funis.data ?? []).map((f) => ({ value: f.id, label: f.nome }))} />
                </Form.Item>
              </Col>
              <Col xs={24} sm={4}><Form.Item name="crm_so_ganhas" valuePropName="checked" label=" "><Checkbox>Só oportunidades ganhas</Checkbox></Form.Item></Col>
            </Row>
          </SeccaoFormulario>
        )}
        <SeccaoFormulario titulo="Prazo de execução">
          <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>
            O prazo escreve-se «dia D do mês M do período» ({PERIODICIDADES[periodicidade].toLowerCase()}). Uma entrega depois do limite conta como fora de prazo.
          </Typography.Paragraph>
          <Row gutter={[16, 0]}>
            <Col xs={12} sm={4}><Form.Item name="prazo_dia" label="Dia" rules={[{ required: !!alerta, message: 'O alerta exige um dia de prazo.' }]}><InputNumber min={1} max={31} style={{ width: '100%' }} placeholder="sem prazo" /></Form.Item></Col>
            <Col xs={12} sm={8}><Form.Item name="prazo_mes" label="De que mês"><Select options={opcoesMes(periodicidade)} /></Form.Item></Col>
            <Col xs={24} sm={6}><Form.Item name="paga_fora_prazo" valuePropName="checked" label=" "><Checkbox>Paga fora do prazo</Checkbox></Form.Item></Col>
            <Col xs={24} sm={6}><Form.Item name="alerta" valuePropName="checked" label=" "><Checkbox>Alerta quando o prazo passar</Checkbox></Form.Item></Col>
            {alerta && (
              <>
                <Col xs={24} sm={8}><Form.Item name="multa_valor" label="Multa (Kz)"><InputNumber min={0} style={{ width: '100%' }} placeholder="opcional" /></Form.Item></Col>
                <Col xs={24} sm={16}><Form.Item name="multa_descricao" label="A que se deve a multa"><Input maxLength={255} placeholder="Ex.: coima da AGT pela entrega fora de prazo" /></Form.Item></Col>
              </>
            )}
          </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Remuneração (subsídio de produtividade)">
          <Form.Item name="modo" label="Remuneração">
            <Select options={[
              { value: '', label: 'Não remunerado — fica registado mas não se paga' },
              { value: 'EXISTE', label: 'Ligar a um item de produtividade que já existe' },
              ...(podeCriarItem && !e?.item_produtividade_id ? [{ value: 'NOVO', label: 'Criar agora o item de produtividade no RH' }] : []),
            ]} />
          </Form.Item>
          {modo === 'EXISTE' && (
            <Form.Item name="item_produtividade_id" label="Item de produtividade" rules={[{ required: true, message: 'Escolha o item.' }]}
              extra={compativeis.length ? 'Só itens activos com a mesma métrica e a mesma unidade de medida.' : 'Não há itens activos com esta métrica e unidade de medida.'}>
              <Select showSearch optionFilterProp="label" loading={itens.isLoading} placeholder="— escolher —" options={compativeis.map((i) => ({ value: i.id, label: `${i.codigo} · ${i.descricao} (${i.unidade ?? ''})` }))} />
            </Form.Item>
          )}
          {modo === 'NOVO' && (
            <Row gutter={[16, 0]}>
              <Col xs={24} sm={8}><Form.Item name="item_codigo" label="Código do item" extra="Vazio: ENT-<n.º do entregável>."><Input maxLength={20} placeholder="Ex.: VIATURAS-REV" /></Form.Item></Col>
              <Col xs={24} sm={8}><Form.Item name="item_preco" label={metrica === 'OBJECTIVO' ? 'Prémio a 100 %' : 'Valor a pagar por unidade'} rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0.0001} precision={4} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={24} sm={8}><Form.Item name="item_infotipo" label="Rubrica de vencimento onde é pago" rules={[{ required: true, message: 'Escolha a rubrica.' }]}><Select showSearch optionFilterProp="label" options={rubricas} /></Form.Item></Col>
            </Row>
          )}
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Avaliação de desempenho" recolhida={!e?.avaliacao}>
          <Row gutter={[16, 0]} align="middle">
            <Col xs={24} sm={14}><Form.Item name="avaliacao" valuePropName="checked"><Checkbox>Entra como objectivo quantitativo na avaliação de quem o executa (exige meta)</Checkbox></Form.Item></Col>
            <Col xs={24} sm={10}><Form.Item name="peso_avaliacao" label="Peso do objectivo" rules={avaliacao ? [{ required: true }] : []}><InputNumber min={0.01} max={100} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </SeccaoFormulario>
        <Form.Item name="descricao" label="Descrição"><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}

/** Copiar o entregável para esta ou outra tarefa da unidade (copiarEntregavel, §3.2): sem entregas e sem dono. */
export function ModalCopiar({ entregavel, tarefas, aoFechar }: { entregavel: Entregavel | null; tarefas: TarefaUnidade[]; aoFechar: () => void }) {
  const [form] = Form.useForm<{ nome: string; tarefa_destino_id: number }>();
  const accao = useMutacao((d: { nome: string; tarefa_destino_id: number }) => copiarEntregavel(entregavel!.id, { tarefa_destino_id: d.tarefa_destino_id, nome: d.nome.trim() || null }), { aoSucesso: aoFechar });
  useEffect(() => {
    if (!entregavel) return;
    form.resetFields();
    form.setFieldsValue({ nome: `${entregavel.nome} (cópia)`, tarefa_destino_id: entregavel.tarefa_unidade_id });
  }, [entregavel, form]);
  return (
    <Modal open={!!entregavel} title="Copiar entregável" onCancel={aoFechar} okText="Copiar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
      <Typography.Paragraph type="secondary">Copia a definição (medida, meta, prazo, remuneração) sem as entregas e sem a pessoa atribuída. O nome tem de ser único na tarefa de destino.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate(v)}>
        <Form.Item name="nome" label="Nome da cópia" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item>
        <Form.Item name="tarefa_destino_id" label="Tarefa de destino" rules={[{ required: true, message: 'Escolha a tarefa.' }]}>
          <Select showSearch optionFilterProp="label" options={tarefas.map((t) => ({ value: t.id, label: `${t.codigo ? `${t.codigo} · ` : ''}${t.nome}` }))} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Criar o item de produtividade a partir do entregável (§3.2): métrica e unidade do entregável; código por omissão ENT-<id>. */
export function ModalCriarItem({ entregavel, aoFechar }: { entregavel: Entregavel | null; aoFechar: () => void }) {
  // as consultas (rubricas) só correm com o modal aberto
  return entregavel ? <ModalCriarItemAberto entregavel={entregavel} aoFechar={aoFechar} /> : null;
}

function ModalCriarItemAberto({ entregavel, aoFechar }: { entregavel: Entregavel; aoFechar: () => void }) {
  const [form] = Form.useForm<{ codigo?: string; preco_unitario: number; infotipo_salarial_id: number }>();
  const infotipos = useInfotipos();
  const accao = useMutacao((d: { codigo?: string; preco_unitario: number; infotipo_salarial_id: number }) => criarItemDoEntregavel(entregavel.id, { ...d, codigo: d.codigo?.trim() || null }), { aoSucesso: aoFechar });
  useEffect(() => { form.resetFields(); }, [entregavel, form]);
  return (
    <Modal open title={`Criar item de produtividade — ${entregavel.nome}`} onCancel={aoFechar} okText="Criar e ligar" cancelText="Cancelar" confirmLoading={accao.isPending} onOk={() => form.submit()} destroyOnHidden>
      <Typography.Paragraph type="secondary">O item nasce em RH › Produtividade › Itens com a métrica «{METRICAS[entregavel.metrica]?.nome ?? entregavel.metrica}» em {entregavel.unidade} e fica ligado a este entregável. Quem executar o entregável passa a elegível ao validar as entregas.</Typography.Paragraph>
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate(v)}>
        <Form.Item name="codigo" label="Código do item" extra={`Vazio: ENT-${entregavel.id}.`}><Input maxLength={20} /></Form.Item>
        <Form.Item name="preco_unitario" label={entregavel.metrica === 'OBJECTIVO' ? 'Prémio a 100 %' : 'Valor a pagar por unidade'} rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0.0001} precision={4} style={{ width: '100%' }} /></Form.Item>
        <Form.Item name="infotipo_salarial_id" label="Rubrica de vencimento onde é pago" rules={[{ required: true, message: 'Escolha a rubrica.' }]}>
          <Select showSearch optionFilterProp="label" loading={infotipos.isLoading} options={infotipos.lista.filter((i) => i.tipo === 'VENCIMENTO').map((i) => ({ value: i.id, label: i.nome }))} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
