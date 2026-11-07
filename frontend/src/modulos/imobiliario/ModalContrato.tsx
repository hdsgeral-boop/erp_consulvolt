import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { Alert, Button, Col, DatePicker, Form, Input, InputNumber, Modal, Row, Select, Space, Switch } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import dayjs from 'dayjs';
import { useEffect, useMemo } from 'react';
import { larguraModal } from '@/componentes/responsivo';
import { notificarErro } from '@/utilitarios/erros';
import { listarFracoes, listarImoveis, listarTerceiros, obterTaxas, type Fracao } from './api';
import { CHAVES_CT, gravarContrato, listarContas, listarContratos, type Encargo, type FichaContrato, type ListasContratos } from './apiContratos';
import { useRefrescar } from './hooks';
import { diasDoPrimeiroPeriodo, limiteCaucao, previsaoRenda } from './logicaContratos';
import { formatarKz } from './logica';

type Valores = Record<string, unknown> & { encargos?: Encargo[] };

const DATAS = ['inicio', 'fim', 'registo_agt_data'] as const;
const TRAVADOS_EM_VIGOR = ['tipo', 'ambito', 'imovel_id', 'fracao_id', 'fracoes_ids', 'terceiro_id', 'inicio', 'contrato_pai_id'];

const paraForm = (c: FichaContrato): Valores => ({
  ...c, fracoes_ids: c.fracoes_detalhe.map((f) => f.id), encargos: c.encargos,
  ...Object.fromEntries(DATAS.map((d) => [d, c[d] ? dayjs(c[d] as string) : null])),
});

/** Valores do formulário para o pedido: datas em texto, vazios a null. Os encargos lêem-se da lista (antd 6: subcampos sem Form.Item perdem-se no onFinish). */
export function dadosContrato(v: Valores): Record<string, unknown> {
  const saida: Record<string, unknown> = {};
  for (const [k, x] of Object.entries(v)) {
    if ((DATAS as readonly string[]).includes(k)) saida[k] = x ? dayjs(x as string).format('YYYY-MM-DD') : null;
    else if (k === 'encargos') saida[k] = (x as Encargo[] | undefined ?? []).filter((e) => e && (e.designacao ?? '').trim() !== '').map((e) => ({ designacao: e.designacao, valor: e.valor ?? 0, incluido_na_renda: !!e.incluido_na_renda }));
    else saida[k] = x === undefined || x === '' ? null : x;
  }
  return saida;
}

