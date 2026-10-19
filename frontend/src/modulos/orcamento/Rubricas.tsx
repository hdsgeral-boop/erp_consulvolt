import { Button, Card, Checkbox, Col, Form, Input, InputNumber, Popconfirm, Row, Select, Space, Switch, Tabs, Tag, Typography } from 'antd';
import { IconeFa } from '@/componentes/fluxos';
import { DeleteOutlined, PlusOutlined, ThunderboltOutlined } from '@ant-design/icons';
import { useEffect, useState } from 'react';
import { CabecalhoPagina } from '@/componentes/CabecalhoPagina';
import type { ColunaApi } from '@/componentes/TabelaApi';
import { useSessao } from '@/sessao/SessaoContexto';
import { useAccao } from '@/componentes/Accoes';
import { enviar } from '@/api/cliente';
import { ModalFormulario, SeccaoFormulario, type CampoFormulario } from '@/componentes/formularios';
import { contemTexto } from '@/modulos/compras/comum/lista';
import { EtiquetaOrc, rotuloOrc, useRubricas } from './comum/componentes';
import type { Rubrica, TipoOrcamento } from './comum/tipos';
import { BarraFiltros, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { pedidoTabela } from './comum/impressao';

import { TabelaComModos } from '@/componentes/vistas';
import { useSeparadorUrl } from '@/componentes/navegacao/useSeparadorUrl';
const NATUREZAS: Record<TipoOrcamento, { value: string; label: string }[]> = {
  EXPLORACAO: [{ value: 'PROVEITO', label: 'Proveito' }, { value: 'CUSTO', label: 'Custo' }],
  TESOURARIA: [{ value: 'RECEBIMENTO', label: 'Recebimento' }, { value: 'PAGAMENTO', label: 'Pagamento' }],
};

/** Orçamento › Rubricas (ecrã orc_rubricas): rubricas de exploração e tesouraria, ligação às contas e controlo de excesso. */
export default function Rubricas() {
  const { pode } = useSessao();
  const [tipo, setTipo] = useSeparadorUrl<TipoOrcamento>(['EXPLORACAO', 'TESOURARIA']);
  const [texto, setTexto] = useState('');
  const [editar, setEditar] = useState<Partial<Rubrica> | null>(null);
  const q = useRubricas(tipo);
  const accao = useAccao<{ criadas: unknown[]; ignoradas: unknown[] }>({ invalidar: [['orcamento']] });
  const gerir = pode('orc_rubricas_edit');
  const linhas = (q.data ?? []).filter((r) => contemTexto(texto, r.codigo, r.nome, r.grupo, ...r.contas.map((x) => x.codigo)));

  const colunas: ColunaApi<Rubrica>[] = [
    // colunas do legado (orcamento_ui.js:157): Código, Rubrica, Natureza, Contas / prefixos; Grupo/Controlo/Activa em «Colunas ▾»
    { title: 'Código', dataIndex: 'codigo', render: (v) => <strong>{v}</strong> },
    {
      title: 'Rubrica', dataIndex: 'nome', valorImpressao: (r) => `${r.nome}${r.ativo === false ? ' (inactiva)' : ''}`,
      render: (v, r) => <>{v}{r.ativo === false && <Tag style={{ marginLeft: 4 }}>inactiva</Tag>}{r.descricao && <Typography.Text type="secondary" style={{ display: 'block', fontSize: 11.8 }}>{r.descricao}</Typography.Text>}</>,
    },
    { title: 'Natureza', dataIndex: 'natureza', valorFiltro: (r) => rotuloOrc(r.natureza), render: (v) => <EtiquetaOrc valor={v} /> },
    { title: 'Contas / prefixos', dataIndex: 'contas', width: 260, valorFiltro: (r) => r.contas.map((x) => x.codigo).join(' '), valorImpressao: (r) => r.contas.map((x) => `${x.codigo}${x.prefixo ? '*' : ''}`).join(', '), render: (cs: Rubrica['contas']) => <Space size={2} wrap>{cs.map((x) => <Tag key={x.codigo} color="blue" style={{ fontFamily: 'ui-monospace, Consolas, monospace' }} title={x.prefixo ? 'Prefixo: inclui todas as subcontas' : 'Conta exacta'}>{x.codigo}{x.prefixo ? '*' : ''}</Tag>)}</Space> },
    { title: 'Grupo', dataIndex: 'grupo', ocultaPorOmissao: true, render: (v) => v ?? '—' },
    { title: 'Controlo', key: 'ctl', ocultaPorOmissao: true, render: (_, r) => (r.controlo?.modo && r.controlo.modo !== 'NENHUM' ? <><EtiquetaOrc valor={r.controlo.modo} /><Typography.Text type="secondary">{r.controlo.aviso_pct ?? 90}% / {r.controlo.limite_pct ?? 100}%</Typography.Text></> : '—') },
    { title: 'Activa', dataIndex: 'ativo', ocultaPorOmissao: true, render: (v) => (v === false ? <Tag>Inactiva</Tag> : <Tag color="green">Sim</Tag>) },
  ];

  return (
    <>
      <CabecalhoPagina
        titulo="Rubricas Orçamentais"
        subtitulo="Cada conta pertence a uma só rubrica do mesmo tipo (exacta ou por prefixo)"
        accoes={gerir && (
          <>
            <Popconfirm title={`Criar as rubricas base do PGC (${tipo === 'EXPLORACAO' ? 'exploração' : 'tesouraria'})?`} description="Só se criam as que existem no plano e não se sobrepõem às actuais." okText="Criar" cancelText="Cancelar"
              onConfirm={() => accao.mutate({ url: '/orcamento/rubricas/base', dados: { tipo } })}>
              <Button icon={<ThunderboltOutlined />} loading={accao.isPending}>Criar rubricas base</Button>
            </Popconfirm>
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setEditar({ tipo, ativo: true, contas: [] })}>Nova rubrica</Button>
          </>
        )}
        impressaoDesactivada={!linhas.length}
        impressao={() => pedidoTabela({ titulo: `Rubricas orçamentais (${tipo === 'EXPLORACAO' ? 'exploração' : 'tesouraria'})`, filtros: texto ? [`Pesquisa: ${texto}`] : undefined, colunas, linhas })}
      />
      {/* disposição do legado (orcamento_ui.js:103-162): separadores com ícone, nota informativa e só depois a tabela */}
      <Tabs activeKey={tipo} onChange={setTipo}
        items={[{ key: 'EXPLORACAO', label: <><IconeFa nome="chart-bar" /> Exploração</> }, { key: 'TESOURARIA', label: <><IconeFa nome="coins" /> Tesouraria</> }]} />
        <div role="note" style={{ border: '1px solid rgba(37,99,235,0.35)', background: 'rgba(37,99,235,0.08)', color: '#1d4ed8', borderRadius: 4, padding: '0.5rem 0.7rem', fontSize: 13, margin: '8px 0' }}>
          {tipo === 'EXPLORACAO'
            ? <><b>Exploração:</b> associe contas de proveitos (classe 6) e custos (classe 7). O realizado é o movimento dessas contas no Diário (proveitos C − D, custos D − C).</>
            : <><b>Tesouraria:</b> associe as contas de <u>contrapartida</u> dos pagamentos e recebimentos (ex.: 31 clientes, 32 fornecedores, 36 pessoal, 34 Estado). O realizado é o dinheiro que entrou ou saiu de bancos/caixa (43/45) contra essas contas.</>}
        </div>
      <Card>
        <BarraFiltros>
          <Input.Search placeholder="Código, nome, grupo ou conta" allowClear onSearch={setTexto} style={{ width: 280 }} />
        </BarraFiltros>
        <TabelaComModos<Rubrica>
          rowKey="id"
          size="middle"
          loading={q.isFetching}
          dataSource={linhas}
          pagination={false}
          scroll={scrollTabela()}
          columns={colunas}
          accoes={gerir ? (r) => [
            { icone: 'edit', titulo: 'Editar', onClick: () => setEditar(r) },
            { icone: 'trash', titulo: 'Eliminar', variante: 'perigo', confirmar: { titulo: `Eliminar a rubrica ${r.codigo} — ${r.nome}?`, texto: 'Rubricas usadas em orçamentos, previsões ou pedidos não se eliminam.', textoOk: 'Eliminar', perigo: true },
              onClick: () => accao.mutateAsync({ metodo: 'delete', url: `/orcamento/rubricas/${r.id}` }) },
          ] : undefined}
        />
        <Typography.Text type="secondary">* conta por prefixo (abrange as subcontas)</Typography.Text>
      </Card>
      <ModalRubrica rubrica={editar} aoFechar={() => setEditar(null)} />
    </>
  );
}

