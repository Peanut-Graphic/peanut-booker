import { useState } from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { type RowSelectionState } from '@tanstack/react-table';
import Table, { SortableHeader, createCheckboxColumn, type TableColumnDef } from './Table';

interface TestData {
  id: number;
  name: string;
  email: string;
}

const testData: TestData[] = [
  { id: 1, name: 'John Doe', email: 'john@example.com' },
  { id: 2, name: 'Jane Smith', email: 'jane@example.com' },
  { id: 3, name: 'Bob Johnson', email: 'bob@example.com' },
];

const testColumns: TableColumnDef<TestData>[] = [
  { accessorKey: 'name', header: 'Name' },
  { accessorKey: 'email', header: 'Email' },
];

describe('Table', () => {
  describe('rendering', () => {
    it('renders table with data', () => {
      render(<Table data={testData} columns={testColumns} />);

      expect(screen.getByRole('grid')).toBeInTheDocument();
      expect(screen.getByText('John Doe')).toBeInTheDocument();
      expect(screen.getByText('Jane Smith')).toBeInTheDocument();
      expect(screen.getByText('Bob Johnson')).toBeInTheDocument();
    });

    it('renders column headers', () => {
      render(<Table data={testData} columns={testColumns} />);

      expect(screen.getByRole('columnheader', { name: 'Name' })).toBeInTheDocument();
      expect(screen.getByRole('columnheader', { name: 'Email' })).toBeInTheDocument();
    });

    it('renders correct number of rows', () => {
      render(<Table data={testData} columns={testColumns} />);

      const rows = screen.getAllByRole('row');
      // 1 header row + 3 data rows
      expect(rows).toHaveLength(4);
    });

    it('renders empty message when no data', () => {
      render(<Table data={[]} columns={testColumns} />);

      expect(screen.getByText('No data found')).toBeInTheDocument();
    });

    it('renders custom empty message', () => {
      render(<Table data={[]} columns={testColumns} emptyMessage="No items available" />);

      expect(screen.getByText('No items available')).toBeInTheDocument();
    });

    it('renders custom empty state', () => {
      const emptyState = {
        title: 'No Results',
        description: 'Try adjusting your filters',
      };
      render(<Table data={[]} columns={testColumns} emptyState={emptyState} />);

      expect(screen.getByText('No Results')).toBeInTheDocument();
      expect(screen.getByText('Try adjusting your filters')).toBeInTheDocument();
    });
  });

  describe('loading state', () => {
    it('renders skeleton when loading', () => {
      const { container } = render(<Table data={testData} columns={testColumns} loading />);

      // Should render skeleton with animated elements
      expect(container.querySelectorAll('.animate-pulse').length).toBeGreaterThan(0);
    });

    it('does not render data when loading', () => {
      render(<Table data={testData} columns={testColumns} loading />);

      expect(screen.queryByText('John Doe')).not.toBeInTheDocument();
    });
  });

  describe('accessibility', () => {
    it('has aria-label', () => {
      render(<Table data={testData} columns={testColumns} ariaLabel="Users table" />);

      expect(screen.getByRole('grid')).toHaveAttribute('aria-label', 'Users table');
    });

    it('has default aria-label', () => {
      render(<Table data={testData} columns={testColumns} />);

      expect(screen.getByRole('grid')).toHaveAttribute('aria-label', 'Data table');
    });

    it('has aria-rowcount', () => {
      render(<Table data={testData} columns={testColumns} />);

      expect(screen.getByRole('grid')).toHaveAttribute('aria-rowcount', '3');
    });

    it('renders rows with correct roles', () => {
      render(<Table data={testData} columns={testColumns} />);

      const rows = screen.getAllByRole('row');
      expect(rows.length).toBeGreaterThan(0);
    });

    it('renders cells with gridcell role', () => {
      render(<Table data={testData} columns={testColumns} />);

      const cells = screen.getAllByRole('gridcell');
      expect(cells.length).toBeGreaterThan(0);
    });
  });

  describe('row interaction', () => {
    it('calls onRowClick when row is clicked', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      fireEvent.click(screen.getByText('John Doe'));

      expect(handleRowClick).toHaveBeenCalledWith(testData[0]);
    });

    it('rows are focusable when interactive', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      // First data row should be focusable
      expect(rows[1]).toHaveAttribute('tabindex', '0');
    });

    it('rows are not focusable when not interactive', () => {
      render(<Table data={testData} columns={testColumns} />);

      const rows = screen.getAllByRole('row');
      // Data rows should not have tabindex
      expect(rows[1]).not.toHaveAttribute('tabindex');
    });
  });

  describe('keyboard navigation', () => {
    it('navigates to next row with ArrowDown', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      const firstDataRow = rows[1];

      fireEvent.focus(firstDataRow);
      fireEvent.keyDown(firstDataRow, { key: 'ArrowDown' });

      // Second data row should now be focused
      expect(rows[2]).toHaveFocus();
    });

    it('navigates to previous row with ArrowUp', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      const secondDataRow = rows[2];

      fireEvent.focus(secondDataRow);
      fireEvent.keyDown(secondDataRow, { key: 'ArrowUp' });

      expect(rows[1]).toHaveFocus();
    });

    it('navigates to first row with Home', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      const lastDataRow = rows[3];

      fireEvent.focus(lastDataRow);
      fireEvent.keyDown(lastDataRow, { key: 'Home' });

      expect(rows[1]).toHaveFocus();
    });

    it('navigates to last row with End', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      const firstDataRow = rows[1];

      fireEvent.focus(firstDataRow);
      fireEvent.keyDown(firstDataRow, { key: 'End' });

      expect(rows[3]).toHaveFocus();
    });

    it('triggers onRowClick with Enter', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      const firstDataRow = rows[1];

      fireEvent.focus(firstDataRow);
      fireEvent.keyDown(firstDataRow, { key: 'Enter' });

      expect(handleRowClick).toHaveBeenCalledWith(testData[0]);
    });
  });

  describe('row selection', () => {
    it('selects row on Space keypress', () => {
      const handleSelectionChange = vi.fn();
      render(
        <Table
          data={testData}
          columns={testColumns}
          rowSelection={{}}
          onRowSelectionChange={handleSelectionChange}
        />
      );

      const rows = screen.getAllByRole('row');
      const firstDataRow = rows[1];

      fireEvent.focus(firstDataRow);
      fireEvent.keyDown(firstDataRow, { key: ' ' });

      expect(handleSelectionChange).toHaveBeenCalled();
    });

    it('shows selected state', () => {
      render(
        <Table
          data={testData}
          columns={testColumns}
          rowSelection={{ '0': true }}
          onRowSelectionChange={vi.fn()}
        />
      );

      const rows = screen.getAllByRole('row');
      expect(rows[1]).toHaveAttribute('aria-selected', 'true');
    });
  });

  describe('styling', () => {
    it('applies custom className', () => {
      const { container } = render(
        <Table data={testData} columns={testColumns} className="custom-table" />
      );

      expect(container.firstChild).toHaveClass('custom-table');
    });

    it('applies hover styles to interactive rows', () => {
      const handleRowClick = vi.fn();
      render(<Table data={testData} columns={testColumns} onRowClick={handleRowClick} />);

      const rows = screen.getAllByRole('row');
      expect(rows[1]).toHaveClass('cursor-pointer', 'hover:bg-slate-50');
    });
  });
});

