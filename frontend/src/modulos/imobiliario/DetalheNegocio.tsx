import { Alert, Button, Descriptions, Drawer, Popconfirm, Skeleton, Space, Table, Tag, Timeline, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { larguraGaveta } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import {
  anularPagamentoNegocio, aprovarNegocio, avancarNegocio, cancelarNegocio, CHAVES_NEG, eliminarNegocio, obterNegocio, type CustoNegocio, type FichaNegocio, type ListasNegocios, type PagamentoNegocio,
} from './apiNegocios';
import { useRefrescar } from './hooks';
import { accoesDoNegocio, COR_FASE, FASES_DA_BARRA, formatarMoeda, ordemDaFase, ROTULO_EVENTO, ROTULO_FASE, ROTULO_TIPO, rotuloResultado } from './logicaNegocios';
import { ModalMotivo } from './ModaisContrato';
import { ModalAbater, ModalConcluir, ModalContratosVenda, ModalCustos, ModalNegocio, ModalPagamento } from './ModaisNegocio';

/**
 * Ficha do negócio: barra de fases, o que falta para avançar, dados, impostos da transmissão, custo de aquisição (compra) ou resultado (venda), encargos, pagamentos, trilho e as acções que o estado
 * e as permissões deixam fazer. Quem aprova não regista pagamentos, quem conclui a venda não abate e quem avança as fases não conclui (pares de segregação do catálogo).
 */
export function DetalheNegocio({ id, listas, pode, aoFechar }: { id: number | null; listas: ListasNegocios | undefined; pode: (...p: string[]) => boolean; aoFechar: () => void }) {
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_NEG.negocio(id ?? 0), queryFn: () => obterNegocio(id as number), enabled: id !== null });
  const [editar, setEditar] = useState<FichaNegocio | null | undefined>(undefined);
  const [custos, setCustos] = useState<FichaNegocio | null>(null);
  const [pagar, setPagar] = useState<FichaNegocio | null>(null);
  const [contratos, setContratos] = useState<FichaNegocio | null>(null);
  const [concluir, setConcluir] = useState<FichaNegocio | null>(null);
  const [abater, setAbater] = useState<FichaNegocio | null>(null);
  const [cancelar, setCancelar] = useState(false);
  const [anular, setAnular] = useState<PagamentoNegocio | null>(null);
  const n = q.data;
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível abrir o negócio'); }, [q.error]);

  const accao = <T,>(fn: () => Promise<{ mensagem: string } & T>, erro: string) => ({
    mutationFn: fn, onError: (e: unknown) => notificarErro(e, erro), onSuccess: async (r: { mensagem: string }) => { message.success(r.mensagem); await refrescar(); },
  });
  const aprovar = useMutation(accao(() => aprovarNegocio(id as number), 'Não foi possível aprovar o negócio'));
  const avancar = useMutation(accao(() => avancarNegocio(id as number, (n as FichaNegocio).fases_seguintes[0]), 'Não foi possível avançar de fase'));
  const apagar = useMutation({
    mutationFn: () => eliminarNegocio(id as number), onError: (e) => notificarErro(e, 'Não foi possível eliminar o negócio'),
    onSuccess: async () => { message.success('Negócio eliminado.'); await refrescar(); aoFechar(); },
  });
  const cancelamento = useMutation({
    mutationFn: (motivo: string) => cancelarNegocio(id as number, motivo), onError: (e) => notificarErro(e, 'Não foi possível cancelar o negócio'),
    onSuccess: async (r) => { message.success(r.mensagem); setCancelar(false); await refrescar(); },
  });
  const anulacao = useMutation({
    mutationFn: (motivo: string) => anularPagamentoNegocio(id as number, (anular as PagamentoNegocio).id, motivo), onError: (e) => notificarErro(e, 'Não foi possível anular o pagamento'),
    onSuccess: async (r) => { message.success(r.mensagem); setAnular(null); await refrescar(); },
  });

  const a = n ? accoesDoNegocio(n, pode) : null;
  const proxima = n?.fases_seguintes[0];
  const compra = n?.tipo === 'COMPRA';
  const rv = n?.resultado_venda;
  const cor = n ? ordemDaFase(n.estado) : 0;

  return (
    <Drawer open={id !== null} onClose={aoFechar} size={larguraGaveta(900)} destroyOnHidden
      title={n ? <span>{n.numero} · {ROTULO_TIPO[n.tipo]} <Tag color={COR_FASE[n.estado]}>{ROTULO_FASE[n.estado]}</Tag></span> : 'Negócio'}>
      {!n || !a ? <Skeleton active /> : (
        <>
          <p>{n.imovel_codigo ? `${n.imovel_codigo} · ${n.imovel_nome}` : (n.imovel_nome ?? '(imóvel por ligar)')}{n.fracao_designacao ? ` — ${n.fracao_designacao}` : n.imovel_nome ? ' (imóvel inteiro)' : ''} · {compra ? 'Vendedor' : 'Comprador'}: {n.terceiro_nome ?? '—'}</p>
          <ol className="imo-fases" aria-label="Fases do negócio">
            {FASES_DA_BARRA.map((f) => (
              <li key={f} aria-current={n.estado === f ? 'step' : undefined} className={n.estado === f ? 'agora' : ordemDaFase(f) < cor ? 'feita' : ''}>
                {ROTULO_FASE[f]}{n.estado === f ? ' (agora)' : ordemDaFase(f) < cor ? ' (feita)' : ''}
                {f === 'PROMESSA' && n.data_promessa ? <small> {n.data_promessa}</small> : null}{f === 'ESCRITURA' && n.data_escritura ? <small> {n.data_escritura}</small> : null}
                {f === 'REGISTADO' && n.data_registo ? <small> {n.data_registo}</small> : null}
              </li>
            ))}
          </ol>
          {n.estado === 'CANCELADO' && <Alert type="error" showIcon style={{ marginBottom: 12 }} title={`Cancelado: ${n.motivo_cancelamento ?? ''}`} />}
          {!n.aprovado && n.estado !== 'CANCELADO' && n.estado !== 'CONCLUIDO' && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Negócio por aprovar: só depois de aprovado se avança de fase ou se registam pagamentos." />}
          {n.faltas.length > 0 && proxima && <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Para avançar para «${ROTULO_FASE[proxima]}» falta:`} description={<ul className="imo-lista">{n.faltas.map((f) => <li key={f}>{f}</li>)}</ul>} />}
          {n.tipo === 'VENDA' && n.estado === 'CONCLUIDO' && n.ativo_id !== null && n.abatido_em === null && <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Venda concluída e ainda sem abate do bem nem lançamento da alienação: falta o passo «Abater o bem»." />}
          <Descriptions size="small" column={1} bordered items={[
            { key: 'p', label: 'Preço', children: formatarMoeda(n.preco) }, { key: 'v', label: 'Valor patrimonial', children: n.vpt > 0 ? formatarMoeda(n.vpt) : 'por indicar' },
            { key: 'b', label: 'Base do imposto', children: <span>{formatarMoeda(n.base)}{n.base_e_vpt ? ' (o valor patrimonial é maior)' : ''}</span> },
            ...(n.sinal > 0 ? [{ key: 's', label: 'Sinal acordado', children: formatarMoeda(n.sinal) }] : []),
            { key: 'f', label: 'Finalidade', children: listas?.finalidades.find((x) => x.chave === n.finalidade)?.rotulo ?? n.finalidade }, ...(n.notario ? [{ key: 'nt', label: 'Cartório', children: n.notario }] : []),
            { key: 'ap', label: 'Aprovação', children: n.aprovado ? `Aprovado por ${n.aprovado_por ?? '—'}` : 'Por aprovar' },
            ...(n.notas ? [{ key: 'no', label: 'Notas', children: n.notas }] : []),
          ]} />
          <div className="imo-subtitulo">Impostos da transmissão{n.impostos_fixados_em ? ' (fixados na escritura)' : ' (ainda por fixar, por validar)'}</div>
          <Descriptions size="small" column={1} bordered items={[
            { key: 'ip', label: `Imposto Predial (${n.ip_taxa.toLocaleString('pt-PT')} %)${n.isencao !== 'NAO' ? ` — ${listas?.isencoes.find((x) => x.chave === n.isencao)?.rotulo ?? n.isencao}` : ''}`, children: formatarMoeda(n.ip_valor) },
            { key: 'se', label: `Imposto de Selo (${n.selo_pct.toLocaleString('pt-PT')} %)`, children: formatarMoeda(n.selo_valor) }, { key: 'es', label: 'Escritura', children: formatarMoeda(n.escritura_valor) },
            { key: 'to', label: 'Total', children: <strong>{formatarMoeda(n.total_impostos)}</strong> },
          ]} />
          <p style={{ marginTop: 4 }}><small>A SISA foi extinta. Estes três são encargo do adquirente — ou seja, {compra ? 'da empresa' : 'do comprador, não da empresa'}. Taxas do ano {n.impostos_ano ?? '—'}, a validar pelo contabilista.</small></p>
          {compra && n.custo_aquisicao_calculado && (
            <>
              <div className="imo-subtitulo">Custo de aquisição</div>
              <Descriptions size="small" column={1} bordered items={[
                { key: 'a', label: 'Preço', children: formatarMoeda(n.custo_aquisicao_calculado.preco) }, { key: 'b', label: 'Impostos e escritura', children: formatarMoeda(n.custo_aquisicao_calculado.impostos) },
                { key: 'c', label: 'Outros encargos capitalizáveis', children: formatarMoeda(n.custo_aquisicao_calculado.encargos) },
                { key: 'd', label: 'Vai para o imobilizado', children: <strong>{formatarMoeda(n.custo_aquisicao_calculado.total)}</strong> },
                ...(Number(n.custo_aquisicao_calculado.gasto_periodo) > 0 ? [{ key: 'e', label: 'Gasto do período (não capitaliza)', children: formatarMoeda(n.custo_aquisicao_calculado.gasto_periodo) }] : []),
              ]} />
            </>
          )}
          {!compra && (
            <>
              <div className="imo-subtitulo">Resultado da venda</div>
              {n.abatido_em !== null || n.mais_valia !== null ? (
                <Descriptions size="small" column={1} bordered items={[
                  { key: 'q', label: 'Quota do bem vendida', children: n.quota !== null ? `${(n.quota * 100).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} %${n.abate_parcial ? ' (venda parcial: o bem continua)' : ''}` : '—' },
                  { key: 'vl', label: 'Valor contabilístico', children: formatarMoeda(n.valor_contabilistico) },
                  { key: 'r', label: n.resultado_contabilistico !== null ? rotuloResultado(n.resultado_contabilistico) : 'Resultado', children: <strong>{formatarMoeda(n.resultado_contabilistico)}</strong> },
                  { key: 'm', label: 'Líquido dos encargos da venda (legado)', children: formatarMoeda(n.mais_valia) },
                  ...(n.lancamento_venda ? [{ key: 'l', label: 'Lançamento da alienação', children: n.lancamento_venda }] : []),
                ]} />
              ) : rv?.conhecido ? (
                <Descriptions size="small" column={1} bordered items={[
                  { key: 'q', label: 'Quota do bem vendida', children: `${(Number(rv.quota) * 100).toLocaleString('pt-PT', { maximumFractionDigits: 1 })} % (${rv.criterio_quota})` },
                  { key: 'c', label: 'Custo · amortizações · líquido', children: `${formatarMoeda(rv.custo)} · ${formatarMoeda(rv.amortizacoes)} · ${formatarMoeda(rv.valor_liquido)}` },
                  { key: 'r', label: rotuloResultado(rv.resultado_contabilistico), children: <strong>{formatarMoeda(rv.resultado_contabilistico)}</strong> },
                ]} />
              ) : <Alert type="info" showIcon title={rv?.motivo ?? 'Sem resultado apurado.'} />}
            </>
          )}
          <div className="imo-subtitulo">Encargos</div>
          <Table<CustoNegocio> size="small" rowKey="id" dataSource={n.custos} pagination={false} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem encargos registados além dos impostos.' }}
            columns={[
              { title: 'Tipo', dataIndex: 'tipo', key: 't', render: (v: string) => listas?.tipos_custo.find((x) => x.chave === v)?.rotulo ?? v }, { title: 'Descrição', dataIndex: 'designacao', key: 'd' },
              { title: 'Valor', dataIndex: 'valor', key: 'v', align: 'right', render: (v: number) => formatarMoeda(v) }, { title: 'Data', dataIndex: 'data', key: 'da' },
              { title: 'Capitaliza', dataIndex: 'capitaliza', key: 'c', render: (v: boolean) => (v ? 'Sim' : 'Não') }, { title: 'Pago', dataIndex: 'pago', key: 'p', render: (v: boolean) => (v ? 'Sim' : 'Não') },
            ]} />
          <div className="imo-subtitulo">{compra ? 'Pagamentos ao vendedor' : 'Recebimentos do comprador'} <small>({compra ? 'pago' : 'recebido'} {formatarMoeda(n.pago_total)} · falta {formatarMoeda(n.por_liquidar)})</small></div>
          <Table<PagamentoNegocio> size="small" rowKey="id" dataSource={n.pagamentos} pagination={false} scroll={{ x: 'max-content' }} locale={{ emptyText: 'Sem pagamentos registados.' }}
            columns={[
              { title: 'Tipo', dataIndex: 'tipo', key: 't', render: (v: string) => listas?.tipos_pagamento.find((x) => x.chave === v)?.rotulo ?? v }, { title: 'Data', dataIndex: 'data', key: 'd' },
              { title: 'Valor', dataIndex: 'valor', key: 'v', align: 'right', render: (v: number) => formatarMoeda(v) }, { title: 'Referência', key: 'r', render: (_: unknown, p: PagamentoNegocio) => [p.meio, p.referencia].filter(Boolean).join(' · ') || '—' },
              { title: 'Estado', dataIndex: 'estado', key: 'e', render: (v: PagamentoNegocio['estado'], p: PagamentoNegocio) => (v === 'VALIDO' ? <Tag color="green">Válido</Tag> : <Tag color="red">{`Anulado${p.anulado_motivo ? `: ${p.anulado_motivo}` : ''}`}</Tag>) },
              { title: 'Acções', key: 'a', render: (_: unknown, p: PagamentoNegocio) => (p.estado === 'VALIDO' && a.pagamento ? <Button size="small" danger aria-label={`Anular o pagamento de ${formatarMoeda(p.valor)}`} onClick={() => setAnular(p)}>Anular</Button> : null) },
            ]} />
          <div className="imo-subtitulo">Acções</div>
          <Space wrap>
            {a.editar && <Button onClick={() => setEditar(n)}>Editar</Button>}
            {a.custos && <Button onClick={() => setCustos(n)}>Editar encargos</Button>}
            {a.aprovar && <Button type="primary" loading={aprovar.isPending} onClick={() => aprovar.mutate()}>Aprovar</Button>}
            {a.tratarContratos && <Button onClick={() => setContratos(n)}>Decidir o que fazer aos contratos</Button>}
            {a.avancar && proxima && <Button type="primary" disabled={n.faltas.length > 0} loading={avancar.isPending} onClick={() => avancar.mutate()}>{`Avançar para «${ROTULO_FASE[proxima]}»`}</Button>}
            {a.pagamento && <Button onClick={() => setPagar(n)}>{compra ? 'Registar pagamento' : 'Registar recebimento'}</Button>}
            {a.concluir && <Button type="primary" onClick={() => setConcluir(n)}>Concluir</Button>}
            {a.abater && <Button type="primary" onClick={() => setAbater(n)}>Abater o bem</Button>}
            {a.cancelar && <Button danger onClick={() => setCancelar(true)}>Cancelar o negócio</Button>}
            {a.eliminar && <Popconfirm title="Eliminar o negócio e os seus encargos? Não se pode desfazer." okText="Eliminar" cancelText="Manter" okButtonProps={{ danger: true }} onConfirm={() => apagar.mutate()}><Button danger>Eliminar</Button></Popconfirm>}
          </Space>
          <div className="imo-subtitulo">Histórico</div>
          <Timeline items={n.historico.map((h, i) => ({ key: i, content: <span>{ROTULO_EVENTO[h.evento] ?? h.evento}{h.estado_para && h.evento === 'FASE' ? ` — ${ROTULO_FASE[h.estado_para as keyof typeof ROTULO_FASE] ?? h.estado_para}` : ''}{h.motivo ? ` — ${h.motivo}` : ''} <small>({new Date(h.em).toLocaleString('pt-PT', { dateStyle: 'short', timeStyle: 'short' })})</small></span> }))} />
        </>
      )}
      <ModalNegocio negocio={editar} listas={listas} aoFechar={() => setEditar(undefined)} />
      <ModalCustos negocio={custos} listas={listas} aoFechar={() => setCustos(null)} />
      <ModalPagamento negocio={pagar} listas={listas} aoFechar={() => setPagar(null)} />
      <ModalContratosVenda negocio={contratos} listas={listas} aoFechar={() => setContratos(null)} />
      <ModalConcluir negocio={concluir} aoFechar={() => setConcluir(null)} />
      <ModalAbater negocio={abater} aoFechar={() => setAbater(null)} />
      <ModalMotivo titulo="Cancelar o negócio" aberto={cancelar} texto="Porque está a cancelar? Fica registado." okText="Cancelar o negócio" carregando={cancelamento.isPending} aoFechar={() => setCancelar(false)} aoConfirmar={(m) => cancelamento.mutate(m)} />
      <ModalMotivo titulo="Anular o pagamento" aberto={anular !== null} texto="Diga porque anula o pagamento (por exemplo, cheque devolvido ou valor devolvido ao comprador). Fica registado." okText="Anular o pagamento" carregando={anulacao.isPending} aoFechar={() => setAnular(null)} aoConfirmar={(m) => anulacao.mutate(m)} />
    </Drawer>
  );
}