const OPCOES_TIPO = [{ value: 'EXPLORACAO', label: 'Exploração' }, { value: 'TESOURARIA', label: 'Tesouraria' }];
const MODOS_CONTROLO = [{ value: 'NENHUM', label: 'Sem controlo' }, { value: 'AVISAR', label: 'Avisar' }, { value: 'APROVACAO', label: 'Exigir aprovação' }, { value: 'BLOQUEAR', label: 'Bloquear' }];
const BASES_CONTROLO = [{ value: 'ACUMULADO', label: 'Acumulado ao mês' }, { value: 'ANO', label: 'Ano inteiro' }];
const INDUTORES = ['vendas_volume_pct', 'vendas_preco_pct', 'materias_pct', 'pessoal_pct', 'outros_pct'].map((x) => ({ value: x, label: x.replace('_pct', '').replace('_', ' ') }));
const SIM_NAO = [{ value: 'S', label: 'Sim' }, { value: 'N', label: 'Não' }];
const comControloDe = (natureza: unknown) => natureza === 'CUSTO' || natureza === 'PAGAMENTO';
const vazioANulo = (v: unknown) => (v === undefined || v === '' ? null : v);

/** Contas escritas numa célula («62; 631, 7111») → lista de contas, todas por prefixo ou todas exactas. */
export function contasDeTexto(texto: unknown, prefixo = true): { codigo: string; prefixo: boolean }[] {
  return String(texto ?? '').split(/[;,\s]+/).map((c) => c.trim()).filter(Boolean).map((codigo) => ({ codigo: codigo.slice(0, 20), prefixo }));
}

