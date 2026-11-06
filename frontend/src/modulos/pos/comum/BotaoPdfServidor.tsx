import { FilePdfOutlined } from '@ant-design/icons';
import { Button, Tooltip, type ButtonProps } from 'antd';
import { useState } from 'react';
import { useMensagem, type ObterPedido } from '@/componentes/impressao';
import { notificarErro } from '@/utilitarios/erros';
import { descarregarPdfServidor, type TipoPdfPos } from './pdfServidor';

/**
 * Botão «PDF» dos impressos A4 do POS: gera o PDF no servidor (mesmo conteúdo do «Imprimir», cabeçalho da empresa e «Página X de Y»)
 * e descarrega-o. Não substitui o «Guardar como PDF» do navegador, que continua a existir no botão de impressão.
 */
export function BotaoPdfServidor({ obterPedido, tipo, texto = 'Descarregar PDF', desactivado, tamanho }: { obterPedido: ObterPedido; tipo: TipoPdfPos; texto?: string; desactivado?: boolean; tamanho?: ButtonProps['size'] }) {
  const mensagem = useMensagem();
  const [aGerar, setAGerar] = useState(false);
  const gerar = async () => {
    setAGerar(true);
    try {
      const pedido = await obterPedido();
      if (!pedido) return;
      const nome = await descarregarPdfServidor(tipo, pedido);
      mensagem.success({ content: `PDF gerado: ${nome}`, duration: 4 });
    } catch (e) {
      notificarErro(e, 'Não foi possível gerar o PDF');
    } finally {
      setAGerar(false);
    }
  };
  return (
    <Tooltip title="Gera o PDF A4 no servidor, com o cabeçalho da empresa e a numeração das páginas.">
      <Button size={tamanho} icon={<FilePdfOutlined />} disabled={desactivado} loading={aGerar} onClick={() => void gerar()}>
        {texto}
      </Button>
    </Tooltip>
  );
}
