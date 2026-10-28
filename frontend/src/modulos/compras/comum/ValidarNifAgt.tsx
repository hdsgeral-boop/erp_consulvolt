import { Button, Descriptions, Modal, Tooltip, message } from 'antd';
import { SearchOutlined } from '@ant-design/icons';
import { useMutation } from '@tanstack/react-query';
import { obter } from '@/api/cliente';
import { notificarErro } from '@/utilitarios/erros';

interface RespostaNifAgt {
  encontrado: boolean;
  nif?: string;
  nome?: string;
  tipo?: string;
  estado?: string;
  inadimplente?: string;
  regime_iva?: string;
  residente_fiscal?: string;
  mensagem?: string;
}

/**
 * Botão «Validar NIF na AGT»: consulta o Portal do Contribuinte (GET /terceiros/consulta-nif-agt — servidor-a-servidor,
 * nunca directo do browser) e mostra o resultado (nome, tipo, estado…) ou o aviso de não encontrado.
 */
export function BotaoValidarNifAgt({ nif, tamanho = 'middle', apenasIcone = false }: { nif?: string | null; tamanho?: 'small' | 'middle'; apenasIcone?: boolean }) {
  const consulta = useMutation({
    mutationFn: () => obter<RespostaNifAgt>('/terceiros/consulta-nif-agt', { nif }),
    onSuccess: (r) => {
      if (!r.encontrado) {
        void message.warning(r.mensagem ?? 'NIF não encontrado na AGT.');
        return;
      }
      Modal.info({
        title: 'NIF validado no Portal do Contribuinte (AGT)',
        content: (
          <Descriptions column={1} size="small" bordered style={{ marginTop: 12 }}>
            <Descriptions.Item label="NIF">{r.nif}</Descriptions.Item>
            <Descriptions.Item label="Nome">{r.nome}</Descriptions.Item>
            <Descriptions.Item label="Tipo">{r.tipo}</Descriptions.Item>
            <Descriptions.Item label="Estado">{r.estado}</Descriptions.Item>
            {r.inadimplente && <Descriptions.Item label="Inadimplente">{r.inadimplente}</Descriptions.Item>}
            {r.regime_iva && <Descriptions.Item label="Regime de IVA">{r.regime_iva}</Descriptions.Item>}
            {r.residente_fiscal && <Descriptions.Item label="Residência fiscal">{r.residente_fiscal}</Descriptions.Item>}
          </Descriptions>
        ),
      });
    },
    onError: (e) => notificarErro(e, 'Não foi possível validar o NIF na AGT'),
  });
  return (
    <Tooltip title="Validar o NIF no Portal do Contribuinte da AGT">
      <Button
        size={tamanho}
        icon={<SearchOutlined />}
        loading={consulta.isPending}
        disabled={!nif?.trim()}
        onClick={() => consulta.mutate()}
        aria-label="Validar NIF na AGT"
      >
        {!apenasIcone && 'Validar NIF na AGT'}
      </Button>
    </Tooltip>
  );
}
