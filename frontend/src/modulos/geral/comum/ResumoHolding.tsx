import { Button, Flex, Space, Tag, Typography, theme } from 'antd';
import { useNavigate } from 'react-router-dom';
import { IconeFa } from '@/componentes/fluxos/IconeFa';
import { formatarData } from '@/utilitarios/formatacao';
import { CartaoKpi, TabelaGestao, type TabelaApiGestao } from './componentes';
import { estiloKpi } from './estiloPainel';
import './painel.css';

/** Bloco `holding` de GET /gestao/inicio (ServicoInicio::resumoHolding). */
export interface DadosResumoHolding {
  grupo?: { id: number; nome: string } | null;
  ultima_consolidacao?: { id: number; data_fim?: string | null; executado_em?: string | null; moeda?: string | null } | null;
  aviso?: string | null;
  empresas?: number;
  kpis?: { id: string; rotulo: string; valor: unknown; formato: string; subtitulo?: string | null }[];
  quadro?: TabelaApiGestao | null;
}

/** Rotas do menu da holding usadas nos atalhos (o menu só tem Dashboard e Contabilidade). */
export const ROTA_COMPARACAO_GRUPO = '/m/geral/dashboard?separador=grupo';
export const ROTA_CONSOLIDACAO = '/m/contab/consolidacao';

/**
 * Início de uma holding: indicadores consolidados do ano até ao mês corrente e o quadro comparativo das empresas do grupo
 * (calculados no servidor a partir da última consolidação), com atalhos para a Consolidação e a comparação no Dashboard.
 */
export function ResumoHolding({ dados, rotaPermitida }: { dados: DadosResumoHolding; rotaPermitida: (rota: string) => boolean }) {
  const navegar = useNavigate();
  const { token } = theme.useToken();
  const ultima = dados.ultima_consolidacao;
  const kpis = dados.kpis ?? [];
  return (
    <section aria-label="Grupo consolidado" style={{ marginTop: 24, minWidth: 0 }}>
      <Flex justify="space-between" align="center" wrap gap={8} style={{ marginBottom: 12 }}>
        <Space size={8} wrap>
          <IconeFa nome="sitemap" style={{ color: token.colorPrimary }} />
          <Typography.Title level={4} style={{ margin: 0 }}>
            {dados.grupo?.nome ?? 'Grupo consolidado'}
          </Typography.Title>
          <Tag color="purple" bordered>
            Holding
          </Tag>
          {typeof dados.empresas === 'number' && <Tag>{dados.empresas === 1 ? '1 empresa' : `${dados.empresas} empresas`}</Tag>}
          {ultima?.data_fim && (
            <Typography.Text type="secondary">
              Consolidado até {formatarData(ultima.data_fim)}
              {ultima.moeda ? ` em ${ultima.moeda}` : ''}
            </Typography.Text>
          )}
        </Space>
        <Space size={8} wrap>
          {rotaPermitida(ROTA_CONSOLIDACAO) && (
            <Button icon={<IconeFa nome="layer-group" />} onClick={() => navegar(ROTA_CONSOLIDACAO)}>
              Consolidação
            </Button>
          )}
          {rotaPermitida(ROTA_COMPARACAO_GRUPO) && (
            <Button type="primary" icon={<IconeFa nome="balance-scale" />} onClick={() => navegar(ROTA_COMPARACAO_GRUPO)}>
              Comparar empresas
            </Button>
          )}
        </Space>
      </Flex>
      {dados.aviso && (
        <Typography.Paragraph type="secondary" style={{ fontSize: 13 }}>
          {dados.aviso}
        </Typography.Paragraph>
      )}
      {kpis.length > 0 && (
        <div className="painel-kpis" style={{ marginBottom: 16 }}>
          {kpis.map((k) => {
            const e = estiloKpi('grupo', k.id, k.valor);
            return <CartaoKpi key={k.id} rotulo={k.rotulo} valor={k.valor} formato={k.formato} subtitulo={k.subtitulo} icone={e.icone} cor={e.cor} barra="esquerda" />;
          })}
        </div>
      )}
      {dados.quadro && dados.quadro.linhas.length > 0 && <TabelaGestao tabela={dados.quadro} />}
    </section>
  );
}