/** Criar e editar um contrato (imo_contrato_editar). As regras (conflitos de datas, subarrendamento, contas) são do servidor; aqui escondem-se os campos que não se aplicam e mostra-se a previsão da renda. */
export function ModalContrato({ contrato, listas, aoFechar }: { contrato: FichaContrato | null | undefined; listas: ListasContratos | undefined; aoFechar: (gravado?: FichaContrato) => void }) {
  const [form] = Form.useForm<Valores>();
  const aberto = contrato !== undefined;
  const refrescar = useRefrescar();
  const tipo = (Form.useWatch('tipo', form) as string | undefined) ?? 'ARRENDAMENTO';
  const ambito = (Form.useWatch('ambito', form) as string | undefined) ?? 'FRACCAO';
  const imovelId = Form.useWatch('imovel_id', form) as number | undefined;
  const renda = (Form.useWatch('renda', form) as number | undefined) ?? 0;
  const periodicidade = (Form.useWatch('periodicidade', form) as string | undefined) ?? 'MENSAL';
  const inicio = Form.useWatch('inicio', form) as dayjs.Dayjs | null | undefined;
  const actualizacao = (Form.useWatch('actualizacao', form) as string | undefined) ?? 'NAO';
  const retencao = Form.useWatch('retencao_ip', form) as boolean | undefined;
  const fiscais = { isencao_ip: (Form.useWatch('isencao_ip', form) as string | undefined) ?? 'NAO', selo_encargo: (Form.useWatch('selo_encargo', form) as string | undefined) ?? 'SENHORIO',
    finalidade_fiscal: (Form.useWatch('finalidade_fiscal', form) as string | undefined) ?? 'HABITACAO' };
  const encargos = Form.useWatch('encargos', form) as Encargo[] | undefined;
  const emVigor = !!contrato && contrato.estado !== 'RASCUNHO';
  const tomada = tipo === 'TOMADA';
  const sub = tipo === 'SUBARRENDAMENTO';
  const ano = new Date().getFullYear();

  const imoveis = useQuery({ queryKey: ['imobiliario', 'imoveis'], queryFn: listarImoveis, enabled: aberto });
  const terceiros = useQuery({ queryKey: ['imobiliario', 'terceiros'], queryFn: () => listarTerceiros(), enabled: aberto });
  const fracoes = useQuery({ queryKey: ['imobiliario', 'fracoes', imovelId ?? 0], queryFn: () => listarFracoes(imovelId as number), enabled: aberto && !!imovelId });
  const contratos = useQuery({ queryKey: CHAVES_CT.lista, queryFn: listarContratos, enabled: aberto && sub });
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: aberto && tomada });
  const taxas = useQuery({ queryKey: ['imobiliario', 'taxas', ano], queryFn: () => obterTaxas(ano), enabled: aberto });

  useEffect(() => {
    if (!aberto) return;
    form.resetFields();
    form.setFieldsValue(contrato ? paraForm(contrato) : { tipo: 'ARRENDAMENTO', ambito: 'FRACCAO', periodicidade: 'MENSAL', dia_vencimento: 1, antecipada: true, pro_rata: true, finalidade_fiscal: 'HABITACAO',
      isencao_ip: 'NAO', retencao_ip: true, selo_encargo: 'SENHORIO', doc_tipo: 'FT', actualizacao: 'NAO', actualizacao_meses: 12, renovacao: 'NAO', renovacao_meses: 12, aviso_dias: 60, caucao: 0, inicio: dayjs(), encargos: [] });
  }, [aberto, contrato, form]);

  const gravar = useMutation({
    mutationFn: (dados: Record<string, unknown>) => gravarContrato(dados, contrato?.id),
    onError: (e) => notificarErro(e, 'Não foi possível gravar o contrato'),
    onSuccess: async (r) => { await refrescar(); aoFechar(r.dados); },
  });
  const submeter = () => {
    const v = { ...form.getFieldsValue(true), encargos: (form.getFieldValue('encargos') as Encargo[] | undefined) ?? [] } as Valores;
    gravar.mutate(dadosContrato(v));
  };

  const imoveisPossiveis = useMemo(() => (imoveis.data ?? []).filter((i) => (tomada || sub ? i.papel === 'ARRENDADO' : i.papel !== 'MEDIACAO')), [imoveis.data, tomada, sub]);
  const arrendaveis = useMemo(() => (fracoes.data ?? []).filter((f: Fracao) => f.natureza === 'HABITACIONAL' || f.natureza === 'ARRECADACAO'), [fracoes.data]);
  const tomadasDaFracao = useMemo(() => (contratos.data ?? []).filter((c) => c.tipo === 'TOMADA' && ['ACTIVO', 'RASCUNHO', 'SUSPENSO'].includes(c.estado) && c.permite_sub), [contratos.data]);
  const pf = inicio ? diasDoPrimeiroPeriodo(inicio.format('YYYY-MM-DD'), periodicidade) : null;
  const previsao = taxas.data && renda > 0
    ? previsaoRenda({ renda, retencao_ip: retencao ?? true, encargos: (encargos ?? []).map((e) => ({ valor: Number(e?.valor ?? 0), incluido_na_renda: !!e?.incluido_na_renda })), ...fiscais }, taxas.data.taxas as never)
    : null;
  const bloqueia = (nome: string) => emVigor && TRAVADOS_EM_VIGOR.includes(nome);
  const op = (m: { valor: string; rotulo: string }[] | undefined) => (m ?? []).map((o) => ({ value: o.valor, label: o.rotulo }));
  const opContas = (contas.data ?? []).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }));

  return (
    <Modal title={contrato ? `Editar contrato ${contrato.numero}` : 'Novo contrato'} open={aberto} onCancel={() => aoFechar()} onOk={() => form.submit()} okText="Gravar" cancelText="Cancelar"
      confirmLoading={gravar.isPending} width={larguraModal(980)} destroyOnHidden>
      <Form form={form} layout="vertical" onFinish={submeter} disabled={gravar.isPending}>
        <p>O número é atribuído na gravação. O contrato prende-se a uma fracção: se o imóvel não tem divisões, crie-lhe a fracção «Única» em Imóveis. Nasce em rascunho; só gera rendas depois de activado.</p>
        {emVigor && <Alert type="info" showIcon style={{ marginBottom: 12 }} title="Contrato em vigor" description="Não se mudam o tipo, o âmbito, as fracções, a outra parte nem o início: termine-o e faça um novo." />}
        <Row gutter={12}>
          <Col xs={24} md={12}><Form.Item name="tipo" label="Tipo" extra="O tipo decide de que lado a empresa está: nos dois primeiros recebe, no «tomado» paga."><Select disabled={bloqueia('tipo')} options={op(listas?.tipos)} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="ambito" label="Âmbito"><Select disabled={bloqueia('ambito')} options={op(listas?.ambitos)} /></Form.Item></Col>
          <Col xs={24} md={12}>
            <Form.Item name="imovel_id" label="Imóvel" rules={[{ required: true, message: 'Escolha o imóvel.' }]}>
              <Select showSearch optionFilterProp="label" disabled={bloqueia('imovel_id')} loading={imoveis.isLoading} placeholder="— imóvel —" onChange={() => form.setFieldsValue({ fracao_id: undefined, fracoes_ids: [], contrato_pai_id: undefined })}
                options={imoveisPossiveis.map((i) => ({ value: i.id, label: `${i.codigo} — ${i.nome}` }))} />
            </Form.Item>
          </Col>
          {ambito === 'FRACCAO' && (
            <Col xs={24} md={12}>
              <Form.Item name="fracao_id" label="Fracção" rules={[{ required: true, message: 'Escolha a fracção.' }]} extra="O contrato prende-se sempre a uma fracção.">
                <Select showSearch optionFilterProp="label" disabled={bloqueia('fracao_id') || !imovelId} loading={fracoes.isLoading} placeholder="— fracção —"
                  options={arrendaveis.map((f) => ({ value: f.id, label: `${f.designacao}${f.estado !== 'DISPONIVEL' ? ` (${f.estado.toLowerCase()})` : ''}${f.renda_referencia ? ` · ref. ${formatarKz(f.renda_referencia)}` : ''}`, disabled: f.estado === 'VENDIDA' }))}
                  onChange={(id) => { const f = arrendaveis.find((x) => x.id === id); if (f?.renda_referencia && !form.getFieldValue('renda')) form.setFieldsValue({ renda: f.renda_referencia }); }} />
              </Form.Item>
            </Col>
          )}
          {ambito === 'PARCIAL' && (
            <Col xs={24} md={12}>
              <Form.Item name="fracoes_ids" label="Fracções (pelo menos duas)" rules={[{ required: true, message: 'Escolha as fracções.' }]}>
                <Select mode="multiple" showSearch optionFilterProp="label" disabled={bloqueia('fracoes_ids') || !imovelId} options={arrendaveis.map((f) => ({ value: f.id, label: f.designacao }))} />
              </Form.Item>
            </Col>
          )}
          {ambito === 'IMOVEL' && <Col xs={24} md={12}><p>Abrange todas as fracções arrendáveis do imóvel, com uma só renda.</p></Col>}
          {sub && (
            <Col xs={24}>
              <Form.Item name="contrato_pai_id" label="Contrato tomado de que depende" extra="O subarrendamento caduca com ele e não pode começar antes nem durar mais. Em branco: usa-se o único contrato tomado da fracção.">
                <Select allowClear disabled={bloqueia('contrato_pai_id')} placeholder="— automático —" options={tomadasDaFracao.map((c) => ({ value: c.id, label: `${c.numero} · ${c.fracoes.join(', ')} · ${c.inicio} a ${c.fim ?? 'sem termo'}` }))} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={12}>
            <Form.Item name="terceiro_id" label={tomada ? 'Senhorio (terceiro)' : 'Inquilino (terceiro)'} rules={[{ required: true, message: tomada ? 'Indique o senhorio.' : 'Indique o inquilino.' }]}>
              <Select showSearch optionFilterProp="label" disabled={bloqueia('terceiro_id')} loading={terceiros.isLoading} placeholder="— terceiro —" options={(terceiros.data ?? []).map((t) => ({ value: t.id, label: `${t.nome}${t.nif ? ` · ${t.nif}` : ''}` }))} />
            </Form.Item>
          </Col>
          <Col xs={24} md={6}><Form.Item name="outra_parte_doc" label="Documento de identificação" extra="O contrato precisa dele."><Input maxLength={100} placeholder="BI n.º 000000000LA000" /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="outra_parte_contacto" label="Contacto"><Input maxLength={150} placeholder="telefone ou email" /></Form.Item></Col>
          <Col xs={24}><Form.Item name="outra_parte_morada" label="Morada da outra parte" extra="Em branco, usa-se a morada da ficha do terceiro."><Input maxLength={300} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="inicio" label="Início" rules={[{ required: true, message: 'Indique a data de início.' }]}><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} disabled={bloqueia('inicio')} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="fim" label="Fim" extra="Em branco = sem termo."><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="renovacao" label="Renovação"><Select options={op(listas?.renovacoes)} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="renovacao_meses" label="Renovação (meses)"><InputNumber min={1} max={120} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="renda" label="Renda (Kz)" rules={[{ required: true, message: 'Indique a renda.' }]}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="periodicidade" label="Periodicidade"><Select options={op(listas?.periodicidades)} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="dia_vencimento" label="Dia de vencimento" extra="Até 28, para não falhar em Fevereiro."><InputNumber min={1} max={28} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="antecipada" label="Momento do pagamento"><Select options={[{ value: true, label: 'Adiantada (início do período)' }, { value: false, label: 'No fim do período' }]} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="pro_rata" label="Primeiro e último período proporcionais" valuePropName="checked" extra={pf && pf.cobertos < pf.total ? `O primeiro período cobre ${pf.cobertos} de ${pf.total} dias.` : 'Desligado, cobra-se sempre o período inteiro (como no sistema anterior).'}><Switch /></Form.Item></Col>
          <Col xs={24} md={6}><Form.Item name="actualizacao" label="Actualização"><Select options={op(listas?.actualizacoes)} /></Form.Item></Col>
          <Col xs={12} md={5}><Form.Item name="actualizacao_valor" label="Valor" extra="% ou Kz, conforme a regra."><InputNumber min={0} precision={4} style={{ width: '100%' }} disabled={actualizacao === 'NAO'} /></Form.Item></Col>
          <Col xs={12} md={5}><Form.Item name="actualizacao_meses" label="A cada (meses)"><InputNumber min={1} max={120} precision={0} style={{ width: '100%' }} disabled={actualizacao === 'NAO'} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="aviso_dias" label="Pré-aviso (dias)"><InputNumber min={0} max={3650} precision={0} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="caucao" label="Caução acordada (Kz)" extra={renda > 0 ? `No máximo seis rendas (${formatarKz(limiteCaucao(renda))}). Recebe-se e devolve-se na ficha do contrato.` : 'No máximo seis rendas.'}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="mora_mensal" label="Juro de mora mensal (%)" extra="Em branco usa a taxa do ano."><InputNumber min={0} max={100} precision={4} style={{ width: '100%' }} /></Form.Item></Col>
          <Col xs={12} md={6}><Form.Item name="doc_tipo" label="Documento a emitir"><Select options={op(listas?.doc_tipos)} disabled={tomada} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="finalidade_fiscal" label="Finalidade fiscal" extra="Decide o Selo (0,1 % habitação, 0,4 % o resto) e o IVA (só o alojamento hoteleiro)."><Select options={op(listas?.finalidades_fiscais)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="isencao_ip" label="Isenção do Imposto Predial" extra="As isenções do OGE são anuais — confirme se ainda vigoram."><Select options={op(listas?.isencoes)} /></Form.Item></Col>
          <Col xs={24} md={8}><Form.Item name="selo_encargo" label="Imposto de Selo"><Select options={op(listas?.selo_encargo)} /></Form.Item></Col>
          <Col xs={24} md={12}><Form.Item name="retencao_ip" label={tomada ? 'A empresa retém o Imposto Predial ao senhorio' : 'O inquilino retém o Imposto Predial'} valuePropName="checked" extra="Retido, o senhorio recebe menos: o Imposto Predial sai da renda."><Switch /></Form.Item></Col>
          {!tomada && <Col xs={24} md={12}><Form.Item name="registo_agt_data" label="Cópia entregue na Repartição" extra="A lei dá 10 dias a contar do início."><DatePicker format="DD/MM/YYYY" style={{ width: '100%' }} /></Form.Item></Col>}
        </Row>
        {tomada && (
          <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="A empresa é a inquilina — contabilização do pagamento"
            description={(
              <>
                <p>A renda sai pela Tesouraria, não por uma factura. Estas contas são as que o documento de pagamento vai usar: o ERP nunca as pré-preenche.</p>
                <Row gutter={12}>
                  <Col xs={24} md={8}><Form.Item name="conta_custo" label="Conta de custo da renda" rules={[{ required: true, message: 'Indique a conta de custo.' }]}><Select showSearch optionFilterProp="label" options={opContas} loading={contas.isLoading} placeholder="— conta —" /></Form.Item></Col>
                  <Col xs={24} md={8}><Form.Item name="conta_retencao" label="Conta da retenção" rules={retencao ? [{ required: true, message: 'Indique a conta da retenção.' }] : []}><Select allowClear showSearch optionFilterProp="label" options={opContas} placeholder="— conta —" /></Form.Item></Col>
                  <Col xs={24} md={8}><Form.Item name="conta_financeira" label="Conta de disponibilidades" extra="Pode escolher-se também no momento do pagamento."><Select allowClear showSearch optionFilterProp="label" options={opContas} placeholder="— conta —" /></Form.Item></Col>
                  <Col xs={24}><Form.Item name="permite_sub" label="O senhorio autoriza subarrendar" valuePropName="checked" extra="Sem autorização escrita não se registam subarrendamentos sobre este contrato."><Switch /></Form.Item></Col>
                </Row>
              </>
            )} />
        )}
        {previsao && (
          <Alert type="info" showIcon style={{ marginBottom: 12 }} title={`Previsão de um período inteiro (taxas de ${ano}, por validar)`}
            description={<span>Base {formatarKz(previsao.base)} · Imposto Predial {formatarKz(previsao.ip)} · Selo {formatarKz(previsao.selo)} (não entra na factura) · IVA {formatarKz(previsao.iva)} · A facturar {formatarKz(previsao.totalFacturar)} · A receber {formatarKz(previsao.totalReceber)}. O servidor recalcula ao gerar a renda.</span>} />
        )}
        <Form.List name="encargos">
          {(campos, { add, remove }) => (
            <>
              <div className="imo-subtitulo">Encargos autónomos</div>
              <p>O que não está incluído na renda vai em linha própria da factura: o inquilino tem de ver o que é renda e o que é água, luz ou condomínio.</p>
              {campos.map((c, i) => (
                <Row gutter={8} key={c.key} align="bottom">
                  <Col xs={24} md={12}><Form.Item name={[c.name, 'designacao']} label={i === 0 ? 'Designação' : undefined}><Input maxLength={150} placeholder="condomínio, água, limpeza…" /></Form.Item></Col>
                  <Col xs={12} md={6}><Form.Item name={[c.name, 'valor']} label={i === 0 ? 'Valor por período' : undefined}><InputNumber min={0} precision={2} style={{ width: '100%' }} /></Form.Item></Col>
                  <Col xs={8} md={4}><Form.Item name={[c.name, 'incluido_na_renda']} label={i === 0 ? 'Incluído na renda' : undefined} valuePropName="checked"><Switch /></Form.Item></Col>
                  <Col xs={4} md={2}><Form.Item><Button danger icon={<DeleteOutlined />} aria-label={`Remover o encargo ${i + 1}`} onClick={() => remove(c.name)} /></Form.Item></Col>
                </Row>
              ))}
              <Space><Button icon={<PlusOutlined />} onClick={() => add({ designacao: '', valor: 0, incluido_na_renda: false })}>Encargo</Button></Space>
            </>
          )}
        </Form.List>
        <Form.Item name="notas" label="Notas" style={{ marginTop: 12 }}><Input.TextArea rows={2} maxLength={5000} /></Form.Item>
      </Form>
    </Modal>
  );
}
