import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  StatusBadge,
  EmptyState,
  ConfirmModal,
  useToast,
  emptyStates,
  HelpTooltip,
  type TableColumnDef,
} from '@/components/common';
import { bookings as helpContent } from '@/constants/helpContent';
import { bookingsApi } from '@/api';
import { useFilterStore } from '@/store';
import type { Booking, BookingStatus } from '@/types';
import { Download, Calendar, MapPin, DollarSign } from 'lucide-react';
import { format } from 'date-fns';
import { clsx } from 'clsx';

const statusTabs: { id: string; label: string; status?: BookingStatus }[] = [
  { id: 'all', label: 'All' },
  { id: 'pending', label: 'Pending', status: 'pending' },
  { id: 'confirmed', label: 'Confirmed', status: 'confirmed' },
  { id: 'completed', label: 'Completed', status: 'completed' },
  { id: 'cancelled', label: 'Cancelled', status: 'cancelled' },
];

export default function Bookings() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [activeTab, setActiveTab] = useState('all');
  const [cancelModal, setCancelModal] = useState<{ open: boolean; id: number | null }>({
    open: false,
    id: null,
  });

  const { bookingFilters, setBookingFilter, resetBookingFilters } = useFilterStore();

  const statusFilter = statusTabs.find((t) => t.id === activeTab)?.status;

  const { data, isLoading, isError } = useQuery({
    queryKey: ['bookings', page, statusFilter, bookingFilters],
    queryFn: () =>
      bookingsApi.getAll({
        page,
        per_page: 20,
        status: statusFilter,
        search: bookingFilters.search || undefined,
      }),
  });

  const cancelMutation = useMutation({
    mutationFn: (id: number) => bookingsApi.cancel(id, 'Cancelled by admin'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['bookings'] });
      toast.success('Booking cancelled');
      setCancelModal({ open: false, id: null });
    },
    onError: () => {
      toast.error('Failed to cancel booking');
    },
  });

  const handleExport = async () => {
    try {
      const blob = await bookingsApi.export({
        status: statusFilter,
        date_from: bookingFilters.date_from || undefined,
        date_to: bookingFilters.date_to || undefined,
      });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `bookings-${format(new Date(), 'yyyy-MM-dd')}.csv`;
      a.click();
      URL.revokeObjectURL(url);
      toast.success('Export downloaded');
    } catch {
      toast.error('Failed to export bookings');
    }
  };

  const columns: TableColumnDef<Booking>[] = [
    {
      accessorKey: 'booking_number',
      header: () => (
        <span className="flex items-center gap-1">
          Booking
          <HelpTooltip content={helpContent.columns.booking} />
        </span>
      ),
      cell: ({ row }) => (
        <span className="font-mono text-sm">{row.original.booking_number}</span>
      ),
    },
    {
      accessorKey: 'performer_name',
      header: () => (
        <span className="flex items-center gap-1">
          Performer
          <HelpTooltip content={helpContent.columns.performer} />
        </span>
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
      cell: ({ row }) => (
        <div>
          <p className="font-medium">{row.original.customer_name}</p>
          <p className="text-xs text-slate-500">{row.original.customer_email}</p>
        </div>
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
      accessorKey: 'total_amount',
      header: () => (
        <span className="flex items-center gap-1">
          Amount
          <HelpTooltip content={helpContent.columns.amount} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <DollarSign className="w-4 h-4 text-slate-400" />
          <span>{row.original.total_amount.toLocaleString()}</span>
        </div>
      ),
    },
    {
      accessorKey: 'commission_amount',
      header: () => (
        <span className="flex items-center gap-1">
          Commission
          <HelpTooltip content={helpContent.columns.commission} />
        </span>
      ),
      cell: ({ row }) => `$${row.original.commission_amount.toLocaleString()}`,
    },
    {
      accessorKey: 'booking_status',
      header: () => (
        <span className="flex items-center gap-1">
          Status
          <HelpTooltip content={helpContent.columns.status} />
        </span>
      ),
      cell: ({ row }) => <StatusBadge status={row.original.booking_status} />,
    },
    {
      accessorKey: 'escrow_status',
      header: () => (
        <span className="flex items-center gap-1">
          Escrow
          <HelpTooltip content={helpContent.columns.escrow} />
        </span>
      ),
      cell: ({ row }) => <StatusBadge status={row.original.escrow_status} />,
    },
  ];

  if (isError) {
    return (
      <Layout title="Bookings" description="Manage booking requests" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Bookings" description="Manage booking requests" helpContent={helpContent.helpCard}>
      {/* Status Tabs */}
      <div className="flex items-center justify-between mb-6">
        <div className="flex gap-1 border-b border-slate-200">
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
        <Button
          variant="outline"
          onClick={handleExport}
          icon={<Download className="w-4 h-4" />}
        >
          Export CSV
        </Button>
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search bookings..."
              value={bookingFilters.search}
              onChange={(e) => setBookingFilter('search', e.target.value)}
            />
          </div>
          <Input
            type="date"
            value={bookingFilters.date_from}
            onChange={(e) => setBookingFilter('date_from', e.target.value)}
            placeholder="From date"
          />
          <Input
            type="date"
            value={bookingFilters.date_to}
            onChange={(e) => setBookingFilter('date_to', e.target.value)}
            placeholder="To date"
          />
          <Button variant="ghost" onClick={resetBookingFilters}>
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
          emptyMessage="No bookings found"
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

      {/* Cancel Modal */}
      <ConfirmModal
        isOpen={cancelModal.open}
        onClose={() => setCancelModal({ open: false, id: null })}
        onConfirm={() => cancelModal.id && cancelMutation.mutate(cancelModal.id)}
        title="Cancel Booking"
        message="Are you sure you want to cancel this booking? This will trigger a refund process."
        confirmText="Cancel Booking"
        variant="danger"
        loading={cancelMutation.isPending}
      />
    </Layout>
  );
}
