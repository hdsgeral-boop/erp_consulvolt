import { Badge, Space, Typography } from 'antd';
import { useEffect, useState } from 'react';
import { useSessao } from '@/sessao/SessaoContexto';
import { formatarDataHora } from '@/utilitarios/formatacao';
import { useTerminais } from './dados';
import { SeletorTerminal } from './Filtros';
import type { Terminal, TipoTerminal } from './tipos';

/**
 * Terminal de trabalho de um ecrã de caixa (lavandaria, hotelaria): as operações com dinheiro correm na sessão aberta
 * desse terminal (abre-se e fecha-se na frente de caixa). A escolha fica memorizada neste posto.
 */
export function useTerminalDeTrabalho(tipo: TipoTerminal) {
  const { empresa } = useSessao();
  const terminais = useTerminais();
  const chave = `erp.pos.terminal.${tipo}.${empresa?.id ?? 0}`;
  const [id, setId] = useState<number | undefined>(() => {
    try {
      return Number(window.localStorage.getItem(chave)) || undefined;
    } catch {
      return undefined;
    }
  });
  const doTipo = (terminais.data ?? []).filter((t) => t.tipo === tipo);
  useEffect(() => {
    if (!terminais.data) return;
    if (!id || !doTipo.some((t) => t.id === id)) {
      const preferido = doTipo.find((t) => t.ativo && t.sessao_aberta) ?? doTipo.find((t) => t.ativo);
      setId(preferido?.id);
    }
  }, [terminais.data]);
  const escolher = (novo: number | undefined) => {
    setId(novo);
    try {
      if (novo) window.localStorage.setItem(chave, String(novo));
    } catch {
      /* sem armazenamento: só nesta página */
    }
  };
  const terminal: Terminal | undefined = doTipo.find((t) => t.id === id);
  return { terminal, terminalId: id, escolher, sessaoId: terminal?.sessao_aberta?.id ?? null, carregando: terminais.isLoading };
}

export function BarraSessao({ tipo, terminal, terminalId, escolher }: { tipo: TipoTerminal; terminal: Terminal | undefined; terminalId: number | undefined; escolher: (id: number | undefined) => void }) {
  return (
    <Space wrap>
      <SeletorTerminal tipo={tipo} value={terminalId} onChange={escolher} allowClear={false} />
      {terminal &&
        (terminal.sessao_aberta ? (
          <Badge status="processing" text={`Sessão ${terminal.sessao_aberta.codigo_sessao} · ${terminal.sessao_aberta.nome_operador ?? '—'} · ${formatarDataHora(terminal.sessao_aberta.aberto_em)}`} />
        ) : (
          <Typography.Text type="warning">Sem sessão aberta: abra-a na frente de caixa para receber, facturar e entregar.</Typography.Text>
        ))}
    </Space>
  );
}