describe('SortableHeader', () => {
  it('renders children', () => {
    render(<SortableHeader>Column Name</SortableHeader>);
    expect(screen.getByText('Column Name')).toBeInTheDocument();
  });

  it('renders as button', () => {
    render(<SortableHeader onSort={vi.fn()}>Column</SortableHeader>);
    expect(screen.getByRole('button')).toBeInTheDocument();
  });

  it('shows unsorted icon by default', () => {
    const { container } = render(<SortableHeader>Column</SortableHeader>);
    expect(container.querySelector('.lucide-arrow-up-down')).toBeInTheDocument();
  });

  it('shows ascending icon when sorted asc', () => {
    const { container } = render(<SortableHeader sorted="asc">Column</SortableHeader>);
    expect(container.querySelector('.lucide-arrow-up')).toBeInTheDocument();
  });

  it('shows descending icon when sorted desc', () => {
    const { container } = render(<SortableHeader sorted="desc">Column</SortableHeader>);
    expect(container.querySelector('.lucide-arrow-down')).toBeInTheDocument();
  });

  it('calls onSort when clicked', () => {
    const handleSort = vi.fn();
    render(<SortableHeader onSort={handleSort}>Column</SortableHeader>);

    fireEvent.click(screen.getByRole('button'));

    expect(handleSort).toHaveBeenCalled();
  });

  it('calls onSort on Enter keypress', () => {
    const handleSort = vi.fn();
    render(<SortableHeader onSort={handleSort}>Column</SortableHeader>);

    fireEvent.keyDown(screen.getByRole('button'), { key: 'Enter' });

    expect(handleSort).toHaveBeenCalled();
  });

  it('calls onSort on Space keypress', () => {
    const handleSort = vi.fn();
    render(<SortableHeader onSort={handleSort}>Column</SortableHeader>);

    fireEvent.keyDown(screen.getByRole('button'), { key: ' ' });

    expect(handleSort).toHaveBeenCalled();
  });

  it('has correct aria-sort for ascending', () => {
    render(<SortableHeader sorted="asc">Column</SortableHeader>);
    expect(screen.getByRole('button')).toHaveAttribute('aria-sort', 'ascending');
  });

  it('has correct aria-sort for descending', () => {
    render(<SortableHeader sorted="desc">Column</SortableHeader>);
    expect(screen.getByRole('button')).toHaveAttribute('aria-sort', 'descending');
  });

  it('has aria-sort none when unsorted', () => {
    render(<SortableHeader>Column</SortableHeader>);
    expect(screen.getByRole('button')).toHaveAttribute('aria-sort', 'none');
  });
});

