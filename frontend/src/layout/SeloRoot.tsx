import { Tag, Tooltip } from 'antd';

/**
 * Selo discreto «ROOT» do cabeçalho da conta root global (ADR-084). Só aparece quando o servidor diz `conta_root`;
 * lembra que cada acção desta conta fica auditada e que ela atravessa todos os clientes.
 */
export function SeloRoot({ utilizador }: { utilizador: { conta_root?: unknown } | null | undefined }) {
  if (utilizador?.conta_root !== true) return null;
  return (
    <Tooltip title="Conta root global: acesso a todos os clientes e empresas. Cada acção fica auditada.">
      <Tag color="red" variant="outlined" data-testid="selo-root" style={{ margin: 0, fontSize: 10, lineHeight: '16px', padding: '0 6px', fontWeight: 700, letterSpacing: 0.5 }}>
        ROOT
      </Tag>
    </Tooltip>
  );
}
