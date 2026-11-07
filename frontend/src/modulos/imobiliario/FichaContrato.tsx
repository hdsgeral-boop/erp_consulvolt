import { Alert, Button, Card, Col, Descriptions, Drawer, Empty, Popconfirm, Row, Skeleton, Space, Statistic, Tabs, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { TabelaComModos } from '@/componentes/vistas';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import {
  activarContrato, anularMovimentoCaucao, CHAVES_CT, gerarRendasContrato, obterContaCorrente, obterContrato, renovarContrato, suspenderContrato,
  type ContaCorrente, type FichaContrato as Ficha, type ListasContratos, type MovimentoCaucao, type Renda,
} from './apiContratos';
import { useRefrescar } from './hooks';
import { formatarKz } from './logica';
import { accoesDoContrato, COR_CONTRATO, COR_RENDA, textoAtraso } from './logicaContratos';
import { ModalActualizarRenda, ModalCaucao, ModalMotivo, ModalTerminar } from './ModaisContrato';

export interface PermissoesContrato { editar: boolean; activar: boolean; suspender: boolean; terminar: boolean; renovar: boolean; gerarRendas: boolean; caucao: boolean }

const rot = (l: { valor: string; rotulo: string }[] | undefined, v: string | null | undefined) => (v ? (l?.find((o) => o.valor === v)?.rotulo ?? v) : '—');
const dataPt = (d: string | null | undefined) => (d ? d.split('-').reverse().join('/') : '—');

/** Ficha do contrato: condições, conta corrente com mora, caução (ledger) e histórico. As acções seguem o estado e as permissões; o servidor volta a validar tudo. */
export function FichaContrato({ id, listas, pode, aoEditar, aoFechar }: { id: number | null; listas: ListasContratos | undefined; pode: PermissoesContrato; aoEditar: (c: Ficha) => void; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_CT.ficha(id ?? 0), queryFn: () => obterContrato(id as number), enabled: id !== null });
  const cc = useQuery({ queryKey: ['imobiliario', 'contratos', 'conta-corrente', id ?? 0], queryFn: () => obterContaCorrente(id as number), enabled: id !== null });
  const [terminar, setTerminar] = useState<Ficha | null>(null);
  const [actualizar, setActualizar] = useState<Ficha | null>(null);
  const [suspender, setSuspender] = useState(false);
  const [caucao, setCaucao] = useState<'RECEBIDA' | 'DEVOLVIDA' | 'RETIDA' | null>(null);
  const [anular, setAnular] = useState<MovimentoCaucao | null>(null);
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar o contrato'); }, [q.error]);
  useEffect(() => { if (cc.error) notificarErro(cc.error, 'Não foi possível carregar a conta corrente'); }, [cc.error]);
  const c = q.data;
  const aoErro = (e: unknown) => notificarErro(e);
  const depois = (texto?: string) => async () => { if (texto) message.success(texto); await refrescar(); };
  const activar = useMutation({ mutationFn: () => activarContrato(id as number), onError: aoErro, onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const gerar = useMutation({ mutationFn: () => gerarRendasContrato(id as number), onError: aoErro, onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const renovar = useMutation({ mutationFn: () => renovarContrato(id as number), onError: aoErro, onSuccess: async (r) => { message.success(r.mensagem); await refrescar(); } });
  const susp = useMutation({ mutationFn: (m: string) => suspenderContrato(id as number, m), onError: aoErro, onSuccess: async () => { setSuspender(false); await depois('Contrato suspenso.')(); } });
  const anulaMov = useMutation({ mutationFn: (m: string) => anularMovimentoCaucao((anular as MovimentoCaucao).id, m), onError: aoErro, onSuccess: async () => { setAnular(null); await depois('Movimento anulado e estornado.')(); } });
  const a = c ? accoesDoContrato(c.estado, !!c.fim) : null;
  const tomada = c?.posicao === 'INQUILINO';

  return (
    <Drawer title={c ? `${c.numero} · ${rot(listas?.tipos, c.tipo).replace(/ \(.*/, '')}` : 'Contrato'} open={id !== null} onClose={aoFechar} size={larguraGaveta(1100)} destroyOnHidden
      extra={c && a && (
        <Space wrap>
          {pode.activar && a.activar && <Popconfirm title={c.posicao === 'SENHORIO' ? 'Activar o contrato? As fracções passam a arrendadas.' : 'Activar o contrato?'} okText="Activar" cancelText="Cancelar" onConfirm={() => activar.mutate()}><Button type="primary" loading={activar.isPending}>Activar</Button></Popconfirm>}
          {pode.gerarRendas && c.estado === 'ACTIVO' && <Button loading={gerar.isPending} onClick={() => gerar.mutate()}>Gerar rendas em falta</Button>}
          {pode.activar && a.actualizarRenda && <Button onClick={() => setActualizar(c)}>Actualizar renda</Button>}
          {pode.renovar && a.renovar && <Popconfirm title={`Renovar por ${c.renovacao_meses} meses?`} okText="Renovar" cancelText="Cancelar" onConfirm={() => renovar.mutate()}><Button loading={renovar.isPending}>Renovar</Button></Popconfirm>}
          {pode.suspender && a.suspender && <Button onClick={() => setSuspender(true)}>Suspender</Button>}
          {pode.terminar && a.terminar && <Button danger onClick={() => setTerminar(c)}>Terminar / denunciar</Button>}
          {pode.editar && a.editar && <Button onClick={() => aoEditar(c)}>Editar</Button>}
        </Space>
      )}>
      {!c ? <Skeleton active /> : (
        <>
          <Space wrap style={{ marginBottom: 12 }}>
            <Tag color={COR_CONTRATO[c.estado]}>{rot(listas?.estados_contrato, c.estado)}</Tag>
            <Tag>{tomada ? 'A empresa paga' : 'A empresa recebe'}</Tag>
            <span>{tomada ? 'Senhorio' : 'Inquilino'}: <b>{c.terceiro?.nome ?? '—'}</b> · {c.imovel?.nome} — {c.fracoes_detalhe.map((f) => f.designacao).join(', ')} · {dataPt(c.inicio)} a {c.fim_real ? dataPt(c.fim_real) : c.fim ? dataPt(c.fim) : 'sem termo'}</span>
          </Space>
          {c.avisos.length > 0 && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title={`${c.avisos.length} ponto(s) a tratar`} description={<ul className="imo-lista">{c.avisos.map((x) => <li key={x.tipo + x.texto}>{x.texto}</li>)}</ul>} />}
          {c.motivo_saida && <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Saída em ${dataPt(c.fim_real)}: ${c.motivo_saida}`} />}
          <Tabs items={[
            { key: 'contrato', label: 'Contrato', children: <SeparadorContrato c={c} listas={listas} /> },
            { key: 'rendas', label: `Rendas e conta corrente (${cc.data?.linhas.length ?? 0})`, children: <SeparadorContaCorrente c={c} cc={cc.data} carregando={cc.isFetching} /> },
            { key: 'caucao', label: 'Caução', children: <SeparadorCaucao c={c} listas={listas} podeMovimentar={pode.caucao} aoMovimentar={setCaucao} aoAnular={setAnular} /> },
            { key: 'historico', label: `Histórico (${c.historico.length})`, children: (
              c.historico.length === 0 ? <Empty description="Sem eventos." /> : (
                <ul className="imo-lista">{c.historico.map((h) => <li key={h.id}><b>{rot(listas?.eventos, h.evento)}</b> · {new Date(h.em).toLocaleString('pt-PT')}{h.estado_para ? ` · ${rot(listas?.estados_contrato, h.estado_para)}` : ''}{h.motivo ? ` · ${h.motivo}` : ''}</li>)}</ul>
              )
            ) },
          ]} />
        </>
      )}
      <ModalTerminar contrato={terminar} aoFechar={(feito) => { setTerminar(null); if (feito) aoFechar(); }} />
      <ModalActualizarRenda contrato={actualizar} aoFechar={() => setActualizar(null)} />
      <ModalMotivo titulo="Suspender o contrato" aberto={suspender} okText="Suspender" texto="Um contrato suspenso deixa de gerar rendas; reactiva-se com «Activar»." aoConfirmar={(m) => susp.mutate(m)} aoFechar={() => setSuspender(false)} carregando={susp.isPending} />
      <ModalMotivo titulo="Anular o movimento da caução" aberto={!!anular} okText="Anular" texto="O lançamento é estornado (fica o rasto) e os totais da caução são recalculados." aoConfirmar={(m) => anulaMov.mutate(m)} aoFechar={() => setAnular(null)} carregando={anulaMov.isPending} />
      <ModalCaucao contrato={c ?? null} tipo={caucao} aoFechar={() => setCaucao(null)} />
    </Drawer>
  );
}

function SeparadorContrato({ c, listas }: { c: Ficha; listas: ListasContratos | undefined }) {
  const tomada = c.posicao === 'INQUILINO';
  const f = c.fiscalidade;

  return (
    <Row gutter={[12, 12]}>
      <Col xs={24} xl={12}>
        <Card size="small" title="Condições">
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Âmbito">{rot(listas?.ambitos, c.ambito)}</Descriptions.Item>
            {c.contrato_pai_numero && <Descriptions.Item label="Depende de">{c.contrato_pai_numero}</Descriptions.Item>}
            {tomada && <Descriptions.Item label="Autoriza subarrendar">{c.permite_sub ? 'Sim' : <Tag color="orange">Não</Tag>}</Descriptions.Item>}
            {tomada && <Descriptions.Item label="Contas">Custo {c.conta_custo ?? '—'} · retenção {c.conta_retencao ?? '—'} · disponibilidades {c.conta_financeira ?? '—'}</Descriptions.Item>}
            <Descriptions.Item label="Finalidade fiscal">{rot(listas?.finalidades_fiscais, c.finalidade_fiscal)}</Descriptions.Item>
            <Descriptions.Item label="Renda">{formatarKz(c.renda)} · {rot(listas?.periodicidades, c.periodicidade).toLowerCase()} ({c.antecipada ? 'adiantada' : 'no fim do período'})</Descriptions.Item>
            <Descriptions.Item label="Vencimento">dia {c.dia_vencimento}{c.pro_rata ? ' · primeiro e último período proporcionais' : ' · períodos inteiros'}</Descriptions.Item>
            <Descriptions.Item label="Actualização">{c.actualizacao === 'NAO' ? 'Não actualiza' : c.actualizacao === 'PERC' ? `${c.actualizacao_valor} % a cada ${c.actualizacao_meses} meses` : `${formatarKz(c.actualizacao_valor)} a cada ${c.actualizacao_meses} meses`}{c.renda_anterior ? ` · renda anterior ${formatarKz(c.renda_anterior)}` : ''}</Descriptions.Item>
            <Descriptions.Item label="Renovação">{c.renovacao === 'AUTO' ? `automática por ${c.renovacao_meses} meses` : 'não renova'} · pré-aviso {c.aviso_dias} dias</Descriptions.Item>
            <Descriptions.Item label="Mora">{c.mora_mensal != null ? `${c.mora_mensal} % ao mês (do contrato)` : 'taxa do ano (Modelos e Taxas)'}</Descriptions.Item>
            {!tomada && <Descriptions.Item label="Cópia na Repartição">{c.registo_agt_data ? dataPt(c.registo_agt_data) : <Tag color="orange">em falta</Tag>}</Descriptions.Item>}
            {c.notas && <Descriptions.Item label="Notas">{c.notas}</Descriptions.Item>}
          </Descriptions>
        </Card>
      </Col>
      <Col xs={24} xl={12}>
        <Card size="small" title={`Fiscalidade de um período, à taxa de ${f.ano} (por validar)`} style={{ marginBottom: 12 }}>
          <Descriptions size="small" column={1} bordered>
            <Descriptions.Item label="Base (renda + encargos a acrescer)">{formatarKz(f.base)}</Descriptions.Item>
            <Descriptions.Item label={`Imposto Predial — ${f.ip_efectivo} % efectivo`}>{formatarKz(f.ip_valor)}{c.isencao_ip !== 'NAO' ? ` (${rot(listas?.isencoes, c.isencao_ip)})` : ''}</Descriptions.Item>
            <Descriptions.Item label="IVA">{f.iva_taxa > 0 ? `${f.iva_taxa} % — ${formatarKz(f.iva_valor)}` : 'isento'}</Descriptions.Item>
            <Descriptions.Item label={`Imposto de Selo — ${f.selo_pct} %`}>{formatarKz(f.selo_valor)} · {rot(listas?.selo_encargo, c.selo_encargo).toLowerCase()} (não entra na factura)</Descriptions.Item>
            <Descriptions.Item label={tomada ? 'Total do documento' : 'Total a facturar'}>{formatarKz(f.total_facturar)}</Descriptions.Item>
            <Descriptions.Item label={tomada ? 'A pagar (após retenção)' : 'A receber'}>{formatarKz(f.total_receber)}{f.ip_retido ? ` (após retenção ${tomada ? 'ao senhorio' : 'do inquilino'})` : ''}</Descriptions.Item>
          </Descriptions>
        </Card>
        <Card size="small" title="Encargos">
          {c.encargos.length === 0 ? <span>Sem encargos autónomos: a renda inclui tudo.</span> : (
            <ul className="imo-lista">{c.encargos.map((e) => <li key={e.id ?? e.designacao}>{e.designacao}: {formatarKz(e.valor)} {e.incluido_na_renda ? '(incluído na renda)' : '(a acrescer à renda)'}</li>)}</ul>
          )}
        </Card>
      </Col>
    </Row>
  );
}

function SeparadorContaCorrente({ c, cc, carregando }: { c: Ficha; cc: ContaCorrente | undefined; carregando: boolean }) {
  const t = cc?.totais;
  const tomada = c.posicao === 'INQUILINO';

  return (
    <>
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Em documento' : 'Facturado'} value={formatarKz(t?.facturado)} loading={carregando} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'A sair de caixa' : 'Esperado em caixa'} value={formatarKz(t?.esperado)} loading={carregando} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Pago' : 'Recebido'} value={formatarKz(t?.recebido)} loading={carregando} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Por pagar' : 'Em dívida'} value={formatarKz(t?.em_divida)} loading={carregando} styles={{ content: { color: (t?.em_divida ?? 0) > 0 ? '#b91c1c' : undefined } }} /></Card></Col>
        {(t?.juros ?? 0) > 0 && <Col xs={12} md={6}><Card size="small"><Statistic title="Juros de mora" value={formatarKz(t?.juros)} /></Card></Col>}
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Imposto Predial retido ao senhorio' : 'Imposto Predial retido pelo inquilino'} value={formatarKz(t?.ip_retido)} /></Card></Col>
        {!tomada && <Col xs={12} md={6}><Card size="small"><Statistic title="Selo suportado pelo senhorio" value={formatarKz(t?.selo_senhorio)} /></Card></Col>}
      </Row>
      <TabelaComModos<Renda>
        idVista="imobiliario_conta_corrente" rowKey="id" size="small" loading={carregando} dataSource={cc?.linhas ?? []} scroll={{ x: 'max-content' }} impressao={{ titulo: `Conta corrente — contrato ${c.numero}` }}
        columns={[
          { title: 'Período', dataIndex: 'periodo', principal: true, ocultavel: false, render: (v: string, r: Renda) => <span>{v}<br /><small>{dataPt(r.de)} a {dataPt(r.ate)}</small></span>, valorImpressao: (r: Renda) => r.periodo },
          { title: 'Vencimento', dataIndex: 'vencimento', render: (v: string, r: Renda) => <span>{dataPt(v)}{r.atraso > 0 ? <><br /><small>{textoAtraso(r.atraso)}</small></> : null}</span>, valorImpressao: (r: Renda) => dataPt(r.vencimento) },
          { title: 'Renda', dataIndex: 'renda', align: 'right', render: (v: number, r: Renda) => formatarKz(v) + (r.factor < 1 ? ' *' : ''), valorImpressao: (r: Renda) => formatarKz(r.renda) },
          { title: 'Base', dataIndex: 'base', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.base) },
          { title: tomada ? 'Valor do documento' : 'A facturar', dataIndex: 'total_facturar', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.total_facturar) },
          { title: 'IP retido', dataIndex: 'ip_valor', align: 'right', ocultaPorOmissao: true, render: (v: number, r: Renda) => (r.ip_retido ? formatarKz(v) : '—'), valorImpressao: (r: Renda) => (r.ip_retido ? formatarKz(r.ip_valor) : '—') },
          { title: tomada ? 'A pagar' : 'A receber', dataIndex: 'total_receber', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.total_receber) },
          { title: tomada ? 'Pago' : 'Recebido', dataIndex: 'pago', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.pago) },
          { title: tomada ? 'Por pagar' : 'Dívida', dataIndex: 'divida', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (r: Renda) => formatarKz(r.divida) },
          { title: 'Juros', dataIndex: 'juros', align: 'right', ocultaPorOmissao: true, render: (v: number) => (v > 0 ? formatarKz(v) : '—'), valorImpressao: (r: Renda) => (r.juros > 0 ? formatarKz(r.juros) : '—') },
          { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: Renda['estado']) => <Tag color={COR_RENDA[v]}>{v.charAt(0) + v.slice(1).toLowerCase()}</Tag>, valorImpressao: (r: Renda) => r.estado },
          { title: 'Documento', dataIndex: 'documento', render: (v: string | null) => v ?? '—', valorImpressao: (r: Renda) => r.documento ?? '—' },
        ]}
        vazio={{ titulo: 'Sem rendas', texto: c.estado === 'ACTIVO' ? 'Use «Gerar rendas em falta» para criar as rendas previstas.' : 'As rendas geram-se depois de o contrato estar activo.' }}
      />
      <p style={{ marginTop: 8 }}>* Período proporcional aos dias em que o contrato vigora. {tomada ? 'O pagamento cria um documento pendente na Tesouraria; integrá-lo faz-se lá.' : 'O recebimento vem dos recibos de Vendas: é lá que se recebe a renda.'}</p>
    </>
  );
}

function SeparadorCaucao({ c, listas, podeMovimentar, aoMovimentar, aoAnular }: {
  c: Ficha; listas: ListasContratos | undefined; podeMovimentar: boolean; aoMovimentar: (t: 'RECEBIDA' | 'DEVOLVIDA' | 'RETIDA') => void; aoAnular: (m: MovimentoCaucao) => void;
}) {
  const r = c.caucao_ledger.resumo;
  const tomada = c.posicao === 'INQUILINO';

  return (
    <>
      <Row gutter={[12, 12]} className="imo-kpis">
        <Col xs={12} md={6}><Card size="small"><Statistic title="Acordada" value={formatarKz(r.acordada)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Entregue' : 'Recebida'} value={formatarKz(r.recebida)} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title="Devolvida / retida" value={`${formatarKz(r.devolvida)} / ${formatarKz(r.retida)}`} /></Card></Col>
        <Col xs={12} md={6}><Card size="small"><Statistic title={tomada ? 'Em poder do senhorio' : 'Em poder da empresa'} value={formatarKz(r.em_poder)} /></Card></Col>
      </Row>
      {podeMovimentar && (
        <Space wrap style={{ margin: '12px 0' }}>
          <Button type="primary" disabled={r.por_receber <= 0} onClick={() => aoMovimentar('RECEBIDA')}>{tomada ? 'Entregar caução' : 'Receber caução'}</Button>
          <Button disabled={r.em_poder <= 0} onClick={() => aoMovimentar('DEVOLVIDA')}>Devolver</Button>
          <Button danger disabled={r.em_poder <= 0} onClick={() => aoMovimentar('RETIDA')}>Reter (com motivo)</Button>
        </Space>
      )}
      <TabelaComModos<MovimentoCaucao>
        idVista="imobiliario_caucao" rowKey="id" size="small" dataSource={c.caucao_ledger.movimentos} scroll={{ x: 'max-content' }} impressao={{ titulo: `Caução — contrato ${c.numero}` }}
        columns={[
          { title: 'Data', dataIndex: 'data', principal: true, render: (v: string) => dataPt(v), valorImpressao: (m: MovimentoCaucao) => dataPt(m.data) },
          { title: 'Movimento', dataIndex: 'tipo', render: (v: string) => rot(listas?.tipos_caucao, v), valorImpressao: (m: MovimentoCaucao) => m.tipo },
          { title: 'Valor', dataIndex: 'valor', align: 'right', render: (v: number) => formatarKz(v), valorImpressao: (m: MovimentoCaucao) => formatarKz(m.valor) },
          { title: 'Conta', dataIndex: 'conta_financeira', render: (v: string | null) => v ?? '—' },
          { title: 'Motivo', dataIndex: 'motivo', render: (v: string | null) => v ?? '—' },
          { title: 'Lançamento', dataIndex: 'lancamento_numero', render: (v: string | null, m: MovimentoCaucao) => (v ?? '—') + (m.estorno_numero ? ` · estorno ${m.estorno_numero}` : ''), valorImpressao: (m: MovimentoCaucao) => m.lancamento_numero ?? '—' },
          { title: 'Estado', dataIndex: 'estado', etiqueta: true, render: (v: string) => <Tag color={v === 'VALIDO' ? 'green' : 'red'}>{v === 'VALIDO' ? 'Válido' : 'Anulado'}</Tag>, valorImpressao: (m: MovimentoCaucao) => m.estado },
        ]}
        accoes={(m) => (podeMovimentar && m.estado === 'VALIDO' ? [{ icone: 'undo', titulo: 'Anular o movimento', onClick: () => aoAnular(m) }] : [])}
        vazio={{ titulo: 'Sem movimentos', texto: 'A caução recebe-se, devolve-se ou retém-se aqui; cada movimento fica lançado na contabilidade.' }}
      />
    </>
  );
}