/**
 * Regras de gravação da rubrica, comuns à grade e à vista Linhas: nulos explícitos, contas preenchidas (lista da grade
 * ou texto «62; 63» das linhas) e o controlo de excesso só nas naturezas de custo e pagamento.
 */
export function dadosRubrica(v: Record<string, unknown>): Record<string, unknown> {
  const contas = Array.isArray(v.contas)
    ? (v.contas as { codigo?: string; prefixo?: boolean }[]).filter((x) => x?.codigo?.trim()).map((x) => ({ codigo: String(x.codigo).trim(), prefixo: !!x.prefixo }))
    : contasDeTexto(v.contas_texto, v.contas_prefixo !== 'N');
  const controlo = v.controlo && typeof v.controlo === 'object'
    ? v.controlo
    : { modo: v.controlo_modo || 'NENHUM', aviso_pct: v.aviso_pct ?? 90, limite_pct: v.limite_pct ?? 100, base: v.controlo_base || 'ACUMULADO' };
  return {
    tipo: v.tipo, natureza: v.natureza, codigo: typeof v.codigo === 'string' ? v.codigo.trim() : v.codigo, nome: v.nome, ordem: vazioANulo(v.ordem),
    grupo: v.grupo || null, descricao: v.descricao || null, indutor: v.indutor || null, variavel_pct: vazioANulo(v.variavel_pct), cambial_pct: vazioANulo(v.cambial_pct),
    ativo: typeof v.ativo === 'boolean' ? v.ativo : v.activa !== 'N',
    controlo: comControloDe(v.natureza) ? controlo : null,
    contas,
  };
}

