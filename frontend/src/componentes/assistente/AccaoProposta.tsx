import { mensagemParaUtilizador } from '@/utilitarios/mensagensErro';
import { useState } from 'react';
import { Button, Popconfirm, Space, Tag, Typography, message } from 'antd';
import { useNavigate } from 'react-router';
import { enviar } from '@/api/cliente';
import { ErroApi } from '@/api/tipos';
import { useSessao } from '@/sessao/SessaoContexto';
import { notificarErro } from '@/utilitarios/erros';
import { decidirAccao } from './api';
import { resolverAccao } from './regras';
import type { AccaoIA } from './tipos';

const ROTULO_ESTADO: Record<string, { texto: string; cor: string }> = {
  PENDENTE: { texto: 'Por confirmar', cor: 'gold' },
  EXECUTADA: { texto: 'Executada', cor: 'green' },
  REJEITADA: { texto: 'Rejeitada', cor: 'default' },
  FALHOU: { texto: 'Falhou', cor: 'red' },
};

interface Props {
  accao: AccaoIA;
  /** Chamada depois de a decisão ficar registada no servidor (para recarregar a conversa). */
  aoDecidir: () => void | Promise<void>;
  /** Chamada quando a acção abre um ecrã (o painel do assistente fecha-se). */
  aoNavegar?: () => void;
}

/**
 * Uma acção proposta pela IA. NADA é executado sem o utilizador clicar: as escritas (POST/PUT/DELETE) exigem
 * «Confirmar» seguido da confirmação explícita («Sim, executar»); «Rejeitar» só regista a decisão. Abrir um ecrã
 * também precisa de um clique. Os pedidos propostos só passam se `resolverAccao` os aceitar (caminho interno seguro).
 */
export function AccaoProposta({ accao, aoDecidir, aoNavegar }: Props) {
  const { menu } = useSessao();
  const navegar = useNavigate();
  const [aTrabalhar, setATrabalhar] = useState(false);
  const resolvida = resolverAccao(accao, menu ?? []);
  const pendente = accao.estado === 'PENDENTE';
  const estado = ROTULO_ESTADO[accao.estado];

  /** Regista a decisão no servidor; uma falha aqui não desfaz o que já foi feito, só avisa. */
  async function registar(estadoFinal: 'EXECUTADA' | 'REJEITADA' | 'FALHOU', resultado?: Record<string, unknown>) {
    try {
      await decidirAccao(accao.id, estadoFinal, resultado);
    } catch (e) {
      if (e instanceof ErroApi && e.codigo === 'IA_ACCAO_DECIDIDA') return;
      message.warning('A operação foi tratada, mas não foi possível registar a decisão no assistente.');
    }
    await aoDecidir();
  }

  async function rejeitar() {
    setATrabalhar(true);
    try {
      await registar('REJEITADA');
    } finally {
      setATrabalhar(false);
    }
  }

  async function executar() {
    if (resolvida.tipo !== 'pedido') return;
    setATrabalhar(true);
    try {
      let mensagemOk = 'Operação concluída.';
      try {
        const r = await enviar<unknown>(resolvida.metodo.toLowerCase() as 'post' | 'put' | 'delete', resolvida.url, resolvida.dados);
        mensagemOk = r.mensagem || mensagemOk;
      } catch (e) {
        notificarErro(e);
        await registar('FALHOU', { mensagem: mensagemParaUtilizador(e) });
        return;
      }
      message.success(mensagemOk);
      await registar('EXECUTADA', { mensagem: mensagemOk });
    } finally {
      setATrabalhar(false);
    }
  }

  async function abrirEcra() {
    if (resolvida.tipo !== 'navegar') return;
    navegar(resolvida.caminho);
    if (pendente) await registar('EXECUTADA', { mensagem: `Aberto ${resolvida.rotulo}` });
    aoNavegar?.();
  }

  const detalhes = Object.keys(accao.dados ?? {}).length > 0 && resolvida.tipo === 'pedido';

  return (
    <div style={{ marginTop: 10 }} data-testid="accao-ia">
      <Space size={6} wrap align="start">
        <Typography.Text strong>{accao.resumo}</Typography.Text>
        {estado && accao.tipo !== 'navegar' && <Tag color={estado.cor}>{estado.texto}</Tag>}
      </Space>
      {detalhes && (
        <details style={{ marginTop: 4 }}>
          <summary style={{ cursor: 'pointer' }}>Ver o que vai ser enviado</summary>
          <pre style={{ margin: '4px 0 0', maxHeight: 180, overflow: 'auto', fontSize: 12 }}>{JSON.stringify(accao.dados, null, 2)}</pre>
        </details>
      )}
      <div style={{ marginTop: 6 }}>
        {resolvida.tipo === 'invalida' && pendente && (
          <Space wrap>
            <Typography.Text type="secondary">{resolvida.motivo}</Typography.Text>
            <Button size="small" loading={aTrabalhar} onClick={() => void rejeitar()}>Rejeitar</Button>
          </Space>
        )}
        {resolvida.tipo === 'invalida' && !pendente && accao.tipo === 'navegar' && <Typography.Text type="secondary">{resolvida.motivo}</Typography.Text>}
        {resolvida.tipo === 'navegar' && (
          <Button size="small" type="primary" ghost onClick={() => void abrirEcra()}>Abrir {resolvida.rotulo}</Button>
        )}
        {resolvida.tipo === 'pedido' && pendente && (
          <Space wrap>
            <Popconfirm
              title="Confirmar esta operação?"
              description="Só é executada depois de confirmar."
              okText="Sim, executar"
              cancelText="Não"
              onConfirm={() => executar()}
            >
              <Button size="small" type="primary" loading={aTrabalhar}>Confirmar</Button>
            </Popconfirm>
            <Button size="small" disabled={aTrabalhar} onClick={() => void rejeitar()}>Rejeitar</Button>
          </Space>
        )}
      </div>
    </div>
  );
}
