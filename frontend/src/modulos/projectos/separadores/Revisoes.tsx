import { Alert, Button, Card, Checkbox, DatePicker, Descriptions, Drawer, Form, Input, InputNumber, Modal, Select, Space, Table, Typography } from 'antd';
import { CalculatorOutlined, FileDoneOutlined, PlusOutlined } from '@ant-design/icons';
import { useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useState } from 'react';
import { obter } from '@/api/cliente';
import { ValorKz } from '@/modulos/contab/comum/Componentes';
import { SeletorConta, SeletorProduto } from '@/modulos/compras/comum/Seletores';
import { useAccao } from '@/componentes/Accoes';
import { notificarErro } from '@/utilitarios/erros';
import { dataApi, formatarKz, formatarNumero } from '@/utilitarios/formatacao';
import { somar } from '@/utilitarios/decimal';
import { EtiquetaProjectos } from '../comum/componentes';
import type { DetalheRevisao, PropostaFaturacao, Revisao, SimulacaoRevisao } from '../comum/tipos';
import type { PropsSeparador } from '../DetalheProjecto';
import { larguraGaveta, larguraModal, scrollTabela } from '@/componentes/responsivo';
import { BotoesExportar, CSS_SIMULACAO_COMUM, marcaSimulacao, pares, tabelaHtml } from '@/componentes/impressao';
import { useRef } from 'react';
import { ImpressaoSeparador } from '../comum/ImpressaoSeparador';

/** Auto de medição impresso (A4 retrato): resumo, subempreitadas, mão de obra, equipamentos e assinaturas. */
export function pedidoAutoMedicao(d: DetalheRevisao, mes: string) {
  type S = DetalheRevisao['subempreitadas'][number];
  type M = DetalheRevisao['mao_obra'][number];
  type E = DetalheRevisao['equipamentos'][number];
  return {
    titulo: `Auto de medição ${d.revisao.referencia}`,
    periodo: `${mes} de ${d.revisao.ano}`,
    filtros: [`Projecto: ${d.projeto.codigo} — ${d.projeto.nome}`, d.cliente ? `Cliente: ${d.cliente}` : null],
    orientacao: 'retrato' as const,
    conteudo:
      pares(
        [
          ['Subempreitadas', `${formatarKz(d.totais.subempreitadas)} Kz`],
          ['Mão de obra', `${formatarKz(d.totais.mao_obra)} Kz`],
          ['Equipamentos', `${formatarKz(d.totais.equipamentos)} Kz`],
          ['Total do auto', `${formatarKz(d.totais.geral)} Kz`],
        ],
        2,
      ) +
      (d.subempreitadas.length
        ? tabelaHtml({
            legenda: 'Subempreitadas',
            colunas: [
              { titulo: 'Subempreiteiro', valor: (s: S) => s.nome ?? '', quebrar: true },
              { titulo: 'Tarefa', valor: (s) => s.tarefa ?? '', quebrar: true },
              { titulo: '% anterior', valor: (s) => s.percentagem_anterior, formato: 'percentagem' },
              { titulo: '% actual', valor: (s) => s.percentagem_atual, formato: 'percentagem' },
              { titulo: 'Valor (Kz)', valor: (s) => s.valor_calculado, formato: 'moeda', somar: true },
            ],
            linhas: d.subempreitadas,
            totais: true,
          })
        : '') +
      tabelaHtml({
        legenda: 'Mão de obra',
        colunas: [
          { titulo: 'Colaborador', valor: (m: M) => m.nome, quebrar: true },
          { titulo: 'Valor (Kz)', valor: (m) => m.valor_calculado, formato: 'moeda', somar: true },
        ],
        linhas: d.mao_obra,
        totais: true,
      }) +
      (d.equipamentos.length
        ? tabelaHtml({
            legenda: 'Equipamentos',
            colunas: [
              { titulo: 'Código', valor: (e: E) => e.codigo },
              { titulo: 'Descrição', valor: (e) => e.descricao, quebrar: true },
              { titulo: 'Valor (Kz)', valor: (e) => e.valor, formato: 'moeda', somar: true },
            ],
            linhas: d.equipamentos,
            totais: true,
          })
        : '') +
      '<div class="imp-sem-quebra" style="display:flex;justify-content:space-around;gap:10mm;margin-top:16mm"><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Pelo empreiteiro</div><div style="flex:0 1 38%;text-align:center;border-top:0.3mm solid #1f1f1f;padding-top:1mm;font-size:8pt">Pelo dono da obra / fiscalização</div></div>',
  };
}