/** Vista Linhas da «Nova rubrica»: uma rubrica por linha, com as contas escritas na célula (separadas por «;»). */
export const CAMPOS_RUBRICA: CampoFormulario[] = [
  { nome: 'tipo', rotulo: 'Tipo', tipo: 'select', opcoes: OPCOES_TIPO, obrigatorio: true, largura: 130 },
  { nome: 'natureza', rotulo: 'Natureza', tipo: 'select', obrigatorio: true, largura: 140, opcoes: [...NATUREZAS.EXPLORACAO, ...NATUREZAS.TESOURARIA],
    validar: (n, r) => (n && r.tipo && !NATUREZAS[r.tipo as TipoOrcamento]?.some((o) => o.value === n) ? 'A natureza não é deste tipo de rubrica.' : null) },
  { nome: 'codigo', rotulo: 'Código', obrigatorio: true, largura: 110, maxLength: 50 },
  { nome: 'nome', rotulo: 'Nome', obrigatorio: true, largura: 220, maxLength: 255, aliases: ['Rubrica', 'Designação'] },
  { nome: 'contas_texto', rotulo: 'Contas', obrigatorio: true, largura: 170, placeholder: '62; 63', aliases: ['Contas / prefixos', 'Conta'],
    validar: (t) => (t && contasDeTexto(t).some((c) => !/^[0-9A-Za-z.]+$/.test(c.codigo)) ? 'Conta inválida.' : null) },
  { nome: 'contas_prefixo', rotulo: 'Por prefixo', tipo: 'select', opcoes: SIM_NAO, omissao: 'S', largura: 100, aliases: ['Prefixo'] },
  { nome: 'grupo', rotulo: 'Grupo', largura: 160, maxLength: 50, emLinhas: 'opcional', seccao: 'Identificação' },
  { nome: 'ordem', rotulo: 'Ordem', tipo: 'numero', precisao: 0, largura: 80, emLinhas: 'opcional', seccao: 'Identificação' },
  { nome: 'descricao', rotulo: 'Descrição', largura: 220, maxLength: 2000, emLinhas: 'opcional', seccao: 'Identificação' },
  { nome: 'activa', rotulo: 'Activa', tipo: 'select', opcoes: SIM_NAO, omissao: 'S', largura: 90, emLinhas: 'opcional', seccao: 'Identificação' },
  { nome: 'controlo_modo', rotulo: 'Controlo de excesso', tipo: 'select', opcoes: MODOS_CONTROLO, largura: 150, emLinhas: 'opcional', seccao: 'Controlo de excesso', aliases: ['Controlo', 'Modo'],
    validar: (m, r) => (m && m !== 'NENHUM' && !comControloDe(r.natureza) ? 'O controlo de excesso só se aplica a custos e pagamentos.' : null) },
  { nome: 'aviso_pct', rotulo: 'Aviso (%)', tipo: 'numero', min: 0, largura: 90, emLinhas: 'opcional', seccao: 'Controlo de excesso' },
  { nome: 'limite_pct', rotulo: 'Limite (%)', tipo: 'numero', min: 0, largura: 90, emLinhas: 'opcional', seccao: 'Controlo de excesso',
    validar: (l, r) => (l !== null && l !== undefined && r.aviso_pct !== null && r.aviso_pct !== undefined && Number(l) < Number(r.aviso_pct) ? 'O limite é inferior ao aviso.' : null) },
  { nome: 'controlo_base', rotulo: 'Base do controlo', tipo: 'select', opcoes: BASES_CONTROLO, largura: 140, emLinhas: 'opcional', seccao: 'Controlo de excesso' },
  { nome: 'indutor', rotulo: 'Indutor', tipo: 'select', opcoes: INDUTORES, largura: 140, emLinhas: 'opcional', seccao: 'Cenários (what-if)' },
  { nome: 'variavel_pct', rotulo: 'Parte variável (%)', tipo: 'numero', min: 0, max: 100, largura: 110, emLinhas: 'opcional', seccao: 'Cenários (what-if)' },
  { nome: 'cambial_pct', rotulo: 'Exposição cambial (%)', tipo: 'numero', min: 0, max: 100, largura: 120, emLinhas: 'opcional', seccao: 'Cenários (what-if)' },
];

