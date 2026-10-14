/**
 * Bloco «Dados bancários da conta» do formulário do plano de contas (legado novo app_v2.js:8893-8905, #acc-banco-group):
 * só aparece nas contas 42/43 de movimento. Banco (com sugestões dos bancos registados, _accSugerirBancos), balcão,
 * n.º de conta, IBAN (validado), SWIFT/BIC e titular (vazio = nome da empresa). Os dados saem nas cartas de pagamento.
 */
import { AutoComplete, Col, Form, Input, Row, Typography } from 'antd';
import { useQuery } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { IconeFa } from '@/componentes/fluxos';
import { eContaBancaria, ibanContaValido, swiftValido, type ContaBancaria } from './contasBancarias';

export const CHAVE_DADOS_BANCARIOS = ['contab', 'plano-contas', 'dados-bancarios'];

/** Dados bancários gravados de uma conta 42/43 (GET /tesouraria/contas-bancarias/{codigo}). */
export function useDadosBancariosConta(codigo: string | undefined, tipo: string | undefined) {
  return useQuery({
    queryKey: [...CHAVE_DADOS_BANCARIOS, codigo],
    queryFn: () => obter<ContaBancaria>(`/tesouraria/contas-bancarias/${encodeURIComponent(codigo ?? '')}`),
    enabled: !!codigo && eContaBancaria(codigo, tipo),
    retry: false,
    staleTime: 0,
  });
}

function useSugestoesBancos(activo: boolean) {
  return useQuery({
    queryKey: [...CHAVE_DADOS_BANCARIOS, 'sugestoes-bancos'],
    queryFn: () => obter<string[]>('/tesouraria/contas-bancarias/sugestoes-bancos'),
    enabled: activo,
    retry: false,
    staleTime: 300_000,
  });
}

/** Dentro de um <Form> com os campos codigo e tipo: mostra o bloco só para contas bancárias de movimento. */
export function DadosBancariosConta() {
  const form = Form.useFormInstance();
  const codigo = Form.useWatch('codigo', form) as string | undefined;
  const tipo = Form.useWatch('tipo', form) as string | undefined;
  const visivel = eContaBancaria(codigo, tipo);
  const sugestoes = useSugestoesBancos(visivel);
  if (!visivel) return null;
  return (
    <div style={{ border: '1px solid #e2e8f0', borderRadius: 4, padding: '1rem', background: '#f8fafc', marginBottom: 16 }}>
      <Typography.Title level={5} style={{ margin: '0 0 0.25rem', fontSize: '0.95rem' }}>
        <IconeFa nome="university" style={{ color: '#0891b2' }} /> Dados bancários da conta
      </Typography.Title>
      <Typography.Text type="secondary" style={{ display: 'block', marginBottom: 12, fontSize: 12 }}>
        Saem no cabeçalho da carta de ordem de pagamento. Sem o IBAN preenchido, esta conta não pode emitir cartas.
      </Typography.Text>
      <Row gutter={12}>
        <Col xs={24} sm={12}>
          <Form.Item name="banco_nome" label="Banco">
            <AutoComplete
              options={(sugestoes.data ?? []).map((n) => ({ value: n }))}
              filterOption={(entrada, opcao) => String(opcao?.value ?? '').toLowerCase().includes(entrada.toLowerCase())}
              placeholder="Ex.: Banco Angolano de Investimentos"
              maxLength={100}
            />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item name="banco_balcao" label="Balcão / Agência"><Input placeholder="Ex.: Agência Talatona" maxLength={100} /></Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item name="banco_numero_conta" label="Nº de conta"><Input placeholder="Ex.: 123456789.10.001" maxLength={40} /></Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item
            name="banco_iban"
            label="IBAN"
            normalize={(v?: string) => (v ? v.toUpperCase() : v)}
            rules={[{ validator: (_, v?: string) => (!v?.trim() || ibanContaValido(v) ? Promise.resolve() : Promise.reject(new Error('IBAN inválido: AO + 23 dígitos (ou os 21 dígitos do NIB), ou um IBAN estrangeiro válido.'))) }]}
          >
            <Input placeholder="AO06 0000 0000 0000 0000 0000 0" maxLength={50} style={{ fontFamily: 'monospace' }} />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item
            name="banco_swift"
            label="SWIFT / BIC"
            normalize={(v?: string) => (v ? v.toUpperCase() : v)}
            rules={[{ validator: (_, v?: string) => (!v?.trim() || swiftValido(v) ? Promise.resolve() : Promise.reject(new Error('SWIFT/BIC inválido: 8 ou 11 caracteres (ex.: BAIPAOLU).'))) }]}
          >
            <Input placeholder="Ex.: BAIPAOLU" maxLength={15} />
          </Form.Item>
        </Col>
        <Col xs={24} sm={12}>
          <Form.Item name="banco_titular" label="Titular da conta"><Input placeholder="Deixe vazio para usar o nome da empresa" maxLength={150} /></Form.Item>
        </Col>
      </Row>
    </div>
  );
}
