import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  StatCard,
  Button,
  Table,
  StatusBadge,
  EmptyState,
  ConfirmModal,
  useToast,
  emptyStates,
  HelpTooltip,
  type TableColumnDef,
} from '@/components/common';
import { payouts as helpContent } from '@/constants/helpContent';
import { payoutsApi } from '@/api';
import type { Payout } from '@/types';
import { Clock, CheckCircle, Download } from 'lucide-react';
import { format, differenceInDays } from 'date-fns';
import { useState } from 'react';

export default function Payouts() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [releaseModal, setReleaseModal] = useState<{
    open: boolean;
    bookingId: number | null;
  }>({ open: false, bookingId: null });

  const { data: stats, isLoading: loadingStats } = useQuery({
    queryKey: ['payout-stats'],
    queryFn: payoutsApi.getStats,
  });

  const { data: payouts, isLoading: loadingPayouts, isError } = useQuery({
    queryKey: ['pending-payouts'],
    queryFn: payoutsApi.getPending,
  });

  const releaseMutation = useMutation({
    mutationFn: (bookingId: number) => payoutsApi.release(bookingId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['pending-payouts'] });
      queryClient.invalidateQueries({ queryKey: ['payout-stats'] });
      toast.success('Payout released successfully');
      setReleaseModal({ open: false, bookingId: null });
    },
    onError: () => {
      toast.error('Failed to release payout');
    },
  });

  const columns: TableColumnDef<Payout>[] = [
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
      accessorKey: 'event_date',
      header: 'Event Date',
      cell: ({ row }) => format(new Date(row.original.event_date), 'MMM d, yyyy'),
    },
    {
      accessorKey: 'completion_date',
      header: 'Completed',
      cell: ({ row }) => format(new Date(row.original.completion_date), 'MMM d, yyyy'),
    },
    {
      accessorKey: 'total_amount',
      header: () => (
        <span className="flex items-center gap-1">
          Amount
          <HelpTooltip content={helpContent.columns.amount} />
        </span>
      ),
      cell: ({ row }) => `$${row.original.total_amount.toLocaleString()}`,
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
      accessorKey: 'payout_amount',
      header: () => (
        <span className="flex items-center gap-1">
          Payout
          <HelpTooltip content={helpContent.columns.payout} />
        </span>
      ),
      cell: ({ row }) => (
        <span className="font-semibold text-green-600">
          ${row.original.payout_amount.toLocaleString()}
        </span>
      ),
    },
    {
      accessorKey: 'escrow_status',
      header: () => (
        <span className="flex items-center gap-1">
          Escrow
          <HelpTooltip content={helpContent.columns.status} />
        </span>
      ),
      cell: ({ row }) => <StatusBadge status={row.original.escrow_status} />,
    },
    {
      id: 'auto_release',
      header: 'Auto Release',
      cell: ({ row }) => {
        const daysUntil = differenceInDays(
          new Date(row.original.auto_release_date),
          new Date()
        );
        return (
          <span className="text-sm text-slate-500">
            {daysUntil > 0 ? `${daysUntil} days` : 'Today'}
          </span>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <Button
          size="sm"
          onClick={() =>
            setReleaseModal({ open: true, bookingId: row.original.booking_id })
          }
          disabled={row.original.escrow_status === 'released'}
        >
          Release Payout
        </Button>
      ),
    },
  ];

  if (isError) {
    return (
      <Layout title="Payouts" description="Process performer payouts" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Payouts" description="Process performer payouts" helpContent={helpContent.helpCard}>
      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <StatCard
          title="Pending Payouts"
          value={loadingStats ? '...' : `$${(stats?.pending ?? 0).toLocaleString()}`}
          icon={<Clock className="w-5 h-5" />}
        />
        <StatCard
          title="Total Released"
          value={loadingStats ? '...' : `$${(stats?.released ?? 0).toLocaleString()}`}
          icon={<CheckCircle className="w-5 h-5" />}
        />
      </div>

      {/* Table */}
      <Card padding="none">
        <div className="p-4 border-b border-slate-200 flex items-center justify-between">
          <h2 className="text-lg font-semibold text-slate-900">Pending Payouts</h2>
          <Button variant="outline" icon={<Download className="w-4 h-4" />}>
            Export
          </Button>
        </div>
        <Table
          data={payouts ?? []}
          columns={columns}
          loading={loadingPayouts}
          emptyMessage="No pending payouts"
        />
      </Card>

      {/* Release Confirmation Modal */}
      <ConfirmModal
        isOpen={releaseModal.open}
        onClose={() => setReleaseModal({ open: false, bookingId: null })}
        onConfirm={() =>
          releaseModal.bookingId && releaseMutation.mutate(releaseModal.bookingId)
        }
        title="Release Payout"
        message="Are you sure you want to release this payout to the performer? This action cannot be undone."
        confirmText="Release Payout"
        loading={releaseMutation.isPending}
      />
    </Layout>
  );
}
