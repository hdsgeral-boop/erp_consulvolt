import { Card, Input, Modal, Space, Typography, message } from 'antd';
import type { CSSProperties, ReactNode } from 'react';
import { IconeFa } from '@/componentes/fluxos/IconeFa';

/** Mínimo de caracteres do motivo (o servidor valida min:5). */
export const MINIMO_MOTIVO = 5;

export function motivoValido(m: string): boolean {
  return m.trim().length >= MINIMO_MOTIVO;
}

/**
 * Confirmação com motivo obrigatório (anular, desintegrar/anular integração, repor). O pedido só segue com o motivo
 * preenchido; a promessa de `aoConfirmar` mantém o botão «a carregar».
 */
export function pedirMotivo({ titulo, texto, textoOk = 'Confirmar', aoConfirmar }: { titulo: ReactNode; texto?: ReactNode; textoOk?: string; aoConfirmar: (motivo: string) => Promise<unknown> | unknown }) {
  let motivo = '';
  Modal.confirm({
    title: titulo,
    content: (
      <Space direction="vertical" style={{ width: '100%' }}>
        {texto && <Typography.Text>{texto}</Typography.Text>}
        <Input.TextArea rows={2} maxLength={500} placeholder="Motivo (obrigatório)" aria-label="Motivo" onChange={(e) => { motivo = e.target.value; }} />
      </Space>
    ),
    okText: textoOk,
    okButtonProps: { danger: true },
    cancelText: 'Cancelar',
    onOk: () => (motivoValido(motivo) ? aoConfirmar(motivo.trim()) : Promise.reject(message.error(`Indique o motivo (mínimo ${MINIMO_MOTIVO} caracteres).`))),
  });
}

/**
 * Cartão-título das secções da Tesouraria do legado (ex.: «Histórico / Descontabilização», «Integração Contabilística»,
 * «Emissão de Mapas de Tesouraria»): barra de cor à esquerda, fundo suave, ícone, título, texto e acções à direita.
 */
export function BannerTeso({ icone, titulo, texto, cor, fundo, corTitulo, accoes, style }: {
  icone: string; titulo: ReactNode; texto?: ReactNode; cor: string; fundo: string; corTitulo?: string; accoes?: ReactNode; style?: CSSProperties;
}) {
  return (
    <Card style={{ marginBottom: 16, borderLeft: `4px solid ${cor}`, ...style }} styles={{ body: { background: fundo, display: 'flex', flexWrap: 'wrap', gap: 12, justifyContent: 'space-between', alignItems: 'center' } }}>
      <div>
        <h3 style={{ margin: 0, color: corTitulo ?? cor }}><IconeFa nome={icone} /> {titulo}</h3>
        {texto && <p style={{ margin: 0, fontSize: 13, color: corTitulo ?? cor, opacity: 0.85 }}>{texto}</p>}
      </div>
      {accoes && <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, alignItems: 'center' }}>{accoes}</div>}
    </Card>
  );
}