// Stateful harness: exercises controlled row selection through the v9 store.
function SelectableTable({
  initial = {},
  onChange,
}: {
  initial?: RowSelectionState;
  onChange?: (selection: RowSelectionState) => void;
}) {
  const [rowSelection, setRowSelection] = useState<RowSelectionState>(initial);
  return (
    <>
      <Table
        data={testData}
        columns={[createCheckboxColumn<TestData>(), ...testColumns]}
        rowSelection={rowSelection}
        onRowSelectionChange={(updater) => {
          setRowSelection((prev) => {
            const next = typeof updater === 'function' ? updater(prev) : updater;
            onChange?.(next);
            return next;
          });
        }}
      />
      <output data-testid="selected">{Object.keys(rowSelection).filter((k) => rowSelection[k]).sort().join(',')}</output>
    </>
  );
}

describe('row selection with checkbox column (TanStack Table v9)', () => {
  const dataRows = () => screen.getAllByRole('row').slice(1);
  const selectAll = () => screen.getByRole('checkbox', { name: /select all rows/i });

  it('toggles a single row via its checkbox and reflects it in the DOM', () => {
    render(<SelectableTable />);

    const rowCheckbox = within(dataRows()[1]).getByRole('checkbox', { name: 'Select row' });
    fireEvent.click(rowCheckbox);

    expect(screen.getByTestId('selected')).toHaveTextContent('1');
    expect(dataRows()[1]).toHaveAttribute('aria-selected', 'true');
    expect(dataRows()[1]).toHaveClass('bg-primary-50');
    expect(within(dataRows()[1]).getByRole('checkbox', { name: 'Deselect row' })).toBeChecked();
    expect(dataRows()[0]).not.toHaveAttribute('aria-selected');
  });

  it('marks select-all as indeterminate when some (not all) rows are selected', () => {
    render(<SelectableTable initial={{ '0': true }} />);

    const header = selectAll() as HTMLInputElement;
    expect(header.checked).toBe(false);
    expect(header.indeterminate).toBe(true);
  });

  it('select-all checkbox selects every row, then clears them', () => {
    render(<SelectableTable />);

    fireEvent.click(selectAll());
    expect(screen.getByTestId('selected')).toHaveTextContent('0,1,2');
    const header = screen.getByRole('checkbox', { name: /deselect all rows/i }) as HTMLInputElement;
    expect(header.checked).toBe(true);
    expect(header.indeterminate).toBe(false);
    dataRows().forEach((row) => expect(row).toHaveAttribute('aria-selected', 'true'));

    fireEvent.click(header);
    expect(screen.getByTestId('selected')).toHaveTextContent('');
    dataRows().forEach((row) => expect(row).not.toHaveAttribute('aria-selected'));
  });

  it('Ctrl+A selects all and Escape clears the selection', () => {
    render(<SelectableTable />);

    fireEvent.keyDown(dataRows()[0], { key: 'a', ctrlKey: true });
    expect(screen.getByTestId('selected')).toHaveTextContent('0,1,2');

    fireEvent.keyDown(dataRows()[0], { key: 'Escape' });
    expect(screen.getByTestId('selected')).toHaveTextContent('');
  });

  it('Space toggles the focused row on and off', () => {
    const onChange = vi.fn();
    render(<SelectableTable onChange={onChange} />);

    fireEvent.keyDown(dataRows()[2], { key: ' ' });
    expect(onChange).toHaveBeenLastCalledWith({ '2': true });
    expect(dataRows()[2]).toHaveAttribute('aria-selected', 'true');

    fireEvent.keyDown(dataRows()[2], { key: ' ' });
    expect(screen.getByTestId('selected')).toHaveTextContent('');
  });

  it('clicking a row checkbox does not trigger onRowClick', () => {
    const handleRowClick = vi.fn();
    render(
      <Table
        data={testData}
        columns={[createCheckboxColumn<TestData>(), ...testColumns]}
        rowSelection={{}}
        onRowSelectionChange={vi.fn()}
        onRowClick={handleRowClick}
      />
    );

    fireEvent.click(within(screen.getAllByRole('row')[1]).getByRole('checkbox'));
    expect(handleRowClick).not.toHaveBeenCalled();
  });

  it('does not select rows when selection is not enabled', () => {
    render(<Table data={testData} columns={testColumns} />);

    fireEvent.keyDown(screen.getAllByRole('row')[1], { key: ' ' });
    expect(screen.getAllByRole('row')[1]).not.toHaveAttribute('aria-selected');
  });
});

