import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Select,
  Table,
  Pagination,
  StatusBadge,
  TierBadge,
  LevelBadge,
  EmptyState,
  ConfirmModal,
  useToast,
  emptyStates,
  HelpTooltip,
  Badge,
  type TableColumnDef,
} from '@/components/common';
import { performers as helpContent } from '@/constants/helpContent';
import { performersApi } from '@/api';
import { useFilterStore } from '@/store';
import type { Performer } from '@/types';
import {
  Star,
  CheckCircle,
  XCircle,
  Edit,
  Globe,
  ChevronDown,
  ChevronRight,
  ExternalLink,
  Eye,
  Calendar,
  Link2,
  Crown,
  Search,
} from 'lucide-react';
import { format } from 'date-fns';

export default function Performers() {
  const navigate = useNavigate();
  const toast = useToast();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [expandedRows, setExpandedRows] = useState<Set<number>>(new Set());
  const [deleteModal, setDeleteModal] = useState<{ open: boolean; ids: number[] }>({
    open: false,
    ids: [],
  });

  const { performerFilters, setPerformerFilter, resetPerformerFilters } = useFilterStore();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['performers', page, performerFilters],
    queryFn: () =>
      performersApi.getAll({
        page,
        per_page: 20,
        search: performerFilters.search || undefined,
      }),
  });

  const verifyMutation = useMutation({
    mutationFn: ({ id, verified }: { id: number; verified: boolean }) =>
      performersApi.verify(id, verified),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performers'] });
      toast.success('Performer verification updated');
    },
    onError: () => {
      toast.error('Failed to update verification');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (ids: number[]) => performersApi.bulkDelete(ids),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performers'] });
      toast.success('Performers deleted');
      setDeleteModal({ open: false, ids: [] });
    },
    onError: () => {
      toast.error('Failed to delete performers');
    },
  });

  const toggleRow = (id: number) => {
    setExpandedRows((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const handleEditClick = (performer: Performer, e: React.MouseEvent) => {
    e.stopPropagation();
    if (performer.microsite_id) {
      // Has microsite - go to full editor
      navigate(`/performers/${performer.id}/edit`);
    } else {
      // No microsite - go to performer profile only
      navigate(`/performers/${performer.id}/profile`);
    }
  };

  const columns: TableColumnDef<Performer>[] = [
    {
      accessorKey: 'stage_name',
      header: () => (
        <span className="flex items-center gap-1">
          Performer
          <HelpTooltip content={helpContent.columns.name} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-3">
          {row.original.microsite_id && (
            <button
              onClick={(e) => {
                e.stopPropagation();
                toggleRow(row.original.id);
              }}
              className="p-1 hover:bg-slate-100 rounded transition-colors"
            >
              {expandedRows.has(row.original.id) ? (
                <ChevronDown className="w-4 h-4 text-slate-400" />
              ) : (
                <ChevronRight className="w-4 h-4 text-slate-400" />
              )}
            </button>
          )}
          {!row.original.microsite_id && <div className="w-6" />}
          <div>
            <p className="font-medium text-slate-900">{row.original.stage_name}</p>
            <p className="text-xs text-slate-500">{row.original.email}</p>
          </div>
        </div>
      ),
    },
    {
      accessorKey: 'tier',
      header: () => (
        <span className="flex items-center gap-1">
          Tier
          <HelpTooltip content={helpContent.columns.tier} />
        </span>
      ),
      cell: ({ row }) => <TierBadge tier={row.original.tier} />,
    },
    {
      accessorKey: 'achievement_level',
      header: () => (
        <span className="flex items-center gap-1">
          Level
          <HelpTooltip content={helpContent.columns.level} />
        </span>
      ),
      cell: ({ row }) => <LevelBadge level={row.original.achievement_level} />,
    },
    {
      accessorKey: 'completed_bookings',
      header: () => (
        <span className="flex items-center gap-1">
          Bookings
          <HelpTooltip content={helpContent.columns.bookings} />
        </span>
      ),
      cell: ({ row }) => row.original.completed_bookings,
    },
    {
      accessorKey: 'average_rating',
      header: () => (
        <span className="flex items-center gap-1">
          Rating
          <HelpTooltip content={helpContent.columns.rating} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-1">
          <Star className="w-4 h-4 text-amber-500 fill-amber-500" />
          <span>{row.original.average_rating.toFixed(1)}</span>
          <span className="text-slate-400">({row.original.total_reviews})</span>
        </div>
      ),
    },
    {
      accessorKey: 'is_verified',
      header: () => (
        <span className="flex items-center gap-1">
          Verified
          <HelpTooltip content={helpContent.columns.verified} />
        </span>
      ),
      cell: ({ row }) =>
        row.original.is_verified ? (
          <CheckCircle className="w-5 h-5 text-green-500" />
        ) : (
          <XCircle className="w-5 h-5 text-slate-300" />
        ),
    },
    {
      accessorKey: 'status',
      header: () => (
        <span className="flex items-center gap-1">
          Status
          <HelpTooltip content={helpContent.columns.status} />
        </span>
      ),
      cell: ({ row }) => <StatusBadge status={row.original.status} />,
    },
    {
      accessorKey: 'microsite_status',
      header: () => (
        <span className="flex items-center gap-1">
          Microsite
        </span>
      ),
      cell: ({ row }) => {
        const { microsite_status, microsite_id } = row.original;
        if (!microsite_id) {
          return (
            <span className="text-slate-400 text-sm">—</span>
          );
        }
        return (
          <button
            onClick={(e) => {
              e.stopPropagation();
              toggleRow(row.original.id);
            }}
            className="flex items-center gap-1.5 hover:opacity-80 transition-opacity"
          >
            {microsite_status === 'active' ? (
              <>
                <Globe className="w-4 h-4 text-green-500" />
                <span className="text-xs font-medium text-green-600">Live</span>
              </>
            ) : (
              <>
                <Globe className="w-4 h-4 text-slate-400" />
                <span className="text-xs text-slate-500 capitalize">{microsite_status}</span>
              </>
            )}
          </button>
        );
      },
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={(e) => handleEditClick(row.original, e)}
            icon={<Edit className="w-4 h-4" />}
          />
          <Button
            variant="ghost"
            size="sm"
            onClick={(e) => {
              e.stopPropagation();
              verifyMutation.mutate({
                id: row.original.id,
                verified: !row.original.is_verified,
              });
            }}
            icon={
              row.original.is_verified ? (
                <XCircle className="w-4 h-4" />
              ) : (
                <CheckCircle className="w-4 h-4" />
              )
            }
          />
        </div>
      ),
    },
  ];

  // Custom row renderer that includes expandable microsite details
  const renderExpandedRow = (performer: Performer) => {
    if (!performer.microsite_id || !expandedRows.has(performer.id)) {
      return null;
    }

    return (
      <tr className="bg-slate-50 border-b border-slate-200">
        <td colSpan={9} className="px-6 py-4">
          <div className="flex items-start gap-8">
            {/* Microsite Info */}
            <div className="flex-1">
              <div className="flex items-center gap-2 mb-3">
                <Crown className="w-4 h-4 text-amber-500" />
                <h4 className="font-medium text-slate-900">Microsite Details</h4>
                <Badge
                  variant={performer.microsite_status === 'active' ? 'success' : 'default'}
                  className="ml-2"
                >
                  {performer.microsite_status === 'active' ? 'Live' : performer.microsite_status}
                </Badge>
              </div>
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <p className="text-xs text-slate-500 mb-1 flex items-center gap-1">
                    <Link2 className="w-3 h-3" /> Slug
                  </p>
                  <p className="text-sm font-medium text-slate-700">
                    /{performer.microsite_slug || 'Not set'}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-slate-500 mb-1 flex items-center gap-1">
                    <Calendar className="w-3 h-3" /> Created
                  </p>
                  <p className="text-sm text-slate-700">
                    {performer.microsite_created_at
                      ? format(new Date(performer.microsite_created_at), 'MMM d, yyyy')
                      : '—'}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-slate-500 mb-1 flex items-center gap-1">
                    <Eye className="w-3 h-3" /> Total Views
                  </p>
                  <p className="text-sm font-medium text-slate-700">
                    {performer.microsite_views?.toLocaleString() || '0'}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-slate-500 mb-1">Actions</p>
                  <div className="flex items-center gap-2">
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => navigate(`/performers/${performer.id}/edit`)}
                      icon={<Edit className="w-3 h-3" />}
                    >
                      Edit
                    </Button>
                    {performer.microsite_status === 'active' && (
                      <Button
                        size="sm"
                        variant="ghost"
                        onClick={() => window.open(`/performer/${performer.microsite_slug}`, '_blank')}
                        icon={<ExternalLink className="w-3 h-3" />}
                      >
                        View
                      </Button>
                    )}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </td>
      </tr>
    );
  };

  if (isError) {
    return (
      <Layout title="Performers" description="Manage your performers" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Performers" description="Manage your performers" helpContent={helpContent.helpCard}>
      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search performers..."
              value={performerFilters.search}
              onChange={(e) => setPerformerFilter('search', e.target.value)}
              leftIcon={<Search className="w-4 h-4" />}
            />
          </div>
          <Select
            options={[
              { value: '', label: 'All Tiers' },
              { value: 'free', label: 'Free' },
              { value: 'pro', label: 'Pro' },
            ]}
            value={performerFilters.tier}
            onChange={(e) => setPerformerFilter('tier', e.target.value)}
          />
          <Select
            options={[
              { value: '', label: 'All Statuses' },
              { value: 'active', label: 'Active' },
              { value: 'inactive', label: 'Inactive' },
              { value: 'suspended', label: 'Suspended' },
            ]}
            value={performerFilters.status}
            onChange={(e) => setPerformerFilter('status', e.target.value)}
          />
          <Button variant="ghost" onClick={resetPerformerFilters}>
            Clear Filters
          </Button>
        </div>
      </Card>

      {/* Table */}
      <Card padding="none" className="overflow-x-auto">
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyMessage="No performers found"
          onRowClick={(performer) => {
            if (performer.microsite_id) {
              toggleRow(performer.id);
            } else {
              navigate(`/performers/${performer.id}/profile`);
            }
          }}
          renderExpandedRow={renderExpandedRow}
          expandedRows={expandedRows}
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

      {/* Delete Confirmation Modal */}
      <ConfirmModal
        isOpen={deleteModal.open}
        onClose={() => setDeleteModal({ open: false, ids: [] })}
        onConfirm={() => deleteMutation.mutate(deleteModal.ids)}
        title="Delete Performers"
        message={`Are you sure you want to delete ${deleteModal.ids.length} performer(s)? This action cannot be undone.`}
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </Layout>
  );
}
