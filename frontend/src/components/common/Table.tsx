import { type ReactNode, useState, useRef, useCallback, useId } from 'react';
import { clsx } from 'clsx';
import {
  useTable,
  tableFeatures,
  rowSelectionFeature,
  columnVisibilityFeature,
  flexRender,
  type ColumnDef,
  type RowData,
  type RowSelectionState,
  type OnChangeFn,
} from '@tanstack/react-table';
import { ArrowUp, ArrowDown, ArrowUpDown } from 'lucide-react';
import EmptyState from './EmptyState';

// TanStack Table v9 registers features explicitly. The shared Table needs row
// selection and column visibility (row.getVisibleCells); sorting and pagination
// are handled by the pages (server-side via SortableHeader and Pagination), and
// the core row model is automatic.
export const tableFeatureSet = tableFeatures({ rowSelectionFeature, columnVisibilityFeature });
export type TableFeatureSet = typeof tableFeatureSet;

/** Column definition type for the shared Table component. */
export type TableColumnDef<T extends RowData> = ColumnDef<TableFeatureSet, T>;

interface EmptyStateConfig {
  icon?: ReactNode;
  title: string;
  description?: string;
  tips?: string[];
}

interface TableProps<T extends RowData> {
  data: T[];
  columns: TableColumnDef<T>[];
  loading?: boolean;
  rowSelection?: RowSelectionState;
  onRowSelectionChange?: OnChangeFn<RowSelectionState>;
  onRowClick?: (row: T) => void;
  className?: string;
  emptyMessage?: string;
  emptyState?: EmptyStateConfig;
  /** Accessible label for the table */
  ariaLabel?: string;
  /** Function to render expanded row content */
  renderExpandedRow?: (row: T) => ReactNode;
  /** Set of expanded row IDs (for controlled expansion) */
  expandedRows?: Set<number>;
}

// Number of rows to skip with Page Up/Down
const PAGE_SIZE = 5;