/** Avanço do mês nas subempreitadas (% actual − % anterior), só para mostrar. */
const avanco = (l: SimulacaoRevisao['externos'][number]) =>
  l.percentagem_atual !== undefined && l.percentagem_anterior !== undefined ? Math.round((Number(l.percentagem_atual) - Number(l.percentagem_anterior)) * 100) / 100 : null;

/**
 * Simulação da revisão mensal impressa (legado «Simulador de Revisão e Autos de Medição», js/ui_projects.js): mão de obra
 * a imputar, subempreitadas a facturar (avanço do mês) e equipamentos — calculada no servidor, marcada «Simulação».
 */
export function pedidoSimulacaoRevisao(s: SimulacaoRevisao, projecto?: { codigo?: string | null; nome?: string | null }) {
  type I = SimulacaoRevisao['internos'][number];
  type X = SimulacaoRevisao['externos'][number];
  type E = SimulacaoRevisao['equipamentos']['linhas'][number];
  const totalInternos = somar(s.internos.map((i) => i.custo));
  const totalExternos = somar(s.externos.map((l) => l.valor ?? l.custo ?? 0));
  return {
    titulo: 'Simulação da revisão mensal (auto de medição)',
    periodo: `${MESES[s.mes - 1]} de ${s.ano}`,
    filtros: [projecto?.codigo ? `Projecto: ${projecto.codigo}${projecto.nome ? ` — ${projecto.nome}` : ''}` : null, s.revisao_existente ? 'Já existe revisão neste mês: deduz-se o já imputado' : null],
    orientacao: 'retrato' as const,
    cssExtra: CSS_SIMULACAO_COMUM,
    conteudo:
      marcaSimulacao('cálculo do servidor para o mês escolhido; nada foi gravado até «Executar revisão».') +
      pares([
        ['Mão de obra a imputar', `${formatarKz(totalInternos)} Kz`],
        ['Subempreitadas a facturar', `${formatarKz(totalExternos)} Kz`],
        ['Equipamentos', `${formatarKz(s.equipamentos.total)} Kz`],
        ['Total', `${formatarKz(somar([totalInternos, totalExternos, s.equipamentos.total]))} Kz`],
      ], 2) +
      tabelaHtml<X>({
        legenda: 'Subempreitadas (a facturar / autos)',
        linhas: s.externos,
        totais: true,
        vazio: 'Nenhum avanço facturável neste mês para tarefas adjudicadas a subempreiteiros.',
        colunas: [
          { titulo: 'Subempreiteiro', valor: (l) => l.nome, quebrar: true },
          { titulo: '% anterior', valor: (l) => l.percentagem_anterior ?? '', formato: 'percentagem' },
          { titulo: '% actual', valor: (l) => l.percentagem_atual ?? '', formato: 'percentagem' },
          { titulo: 'Avanço', valor: (l) => avanco(l) ?? '', formato: 'percentagem' },
          { titulo: 'A facturar (Kz)', valor: (l) => l.valor ?? l.custo ?? '', formato: 'moeda', somar: true },
        ],
      }) +
      tabelaHtml<I>({
        legenda: 'Mão de obra interna (a imputar analiticamente)',
        linhas: s.internos,
        totais: true,
        vazio: 'Nenhum recurso interno alocado.',
        colunas: [
          { titulo: 'Colaborador', valor: (i) => i.nome, quebrar: true },
          { titulo: 'h/dia', valor: (i) => i.horas_dia, formato: 'numero' },
          { titulo: 'Fonte do custo', valor: (i) => i.fonte },
          { titulo: 'Custo mensal (Kz)', valor: (i) => i.custo_mensal, formato: 'moeda', somar: true },
          { titulo: 'Já imputado (Kz)', valor: (i) => i.ja_imputado, formato: 'moeda', somar: true },
          { titulo: 'A imputar (Kz)', valor: (i) => i.custo, formato: 'moeda', somar: true },
        ],
      }) +
      tabelaHtml<E>({
        legenda: 'Equipamentos / máquinas (amortizações)',
        linhas: s.equipamentos.linhas,
        totais: true,
        vazio: 'Nenhum equipamento alocado/amortizado neste mês.',
        colunas: [
          { titulo: 'Código', valor: (e) => e.codigo },
          { titulo: 'Descrição', valor: (e) => e.descricao, quebrar: true },
          { titulo: 'Custo a imputar (Kz)', valor: (e) => e.valor, formato: 'moeda', somar: true },
        ],
      }) +
      '<p style="font-size:7.5pt;color:#555">A mão de obra é calculada preferencialmente pelo recibo de vencimento do mês (RH); sem processamento, assume-se o valor contratual. As subempreitadas medem a % de execução das tarefas adjudicadas face à % já facturada no mês anterior.</p>',
  };
}