/**
 * Modal da rubrica (abrirRubrica, orcamento_ui.js:164): na criação, Grade | Linhas; a grade agrupa os campos como o
 * legado (identificação, contas, controlo de excesso, cenários what-if — este recolhido quando não tem valores).
 */
function ModalRubrica({ rubrica, aoFechar }: { rubrica: Partial<Rubrica> | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const tipo: TipoOrcamento = Form.useWatch('tipo', form) ?? rubrica?.tipo ?? 'EXPLORACAO';
  const natureza = Form.useWatch('natureza', form);
  const modo = Form.useWatch(['controlo', 'modo'], form);
  const accao = useAccao({ invalidar: [['orcamento']], aoSucesso: () => aoFechar() });
  const comControlo = comControloDe(natureza);
  const editar = !!rubrica?.id;
  useEffect(() => {
    if (!rubrica) return;
    form.resetFields();
    form.setFieldsValue({
      ...rubrica,
      contas: rubrica.contas?.length ? rubrica.contas.map((x) => ({ codigo: x.codigo, prefixo: !!x.prefixo })) : [{ codigo: '', prefixo: true }],
      controlo: { modo: 'NENHUM', aviso_pct: 90, limite_pct: 100, base: 'ACUMULADO', ...(rubrica.controlo ?? {}) },
      cambial_pct: rubrica.cambial_pct !== null && rubrica.cambial_pct !== undefined ? Number(rubrica.cambial_pct) : undefined,
      variavel_pct: rubrica.variavel_pct !== null && rubrica.variavel_pct !== undefined ? Number(rubrica.variavel_pct) : undefined,
    });
  }, [rubrica, form]);
  const semCenarios = !rubrica?.indutor && (rubrica?.variavel_pct ?? null) === null && !Number(rubrica?.cambial_pct ?? 0);

  return (
    <ModalFormulario chave="orcamento.rubricas" titulo={editar ? `Editar rubrica ${rubrica?.codigo}` : 'Nova rubrica'} aberto={!!rubrica} aoFechar={aoFechar}
      criacao={!editar} form={form} campos={CAMPOS_RUBRICA} nomeRegistos="rubricas" invalidar={[['orcamento']]}
      gravarLinha={(r) => enviar('post', '/orcamento/rubricas', dadosRubrica(r))}
      nota="Uma linha por rubrica. Contas separadas por «;» (ex.: 62; 631), todas por prefixo ou todas exactas. Linhas deixadas em branco são ignoradas. Cada linha é gravada com as mesmas regras do formulário. Campos com * são obrigatórios."
      onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(820)}>
      <Form form={form} layout="vertical" onFinish={(v) => {
        const dados = dadosRubrica(v);
        accao.mutate(editar ? { metodo: 'put', url: `/orcamento/rubricas/${rubrica?.id}`, dados } : { url: '/orcamento/rubricas', dados });
      }}>
        <Row gutter={12}>
          <Col xs={24} md={8}><Form.Item name="tipo" label="Tipo" rules={[{ required: true }]}><Select options={OPCOES_TIPO} onChange={() => form.setFieldValue('natureza', undefined)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="natureza" label="Natureza" rules={[{ required: true, message: 'Escolha a natureza.' }]}><Select options={NATUREZAS[tipo]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="ativo" label="Activa" valuePropName="checked"><Switch /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="codigo" label="Código" rules={[{ required: true, message: 'Indique o código.' }]}><Input maxLength={50} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="nome" label="Nome" rules={[{ required: true, message: 'Indique o nome.' }]}><Input maxLength={255} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="ordem" label="Ordem"><InputNumber style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="grupo" label="Grupo"><Input maxLength={50} placeholder="Ex.: Custos operacionais" /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="descricao" label="Descrição"><Input maxLength={2000} /></Form.Item></Col>
        </Row>
        <SeccaoFormulario titulo="Contas">
          {tipo === 'TESOURARIA' && <Typography.Paragraph type="secondary" style={{ margin: 0 }}>Na tesouraria indicam-se as contrapartidas (não as contas 43/45).</Typography.Paragraph>}
          <Form.List name="contas" rules={[{ validator: (_, v) => (v?.some((x: { codigo?: string }) => x?.codigo?.trim()) ? Promise.resolve() : Promise.reject(new Error('Indique pelo menos uma conta.'))) }]}>
            {(campos, { add, remove }, { errors }) => (
              <div style={{ marginTop: 8 }}>
                {campos.map((c) => (
                  <Space key={c.key} align="baseline">
                    <Form.Item name={[c.name, 'codigo']}><Input placeholder="Conta" maxLength={20} style={{ width: 160 }} /></Form.Item>
                    <Form.Item name={[c.name, 'prefixo']} valuePropName="checked"><Checkbox>Prefixo</Checkbox></Form.Item>
                    <Button danger size="small" icon={<DeleteOutlined />} onClick={() => remove(c.name)} />
                  </Space>
                ))}
                <Form.ErrorList errors={errors} />
                <Button size="small" icon={<PlusOutlined />} onClick={() => add({ codigo: '', prefixo: true })}>Conta</Button>
              </div>
            )}
          </Form.List>
        </SeccaoFormulario>
        {comControlo && (
          <SeccaoFormulario titulo="Controlo de excesso nos documentos">
            <Row gutter={12}>
              <Col xs={24} md={8}>
                <Form.Item name={['controlo', 'modo']} label="Modo">
                  <Select options={MODOS_CONTROLO} />
                </Form.Item>
              </Col>
              {modo && modo !== 'NENHUM' && (
                <>
                  <Col xs={8} md={5}><Form.Item name={['controlo', 'aviso_pct']} label="Aviso (%)"><InputNumber min={0} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={8} md={5}>
                    <Form.Item name={['controlo', 'limite_pct']} label="Limite (%)" dependencies={[['controlo', 'aviso_pct']]} rules={[({ getFieldValue }) => ({
                      validator: (_, v) => (v === undefined || v === null || v >= (getFieldValue(['controlo', 'aviso_pct']) ?? 0) ? Promise.resolve() : Promise.reject(new Error('Inferior ao aviso.'))),
                    })]}>
                      <InputNumber min={0} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                  <Col xs={8} md={6}><Form.Item name={['controlo', 'base']} label="Base"><Select options={BASES_CONTROLO} /></Form.Item></Col>
                </>
              )}
            </Row>
          </SeccaoFormulario>
        )}
        <SeccaoFormulario titulo="Cenários (what-if)" recolhida={semCenarios}>
          <Row gutter={12}>
            <Col xs={24} md={8}><Form.Item name="indutor" label="Indutor" tooltip="Vazio = deduzido do PGC"><Select allowClear options={INDUTORES} /></Form.Item></Col>
            <Col xs={12} md={8}><Form.Item name="variavel_pct" label="Parte variável (%)"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item></Col>
            <Col xs={12} md={8}><Form.Item name="cambial_pct" label="Exposição cambial (%)"><InputNumber min={0} max={100} style={{ width: '100%' }} /></Form.Item></Col>
          </Row>
        </SeccaoFormulario>
      </Form>
    </ModalFormulario>
  );
}