export default function Table<T extends RowData>({
  data,
  columns,
  loading = false,
  rowSelection,
  onRowSelectionChange,
  onRowClick,
  className,
  emptyMessage = 'No data found',
  emptyState,
  ariaLabel = 'Data table',
  renderExpandedRow,
  expandedRows,
}: TableProps<T>) {
  const tableId = useId();
  const tableRef = useRef<HTMLTableElement>(null);
  const rowRefs = useRef<(HTMLTableRowElement | null)[]>([]);
  const [focusedRowIndex, setFocusedRowIndex] = useState<number>(-1);

  const table = useTable({
    features: tableFeatureSet,
    data,
    columns,
    state: {
      rowSelection: rowSelection || {},
    },
    onRowSelectionChange,
    enableRowSelection: !!onRowSelectionChange,
  });

  const rows = table.getRowModel().rows;
  const rowCount = rows.length;

  // Focus a specific row
  const focusRow = useCallback((index: number) => {
    const clampedIndex = Math.max(0, Math.min(index, rowCount - 1));
    if (clampedIndex >= 0 && rowRefs.current[clampedIndex]) {
      rowRefs.current[clampedIndex]?.focus();
      setFocusedRowIndex(clampedIndex);
    }
  }, [rowCount]);

  // Handle keyboard navigation
  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent, rowIndex: number) => {
      const row = rows[rowIndex];
      if (!row) return;

      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          focusRow(rowIndex + 1);
          break;

        case 'ArrowUp':
          e.preventDefault();
          focusRow(rowIndex - 1);
          break;

        case 'Home':
          e.preventDefault();
          if (e.ctrlKey) {
            // Ctrl+Home: go to first row
            focusRow(0);
          } else {
            focusRow(0);
          }
          break;

        case 'End':
          e.preventDefault();
          if (e.ctrlKey) {
            // Ctrl+End: go to last row
            focusRow(rowCount - 1);
          } else {
            focusRow(rowCount - 1);
          }
          break;

        case 'PageUp':
          e.preventDefault();
          focusRow(Math.max(0, rowIndex - PAGE_SIZE));
          break;

        case 'PageDown':
          e.preventDefault();
          focusRow(Math.min(rowCount - 1, rowIndex + PAGE_SIZE));
          break;

        case 'Enter':
          e.preventDefault();
          if (onRowClick) {
            onRowClick(row.original);
          }
          break;

        case ' ':
          e.preventDefault();
          if (onRowSelectionChange) {
            // Toggle row selection
            row.toggleSelected();
          } else if (onRowClick) {
            onRowClick(row.original);
          }
          break;

        case 'a':
          // Ctrl+A or Cmd+A: select all
          if ((e.ctrlKey || e.metaKey) && onRowSelectionChange) {
            e.preventDefault();
            table.toggleAllRowsSelected(true);
          }
          break;

        case 'Escape':
          // Escape: clear selection
          if (onRowSelectionChange) {
            e.preventDefault();
            table.toggleAllRowsSelected(false);
          }
          break;
      }
    },
    [rows, rowCount, focusRow, onRowClick, onRowSelectionChange, table]
  );

  // Handle row focus
  const handleRowFocus = useCallback((index: number) => {
    setFocusedRowIndex(index);
  }, []);

  if (loading) {
    return <TableSkeleton columns={columns.length} rows={5} />;
  }

  const hasRows = rowCount > 0;
  const isInteractive = !!onRowClick || !!onRowSelectionChange;

  return (
    <div className={clsx('overflow-x-auto', className)}>
      <table
        ref={tableRef}
        id={tableId}
        className="w-full"
        role="grid"
        aria-label={ariaLabel}
        aria-rowcount={rowCount}
      >
        <thead>
          {table.getHeaderGroups().map((headerGroup) => (
            <tr key={headerGroup.id} className="border-b border-slate-200" role="row">
              {headerGroup.headers.map((header, colIndex) => (
                <th
                  key={header.id}
                  role="columnheader"
                  scope="col"
                  aria-colindex={colIndex + 1}
                  className="px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider"
                >
                  {header.isPlaceholder
                    ? null
                    : flexRender(header.column.columnDef.header, header.getContext())}
                </th>
              ))}
            </tr>
          ))}
        </thead>
        <tbody className="divide-y divide-slate-100">
          {!hasRows ? (
            <tr role="row">
              <td colSpan={columns.length} role="gridcell">
                {emptyState ? (
                  <EmptyState {...emptyState} />
                ) : (
                  <div className="px-4 py-8 text-center text-sm text-slate-500">
                    {emptyMessage}
                  </div>
                )}
              </td>
            </tr>
          ) : (
            rows.map((row, rowIndex) => {
              const rowId = (row.original as { id?: number }).id;
              const isExpanded = rowId !== undefined && expandedRows?.has(rowId);
              return (
                <>
                  <tr
                    key={row.id}
                    ref={(el) => {
                      rowRefs.current[rowIndex] = el;
                    }}
                    role="row"
                    aria-rowindex={rowIndex + 1}
                    aria-selected={row.getIsSelected() || undefined}
                    aria-expanded={renderExpandedRow ? isExpanded : undefined}
                    tabIndex={isInteractive ? (focusedRowIndex === rowIndex || (focusedRowIndex === -1 && rowIndex === 0) ? 0 : -1) : undefined}
                    onClick={() => onRowClick?.(row.original)}
                    onKeyDown={(e) => handleKeyDown(e, rowIndex)}
                    onFocus={() => handleRowFocus(rowIndex)}
                    className={clsx(
                      'transition-colors',
                      isInteractive && 'cursor-pointer hover:bg-slate-50',
                      isInteractive && 'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-inset',
                      row.getIsSelected() && 'bg-primary-50',
                      isExpanded && 'bg-slate-50'
                    )}
                  >
                    {row.getVisibleCells().map((cell, colIndex) => (
                      <td
                        key={cell.id}
                        role="gridcell"
                        aria-colindex={colIndex + 1}
                        className="px-4 py-3 text-sm text-slate-700"
                      >
                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                      </td>
                    ))}
                  </tr>
                  {renderExpandedRow && isExpanded && renderExpandedRow(row.original)}
                </>
              );
            })
          )}
        </tbody>
      </table>

      {/* Screen reader instructions */}
      {isInteractive && hasRows && (
        <div className="sr-only" aria-live="polite">
          Use arrow keys to navigate rows.
          {onRowClick && ' Press Enter to open.'}
          {onRowSelectionChange && ' Press Space to select.'}
        </div>
      )}
    </div>
  );
}

