import { Alert, Button, Form, Select, Skeleton, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect } from 'react';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES_CT, gravarConfiguracao, listarArtigos, listarContas, obterConfiguracao } from './apiContratos';
import { useRefrescar } from './hooks';

const CONTAS = [
  { campo: 'conta_caucao_passivo', rotulo: 'Cauções de terceiros (passivo)', ajuda: 'Caução recebida dos inquilinos e dos reservantes de áreas comuns, a devolver. Usada nos contratos em que a empresa é senhoria.' },
  { campo: 'conta_caucao_receita', rotulo: 'Caução retida (receita)', ajuda: 'Onde vai a parte da caução retida por danos, com motivo escrito.' },
  { campo: 'conta_caucao_activo', rotulo: 'Cauções prestadas (activo)', ajuda: 'Caução entregue aos senhorios nos contratos tomados.' },
  { campo: 'conta_ip_credito', rotulo: 'Imposto Predial retido (crédito de imposto)', ajuda: 'Imposto retido pelo inquilino à renda: crédito de imposto a receber. A validar pelo contabilista.' },
] as const;

const CONTAS_NEGOCIOS = [
  { campo: 'conta_compra_contrapartida', rotulo: 'Compra de imóveis: contrapartida (credores de imobilizado)', ajuda: 'A aquisição lança D conta do imobilizado da categoria / C esta conta, pelo custo de aquisição. O pagamento ao vendedor, ao Estado e ao notário regista-se na Tesouraria contra ela.' },
  { campo: 'conta_mais_valia', rotulo: 'Mais-valia na alienação de imóveis (ganho)', ajuda: 'Onde vai o ganho quando o preço da venda é maior do que o valor líquido contabilístico do bem. A validar pelo contabilista.' },
  { campo: 'conta_menos_valia', rotulo: 'Menos-valia na alienação de imóveis (perda)', ajuda: 'Onde vai a perda quando o preço da venda é menor do que o valor líquido contabilístico do bem. A validar pelo contabilista.' },
] as const;

/** Configuração de contabilização do módulo imobiliário (imo_taxas_editar): artigo de serviço «Renda» e contas da caução e do imposto retido. O ERP não pré-preenche nada. */
export function SeparadorConfiguracao({ podeEditar }: { podeEditar: boolean }) {
  const [form] = Form.useForm<Record<string, number | string | null>>();
  const refrescar = useRefrescar();
  const q = useQuery({ queryKey: CHAVES_CT.configuracao, queryFn: obterConfiguracao });
  const artigos = useQuery({ queryKey: CHAVES_CT.artigos, queryFn: listarArtigos, enabled: podeEditar });
  const contas = useQuery({ queryKey: CHAVES_CT.contas, queryFn: listarContas, enabled: podeEditar });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar a configuração'); }, [q.error]);
  useEffect(() => {
    const c = q.data;
    if (!c) return;
    form.setFieldsValue({ produto_renda_id: c.produto_renda?.id ?? null, produto_renda_alojamento_id: c.produto_renda_alojamento?.id ?? null, produto_reserva_id: c.produto_reserva?.id ?? null,
      conta_caucao_passivo: c.conta_caucao_passivo?.codigo ?? null, conta_caucao_activo: c.conta_caucao_activo?.codigo ?? null, conta_caucao_receita: c.conta_caucao_receita?.codigo ?? null, conta_ip_credito: c.conta_ip_credito?.codigo ?? null,
      produto_venda_imovel_id: c.produto_venda_imovel?.id ?? null, conta_compra_contrapartida: c.conta_compra_contrapartida?.codigo ?? null, conta_mais_valia: c.conta_mais_valia?.codigo ?? null, conta_menos_valia: c.conta_menos_valia?.codigo ?? null });
  }, [q.data, form]);
  const gravar = useMutation({
    mutationFn: (v: Record<string, unknown>) => gravarConfiguracao(v), onError: (e) => notificarErro(e, 'Não foi possível gravar a configuração'),
    onSuccess: async () => { message.success('Configuração gravada.'); await refrescar(); },
  });
  const d = q.data;
  const opArtigos = (artigos.data ?? []).map((a) => ({ value: a.id, label: `${a.codigo} — ${a.nome} (IVA ${a.taxa_imposto} %)` }));
  const opContas = (contas.data ?? []).map((c) => ({ value: c.codigo, label: `${c.codigo} — ${c.descricao ?? ''}` }));

  return !d ? <Skeleton active /> : (
    <>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Sem artigo e sem contas não se factura nem se movimenta a caução" description={d.aviso} />
      <Form form={form} layout="vertical" disabled={!podeEditar} onFinish={(v) => gravar.mutate(v)} style={{ maxWidth: 720 }}>
        <Form.Item name="produto_renda_id" label="Artigo de serviço «Renda» (IVA 0 %, com motivo de isenção)" extra="É o artigo das linhas da factura da renda. Um arrendamento está isento de IVA.">
          <Select allowClear showSearch optionFilterProp="label" loading={artigos.isLoading} placeholder="— artigo —" options={opArtigos} />
        </Form.Item>
        <Form.Item name="produto_renda_alojamento_id" label="Artigo de serviço «Renda» do alojamento (com IVA)" extra="Só se há contratos de alojamento hoteleiro, tributados em IVA.">
          <Select allowClear showSearch optionFilterProp="label" loading={artigos.isLoading} placeholder="— artigo —" options={opArtigos} />
        </Form.Item>
        <Form.Item name="produto_reserva_id" label="Artigo de serviço das reservas de áreas comuns (IVA 0 %)" extra="É o artigo da factura da utilização do espaço e da factura da caução retida por danos. A receita vai pela conta do artigo; o tratamento do IVA destes serviços deve ser validado pelo contabilista.">
          <Select allowClear showSearch optionFilterProp="label" loading={artigos.isLoading} placeholder="— artigo —" options={opArtigos} />
        </Form.Item>
        <Form.Item name="produto_venda_imovel_id" label="Artigo de serviço da venda de imóveis (IVA 0 %)" extra="É o artigo da linha da factura da venda de um imóvel ou fracção. A transmissão está isenta de IVA; a receita vai pela conta do artigo (que o lançamento da alienação estorna para deixar só a mais ou menos-valia).">
          <Select allowClear showSearch optionFilterProp="label" loading={artigos.isLoading} placeholder="— artigo —" options={opArtigos} />
        </Form.Item>
        {[...CONTAS_NEGOCIOS, ...CONTAS].map((c) => (
          <Form.Item key={c.campo} name={c.campo} label={c.rotulo} extra={c.ajuda}>
            <Select allowClear showSearch optionFilterProp="label" loading={contas.isLoading} placeholder="— conta —" options={opContas} />
          </Form.Item>
        ))}
        {podeEditar && <Button type="primary" htmlType="submit" loading={gravar.isPending}>Gravar a configuração</Button>}
      </Form>
    </>
  );
}
