import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Layout } from '@/components/layout';
import {
  Card,
  Button,
  Input,
  Table,
  Pagination,
  Badge,
  EmptyState,
  emptyStates,
  HelpCard,
  Modal,
  type TableColumnDef,
} from '@/components/common';
import { messagesApi, type Conversation, type Message } from '@/api/endpoints';
import { MessageSquare, User, Eye, Mail } from 'lucide-react';
import { format, formatDistanceToNow } from 'date-fns';

export default function Messages() {
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [selectedConversation, setSelectedConversation] = useState<Conversation | null>(null);
  const [viewModalOpen, setViewModalOpen] = useState(false);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['conversations', page, search],
    queryFn: () =>
      messagesApi.getConversations({
        page,
        per_page: 20,
        search: search || undefined,
      }),
  });

  const { data: messagesData, isLoading: messagesLoading } = useQuery({
    queryKey: ['messages', selectedConversation?.id],
    queryFn: () =>
      selectedConversation
        ? messagesApi.getMessages(selectedConversation.id, { per_page: 50 })
        : null,
    enabled: !!selectedConversation && viewModalOpen,
  });

  const handleViewMessages = (conversation: Conversation) => {
    setSelectedConversation(conversation);
    setViewModalOpen(true);
  };

  const columns: TableColumnDef<Conversation>[] = [
    {
      accessorKey: 'participants',
      header: 'Participants',
      cell: ({ row }) => (
        <div className="space-y-1">
          <div className="flex items-center gap-2">
            <User className="w-4 h-4 text-slate-400" />
            <span className="font-medium text-slate-900">
              {row.original.participant_1_name}
            </span>
            <Badge variant="default" className="text-xs">
              {row.original.participant_1_type}
            </Badge>
          </div>
          <div className="flex items-center gap-2 text-slate-600">
            <User className="w-4 h-4 text-slate-400" />
            <span>{row.original.participant_2_name}</span>
            <Badge variant="default" className="text-xs">
              {row.original.participant_2_type}
            </Badge>
          </div>
        </div>
      ),
    },
    {
      accessorKey: 'last_message',
      header: 'Last Message',
      cell: ({ row }) => (
        <div className="max-w-md">
          <p className="text-sm text-slate-700 truncate">
            {row.original.last_message || 'No messages yet'}
          </p>
          {row.original.last_message_at && (
            <p className="text-xs text-slate-500 mt-1">
              {formatDistanceToNow(new Date(row.original.last_message_at), { addSuffix: true })}
            </p>
          )}
        </div>
      ),
    },
    {
      accessorKey: 'unread_count',
      header: 'Unread',
      cell: ({ row }) =>
        row.original.unread_count > 0 ? (
          <Badge variant="warning">{row.original.unread_count}</Badge>
        ) : (
          <span className="text-slate-400">-</span>
        ),
    },
    {
      accessorKey: 'booking_id',
      header: 'Linked Booking',
      cell: ({ row }) =>
        row.original.booking_id ? (
          <Badge variant="info">#{row.original.booking_id}</Badge>
        ) : (
          <span className="text-slate-400">None</span>
        ),
    },
    {
      accessorKey: 'created_at',
      header: 'Started',
      cell: ({ row }) => format(new Date(row.original.created_at), 'MMM d, yyyy'),
    },
    {
      id: 'actions',
      header: '',
      cell: ({ row }) => (
        <Button
          variant="ghost"
          size="sm"
          onClick={() => handleViewMessages(row.original)}
          icon={<Eye className="w-4 h-4" />}
        >
          View
        </Button>
      ),
    },
  ];

  if (isError) {
    return (
      <Layout title="Messages" description="View conversations between performers and customers">
        <Card>
          <EmptyState {...emptyStates.error} />
        </Card>
      </Layout>
    );
  }

  return (
    <Layout title="Messages" description="View conversations between performers and customers">
      {/* Help Section */}
      <HelpCard
        title="About Messages"
        icon={<Mail className="w-5 h-5" />}
        className="mb-6"
      >
        <p>
          This section displays all conversations between performers and customers on your platform.
          Messages are often linked to bookings or inquiries. You can view message content but cannot
          send or edit messages from this admin interface.
        </p>
      </HelpCard>

      {/* Filters */}
      <Card className="mb-6">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex-1 min-w-[200px]">
            <Input
              placeholder="Search by participant name..."
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
            <MessageSquare className="w-5 h-5 text-primary-600" />
            <h2 className="text-lg font-semibold text-slate-900">Conversations</h2>
          </div>
          <span className="text-sm text-slate-500">
            {data?.total || 0} total conversations
          </span>
        </div>
        <Table
          data={data?.data ?? []}
          columns={columns}
          loading={isLoading}
          emptyState={emptyStates.messages}
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

      {/* View Messages Modal */}
      <Modal
        isOpen={viewModalOpen}
        onClose={() => {
          setViewModalOpen(false);
          setSelectedConversation(null);
        }}
        title="Conversation"
        size="lg"
      >
        {selectedConversation && (
          <div>
            <div className="flex items-center justify-between mb-4 pb-4 border-b border-slate-200">
              <div>
                <p className="font-medium">
                  {selectedConversation.participant_1_name} &amp; {selectedConversation.participant_2_name}
                </p>
                {selectedConversation.booking_id && (
                  <p className="text-sm text-slate-500">
                    Related to Booking #{selectedConversation.booking_id}
                  </p>
                )}
              </div>
            </div>

            <div className="max-h-96 overflow-y-auto space-y-4">
              {messagesLoading ? (
                <div className="text-center py-8 text-slate-500">Loading messages...</div>
              ) : messagesData?.data && messagesData.data.length > 0 ? (
                messagesData.data.map((message: Message) => (
                  <div
                    key={message.id}
                    className={`p-3 rounded-lg ${
                      message.sender_type === 'performer'
                        ? 'bg-primary-50 ml-8'
                        : 'bg-slate-100 mr-8'
                    }`}
                  >
                    <div className="flex items-center justify-between mb-1">
                      <span className="text-sm font-medium text-slate-900">
                        {message.sender_name}
                      </span>
                      <span className="text-xs text-slate-500">
                        {format(new Date(message.created_at), 'MMM d, h:mm a')}
                      </span>
                    </div>
                    <p className="text-sm text-slate-700">{message.content}</p>
                  </div>
                ))
              ) : (
                <div className="text-center py-8 text-slate-500">No messages in this conversation</div>
              )}
            </div>
          </div>
        )}
      </Modal>
    </Layout>
  );
}
