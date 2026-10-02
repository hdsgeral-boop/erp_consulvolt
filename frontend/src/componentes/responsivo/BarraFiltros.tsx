import { Flex } from 'antd';
import type { CSSProperties, ReactNode } from 'react';

interface Props {
  children: ReactNode;
  /** acções alinhadas à direita (ex.: Novo, Exportar); em telemóvel descem para uma linha própria */
  accoes?: ReactNode;
  style?: CSSProperties;
  className?: string;
}

/**
 * Barra de filtros que quebra linha: os filtros ocupam o espaço disponível e, em telemóvel (< 576 px), cada um
 * fica com a largura toda (Select, DatePicker, RangePicker e Input incluídos — ver `.erp-barra-filtros` no CSS global).
 *
 * ```tsx
 * <BarraFiltros accoes={<Button type="primary">Novo</Button>}>
 *   <Input.Search placeholder="Pesquisar" style={{ width: 240 }} />
 *   <Select options={…} style={{ width: 180 }} />
 *   <DatePicker.RangePicker />
 * </BarraFiltros>
 * ```
 */
export function BarraFiltros({ children, accoes, style, className }: Props) {
  return (
    <Flex wrap gap="small" align="center" justify="space-between" className={['erp-barra-filtros-contentor', className].filter(Boolean).join(' ')} style={{ marginBottom: 16, ...style }}>
      <Flex wrap gap="small" align="center" className="erp-barra-filtros" style={{ flex: '1 1 auto', minWidth: 0 }}>
        {children}
      </Flex>
      {accoes && (
        <Flex wrap gap="small" align="center" className="erp-barra-filtros-accoes">
          {accoes}
        </Flex>
      )}
    </Flex>
  );
}