const MESES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

/** Autos de medição (revisões mensais: mão de obra, subempreitadas e equipamentos) e facturação do auto em Vendas. */
export function SeparadorRevisoes({ projecto, acc }: PropsSeparador) {
  const refSeparador = useRef<HTMLDivElement>(null);
  const q = useQuery({ queryKey: ['projectos', 'revisoes', projecto.id], queryFn: () => obter<Revisao[]>(`/projetos/${projecto.id}/revisoes`) });
  const [nova, setNova] = useState(false);
  const [detalhe, setDetalhe] = useState<number | null>(null);
  const [faturar, setFaturar] = useState<Revisao | null>(null);
  const faturada = (f: Revisao['faturada']) => (!f ? null : typeof f === 'string' ? f : f.numero_documento);

  return (
    <Card size="small" ref={refSeparador} title="Autos de medição e facturação" extra={<Space wrap>{acc.revisao && <Button type="primary" size="small" icon={<PlusOutlined />} onClick={() => setNova(true)}>Revisão mensal</Button>}<ImpressaoSeparador alvo={refSeparador} titulo="Autos de medição" projecto={projecto} /></Space>}>
      <Table<Revisao> scroll={scrollTabela()}
        rowKey="id"
        size="small"
        loading={q.isFetching}
        dataSource={q.data}
        pagination={{ pageSize: 24 }}
        onRow={(r) => ({ onClick: () => setDetalhe(r.id), style: { cursor: 'pointer' } })}
        columns={[
          { title: 'Referência', dataIndex: 'referencia', render: (v) => <strong>{v}</strong> },
          { title: 'Mês', key: 'm', render: (_, r) => `${MESES[r.mes - 1]} ${r.ano}` },
          { title: 'Mão de obra', dataIndex: 'total_mao_obra', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Subempreitadas', dataIndex: 'total_subempreitadas', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Equipamentos', dataIndex: 'total_equipamentos', align: 'right', render: (v) => <ValorKz valor={v} discretoSeZero /> },
          { title: 'Estado', dataIndex: 'estado', render: (v) => <EtiquetaProjectos valor={v} /> },
          { title: 'Facturação', key: 'f', render: (_, r) => faturada(r.faturada) ?? (r.faturavel ? 'Por facturar' : '—') },
          {
            title: '', key: 'acc', align: 'right',
            render: (_, r) => acc.revisao && r.faturavel && !r.faturada && (
              <Button size="small" icon={<FileDoneOutlined />} onClick={(e) => { e.stopPropagation(); setFaturar(r); }}>Facturar</Button>
            ),
          },
        ]}
      />
      <ModalNovaRevisao projectoId={projecto.id} aberto={nova} aoFechar={() => setNova(false)} />
      <DetalheAuto projectoId={projecto.id} revisaoId={detalhe} aoFechar={() => setDetalhe(null)} />
      <ModalFaturar projectoId={projecto.id} revisao={faturar} aoFechar={() => setFaturar(null)} />
    </Card>
  );
}

