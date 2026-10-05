import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Textarea,
  Table,
  Pagination,
  Badge,
  Modal,
  EmptyState,
  useToast,
  emptyStates,
  HelpTooltip,
  type TableColumnDef,
} from '@/components/common';
import { reviews as helpContent } from '@/constants/helpContent';
import { reviewsApi } from '@/api';
import { useFilterStore } from '@/store';
import type { Review } from '@/types';
import { Star, Flag, Eye, EyeOff, MessageSquare, AlertTriangle } from 'lucide-react';
import { format } from 'date-fns';
import { clsx } from 'clsx';

export default function Reviews() {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [activeTab, setActiveTab] = useState<'all' | 'flagged'>('all');
  const [arbitrationModal, setArbitrationModal] = useState<{
    open: boolean;
    review: Review | null;
  }>({ open: false, review: null });
  const [arbitrationNotes, setArbitrationNotes] = useState('');

  const { reviewFilters, setReviewFilter } = useFilterStore();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['reviews', page, activeTab, reviewFilters],
    queryFn: () =>
      reviewsApi.getAll({
        page,
        per_page: 20,
        flagged: activeTab === 'flagged' ? true : undefined,
        search: reviewFilters.search || undefined,
      }),
  });

  const arbitrateMutation = useMutation({
    mutationFn: ({
      id,
      decision,
      notes,
    }: {
      id: number;
      decision: 'keep' | 'remove' | 'edit';
      notes: string;
    }) => reviewsApi.arbitrate(id, decision, notes),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
      toast.success('Review arbitrated');
      setArbitrationModal({ open: false, review: null });
      setArbitrationNotes('');
    },
    onError: () => {
      toast.error('Failed to arbitrate review');
    },
  });

  const toggleVisibilityMutation = useMutation({
    mutationFn: ({ id, visible }: { id: number; visible: boolean }) =>
      reviewsApi.toggleVisibility(id, visible),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['reviews'] });
      toast.success('Review visibility updated');
    },
    onError: () => {
      toast.error('Failed to update visibility');
    },
  });

  const renderStars = (rating: number) => (
    <div className="flex items-center gap-0.5">
      {[1, 2, 3, 4, 5].map((star) => (
        <Star
          key={star}
          className={clsx(
            'w-4 h-4',
            star <= rating ? 'text-amber-500 fill-amber-500' : 'text-slate-300'
          )}
        />
      ))}
    </div>
  );

  const columns: TableColumnDef<Review>[] = [
    {
      accessorKey: 'reviewer_name',
      header: () => (
        <span className="flex items-center gap-1">
          Reviewer
          <HelpTooltip content={helpContent.columns.customer} />
        </span>
      ),
      cell: ({ row }) => (
        <div>
          <p className="font-medium">{row.original.reviewer_name}</p>
          <p className="text-xs text-slate-500 capitalize">{row.original.reviewer_type}</p>
        </div>
      ),
    },
    {
      accessorKey: 'reviewee_name',
      header: () => (
        <span className="flex items-center gap-1">
          Performer
          <HelpTooltip content={helpContent.columns.performer} />
        </span>
      ),
    },
    {
      accessorKey: 'rating',
      header: () => (
        <span className="flex items-center gap-1">
          Rating
          <HelpTooltip content={helpContent.columns.rating} />
        </span>
      ),
      cell: ({ row }) => renderStars(row.original.rating),
    },
    {
      accessorKey: 'content',
      header: () => (
        <span className="flex items-center gap-1">
          Review
          <HelpTooltip content={helpContent.columns.content} />
        </span>
      ),
      cell: ({ row }) => (
        <p className="max-w-xs truncate text-sm text-slate-600">
          {row.original.content}
        </p>
      ),
    },
    {
      accessorKey: 'created_at',
      header: 'Date',
      cell: ({ row }) => format(new Date(row.original.created_at), 'MMM d, yyyy'),
    },
    {
      accessorKey: 'is_flagged',
      header: () => (
        <span className="flex items-center gap-1">
          Status
          <HelpTooltip content={helpContent.columns.flagged} />
        </span>
      ),
      cell: ({ row }) =>
        row.original.is_flagged ? (
          <Badge variant="danger">
            <Flag className="w-3 h-3 mr-1" />
            Flagged
          </Badge>
        ) : (
          <Badge variant="success">OK</Badge>
        ),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() =>
              toggleVisibilityMutation.mutate({
                id: row.original.id,
                visible: !row.original.is_visible,
              })
            }
            icon={
              row.original.is_visible ? (
                <EyeOff className="w-4 h-4" />
              ) : (
                <Eye className="w-4 h-4" />
              )
            }
          />
          {row.original.is_flagged && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() =>
                setArbitrationModal({ open: true, review: row.original })
              }
              icon={<MessageSquare className="w-4 h-4" />}
            />
          )}
        </div>
      ),
    },
  ];

  if (isError) {
    return (
      <Layout title="Reviews" description="Manage reviews and handle disputes" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Reviews" description="Manage reviews and handle disputes" helpContent={helpContent.helpCard}>
      {/* Tabs */}
      <div className="flex gap-1 mb-6 border-b border-slate-200">
        <button
          onClick={() => {
            setActiveTab('all');
            setPage(1);
          }}
          className={clsx(
            'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
            activeTab === 'all'
              ? 'border-primary-600 text-primary-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          )}
        >
          All Reviews
        </button>
        <button
          onClick={() => {
            setActiveTab('flagged');
            setPage(1);
          }}
          className={clsx(
            'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors flex items-center gap-2',
            activeTab === 'flagged'
              ? 'border-primary-600 text-primary-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          )}
        >
          <AlertTriangle className="w-4 h-4" />
          Flagged for Arbitration
        </button>
      </div>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex items-center gap-4">
          <div className="flex-1">
            <Input
              placeholder="Search reviews..."
              value={reviewFilters.search}
              onChange={(e) => setReviewFilter('search', e.target.value)}
            />
          </div>
        </div>
      </Card>

      {/* Table */}
      <Card padding="none">
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyMessage="No reviews found"
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

      {/* Arbitration Modal */}
      <Modal
        isOpen={arbitrationModal.open}
        onClose={() => setArbitrationModal({ open: false, review: null })}
        title="Arbitrate Review"
        size="lg"
      >
        {arbitrationModal.review && (
          <div className="space-y-6">
            {/* Review Details */}
            <div className="p-4 bg-slate-50 rounded-lg">
              <div className="flex items-center justify-between mb-2">
                <span className="font-medium">{arbitrationModal.review.reviewer_name}</span>
                {renderStars(arbitrationModal.review.rating)}
              </div>
              <p className="text-sm text-slate-600">{arbitrationModal.review.content}</p>
              {arbitrationModal.review.flag_reason && (
                <div className="mt-3 p-3 bg-red-50 border border-red-200 rounded-lg">
                  <p className="text-sm text-red-800">
                    <strong>Flag Reason:</strong> {arbitrationModal.review.flag_reason}
                  </p>
                </div>
              )}
            </div>

            {/* Arbitration Notes */}
            <Textarea
              label="Arbitration Notes"
              rows={4}
              value={arbitrationNotes}
              onChange={(e) => setArbitrationNotes(e.target.value)}
              placeholder="Explain your decision..."
            />

            {/* Actions */}
            <div className="flex justify-end gap-3">
              <Button
                variant="outline"
                onClick={() => setArbitrationModal({ open: false, review: null })}
              >
                Cancel
              </Button>
              <Button
                variant="danger"
                onClick={() =>
                  arbitrateMutation.mutate({
                    id: arbitrationModal.review!.id,
                    decision: 'remove',
                    notes: arbitrationNotes,
                  })
                }
                loading={arbitrateMutation.isPending}
              >
                Remove Review
              </Button>
              <Button
                variant="success"
                onClick={() =>
                  arbitrateMutation.mutate({
                    id: arbitrationModal.review!.id,
                    decision: 'keep',
                    notes: arbitrationNotes,
                  })
                }
                loading={arbitrateMutation.isPending}
              >
                Keep Review
              </Button>
            </div>
          </div>
        )}
      </Modal>
    </Layout>
  );
}
