import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Select,
  Table,
  Pagination,
  Badge,
  EmptyState,
  ConfirmModal,
  useToast,
  emptyStates,
  HelpTooltip,
  type TableColumnDef,
} from '@/components/common';
import { microsites as helpContent } from '@/constants/helpContent';
import { micrositesApi } from '@/api';
import type { Microsite, MicrositeStatus } from '@/types';
import { Globe, Eye, ExternalLink, Trash2, Edit2 } from 'lucide-react';
import { format } from 'date-fns';

const statusOptions = [
  { value: '', label: 'All Statuses' },
  { value: 'active', label: 'Active' },
  { value: 'pending', label: 'Pending' },
  { value: 'inactive', label: 'Inactive' },
  { value: 'expired', label: 'Expired' },
];

const getStatusVariant = (status: MicrositeStatus) => {
  switch (status) {
    case 'active':
      return 'success';
    case 'pending':
      return 'warning';
    case 'inactive':
      return 'default';
    case 'expired':
      return 'danger';
    default:
      return 'default';
  }
};

export default function Microsites() {
  const toast = useToast();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [deleteModal, setDeleteModal] = useState<{ open: boolean; id: number | null }>({
    open: false,
    id: null,
  });

  const { data, isLoading, isError } = useQuery({
    queryKey: ['microsites', page, search, statusFilter],
    queryFn: () =>
      micrositesApi.getAll({
        page,
        per_page: 20,
        search: search || undefined,
        status: statusFilter || undefined,
      }),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => micrositesApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['microsites'] });
      toast.success('Microsite deleted');
      setDeleteModal({ open: false, id: null });
    },
    onError: () => {
      toast.error('Failed to delete microsite');
    },
  });

  const columns: TableColumnDef<Microsite>[] = [
    {
      accessorKey: 'performer_name',
      header: () => (
        <span className="flex items-center gap-1">
          Performer
          <HelpTooltip content={helpContent.columns.performer} />
        </span>
      ),
      cell: ({ row }) => (
        <div>
          <p className="font-medium text-slate-900">{row.original.performer_name}</p>
          {row.original.custom_domain && row.original.domain_verified && (
            <p className="text-xs text-primary-600">{row.original.custom_domain}</p>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'slug',
      header: () => (
        <span className="flex items-center gap-1">
          Slug
          <HelpTooltip content={helpContent.columns.slug} />
        </span>
      ),
      cell: ({ row }) => (
        <code className="text-sm bg-slate-100 px-2 py-1 rounded">
          /comedy/{row.original.slug}/
        </code>
      ),
    },
    {
      accessorKey: 'design_settings',
      header: () => (
        <span className="flex items-center gap-1">
          Template
          <HelpTooltip content={helpContent.columns.template} />
        </span>
      ),
      cell: ({ row }) => (
        <Badge variant="default">
          {row.original.design_settings?.template || 'classic'}
        </Badge>
      ),
    },
    {
      accessorKey: 'view_count',
      header: () => (
        <span className="flex items-center gap-1">
          Views
          <HelpTooltip content={helpContent.columns.views} />
        </span>
      ),
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Eye className="w-4 h-4 text-slate-400" />
          <span>{row.original.view_count.toLocaleString()}</span>
        </div>
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
      cell: ({ row }) => (
        <Badge variant={getStatusVariant(row.original.status)}>
          {row.original.status}
        </Badge>
      ),
    },
    {
      accessorKey: 'created_at',
      header: 'Created',
      cell: ({ row }) => format(new Date(row.original.created_at), 'MMM d, yyyy'),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate(`/microsites/${row.original.id}/edit`)}
            icon={<Edit2 className="w-4 h-4" />}
          >
            Edit
          </Button>
          {row.original.slug && (
            <Button
              variant="ghost"
              size="sm"
              onClick={() =>
                window.open(`/comedy/${row.original.slug}/`, '_blank')
              }
              icon={<ExternalLink className="w-4 h-4" />}
            />
          )}
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setDeleteModal({ open: true, id: row.original.id })}
            icon={<Trash2 className="w-4 h-4 text-red-500" />}
          />
        </div>
      ),
    },
  ];

  if (isError) {
    return (
      <Layout title="Microsites" description="Manage performer microsites" helpContent={helpContent.helpCard}>
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Microsites" description="Manage performer landing pages" helpContent={helpContent.helpCard}>
      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search by performer or slug..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Select
            options={statusOptions}
            value={statusFilter}
            onChange={(e) => {
              setStatusFilter(e.target.value);
              setPage(1);
            }}
          />
          <Button
            variant="ghost"
            onClick={() => {
              setSearch('');
              setStatusFilter('');
              setPage(1);
            }}
          >
            Clear
          </Button>
        </div>
      </Card>

      {/* Table */}
      <Card padding="none">
        <div className="p-4 border-b border-slate-200 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <Globe className="w-5 h-5 text-primary-600" />
            <h2 className="text-lg font-semibold text-slate-900">Performer Microsites</h2>
          </div>
          <span className="text-sm text-slate-500">
            {data?.total || 0} total microsites
          </span>
        </div>
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyState={emptyStates.microsites}
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

      {/* Delete Confirmation */}
      <ConfirmModal
        isOpen={deleteModal.open}
        onClose={() => setDeleteModal({ open: false, id: null })}
        onConfirm={() => deleteModal.id && deleteMutation.mutate(deleteModal.id)}
        title="Delete Microsite"
        message="Are you sure you want to delete this microsite? This action cannot be undone."
        confirmText="Delete"
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </Layout>
  );
}