function ModalNovaRevisao({ projectoId, aberto, aoFechar }: { projectoId: number; aberto: boolean; aoFechar: () => void }) {
  const [mes, setMes] = useState(dayjs().subtract(1, 'month'));
  const [simulacao, setSimulacao] = useState<SimulacaoRevisao | null>(null);
  const [aSimular, setASimular] = useState(false);
  const [confirmarAdit, setConfirmarAdit] = useState(false);
  const [produto, setProduto] = useState<number>();
  const accao = useAccao({ invalidar: [['projectos']], aoSucesso: () => aoFechar() });
  useEffect(() => { if (aberto) { setSimulacao(null); setConfirmarAdit(false); } }, [aberto]);

  const simular = async () => {
    setASimular(true);
    try {
      setSimulacao(await obter<SimulacaoRevisao>(`/projetos/${projectoId}/revisoes/simulacao`, { mes: mes.month() + 1, ano: mes.year() }));
    } catch (e) {
      notificarErro(e, 'A simulação falhou');
    } finally {
      setASimular(false);
    }
  };

  return (
    <Modal
      title="Revisão mensal (auto de medição)"
      open={aberto}
      onCancel={aoFechar}
      width={larguraModal(900)}
      destroyOnHidden
      footer={[
        <Button key="c" onClick={aoFechar}>Cancelar</Button>,
        <BotoesExportar key="p" chave="projectos simulacao revisao" excel={false} desactivado={!simulacao} textoImprimir="Imprimir simulação"
          obterPedido={() => simulacao && pedidoSimulacaoRevisao(simulacao)} />,
        <Button key="s" icon={<CalculatorOutlined />} loading={aSimular} onClick={simular}>Simular</Button>,
        <Button key="e" type="primary" disabled={!simulacao} loading={accao.isPending}
          onClick={() => accao.mutate({ url: `/projetos/${projectoId}/revisoes`, dados: { mes: mes.month() + 1, ano: mes.year(), confirmar_aditamento: confirmarAdit, produto_subempreitada_id: produto ?? null } })}>
          Executar revisão
        </Button>,
      ]}
    >
      <Space wrap style={{ marginBottom: 12 }}>
        <DatePicker picker="month" format="MM/YYYY" value={mes} allowClear={false} onChange={(d) => { if (d) { setMes(d); setSimulacao(null); } }} />
        <SeletorProduto allowClear placeholder="Artigo da subempreitada (opcional)" value={produto} onChange={setProduto} style={{ width: 300, maxWidth: '100%' }} />
        <Checkbox checked={confirmarAdit} onChange={(e) => setConfirmarAdit(e.target.checked)}>Confirmar excesso sobre o contratado (aditamento)</Checkbox>
      </Space>
      {simulacao && (
        <>
          {simulacao.revisao_existente && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message="Já existe revisão para este mês: a execução deduz o que já foi imputado." />}
          <Typography.Title level={5}>Mão de obra interna</Typography.Title>
          <Table scroll={scrollTabela()} size="small" rowKey="membro_id" pagination={false} dataSource={simulacao.internos}
            columns={[
              { title: 'Colaborador', dataIndex: 'nome' },
              { title: 'h/dia', dataIndex: 'horas_dia', align: 'right' },
              { title: 'Fonte', dataIndex: 'fonte' },
              { title: 'Custo mensal', dataIndex: 'custo_mensal', align: 'right', render: (v) => <ValorKz valor={v} /> },
              { title: 'Já imputado', dataIndex: 'ja_imputado', align: 'right', render: (v) => <ValorKz valor={v} /> },
              { title: 'A imputar', dataIndex: 'custo', align: 'right', render: (v) => <ValorKz valor={v} forte /> },
            ]} />
          {simulacao.externos.length > 0 && (
            <>
              <Typography.Title level={5} style={{ marginTop: 12 }}>Subempreitadas</Typography.Title>
              <Table scroll={scrollTabela()} size="small" rowKey={(l) => JSON.stringify(l)} pagination={false} dataSource={simulacao.externos}
                columns={[
                  { title: 'Subempreiteiro', dataIndex: 'nome' },
                  { title: '% anterior', dataIndex: 'percentagem_anterior', align: 'right' },
                  { title: '% actual', dataIndex: 'percentagem_atual', align: 'right' },
                  { title: 'Avanço', key: 'a', align: 'right', render: (_, l) => { const a = avanco(l); return a === null ? '—' : `${formatarNumero(a)} %`; } },
                  { title: 'A facturar', key: 'v', align: 'right', render: (_, l) => <ValorKz valor={l.valor ?? l.custo} forte /> },
                ]} />
            </>
          )}
          <Typography.Title level={5} style={{ marginTop: 12 }}>Equipamentos ({formatarKz(simulacao.equipamentos.total, true)})</Typography.Title>
          <Table scroll={scrollTabela()} size="small" rowKey={(l) => JSON.stringify(l)} pagination={false} dataSource={simulacao.equipamentos.linhas}
            columns={[{ title: 'Código', dataIndex: 'codigo' }, { title: 'Descrição', dataIndex: 'descricao' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> }]} />
        </>
      )}
    </Modal>
  );
}