describe('sorting through SortableHeader inside Table (TanStack Table v9)', () => {
  function SortedTable() {
    const [sorted, setSorted] = useState<'asc' | 'desc' | false>(false);
    const data =
      sorted === false
        ? testData
        : [...testData].sort((a, b) =>
            sorted === 'asc' ? a.name.localeCompare(b.name) : b.name.localeCompare(a.name)
          );
    const columns: TableColumnDef<TestData>[] = [
      {
        accessorKey: 'name',
        header: () => (
          <SortableHeader
            sorted={sorted}
            columnName="Name"
            onSort={() => setSorted((s) => (s === false ? 'asc' : s === 'asc' ? 'desc' : false))}
          >
            Name
          </SortableHeader>
        ),
      },
      { accessorKey: 'email', header: 'Email' },
    ];
    return <Table data={data} columns={columns} />;
  }

  const names = () =>
    screen.getAllByRole('row').slice(1).map((row) => within(row).getAllByRole('gridcell')[0].textContent);

  it('cycles none -> ascending -> descending -> none and re-renders row order', () => {
    render(<SortedTable />);
    expect(names()).toEqual(['John Doe', 'Jane Smith', 'Bob Johnson']);

    fireEvent.click(screen.getByRole('button', { name: /Name/ }));
    expect(names()).toEqual(['Bob Johnson', 'Jane Smith', 'John Doe']);
    expect(screen.getByRole('button', { name: /sorted ascending/ })).toHaveAttribute('aria-sort', 'ascending');

    fireEvent.click(screen.getByRole('button', { name: /Name/ }));
    expect(names()).toEqual(['John Doe', 'Jane Smith', 'Bob Johnson']);
    expect(screen.getByRole('button', { name: /sorted descending/ })).toHaveAttribute('aria-sort', 'descending');

    fireEvent.click(screen.getByRole('button', { name: /Name/ }));
    expect(names()).toEqual(['John Doe', 'Jane Smith', 'Bob Johnson']);
    expect(screen.getByRole('button', { name: /Name/ })).toHaveAttribute('aria-sort', 'none');
  });
});

describe('createCheckboxColumn', () => {
  it('creates a column definition', () => {
    const column = createCheckboxColumn<TestData>();

    expect(column.id).toBe('select');
    expect(column.header).toBeDefined();
    expect(column.cell).toBeDefined();
  });
});
