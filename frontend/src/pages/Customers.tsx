import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  EmptyState,
  emptyStates,
  HelpCard,
  type TableColumnDef,
} from '@/components/common';
import { customersApi, type Customer } from '@/api/endpoints';
import { Users, Mail, Phone, Calendar, DollarSign, ShoppingBag } from 'lucide-react';
import { format } from 'date-fns';

export default function Customers() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');

  const { data, isLoading, isError } = useQuery({
    queryKey: ['customers', page, search],
    queryFn: () =>
      customersApi.getAll({
        page,
        per_page: 20,
        search: search || undefined,
      }),
  });

  const columns: TableColumnDef<Customer>[] = [
    {
      accessorKey: 'display_name',
      header: 'Customer',
      cell: ({ row }) => (
        <div>
          <p className="font-medium text-slate-900">{row.original.display_name}</p>
          {(row.original.first_name || row.original.last_name) && (
            <p className="text-sm text-slate-500">
              {row.original.first_name} {row.original.last_name}
            </p>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'email',
      header: 'Contact',
      cell: ({ row }) => (
        <div className="space-y-1">
          <div className="flex items-center gap-2 text-sm">
            <Mail className="w-4 h-4 text-slate-400" />
            <a
              href={`mailto:${row.original.email}`}
              className="text-primary-600 hover:underline"
            >
              {row.original.email}
            </a>
          </div>
          {row.original.phone && (
            <div className="flex items-center gap-2 text-sm text-slate-600">
              <Phone className="w-4 h-4 text-slate-400" />
              {row.original.phone}
            </div>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'total_bookings',
      header: 'Bookings',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <ShoppingBag className="w-4 h-4 text-slate-400" />
          <span className="font-medium">{row.original.total_bookings}</span>
        </div>
      ),
    },
    {
      accessorKey: 'total_spent',
      header: 'Total Spent',
      cell: ({ row }) => (
        <div className="flex items-center gap-2">
          <DollarSign className="w-4 h-4 text-green-500" />
          <span className="font-medium text-green-700">
            ${row.original.total_spent.toLocaleString()}
          </span>
        </div>
      ),
    },
    {
      accessorKey: 'last_booking_date',
      header: 'Last Booking',
      cell: ({ row }) =>
        row.original.last_booking_date ? (
          <span className="text-sm">
            {format(new Date(row.original.last_booking_date), 'MMM d, yyyy')}
          </span>
        ) : (
          <span className="text-slate-400">Never</span>
        ),
    },
    {
      accessorKey: 'created_at',
      header: 'Joined',
      cell: ({ row }) => (
        <div className="flex items-center gap-2 text-sm text-slate-600">
          <Calendar className="w-4 h-4 text-slate-400" />
          {format(new Date(row.original.created_at), 'MMM d, yyyy')}
        </div>
      ),
    },
  ];

  if (isError) {
    return (
      <Layout title="Customers" description="View and manage customer accounts">
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Customers" description="View and manage customer accounts">
      {/* Help Section */}
      <HelpCard
        title="About Customers"
        icon={<Users className="w-5 h-5" />}
        className="mb-6"
      >
        <p>
          Customers are users who book performers on your platform. They create accounts when making
          their first booking or registering directly. This view shows customer contact information,
          booking history, and total spend.
        </p>
      </HelpCard>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search by name or email..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Button
            variant="ghost"
            onClick={() => {
              setSearch('');
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
            <Users className="w-5 h-5 text-primary-600" />
            <h2 className="text-lg font-semibold text-slate-900">Customer Accounts</h2>
          </div>
          <span className="text-sm text-slate-500">
            {data?.total || 0} total customers
          </span>
        </div>
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyState={emptyStates.customers}
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