function DetalheAuto({ projectoId, revisaoId, aoFechar }: { projectoId: number; revisaoId: number | null; aoFechar: () => void }) {
  const q = useQuery({ queryKey: ['projectos', 'revisao', projectoId, revisaoId], queryFn: () => obter<DetalheRevisao>(`/projetos/${projectoId}/revisoes/${revisaoId}`), enabled: !!revisaoId });
  const d = q.data;
  return (
    <Drawer
      title={d ? `Auto ${d.revisao.referencia} — ${MESES[d.revisao.mes - 1]} ${d.revisao.ano}` : 'Auto de medição'}
      open={!!revisaoId}
      onClose={aoFechar}
      width={larguraGaveta(820)}
      loading={q.isLoading}
      extra={d && <BotoesExportar tamanho="small" obterPedido={() => pedidoAutoMedicao(d, MESES[d.revisao.mes - 1])} />}
    >
      {d && (
        <Space direction="vertical" style={{ width: '100%' }}>
          <Descriptions size="small" bordered column={{ xs: 1, sm: 2 }}>
            <Descriptions.Item label="Projecto">{d.projeto.codigo} — {d.projeto.nome}</Descriptions.Item>
            <Descriptions.Item label="Cliente">{d.cliente}</Descriptions.Item>
            <Descriptions.Item label="Subempreitadas"><ValorKz valor={d.totais.subempreitadas} /></Descriptions.Item>
            <Descriptions.Item label="Mão de obra"><ValorKz valor={d.totais.mao_obra} /></Descriptions.Item>
            <Descriptions.Item label="Equipamentos"><ValorKz valor={d.totais.equipamentos} /></Descriptions.Item>
            <Descriptions.Item label="Total"><ValorKz valor={d.totais.geral} forte /></Descriptions.Item>
          </Descriptions>
          {d.subempreitadas.length > 0 && <Table scroll={scrollTabela()} size="small" rowKey="id" pagination={false} title={() => <strong>Subempreitadas</strong>} dataSource={d.subempreitadas}
            columns={[{ title: 'Subempreiteiro', dataIndex: 'nome', render: (v) => v ?? '—' }, { title: 'Tarefa', dataIndex: 'tarefa', render: (v) => v ?? '—' },
              { title: '% anterior', dataIndex: 'percentagem_anterior', align: 'right' }, { title: '% actual', dataIndex: 'percentagem_atual', align: 'right' },
              { title: 'Valor', dataIndex: 'valor_calculado', align: 'right', render: (v) => <ValorKz valor={v} /> }]} />}
          <Table scroll={scrollTabela()} size="small" rowKey="id" pagination={false} title={() => <strong>Mão de obra</strong>} dataSource={d.mao_obra}
            columns={[{ title: 'Colaborador', dataIndex: 'nome' }, { title: 'Valor', dataIndex: 'valor_calculado', align: 'right', render: (v) => <ValorKz valor={v} /> }]} />
          {d.equipamentos.length > 0 && <Table scroll={scrollTabela()} size="small" rowKey={(l) => JSON.stringify(l)} pagination={false} title={() => <strong>Equipamentos</strong>} dataSource={d.equipamentos}
            columns={[{ title: 'Código', dataIndex: 'codigo' }, { title: 'Descrição', dataIndex: 'descricao' }, { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v) => <ValorKz valor={v} /> }]} />}
        </Space>
      )}
    </Drawer>
  );
}

