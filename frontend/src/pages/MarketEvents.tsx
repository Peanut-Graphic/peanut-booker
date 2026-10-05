import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  StatusBadge,
  EmptyState,
  emptyStates,
  HelpTooltip,
  type TableColumnDef,
} from '@/components/common';
import { marketEvents as helpContent } from '@/constants/helpContent';
import { marketApi } from '@/api';
import { useFilterStore } from '@/store';
import type { MarketEvent, MarketEventStatus } from '@/types';
import { Calendar, MapPin, DollarSign, Users } from 'lucide-react';
import { format } from 'date-fns';
import { clsx } from 'clsx';

const statusTabs: { id: string; label: string; status?: MarketEventStatus }[] = [
  { id: 'all', label: 'All' },
  { id: 'open', label: 'Open for Bids', status: 'open' },
  { id: 'closed', label: 'Bidding Closed', status: 'closed' },
  { id: 'booked', label: 'Booked', status: 'booked' },
  { id: 'cancelled', label: 'Cancelled', status: 'cancelled' },
];

export default function MarketEvents() {
  const [page, setPage] = useState(1);
  const [activeTab, setActiveTab] = useState('all');

  const { marketFilters, setMarketFilter, resetMarketFilters } = useFilterStore();

  const statusFilter = statusTabs.find((t) => t.id === activeTab)?.status;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['market-events', page, statusFilter, marketFilters],
    queryFn: () =>
      marketApi.getAll({
        page,
        per_page: 20,
        status: statusFilter,
        search: marketFilters.search || undefined,
      }),
  });

  const columns: TableColumnDef<MarketEvent>[] = [
    {
      accessorKey: 'id',
      header: 'ID',
      cell: ({ row }) => <span className="font-mono text-sm">#{row.original.id}</span>,
    },
    {
      accessorKey: 'title',
      header: () => (
        <span className="flex items-center gap-1">
          Event
          <HelpTooltip content={helpContent.columns.eventType} />
        </span>
      ),
      cell: ({ row }) => (
        <div>
          <p className="font-medium text-slate-900">{row.original.title}</p>
          {row.original.category_name && (
            <p className="text-xs text-slate-500">{row.original.category_name}</p>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'customer_name',
      header: () => (
        <span className="flex items-center gap-1">
          Customer
          <HelpTooltip content={helpContent.columns.customer} />
        </span>
      ),
    },
    {
      accessorKey: 'event_date',
      header: 'Event Date',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Calendar className="w-4 h-4 text-slate-400" />
          <span>{format(new Date(row.original.event_date), 'MMM d, yyyy')}</span>
        </div>
      ),
    },
    {
      accessorKey: 'event_city',
      header: 'Location',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <MapPin className="w-4 h-4 text-slate-400" />
          <span>
            {row.original.event_city}, {row.original.event_state}
          </span>
        </div>
      ),
    },
    {
      id: 'budget',
      header: () => (
        <span className="flex items-center gap-1">
          Budget
          <HelpTooltip content={helpContent.columns.budgetRange} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <DollarSign className="w-4 h-4 text-slate-400" />
          <span>
            {row.original.budget_min.toLocaleString()} - {row.original.budget_max.toLocaleString()}
          </span>
        </div>
      ),
    },
    {
      accessorKey: 'total_bids',
      header: () => (
        <span className="flex items-center gap-1">
          Bids
          <HelpTooltip content={helpContent.columns.bidsReceived} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Users className="w-4 h-4 text-slate-400" />
          <span>{row.original.total_bids}</span>
        </div>
      ),
    },
    {
      accessorKey: 'bid_deadline',
      header: () => (
        <span className="flex items-center gap-1">
          Deadline
          <HelpTooltip content={helpContent.columns.bidDeadline} />
        </span>
      ),
      cell: ({ row }) => format(new Date(row.original.bid_deadline), 'MMM d, yyyy'),
    },
    {
      accessorKey: 'status',
      header: 'Status',
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
  ];

  if (isError) {
    return (
      <Layout title="Market Events" description="View customer event requests" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Market Events" description="View customer event requests" helpContent={helpContent.helpCard}>
      {/* Status Tabs */}
      <div className="flex gap-1 mb-6 border-b border-slate-200">
        {statusTabs.map((tab) => (
          <button
            key={tab.id}
            onClick={() => {
              setActiveTab(tab.id);
              setPage(1);
            }}
            className={clsx(
              'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
              activeTab === tab.id
                ? 'border-primary-600 text-primary-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            )}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search events..."
              value={marketFilters.search}
              onChange={(e) => setMarketFilter('search', e.target.value)}
            />
          </div>
          <Input
            type="date"
            value={marketFilters.date_from}
            onChange={(e) => setMarketFilter('date_from', e.target.value)}
            placeholder="Event from"
          />
          <Input
            type="date"
            value={marketFilters.date_to}
            onChange={(e) => setMarketFilter('date_to', e.target.value)}
            placeholder="Event to"
          />
          <Button variant="ghost" onClick={resetMarketFilters}>
            Clear
          </Button>
        </div>
      </Card>

      {/* Table */}
      <Card padding="none">
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyMessage="No market events found"
        />
        {data && data.total_pages > 1 && (
          <div className="p-4 border-t border-slate-200">
            <Pagination
              currentPage={page}
              totalPages={data.total_pages}
              onPageChange={setPage}
            />
          </div>
        )}
      </Card>
    </Layout>
  );
}
