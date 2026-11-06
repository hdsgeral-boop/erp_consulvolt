import { Alert, Button, Card, Col, Form, Input, InputNumber, Modal, Row, Skeleton, Space, Tag, message } from 'antd';
import { useMutation, useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { notificarErro } from '@/utilitarios/erros';
import { CHAVES, gravarTaxas, obterTaxas, validarTaxas } from './api';
import { useRefrescar } from './hooks';
import { GRUPOS_TAXAS, ipEfectivo } from './logica';

const ORIGEM: Record<string, string> = { GRAVADA: 'Gravadas para este ano', HERDADA: 'Herdadas do último ano gravado (ainda não gravadas para este ano)', BASE: 'Valores de arranque (ainda sem nenhum ano gravado)' };

/** Taxas fiscais por ano (imo_taxas_editar, sensível). Editáveis e «por validar»: o ERP não afirma a conformidade legal dos valores. */
export function SeparadorTaxas({ podeEditar }: { podeEditar: boolean }) {
  const [ano, setAno] = useState(new Date().getFullYear());
  const [form] = Form.useForm<Record<string, number | null>>();
  const refrescar = useRefrescar();
  const [validar, setValidar] = useState(false);
  const [nota, setNota] = useState('');
  const q = useQuery({ queryKey: CHAVES.taxas(ano), queryFn: () => obterTaxas(ano) });
  useEffect(() => { if (q.error) notificarErro(q.error, 'Não foi possível carregar as taxas'); }, [q.error]);
  useEffect(() => { if (q.data) form.setFieldsValue(q.data.taxas); }, [q.data, form]);
  const taxa = Form.useWatch('ip_taxa', form);
  const base = Form.useWatch('ip_base', form);

  const gravar = useMutation({
    mutationFn: (v: Record<string, number | null>) => gravarTaxas(ano, v),
    onError: (e) => notificarErro(e, 'Não foi possível gravar as taxas'),
    onSuccess: async () => { message.success(`Taxas de ${ano} gravadas. As rendas já facturadas não mudam.`); await refrescar(); },
  });
  const confirmar = useMutation({
    mutationFn: () => validarTaxas(ano, nota),
    onError: (e) => notificarErro(e, 'Não foi possível registar a validação'),
    onSuccess: async () => { message.success(`Taxas de ${ano} marcadas como validadas.`); setValidar(false); setNota(''); await refrescar(); },
  });
  const d = q.data;

  return (
    <>
      <Space wrap style={{ marginBottom: 12 }}>
        <label htmlFor="imo-taxas-ano">Ano</label>
        <InputNumber id="imo-taxas-ano" min={2000} max={2100} precision={0} value={ano} onChange={(v) => v && setAno(v)} />
        {d && <Tag color={d.estado_validacao === 'VALIDADA' ? 'green' : 'orange'}>{d.estado_validacao === 'VALIDADA' ? 'Validadas' : 'Por validar'}</Tag>}
        {d && <span>{ORIGEM[d.origem]}{d.herdada_de ? ` (${d.herdada_de})` : ''}</span>}
      </Space>
      <Alert type="warning" showIcon style={{ marginBottom: 12 }} title="Valores a validar pelo contabilista ou jurista"
        description={<>As taxas vêm do arranque do sistema anterior e são editáveis por ano. <b>O ERP não confirma a sua conformidade legal.</b>{d?.estado_validacao === 'VALIDADA' && d.validacao_nota ? ` Confirmação registada: ${d.validacao_nota}${d.validada_por ? ` (registada por ${d.validada_por})` : ''}.` : ''}</>} />
      {!d ? <Skeleton active /> : (
        <Form form={form} layout="vertical" disabled={!podeEditar} onFinish={(v) => gravar.mutate(v)}>
          {GRUPOS_TAXAS.map((g) => (
            <Card key={g.titulo} size="small" title={g.titulo} style={{ marginBottom: 12 }}>
              <Row gutter={12}>
                {g.campos.map((c) => (
                  <Col xs={24} sm={12} lg={6} key={c.chave}>
                    <Form.Item name={c.chave} label={c.rotulo} extra={c.chave === 'ip_base' && taxa != null && base != null ? `Efectivo: ${ipEfectivo(Number(taxa), Number(base))} %` : c.ajuda}>
                      <InputNumber min={0} max={c.unidade === '%' ? 100 : undefined} precision={c.unidade === '%' ? 4 : c.unidade === 'Kz' ? 2 : 0} style={{ width: '100%' }} />
                    </Form.Item>
                  </Col>
                ))}
              </Row>
            </Card>
          ))}
          {podeEditar && (
            <Space wrap>
              <Button type="primary" htmlType="submit" loading={gravar.isPending}>{`Gravar taxas de ${ano}`}</Button>
              {d.origem === 'GRAVADA' && d.estado_validacao === 'POR_VALIDAR' && <Button onClick={() => setValidar(true)}>Marcar como validadas…</Button>}
            </Space>
          )}
        </Form>
      )}
      <Modal title={`Registar a validação das taxas de ${ano}`} open={validar} onCancel={() => setValidar(false)} onOk={() => confirmar.mutate()} okText="Registar" cancelText="Cancelar" confirmLoading={confirmar.isPending}>
        <p>A confirmação é feita fora do ERP, pelo contabilista ou jurista. Aqui fica só registado quem confirmou. Qualquer alteração posterior volta a marcar as taxas como «por validar».</p>
        <Input value={nota} onChange={(e) => setNota(e.target.value)} maxLength={300} placeholder="Nome e função de quem confirmou" aria-label="Quem confirmou as taxas" />
      </Modal>
    </>
  );
}
