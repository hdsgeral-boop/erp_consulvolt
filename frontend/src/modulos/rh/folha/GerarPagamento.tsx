import { Alert, Button, Modal, Space, Typography } from 'antd';
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { IconeFa } from '@/componentes/fluxos';
import { larguraModal } from '@/componentes/responsivo';
import { useSessao } from '@/sessao/SessaoContexto';
import type { PeriodoSalarial } from '../api';
import { CartasPeriodo } from '../comum/Cartas';

/**
 * «Gerar Pagamento de Salários» (legado `gerarPagamentoSalarios`, js/modules/rh/folha_salarios.js:323): só depois da
 * integração contabilística (cria os Salários a Pagar); abre o pagamento com os salários líquidos pendentes do período.
 * No sistema novo o documento de pagamento nasce da carta de pagamento (serviço ServicoPagamentoSalarios, regras no
 * servidor): emitir a carta (conta 43) e «Gerar pagamento» cria o documento PENDENTE na Tesouraria.
 */
export function BotaoGerarPagamento({ periodo, texto = 'Gerar Pagamento de Salários' }: { periodo: PeriodoSalarial; texto?: string }) {
  const { pode } = useSessao();
  const navegar = useNavigate();
  const [aberto, setAberto] = useState(false);
  if (!pode('processamento_integrate', 'teso_doc_emitir')) return null;

  const abrir = () => {
    if (!periodo.contabilizado) {
      Modal.warning({
        title: 'Pagamento ainda não disponível',
        content: `O processamento ${periodo.mes_ano} ainda não foi integrado na contabilidade. Integre-o em Processamentos para criar os Salários a Pagar e depois gere o pagamento.`,
        okText: 'Fechar',
      });
      return;
    }
    setAberto(true);
  };

  return (
    <>
      <Button type="primary" style={{ background: '#16a34a', borderColor: '#16a34a' }} icon={<IconeFa nome="money-check-alt" />} onClick={abrir}
        title="Abre a Tesouraria com o período salarial e os salários a pagar pendentes">{texto}</Button>
      <Modal open={aberto} width={larguraModal(980)} onCancel={() => setAberto(false)} destroyOnHidden
        title={<Space><IconeFa nome="money-check-alt" />Pagamento de salários — {periodo.mes_ano}</Space>}
        footer={
          <Space wrap>
            {pode('teso_gestao_pagamentos_view', 'teso_doc_emitir') && (
              <Button icon={<IconeFa nome="university" />} onClick={() => navegar('/m/teso/teso_gestao_pagamentos?tipo=PAGAMENTO')}>Abrir Pagamentos na Tesouraria</Button>
            )}
            <Button onClick={() => setAberto(false)}>Fechar</Button>
          </Space>
        }>
        <Alert type="info" showIcon style={{ marginBottom: 12 }} message="Salários a pagar pendentes"
          description="1) Emita a carta de pagamento (conta bancária 43) com os salários líquidos ainda sem carta; 2) em «Gerar pagamento» o documento de pagamento é criado na Tesouraria (fica PENDENTE; a integração no diário faz-se na Tesouraria)." />
        <Typography.Paragraph type="secondary" style={{ marginBottom: 8 }}>Cartas de pagamento do período:</Typography.Paragraph>
        <CartasPeriodo periodo={periodo} />
      </Modal>
    </>
  );
}