// Skeleton loader for table
interface TableSkeletonProps {
  columns: number;
  rows: number;
}

function TableSkeleton({ columns, rows }: TableSkeletonProps) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full">
        <thead>
          <tr className="border-b border-slate-200">
            {Array.from({ length: columns }).map((_, i) => (
              <th key={i} className="px-4 py-3">
                <div className="h-4 bg-slate-200 rounded animate-pulse w-20" />
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {Array.from({ length: rows }).map((_, rowIndex) => (
            <tr key={rowIndex}>
              {Array.from({ length: columns }).map((_, colIndex) => (
                <td key={colIndex} className="px-4 py-3">
                  <div className="h-4 bg-slate-100 rounded animate-pulse" />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

// Sortable header component
interface SortableHeaderProps {
  children: ReactNode;
  sorted?: 'asc' | 'desc' | false;
  onSort?: () => void;
  /** Column name for accessibility */
  columnName?: string;
}

export function SortableHeader({
  children,
  sorted,
  onSort,
  columnName,
}: SortableHeaderProps) {
  const getSortLabel = () => {
    const name = columnName || 'column';
    if (sorted === 'asc') return `${name}, sorted ascending. Click to sort descending.`;
    if (sorted === 'desc') return `${name}, sorted descending. Click to remove sort.`;
    return `${name}. Click to sort ascending.`;
  };

  return (
    <button
      onClick={onSort}
      onKeyDown={(e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onSort?.();
        }
      }}
      className={clsx(
        'flex items-center gap-1 hover:text-slate-900 transition-colors',
        'focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:rounded'
      )}
      aria-sort={sorted === 'asc' ? 'ascending' : sorted === 'desc' ? 'descending' : 'none'}
      aria-label={getSortLabel()}
    >
      {children}
      {sorted === 'asc' ? (
        <ArrowUp className="w-4 h-4" aria-hidden="true" />
      ) : sorted === 'desc' ? (
        <ArrowDown className="w-4 h-4" aria-hidden="true" />
      ) : (
        <ArrowUpDown className="w-4 h-4 opacity-50" aria-hidden="true" />
      )}
    </button>
  );
}

// Helper to create checkbox column
export function createCheckboxColumn<T extends RowData>(): TableColumnDef<T> {
  return {
    id: 'select',
    header: ({ table }) => {
      const allSelected = table.getIsAllRowsSelected();
      const someSelected = table.getIsSomeRowsSelected();

      return (
        <input
          type="checkbox"
          checked={allSelected}
          ref={(el) => {
            if (el) {
              el.indeterminate = someSelected && !allSelected;
            }
          }}
          onChange={table.getToggleAllRowsSelectedHandler()}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              table.toggleAllRowsSelected(!allSelected);
            }
          }}
          className={clsx(
            'w-4 h-4 rounded border-slate-300 text-primary-600',
            'focus:ring-2 focus:ring-primary-500 focus:ring-offset-0'
          )}
          aria-label={allSelected ? 'Deselect all rows' : 'Select all rows'}
        />
      );
    },
    cell: ({ row }) => (
      <input
        type="checkbox"
        checked={row.getIsSelected()}
        onChange={row.getToggleSelectedHandler()}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            row.toggleSelected();
          }
        }}
        onClick={(e) => e.stopPropagation()}
        className={clsx(
          'w-4 h-4 rounded border-slate-300 text-primary-600',
          'focus:ring-2 focus:ring-primary-500 focus:ring-offset-0'
        )}
        aria-label={row.getIsSelected() ? 'Deselect row' : 'Select row'}
      />
    ),
  };
}
