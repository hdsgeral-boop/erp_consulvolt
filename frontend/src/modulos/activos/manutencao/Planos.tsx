import { Alert, Button, Card, Col, DatePicker, Form, Input, InputNumber, Popconfirm, Radio, Row, Select, Space, Table, Tag, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import dayjs, { type Dayjs } from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { enviar, obter } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
import { useAccao } from '@/componentes/Accoes';
import { IconeFa } from '@/componentes/fluxos';
import { useSessao } from '@/sessao/SessaoContexto';
import { dataApi, formatarData } from '@/utilitarios/formatacao';
import { campoActivoLinhas, SeletorActivo, SeletorCategoria, useCategorias } from '../comum/componentes';
import { SeletorArtigo } from './comum';
import { ModalOrdemAvulsa } from './Ordens';
import { COR_SITUACAO, textoPeriodicidade, TIPOS, UNIDADES, UNIDADES_CONTADOR, UNIDADES_TEMPO, unidadesDoModo } from './regras';
import type { ItemPrevisto, Periodicidade, Plano, TipoManutencao } from './tipos';

/**
 * Planos de manutenção (areaPlanos, js/activos_manutencao_ui.js:108-170): lista com situação, novo/editar, desactivar,
 * eliminar (só sem ordens), «Ordem» do plano, «Gerar ordens dos planos vencidos» e ordem avulsa (avaria).
 */
export function Planos({ aoAbrirOrdens }: { aoAbrirOrdens: () => void }) {
  const { pode } = useSessao();
  const gerir = pode('activos_manut_gerir');
  const q = useQuery({ queryKey: ['activos', 'manutencao', 'planos'], queryFn: () => obter<Plano[]>('/ativos/manutencao/planos') });
  const [plano, setPlano] = useState<Plano | null | undefined>(undefined);
  const [avulsa, setAvulsa] = useState(false);
  const accao = useAccao<{ criadas?: string[] }>({ invalidar: [['activos']], aoSucesso: (_, p) => { if (p.url.endsWith('/ordens') || p.url.endsWith('/gerar')) aoAbrirOrdens(); } });
  const lista = q.data ?? [];
  const porVencer = lista.filter((p) => p.situacao.chave === 'VENCIDO' || p.situacao.chave === 'A_VENCER');
  return (
    <>
      <Card style={{ marginBottom: 16 }} title={<span><IconeFa nome="calendar-check" /> Planos de manutenção</span>}
        extra={gerir && (
          <Space wrap>
            <Button size="small" icon={<IconeFa nome="tools" />} title="Ordem para uma avaria ou um trabalho pontual, que não vem de nenhum plano" onClick={() => setAvulsa(true)}>Ordem avulsa (avaria)</Button>
            <Button size="small" icon={<IconeFa nome="magic" />} loading={accao.isPending} onClick={() => accao.mutate({ url: '/ativos/manutencao/ordens/gerar' })}>
              Gerar ordens dos planos vencidos{porVencer.length ? ` (${porVencer.length})` : ''}
            </Button>
            <Button size="small" type="primary" icon={<IconeFa nome="plus" />} onClick={() => setPlano(null)}>Novo plano</Button>
          </Space>
        )}>
        <Typography.Paragraph type="secondary" style={{ margin: 0 }}>
          O plano diz o que se repete e de quanto em quanto tempo (ou de quantas em quantas horas/km). <strong>As ordens de manutenção abrem-se aqui</strong>: no botão de
          cada plano, de uma vez em «Gerar ordens dos planos vencidos», ou avulsas para uma avaria. A ordem leva as tarefas e o material que o plano prevê.
        </Typography.Paragraph>
        {!!porVencer.length && (
          <Alert style={{ marginTop: 12 }} type="warning" showIcon
            message={`${porVencer.length} plano(s) vencido(s) ou a vencer: ${porVencer.slice(0, 4).map((p) => p.nome).join('; ')}${porVencer.length > 4 ? '…' : ''}`} />
        )}
      </Card>
      <Card>
        <Table<Plano> rowKey="id" size="middle" loading={q.isLoading} dataSource={lista} pagination={false} scroll={{ x: 'max-content' }}
          rowClassName={(p) => (p.ativo === false ? 'linha-inactiva' : '')}
          locale={{ emptyText: 'Ainda não há planos. Comece por um: «Revisão trimestral do gerador», «Inspecção anual da viatura».' }}
          columns={[
            { title: 'Código', dataIndex: 'codigo', render: (v) => <strong>{v ?? '—'}</strong> },
            { title: 'Plano', dataIndex: 'nome' },
            { title: 'Aplica-se a', dataIndex: 'alvo' },
            { title: 'Tipo', dataIndex: 'tipo', render: (v: TipoManutencao) => TIPOS[v] ?? v },
            { title: 'Periodicidade', key: 'per', render: (_, p) => textoPeriodicidade(p.periodicidade) },
            { title: 'Próxima', key: 'prox', render: (_, p) => (p.periodicidade?.modo === 'CONTADOR' ? `Leitura ${Number(p.proximo_contador ?? 0)}` : formatarData(p.proxima_data)) },
            { title: 'Feitas', dataIndex: 'execucoes', align: 'center', render: (v) => v ?? 0 },
            { title: 'Situação', key: 'sit', render: (_, p) => <Tag color={COR_SITUACAO[p.situacao.chave]}>{p.situacao.rotulo}</Tag> },
            ...(gerir ? [{ title: 'Acções', key: 'acc', align: 'right' as const, render: (_: unknown, p: Plano) => (
              <Space size={4}>
                <Button size="small" icon={<IconeFa nome="play" />} title="Abrir já uma ordem de manutenção a partir deste plano" disabled={p.ativo === false}
                  onClick={() => accao.mutate({ url: `/ativos/manutencao/planos/${p.id}/ordens` })}>Ordem</Button>
                <Button size="small" title="Alterar" icon={<IconeFa nome="edit" />} onClick={() => setPlano(p)} />
                <Button size="small" title={p.ativo === false ? 'Reactivar' : 'Desactivar'} icon={<IconeFa nome={p.ativo === false ? 'toggle-off' : 'toggle-on'} />}
                  onClick={() => accao.mutate({ url: `/ativos/manutencao/planos/${p.id}/alternar` })} />
                <Popconfirm title={`Eliminar o plano «${p.nome}»?`} okText="Eliminar" cancelText="Cancelar" okButtonProps={{ danger: true }}
                  onConfirm={() => accao.mutate({ metodo: 'delete', url: `/ativos/manutencao/planos/${p.id}` })}>
                  <Button size="small" danger title={p.ordens ? 'Já gerou ordens: desactive-o' : 'Eliminar'} disabled={p.ordens > 0} icon={<IconeFa nome="trash" />} />
                </Popconfirm>
              </Space>
            ) }] : []),
          ]} />
      </Card>
      <ModalPlano plano={plano} aoFechar={() => setPlano(undefined)} />
      <ModalOrdemAvulsa aberto={avulsa} aoFechar={() => setAvulsa(false)} />
    </>
  );
}

interface ValoresPlano {
  codigo?: string;
  nome: string;
  aplica: 'ATIVO' | 'CATEGORIA';
  ativo_imobilizado_id?: number;
  categoria_ativo_id?: number;
  tipo: TipoManutencao;
  modo: Periodicidade['modo'];
  intervalo: number;
  unidade: Periodicidade['unidade'];
  proxima_data?: Dayjs;
  contador_atual?: number;
  proximo_contador?: number;
  antecedencia_dias?: number;
  duracao_horas?: number;
  responsavel?: string;
  instrucoes?: string;
  tarefas?: string[];
  itens?: ItemPrevisto[];
}

/** Data da grade (dayjs) ou da vista Linhas ('AAAA-MM-DD') → API. */
const dataDe = (d: unknown): string | null => (dayjs.isDayjs(d) ? dataApi(d) ?? null : typeof d === 'string' && d ? d : null);

/**
 * Regras de gravação do plano, comuns à grade e à vista Linhas. Na vista Linhas não há «Aplica-se a»: aplica-se à
 * categoria quando a linha a traz, senão ao activo. Tarefas e material só na grade.
 */
export function dadosPlano(v: Record<string, unknown>): Record<string, unknown> {
  const aplica = v.aplica ?? (v.categoria_ativo_id ? 'CATEGORIA' : 'ATIVO');
  const modo = (v.modo as Periodicidade['modo'] | undefined) ?? 'TEMPO';
  const tarefas = (v.tarefas as string[] | undefined) ?? [];
  const itens = (v.itens as ItemPrevisto[] | undefined) ?? [];
  const texto = (x: unknown) => (typeof x === 'string' && x.trim() ? x : null);
  return {
    codigo: texto(v.codigo), nome: v.nome, tipo: v.tipo, ativo_imobilizado_id: aplica === 'ATIVO' ? v.ativo_imobilizado_id ?? null : null,
    categoria_ativo_id: aplica === 'CATEGORIA' ? v.categoria_ativo_id ?? null : null,
    periodicidade: { modo, intervalo: v.intervalo, unidade: v.unidade },
    proxima_data: modo === 'TEMPO' ? dataDe(v.proxima_data) : null,
    contador_atual: modo === 'CONTADOR' ? v.contador_atual ?? 0 : null, proximo_contador: modo === 'CONTADOR' ? v.proximo_contador ?? null : null,
    antecedencia_dias: v.antecedencia_dias ?? 0, duracao_horas: v.duracao_horas ?? 0, responsavel: texto(v.responsavel), instrucoes: texto(v.instrucoes),
    tarefas: tarefas.filter((t) => t && t.trim()), itens: itens.filter((x) => x && (x.produto_id || x.descricao)),
  };
}

const OPCOES_MODO = [{ value: 'TEMPO', label: 'Tempo' }, { value: 'CONTADOR', label: 'Contador (horas / km)' }];
const unidadeDoModo = (u: unknown, r: Record<string, unknown>) => {
  const validas: string[] = r.modo === 'CONTADOR' ? UNIDADES_CONTADOR : UNIDADES_TEMPO;
  return u && !validas.includes(String(u)) ? `Unidade inválida para «${r.modo === 'CONTADOR' ? 'Contador' : 'Tempo'}».` : null;
};

/** Formulário do plano (formularioPlano, UI :173-318). */
function ModalPlano({ plano, aoFechar }: { plano: Plano | null | undefined; aoFechar: () => void }) {
  const [form] = Form.useForm<ValoresPlano>();
  const accao = useAccao({ invalidar: [['activos']], aoSucesso: aoFechar });
  const aberto = plano !== undefined;
  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    const per = plano?.periodicidade ?? { modo: 'TEMPO', intervalo: 3, unidade: 'MESES' };
    form.setFieldsValue({
      codigo: plano?.codigo ?? undefined, nome: plano?.nome, aplica: plano?.categoria_ativo_id ? 'CATEGORIA' : 'ATIVO',
      ativo_imobilizado_id: plano?.ativo_imobilizado_id ?? undefined, categoria_ativo_id: plano?.categoria_ativo_id ?? undefined, tipo: plano?.tipo ?? 'PREVENTIVA',
      modo: per.modo, intervalo: per.intervalo, unidade: per.unidade, proxima_data: plano?.proxima_data ? dayjs(plano.proxima_data) : dayjs(),
      contador_atual: Number(plano?.contador_atual ?? 0), proximo_contador: plano?.proximo_contador ? Number(plano.proximo_contador) : undefined,
      antecedencia_dias: plano?.antecedencia_dias ?? 7, duracao_horas: Number(plano?.duracao_horas ?? 0), responsavel: plano?.responsavel ?? undefined,
      instrucoes: plano?.instrucoes ?? undefined, tarefas: plano?.tarefas ?? [], itens: plano?.itens ?? [],
    });
  }, [aberto, plano, form]);
  const aplica = Form.useWatch('aplica', form);
  const modo = Form.useWatch('modo', form);
  const gravar = (v: ValoresPlano) => {
    const dados = dadosPlano(v as unknown as Record<string, unknown>);
    accao.mutate(plano ? { metodo: 'put', url: `/ativos/manutencao/planos/${plano.id}`, dados } : { url: '/ativos/manutencao/planos', dados });
  };
  // Vista Linhas: um plano por linha (sem tarefas nem material, que se acrescentam depois na grade)
  const categorias = useCategorias();
  const camposLinhas = useMemo<CampoFormulario[]>(() => [
    { nome: 'nome', rotulo: 'Nome do plano', obrigatorio: true, largura: 230, maxLength: 255, aliases: ['Plano', 'Nome'] },
    { nome: 'codigo', rotulo: 'Código', largura: 100, maxLength: 50, placeholder: 'automático' },
    campoActivoLinhas({ obrigatorio: false, largura: 240,
      validar: (a, r) => (!a && !r.categoria_ativo_id ? 'Escolha o activo ou a categoria.' : a && r.categoria_ativo_id ? 'Escolha o activo ou a categoria, não os dois.' : null) }),
    { nome: 'categoria_ativo_id', rotulo: 'Categoria', tipo: 'select', largura: 170, opcoes: (categorias.data ?? []).map((c) => ({ value: c.id, label: c.nome })) },
    { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', obrigatorio: true, opcoes: Object.entries(TIPOS).map(([value, label]) => ({ value, label })), omissao: 'PREVENTIVA', largura: 150 },
    { nome: 'modo', rotulo: 'Repete-se por', tipo: 'select', opcoes: OPCOES_MODO, omissao: 'TEMPO', largura: 140 },
    { nome: 'intervalo', rotulo: 'Cada', tipo: 'numero', obrigatorio: true, min: 1, precisao: 0, omissao: 3, largura: 80, aliases: ['Intervalo'] },
    { nome: 'unidade', rotulo: 'Unidade', tipo: 'select', obrigatorio: true, opcoes: Object.entries(UNIDADES).map(([value, label]) => ({ value, label })), omissao: 'MESES', largura: 150, validar: unidadeDoModo },
    { nome: 'proxima_data', rotulo: 'Próxima manutenção', tipo: 'data', largura: 140, aliases: ['Próxima'],
      validar: (d, r) => (r.modo !== 'CONTADOR' && !d ? 'Indique a data da próxima manutenção.' : null) },
    { nome: 'proximo_contador', rotulo: 'Devida na leitura', tipo: 'numero', min: 0, largura: 110, emLinhas: 'opcional', seccao: 'Contador',
      validar: (c, r) => (r.modo === 'CONTADOR' && (c === null || c === undefined) ? 'Indique a leitura em que é devida.' : null) },
    { nome: 'contador_atual', rotulo: 'Leitura actual', tipo: 'numero', min: 0, largura: 110, emLinhas: 'opcional', seccao: 'Contador' },
    { nome: 'antecedencia_dias', rotulo: 'Antecedência (dias)', tipo: 'numero', min: 0, precisao: 0, omissao: 7, largura: 100, emLinhas: 'opcional', seccao: 'Execução' },
    { nome: 'duracao_horas', rotulo: 'Duração prevista (horas)', tipo: 'numero', min: 0, largura: 100, emLinhas: 'opcional', seccao: 'Execução' },
    { nome: 'responsavel', rotulo: 'Responsável', largura: 150, maxLength: 150 },
    { nome: 'instrucoes', rotulo: 'Instruções', tipo: 'textoLongo', maxLength: 5000, largura: 240, emLinhas: 'opcional', seccao: 'Execução' },
  ], [categorias.data]);
  const vazio = (l?: unknown[] | null) => !l || l.length === 0;
  return (
    <ModalFormulario chave="activos.manutencao.planos" titulo={plano ? `Plano ${plano.codigo ?? ''}` : 'Novo plano de manutenção'} aberto={aberto} aoFechar={aoFechar}
      criacao={!plano} form={form} campos={camposLinhas} nomeRegistos="planos de manutenção" invalidar={[['activos']]} larguraLinhas={1400}
      gravarLinha={(r) => enviar('post', '/ativos/manutencao/planos', dadosPlano(r))}
      nota="Uma linha por plano: escolha o activo ou a categoria. Por tempo indique a próxima data; por contador, a leitura em que é devida (coluna opcional). As tarefas e o material previstos acrescentam-se depois, ao alterar o plano."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" width={880} confirmLoading={accao.isPending}>
      <Form form={form} layout="vertical" onFinish={gravar}>
        <Row gutter={12}>
          <Col xs={24} md={16}><Form.Item name="nome" label="Nome do plano" rules={[{ required: true, message: 'Dê um nome ao plano (ex.: «Revisão trimestral do gerador»).' }]}><Input maxLength={255} placeholder="Ex.: Revisão trimestral do gerador" /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="codigo" label="Código"><Input maxLength={50} placeholder="automático" /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="aplica" label="Aplica-se a"><Radio.Group options={[{ value: 'ATIVO', label: 'Um activo' }, { value: 'CATEGORIA', label: 'Uma categoria' }]} /></Form.Item></Col>
          <Col xs={24} md={12}>
            {aplica === 'CATEGORIA'
              ? <Form.Item name="categoria_ativo_id" label="Categoria" rules={[{ required: true, message: 'Escolha a categoria.' }]}><SeletorCategoria style={{ width: '100%' }} /></Form.Item>
              : <Form.Item name="ativo_imobilizado_id" label="Activo" rules={[{ required: true, message: 'Escolha o activo.' }]}><SeletorActivo style={{ width: '100%' }} rotuloInicial={plano?.alvo} /></Form.Item>}
          </Col>
          <Col xs={24} md={6}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={Object.entries(TIPOS).map(([value, label]) => ({ value, label }))} /></Form.Item></Col>
        </Row>
        <SeccaoFormulario titulo="Periodicidade">
        <Row gutter={12}>
          <Col xs={24} md={6}><Form.Item name="modo" label="Repete-se por">
            <Select onChange={(m: Periodicidade['modo']) => form.setFieldValue('unidade', unidadesDoModo(m)[m === 'CONTADOR' ? 0 : 2])}
              options={OPCOES_MODO} /></Form.Item></Col>
          <Col xs={12} md={4}><Form.Item name="intervalo" label="Cada" rules={[{ required: true, message: 'Indique o intervalo.' }]}><InputNumber min={1} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="unidade" label="Unidade" rules={[{ required: true }]}><Select options={unidadesDoModo(modo ?? 'TEMPO').map((u) => ({ value: u, label: UNIDADES[u] }))} /></Form.Item></Col>
          {modo === 'CONTADOR' ? (
            <>
              <Col xs={12} md={4}><Form.Item name="contador_atual" label="Leitura actual"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
              <Col xs={12} md={4}><Form.Item name="proximo_contador" label="Devida na leitura" rules={[{ required: true, message: 'Indique a leitura em que é devida.' }]}><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
            </>
          ) : (
            <Col xs={24} md={8}><Form.Item name="proxima_data" label="Próxima manutenção" rules={[{ required: true, message: 'Indique a data da próxima manutenção.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          )}
        </Row>
        </SeccaoFormulario>
        <SeccaoFormulario titulo="Execução" recolhida={!!plano && !plano.responsavel && !plano.instrucoes}>
        <Row gutter={12}>
          <Col xs={12} md={6}><Form.Item name="antecedencia_dias" label="Antecedência (dias)" tooltip="Abrir a ordem com esta antecedência"><InputNumber min={0} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="duracao_horas" label="Duração prevista (horas)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="responsavel" label="Responsável"><Input maxLength={150} placeholder="Quem executa" /></Form.Item></Col>
          <Col span={24}><Form.Item name="instrucoes" label="Instruções"><Input.TextArea rows={2} maxLength={5000} placeholder="O que há a fazer, cuidados, normas a cumprir..." /></Form.Item></Col>
        </Row>
        </SeccaoFormulario>
        <Form.List name="tarefas">
          {(campos, { add, remove }) => (
            <SeccaoFormulario titulo="Tarefas" recolhida={!!plano && vazio(plano.tarefas)} extra={<Button size="small" icon={<IconeFa nome="plus" />} onClick={() => add('')}>Tarefa</Button>}>
              {campos.map((c) => (
                <Space key={c.key} style={{ display: 'flex' }} align="baseline">
                  <Form.Item name={c.name} style={{ flex: 1, minWidth: 420 }}><Input maxLength={500} placeholder="Ex.: Trocar o óleo" /></Form.Item>
                  <Button size="small" danger title="Retirar" icon={<IconeFa nome="times" />} onClick={() => remove(c.name)} />
                </Space>
              ))}
            </SeccaoFormulario>
          )}
        </Form.List>
        <Form.List name="itens">
          {(campos, { add, remove }) => (
            <SeccaoFormulario titulo="Material e serviços previstos" recolhida={!!plano && vazio(plano.itens)} extra={<Button size="small" icon={<IconeFa nome="plus" />} onClick={() => add({ tipo: 'MATERIAL', quantidade: 1 })}>Linha</Button>}>
              {campos.map((c) => (
                <Space key={c.key} wrap align="baseline">
                  <Form.Item name={[c.name, 'tipo']}><Select style={{ width: 120 }} options={[{ value: 'MATERIAL', label: 'Material' }, { value: 'SERVICO', label: 'Serviço' }]} /></Form.Item>
                  <Form.Item name={[c.name, 'produto_id']}><SeletorArtigo style={{ width: 320 }} rotuloInicial={form.getFieldValue(['itens', c.name, 'descricao'])} /></Form.Item>
                  <Form.Item name={[c.name, 'quantidade']}><InputNumber min={0.001} style={{ width: 110 }} /></Form.Item>
                  <Button size="small" danger title="Retirar" icon={<IconeFa nome="times" />} onClick={() => remove(c.name)} />
                </Space>
              ))}
            </SeccaoFormulario>
          )}
        </Form.List>
      </Form>
    </ModalFormulario>
  );
}