function ModalFaturar({ projectoId, revisao, aoFechar }: { projectoId: number; revisao: Revisao | null; aoFechar: () => void }) {
  const [form] = Form.useForm();
  const tipo = Form.useWatch('tipo_documento', form);
  const proposta = useQuery({
    queryKey: ['projectos', 'proposta-faturacao', projectoId, revisao?.id],
    queryFn: () => obter<PropostaFaturacao>(`/projetos/${projectoId}/revisoes/${revisao?.id}/faturacao`),
    enabled: !!revisao,
    retry: false,
  });
  const accao = useAccao({ invalidar: [['projectos'], ['vendas']], aoSucesso: () => aoFechar() });
  useEffect(() => {
    if (revisao) { form.resetFields(); form.setFieldsValue({ tipo_documento: 'FT', data_emissao: dayjs() }); }
  }, [revisao, form]);
  useEffect(() => {
    if (proposta.data) form.setFieldValue('valor', Number(proposta.data.sugerido));
  }, [proposta.data, form]);
  const p = proposta.data;
  return (
    <Modal title={`Facturar o auto ${revisao?.referencia ?? ''}`} open={!!revisao} onCancel={aoFechar} onOk={() => form.submit()} okText="Emitir documento" cancelText="Cancelar" confirmLoading={accao.isPending} width={larguraModal(640)} destroyOnHidden>
      {proposta.error && <Alert type="error" showIcon style={{ marginBottom: 12 }} message={(proposta.error as Error).message} />}
      {p && (
        <Descriptions size="small" column={{ xs: 1, sm: 2 }} bordered style={{ marginBottom: 12 }}>
          <Descriptions.Item label="Execução global">{p.execucao_global}%</Descriptions.Item>
          <Descriptions.Item label="Venda (sem IVA)"><ValorKz valor={p.venda} /></Descriptions.Item>
          <Descriptions.Item label="Já facturado"><ValorKz valor={p.faturado} /></Descriptions.Item>
          <Descriptions.Item label="Sugerido"><ValorKz valor={p.sugerido} forte /></Descriptions.Item>
          <Descriptions.Item label="IVA da encomenda" span={2}>{p.taxa_iva_encomenda}%</Descriptions.Item>
        </Descriptions>
      )}
      {p?.ja_faturado_por && <Alert type="warning" showIcon style={{ marginBottom: 12 }} message={`Este auto já foi facturado por ${p.ja_faturado_por}.`} />}
      <Form form={form} layout="vertical" onFinish={(v) => accao.mutate({
        url: `/projetos/${projectoId}/revisoes/${revisao?.id}/faturar`,
        dados: { ...v, data_emissao: dataApi(v.data_emissao) ?? null, produto_id: v.produto_id ?? null, conta_disponibilidade: v.conta_disponibilidade || null, meio_pagamento: v.meio_pagamento || null },
      })}>
        <Space wrap>
          <Form.Item name="tipo_documento" label="Documento" rules={[{ required: true }]}>
            <Select style={{ width: 200, maxWidth: '100%' }} options={[{ value: 'FT', label: 'Factura (FT)' }, { value: 'FR', label: 'Factura-recibo (FR)' }, { value: 'PF', label: 'Pró-forma (PF)' }]} />
          </Form.Item>
          <Form.Item name="valor" label="Valor sem IVA (Kz)" rules={[{ required: true, message: 'Indique o valor.' }]}><InputNumber min={0.01} precision={2} style={{ width: 180 }} /></Form.Item>
          <Form.Item name="data_emissao" label="Data de emissão"><DatePicker format="DD/MM/YYYY" /></Form.Item>
        </Space>
        <Form.Item name="produto_id" label="Artigo (vazio = artigo de facturação configurado)"><SeletorProduto allowClear /></Form.Item>
        {tipo === 'FR' && (
          <Space wrap>
            <Form.Item name="conta_disponibilidade" label="Conta de caixa/banco" rules={[{ required: true, message: 'Indique a conta.' }]}><SeletorConta prefixo="4" /></Form.Item>
            <Form.Item name="meio_pagamento" label="Meio de pagamento"><Input maxLength={20} style={{ width: 160 }} /></Form.Item>
          </Space>
        )}
      </Form>
    </Modal>
  );
}
