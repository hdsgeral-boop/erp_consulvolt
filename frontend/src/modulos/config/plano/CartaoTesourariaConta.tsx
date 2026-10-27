/**
 * Bloco «Conta da classe 3 — é de tesouraria?» do formulário do plano de contas: só aparece nas contas de movimento
 * da classe 3. Marcada como cartão, pede o banco associado (obrigatório, com sugestões dos bancos registados) e,
 * opcionalmente, os últimos dígitos do cartão — nunca o número completo.
 */
import { Alert, AutoComplete, Col, Form, Input, Radio, Row, Typography } from 'antd';
import { SeccaoFormulario } from '@/componentes/formularios';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { eCodigoElegivelParaCartao, type ContaCartao } from './cartaoTesouraria';

export const CHAVE_DADOS_CARTAO = ['contab', 'plano-contas', 'dados-cartao'];

/** Dados do cartão gravados de uma conta da classe 3 (GET /tesouraria/contas-cartao/{codigo}). */
export function useDadosCartaoConta(codigo: string | undefined, tipo: string | undefined, marcada: boolean | undefined) {
  return useQuery({
    queryKey: [...CHAVE_DADOS_CARTAO, codigo],
    queryFn: () => obter<ContaCartao>(`/tesouraria/contas-cartao/${encodeURIComponent(codigo ?? '')}`),
    enabled: !!codigo && !!marcada && eCodigoElegivelParaCartao(codigo, tipo),
    retry: false,
    staleTime: 0,
  });
}

function useSugestoesBancos(activo: boolean) {
  return useQuery({
    queryKey: [...CHAVE_DADOS_CARTAO, 'sugestoes-bancos'],
    queryFn: () => obter<string[]>('/tesouraria/contas-cartao/sugestoes-bancos'),
    enabled: activo,
    retry: false,
    staleTime: 300_000,
  });
}

/** Dentro de um <Form> com os campos codigo, tipo e cartao_tesouraria: mostra o bloco só para contas da classe 3 de movimento. */
export function CartaoTesourariaConta() {
  const form = Form.useFormInstance();
  const codigo = Form.useWatch('codigo', form) as string | undefined;
  const tipo = Form.useWatch('tipo', form) as string | undefined;
  const marcada = Form.useWatch('cartao_tesouraria', form) as boolean | undefined;
  const elegivel = eCodigoElegivelParaCartao(codigo, tipo);
  const sugestoes = useSugestoesBancos(elegivel && !!marcada);
  if (!elegivel) return null;
  return (
    <SeccaoFormulario titulo={<><IconeFa nome="credit-card" style={{ color: '#7c3aed' }} /> Conta da classe 3 — é de tesouraria?</>}>
      <Form.Item name="cartao_tesouraria" initialValue={false}>
        <Radio.Group
          options={[
            { value: false, label: 'Não — conta de terceiros normal' },
            { value: true, label: 'Sim — cartão (débito ou crédito)' },
          ]}
        />
      </Form.Item>
      {marcada && (
        <>
          <Row gutter={12}>
            <Col xs={24} sm={12}>
              <Form.Item name="cartao_numero" label="Nº do cartão (opcional)"><Input placeholder="Ex.: **** 4417" maxLength={20} /></Form.Item>
            </Col>
            <Col xs={24} sm={12}>
              <Form.Item name="cartao_banco" label="Banco associado" rules={[{ required: true, message: 'Indique o banco associado ao cartão.' }]}>
                <AutoComplete
                  options={(sugestoes.data ?? []).map((n) => ({ value: n }))}
                  filterOption={(entrada, opcao) => String(opcao?.value ?? '').toLowerCase().includes(entrada.toLowerCase())}
                  placeholder="Ex.: Banco Angolano de Investimentos"
                  maxLength={100}
                />
              </Form.Item>
            </Col>
          </Row>
          <Alert type="warning" showIcon style={{ marginBottom: 12 }}
            message="Só os últimos dígitos" description="Escreva só os últimos dígitos do cartão. O número completo (PAN) não deve ficar registado no ERP." />
          <Typography.Text type="secondary" style={{ display: 'block', fontSize: 12 }}>
            O cartão passa a aparecer na Tesouraria (meios de pagamento, recebimentos/pagamentos, mapas e conciliação) e entra abatido nas disponibilidades.
          </Typography.Text>
        </>
      )}
    </SeccaoFormulario>
  );
}
