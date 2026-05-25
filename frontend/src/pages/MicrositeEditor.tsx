import { useState, useEffect, useCallback, useRef } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { clsx } from 'clsx';
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core';
import {
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
  Card,
  Button,
  Input,
  Select,
  Badge,
  useToast,
} from '@/components/common';
import { performersApi, type AvailabilityEvent } from '@/api';
import type {
  Microsite,
  MicrositeStatus,
  MicrositeTemplate,
  MicrositePreviewData,
  MicrositeAnalytics,
  ExternalGig,
  SocialLinks,
  LayoutSettings,
  HeroStyle,
  MicrositeSection,
} from '@/types';
import {
  ArrowLeft,
  Eye,
  Settings,
  Palette,
  Layout as LayoutIcon,
  Share2,
  Calendar,
  BarChart3,
  Monitor,
  Tablet,
  Smartphone,
  ExternalLink,
  RefreshCw,
  Plus,
  Trash2,
  GripVertical,
  Check,
  Loader2,
  AlertCircle,
  Image,
  Upload,
  X,
  Video,
  Lock,
  DollarSign,
  MapPin,
  FileText,
  Crown,
  User,
  Shield,
  LayoutDashboard,
  CalendarDays,
  MessageSquare,
  Star,
  Wallet,
  Clock,
  TrendingUp,
  CalendarClock,
  Edit2,
} from 'lucide-react';
import { format, parse, startOfWeek, getDay, addMonths, subMonths, startOfMonth, endOfMonth, startOfDay, addDays, isBefore } from 'date-fns';
import { enUS } from 'date-fns/locale';
import { Calendar as BigCalendar, dateFnsLocalizer, type View } from 'react-big-calendar';
import 'react-big-calendar/lib/css/react-big-calendar.css';

// Set up date-fns localizer for react-big-calendar
const locales = { 'en-US': enUS };
const localizer = dateFnsLocalizer({
  format,
  parse,
  startOfWeek,
  getDay,
  locales,
});

/**
 * Parse a date string from the API as LOCAL time.
 *
 * CRITICAL: JavaScript's new Date("2025-12-31") parses date-only strings as UTC midnight,
 * which displays as the PREVIOUS day in western timezones. This helper ensures dates
 * are parsed as local midnight instead.
 *
 * @param dateStr - A date string like "2025-12-31" or "2025-12-31T00:00:00"
 * @returns A Date object representing midnight LOCAL time on that date
 */
const parseLocalDate = (dateStr: string): Date => {
  // If it's just a date (YYYY-MM-DD), append T00:00:00 to force local parsing
  if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
    return new Date(dateStr + 'T00:00:00');
  }
  // If it already has time info, let JavaScript handle it
  return new Date(dateStr);
};

// Dashboard tabs + Profile tabs + Microsite tabs
type TabId =
  // Dashboard
  | 'overview'
  // Business
  | 'bookings' | 'availability' | 'messages' | 'reviews' | 'payouts'
  // Profile
  | 'profile' | 'media' | 'pricing' | 'location' | 'bio'
  // Microsite (Premium)
  | 'design' | 'layout' | 'social' | 'gigs' | 'analytics';

type TabSection = 'dashboard' | 'business' | 'profile' | 'microsite';
type DevicePreview = 'desktop' | 'tablet' | 'mobile';
type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

const AUTOSAVE_DELAY = 2000; // 2 seconds after user stops making changes

// WordPress Media Library types
declare global {
  interface Window {
    wp?: {
      media: (options: {
        title: string;
        library: { type: string };
        multiple: boolean;
        button: { text: string };
      }) => {
        on: (event: string, callback: () => void) => void;
        state: () => { get: (key: string) => { toJSON: () => Array<{ id: number; url: string; filename: string }> | { id: number; url: string; filename: string } } };
        open: () => void;
      };
    };
  }
}

interface TabConfig {
  id: TabId;
  label: string;
  icon: typeof Settings;
  section: TabSection;
  premium?: boolean;
}

const tabs: TabConfig[] = [
  // Dashboard section
  { id: 'overview', label: 'Overview', icon: LayoutDashboard, section: 'dashboard' },
  // Business section
  { id: 'bookings', label: 'Bookings', icon: CalendarClock, section: 'business' },
  { id: 'availability', label: 'Availability', icon: CalendarDays, section: 'business' },
  { id: 'messages', label: 'Messages', icon: MessageSquare, section: 'business' },
  { id: 'reviews', label: 'Reviews', icon: Star, section: 'business' },
  { id: 'payouts', label: 'Payouts', icon: Wallet, section: 'business' },
  // Profile section
  { id: 'profile', label: 'Profile', icon: Settings, section: 'profile' },
  { id: 'media', label: 'Media', icon: Image, section: 'profile' },
  { id: 'pricing', label: 'Pricing', icon: DollarSign, section: 'profile' },
  { id: 'location', label: 'Location', icon: MapPin, section: 'profile' },
  { id: 'bio', label: 'Bio', icon: FileText, section: 'profile' },
  // Microsite section (Premium)
  { id: 'design', label: 'Theme', icon: Palette, section: 'microsite', premium: true },
  { id: 'layout', label: 'Layout', icon: LayoutIcon, section: 'microsite', premium: true },
  { id: 'social', label: 'Social Links', icon: Share2, section: 'microsite', premium: true },
  { id: 'gigs', label: 'External Gigs', icon: Calendar, section: 'microsite', premium: true },
  { id: 'analytics', label: 'Analytics', icon: BarChart3, section: 'microsite', premium: true },
];

const templateOptions: { value: MicrositeTemplate; label: string; description: string }[] = [
  { value: 'classic', label: 'Classic', description: 'Traditional and professional' },
  { value: 'modern', label: 'Modern', description: 'Clean and minimal' },
  { value: 'bold', label: 'Bold', description: 'Eye-catching and vibrant' },
  { value: 'minimal', label: 'Minimal', description: 'Simple and elegant' },
];


const sectionLabels: Record<MicrositeSection, string> = {
  hero: 'Hero Section',
  bio: 'Biography',
  gallery: 'Photo Gallery',
  reviews: 'Reviews',
  calendar: 'Availability Calendar',
  social: 'Social Media Links',
  booking: 'Booking Button',
};

interface MediaOverrides {
  hero_image?: string;
  gallery?: Array<{ id: number; url: string }>;
  video_url?: string;
}

const defaultDesignSettings = {
  template: 'classic' as MicrositeTemplate,
  primary_color: '#3b82f6',
  secondary_color: '#1e40af',
  background_color: '#ffffff',
  text_color: '#1e293b',
  font_family: 'Inter',
  show_reviews: true,
  show_calendar: true,
  show_booking_button: true,
  media_overrides: {
    hero_image: '',
    gallery: [] as Array<{ id: number; url: string }>,
    video_url: '',
  } as MediaOverrides,
  custom_css: '',
  social_links: {
    facebook: '',
    instagram: '',
    tiktok: '',
    youtube: '',
    twitter: '',
    linkedin: '',
  } as SocialLinks,
  layout_settings: {
    hero_style: 'full_width' as HeroStyle,
    sections_order: ['hero', 'bio', 'gallery', 'reviews', 'calendar', 'social', 'booking'] as MicrositeSection[],
    show_external_gigs: true,
    external_gig_privacy: 'public' as const,
  } as LayoutSettings,
};

export default function MicrositeEditor() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const toast = useToast();
  const queryClient = useQueryClient();

  const [activeTab, setActiveTab] = useState<TabId>('overview');
  const [devicePreview, setDevicePreview] = useState<DevicePreview>('desktop');
  const [saveStatus, setSaveStatus] = useState<SaveStatus>('idle');
  const [isInitialized, setIsInitialized] = useState(false);
  const [viewMode, setViewMode] = useState<'admin' | 'performer'>('admin');
  const autoSaveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const savedIndicatorTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Form state - includes both performer profile and microsite settings
  const [formData, setFormData] = useState({
    // Performer profile fields
    stage_name: '',
    tagline: '',
    bio: '',
    hourly_rate: 0,
    minimum_booking: 1,
    deposit_percentage: 25,
    sale_price: 0,
    sale_active: false,
    location_city: '',
    location_state: '',
    travel_willing: false,
    travel_radius: 0,
    // Microsite fields
    slug: '',
    status: 'active' as MicrositeStatus,
    meta_title: '',
    meta_description: '',
    custom_domain: '',
    design_settings: defaultDesignSettings,
  });

  // External gig form state
  const [showGigForm, setShowGigForm] = useState(false);
  const [editingGig, setEditingGig] = useState<ExternalGig | null>(null);
  const [gigForm, setGigForm] = useState({
    date: '',
    event_name: '',
    venue_name: '',
    event_location: '',
    event_time: '',
    is_public: true,
    ticket_url: '',
  });

  // Fetch performer + microsite data
  const { data: previewData, isLoading: previewLoading } = useQuery({
    queryKey: ['performer-editor', id],
    queryFn: () => performersApi.getEditorData(Number(id)),
    enabled: !!id,
  });

  // Check if this performer has premium (active microsite)
  // Actual premium status based on microsite
  const actualPremium = previewData?.microsite?.status === 'active';
  // In performer view mode, simulate what a non-premium performer would see
  const hasPremium = viewMode === 'admin' ? actualPremium : false;

  // Fetch analytics
  const { data: analyticsData } = useQuery({
    queryKey: ['performer-analytics', id],
    queryFn: () => performersApi.getAnalytics(Number(id)),
    enabled: !!id && activeTab === 'analytics',
  });

  // Fetch external gigs
  const { data: gigsData, refetch: refetchGigs } = useQuery({
    queryKey: ['performer-gigs', id],
    queryFn: () => performersApi.getExternalGigs(Number(id)),
    enabled: !!id,
  });

  // Initialize form data when preview loads
  useEffect(() => {
    if (previewData) {
      const ms = previewData.microsite;
      const performer = previewData.performer;
      setFormData({
        // Performer profile fields
        stage_name: performer?.stage_name || '',
        tagline: performer?.tagline || '',
        bio: performer?.bio || '',
        hourly_rate: performer?.hourly_rate || 0,
        minimum_booking: performer?.minimum_booking || 1,
        deposit_percentage: performer?.deposit_percentage || 25,
        sale_price: performer?.sale_price || 0,
        sale_active: performer?.sale_active || false,
        location_city: performer?.location_city || '',
        location_state: performer?.location_state || '',
        travel_willing: performer?.travel_willing || false,
        travel_radius: performer?.travel_radius || 0,
        // Microsite fields
        slug: ms?.slug || '',
        status: ms?.status || 'active',
        meta_title: ms?.meta_title || '',
        meta_description: ms?.meta_description || '',
        custom_domain: ms?.custom_domain || '',
        design_settings: {
          ...defaultDesignSettings,
          ...ms?.design_settings,
          social_links: {
            ...defaultDesignSettings.social_links,
            ...(ms?.design_settings as any)?.social_links,
          },
          layout_settings: {
            ...defaultDesignSettings.layout_settings,
            ...(ms?.design_settings as any)?.layout_settings,
          },
          media_overrides: {
            ...defaultDesignSettings.media_overrides,
            ...(ms?.design_settings as any)?.media_overrides,
          },
        },
      });
      // Mark as initialized after a short delay to prevent auto-save on initial load
      setTimeout(() => setIsInitialized(true), 100);
    }
  }, [previewData]);

  // Cleanup timers on unmount
  useEffect(() => {
    return () => {
      if (autoSaveTimerRef.current) clearTimeout(autoSaveTimerRef.current);
      if (savedIndicatorTimerRef.current) clearTimeout(savedIndicatorTimerRef.current);
    };
  }, []);

  // Update mutation - use explicit type to avoid conflicts between performer and microsite status types
  const updateMutation = useMutation({
    mutationFn: (data: {
      performer?: {
        stage_name?: string;
        tagline?: string;
        bio?: string;
        hourly_rate?: number;
        minimum_booking?: number;
        deposit_percentage?: number;
        sale_price?: number;
        sale_active?: boolean;
        location_city?: string;
        location_state?: string;
        travel_willing?: boolean;
        travel_radius?: number;
      };
      microsite?: Partial<Microsite>;
      design_settings?: typeof formData.design_settings;
    }) => performersApi.updateEditorData(Number(id), data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performer-editor', id] });
      queryClient.invalidateQueries({ queryKey: ['performers'] });
      setSaveStatus('saved');
      // Reset to idle after 3 seconds
      if (savedIndicatorTimerRef.current) clearTimeout(savedIndicatorTimerRef.current);
      savedIndicatorTimerRef.current = setTimeout(() => setSaveStatus('idle'), 3000);
    },
    onError: () => {
      setSaveStatus('error');
      toast.error('Failed to save changes');
    },
  });

  // Auto-save function
  const performAutoSave = useCallback(() => {
    setSaveStatus('saving');
    updateMutation.mutate({
      performer: {
        stage_name: formData.stage_name,
        tagline: formData.tagline,
        bio: formData.bio,
        hourly_rate: formData.hourly_rate,
        minimum_booking: formData.minimum_booking,
        deposit_percentage: formData.deposit_percentage,
        sale_price: formData.sale_price,
        sale_active: formData.sale_active,
        location_city: formData.location_city,
        location_state: formData.location_state,
        travel_willing: formData.travel_willing,
        travel_radius: formData.travel_radius,
      },
      microsite: {
        slug: formData.slug,
        status: formData.status,
        meta_title: formData.meta_title || undefined,
        meta_description: formData.meta_description || undefined,
        custom_domain: formData.custom_domain || undefined,
      },
      design_settings: formData.design_settings,
    });
  }, [formData, updateMutation]);

  // Debounced auto-save when form data changes
  useEffect(() => {
    if (!isInitialized) return;

    // Clear any existing timer
    if (autoSaveTimerRef.current) {
      clearTimeout(autoSaveTimerRef.current);
    }

    // Set status to show we have pending changes
    setSaveStatus('idle');

    // Start new debounce timer
    autoSaveTimerRef.current = setTimeout(() => {
      performAutoSave();
    }, AUTOSAVE_DELAY);

    return () => {
      if (autoSaveTimerRef.current) {
        clearTimeout(autoSaveTimerRef.current);
      }
    };
  }, [formData, isInitialized, performAutoSave]);

  // Gig mutations
  const createGigMutation = useMutation({
    mutationFn: (gig: Omit<ExternalGig, 'id' | 'performer_id'>) =>
      performersApi.createExternalGig(Number(id), gig),
    onSuccess: () => {
      refetchGigs();
      toast.success('External gig added');
      resetGigForm();
    },
    onError: () => {
      toast.error('Failed to add external gig');
    },
  });

  const updateGigMutation = useMutation({
    mutationFn: ({ gigId, gig }: { gigId: number; gig: Partial<ExternalGig> }) =>
      performersApi.updateExternalGig(Number(id), gigId, gig),
    onSuccess: () => {
      refetchGigs();
      toast.success('External gig updated');
      resetGigForm();
    },
    onError: () => {
      toast.error('Failed to update external gig');
    },
  });

  const deleteGigMutation = useMutation({
    mutationFn: (gigId: number) => performersApi.deleteExternalGig(Number(id), gigId),
    onSuccess: () => {
      refetchGigs();
      toast.success('External gig deleted');
    },
    onError: () => {
      toast.error('Failed to delete external gig');
    },
  });

  const resetGigForm = () => {
    setShowGigForm(false);
    setEditingGig(null);
    setGigForm({
      date: '',
      event_name: '',
      venue_name: '',
      event_location: '',
      event_time: '',
      is_public: true,
      ticket_url: '',
    });
  };

  const handleChange = useCallback(<T extends keyof typeof formData>(
    field: T,
    value: (typeof formData)[T]
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  }, []);

  const handleDesignChange = useCallback(<T extends keyof typeof defaultDesignSettings>(
    field: T,
    value: (typeof defaultDesignSettings)[T]
  ) => {
    setFormData((prev) => ({
      ...prev,
      design_settings: { ...prev.design_settings, [field]: value },
    }));
  }, []);

  const handleSocialChange = useCallback((platform: keyof SocialLinks, value: string) => {
    setFormData((prev) => ({
      ...prev,
      design_settings: {
        ...prev.design_settings,
        social_links: { ...prev.design_settings.social_links, [platform]: value },
      },
    }));
  }, []);

  const handleLayoutChange = useCallback(<T extends keyof LayoutSettings>(
    field: T,
    value: LayoutSettings[T]
  ) => {
    setFormData((prev) => ({
      ...prev,
      design_settings: {
        ...prev.design_settings,
        layout_settings: { ...prev.design_settings.layout_settings, [field]: value },
      },
    }));
  }, []);

  const handleMediaChange = useCallback(<T extends keyof MediaOverrides>(
    field: T,
    value: MediaOverrides[T]
  ) => {
    setFormData((prev) => ({
      ...prev,
      design_settings: {
        ...prev.design_settings,
        media_overrides: { ...prev.design_settings.media_overrides, [field]: value },
      },
    }));
  }, []);

  const handleGigSubmit = () => {
    if (editingGig) {
      updateGigMutation.mutate({ gigId: editingGig.id, gig: gigForm });
    } else {
      createGigMutation.mutate(gigForm);
    }
  };

  const openEditGig = (gig: ExternalGig) => {
    setEditingGig(gig);
    setGigForm({
      date: gig.date,
      event_name: gig.event_name,
      venue_name: gig.venue_name || '',
      event_location: gig.event_location || '',
      event_time: gig.event_time || '',
      is_public: gig.is_public,
      ticket_url: gig.ticket_url || '',
    });
    setShowGigForm(true);
  };

  const getDeviceWidth = () => {
    switch (devicePreview) {
      case 'mobile':
        return 'max-w-[375px]';
      case 'tablet':
        return 'max-w-[768px]';
      default:
        return 'max-w-full';
    }
  };

  if (previewLoading) {
    return (
      <div className="min-h-[100dvh] bg-slate-50 flex items-center justify-center">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600" />
      </div>
    );
  }

  if (!previewData) {
    return (
      <div className="min-h-[100dvh] bg-slate-50 flex items-center justify-center">
        <Card className="p-8 text-center">
          <p className="text-slate-500">Performer not found</p>
          <Button onClick={() => navigate('/performers')} className="mt-4">
            Back to Performers
          </Button>
        </Card>
      </div>
    );
  }

  // Determine if we're on a tab that should show the preview
  const showPreview = ['profile', 'media', 'pricing', 'location', 'bio', 'design', 'layout', 'social', 'gigs', 'analytics'].includes(activeTab);

  return (
    <div className="min-h-[100dvh] bg-slate-50">
      {/* Header */}
      <div className="bg-white border-b border-slate-200 px-6 py-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button
              variant="ghost"
              onClick={() => navigate('/performers')}
              icon={<ArrowLeft className="w-4 h-4" />}
            >
              Back
            </Button>
            <div className="h-6 w-px bg-slate-200" />
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-lg font-semibold text-slate-900">
                  {previewData.performer?.stage_name || 'Performer Dashboard'}
                </h1>
                <Badge variant="default" className="text-xs">
                  Dashboard
                </Badge>
              </div>
              {formData.slug && (
                <p className="text-sm text-slate-500">/{formData.slug}/</p>
              )}
            </div>
          </div>
          <div className="flex items-center gap-3">
            {/* View Mode Toggle */}
            <div className="flex items-center gap-1 bg-slate-100 rounded-lg p-1">
              <button
                onClick={() => setViewMode('admin')}
                className={clsx(
                  'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                  viewMode === 'admin'
                    ? 'bg-white shadow-sm text-slate-900'
                    : 'text-slate-500 hover:text-slate-700'
                )}
              >
                <Shield className="w-4 h-4" />
                Admin
              </button>
              <button
                onClick={() => setViewMode('performer')}
                className={clsx(
                  'flex items-center gap-1.5 px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
                  viewMode === 'performer'
                    ? 'bg-white shadow-sm text-slate-900'
                    : 'text-slate-500 hover:text-slate-700'
                )}
              >
                <User className="w-4 h-4" />
                Performer View
              </button>
            </div>

            {/* Premium Status Badge */}
            {actualPremium ? (
              <Badge variant="success" className="flex items-center gap-1">
                <Crown className="w-3 h-3" />
                Premium
              </Badge>
            ) : (
              <Badge variant="default" className="flex items-center gap-1">
                Free
              </Badge>
            )}

            <div className="h-6 w-px bg-slate-200" />

            {/* Auto-save status indicator */}
            <div className="flex items-center gap-2 text-sm">
              {saveStatus === 'saving' && (
                <>
                  <Loader2 className="w-4 h-4 text-slate-400 animate-spin" />
                  <span className="text-slate-500">Saving...</span>
                </>
              )}
              {saveStatus === 'saved' && (
                <>
                  <Check className="w-4 h-4 text-green-500" />
                  <span className="text-green-600">Saved</span>
                </>
              )}
              {saveStatus === 'error' && (
                <>
                  <AlertCircle className="w-4 h-4 text-red-500" />
                  <span className="text-red-600">Save failed</span>
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={performAutoSave}
                  >
                    Retry
                  </Button>
                </>
              )}
            </div>
            <Button
              variant="outline"
              onClick={() => window.open(previewData.preview_url, '_blank')}
              icon={<ExternalLink className="w-4 h-4" />}
            >
              Open Live Site
            </Button>
          </div>
        </div>
      </div>

      {/* Main Content - Three Panel Layout */}
      <div className="flex min-h-[calc(100dvh-73px)] flex-col xl:h-[calc(100dvh-73px)] xl:flex-row">
        {/* Left Sidebar - Vertical Tabs */}
        <div className="w-full flex-shrink-0 bg-slate-50 border-b border-slate-200 flex flex-col overflow-y-auto xl:w-[200px] xl:border-b-0 xl:border-r">
          {/* Dashboard Section */}
          <div className="p-3 border-b border-slate-200">
            <nav className="space-y-1" aria-label="Dashboard tabs">
              {tabs.filter(t => t.section === 'dashboard').map((tab) => {
                const Icon = tab.icon;
                return (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id)}
                    className={clsx(
                      'w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors',
                      activeTab === tab.id
                        ? 'bg-primary-100 text-primary-700'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                    )}
                  >
                    <Icon className="w-4 h-4" />
                    {tab.label}
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Business Section */}
          <div className="p-3 border-b border-slate-200">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider px-2 mb-2">Business</p>
            <nav className="space-y-1" aria-label="Business tabs">
              {tabs.filter(t => t.section === 'business').map((tab) => {
                const Icon = tab.icon;
                return (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id)}
                    className={clsx(
                      'w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors',
                      activeTab === tab.id
                        ? 'bg-primary-100 text-primary-700'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                    )}
                  >
                    <Icon className="w-4 h-4" />
                    {tab.label}
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Profile Section */}
          <div className="p-3 border-b border-slate-200">
            <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider px-2 mb-2">Profile</p>
            <nav className="space-y-1" aria-label="Profile tabs">
              {tabs.filter(t => t.section === 'profile').map((tab) => {
                const Icon = tab.icon;
                return (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id)}
                    className={clsx(
                      'w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors',
                      activeTab === tab.id
                        ? 'bg-primary-100 text-primary-700'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                    )}
                  >
                    <Icon className="w-4 h-4" />
                    {tab.label}
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Microsite Section (Premium) */}
          <div className="p-3 flex-1">
            <div className="flex items-center gap-2 px-2 mb-2">
              <p className="text-xs font-semibold text-slate-400 uppercase tracking-wider">Microsite</p>
              {!hasPremium && <Crown className="w-3 h-3 text-amber-500" />}
            </div>
            <nav className="space-y-1" aria-label="Microsite tabs">
              {tabs.filter(t => t.section === 'microsite').map((tab) => {
                const Icon = tab.icon;
                const isLocked = !hasPremium;
                return (
                  <button
                    key={tab.id}
                    onClick={() => setActiveTab(tab.id)}
                    className={clsx(
                      'w-full flex items-center gap-3 px-3 py-2 text-sm font-medium rounded-lg transition-colors',
                      activeTab === tab.id
                        ? 'bg-primary-100 text-primary-700'
                        : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900',
                      isLocked && 'opacity-60'
                    )}
                  >
                    <Icon className="w-4 h-4" />
                    <span className="flex-1 text-left">{tab.label}</span>
                    {isLocked && <Lock className="w-3 h-3 text-slate-400" />}
                  </button>
                );
              })}
            </nav>
          </div>

          {/* Upgrade CTA for non-premium */}
          {!hasPremium && (
            <div className="p-3 border-t border-slate-200">
              <Button
                size="sm"
                className="w-full bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700"
                icon={<Crown className="w-4 h-4" />}
              >
                Upgrade
              </Button>
            </div>
          )}
        </div>

        {/* Middle Panel - Tab Content */}
        <div className={clsx(
          "flex-shrink-0 bg-white overflow-y-auto p-6 h-full",
          showPreview ? "w-[340px] border-r border-slate-200" : "flex-1"
        )}>
            {/* Dashboard tabs */}
            {activeTab === 'overview' && (
              <OverviewTab
                performer={previewData?.performer ?? undefined}
                microsite={previewData?.microsite ?? undefined}
                onNavigate={setActiveTab}
              />
            )}

            {/* Business tabs */}
            {activeTab === 'bookings' && (
              <BookingsTab performerId={Number(id)} />
            )}

            {activeTab === 'availability' && (
              <AvailabilityTab performerId={Number(id)} />
            )}

            {activeTab === 'messages' && (
              <MessagesTab performerId={Number(id)} />
            )}

            {activeTab === 'reviews' && (
              <ReviewsTab performerId={Number(id)} />
            )}

            {activeTab === 'payouts' && (
              <PayoutsTab performerId={Number(id)} />
            )}

            {/* Profile tabs (always available) */}
            {activeTab === 'profile' && (
              <ProfileTab
                formData={formData}
                onChange={handleChange}
              />
            )}

            {activeTab === 'media' && (
              <MediaTab
                mediaOverrides={formData.design_settings.media_overrides}
                performerMedia={{
                  profile_photo: previewData?.performer?.profile_photo,
                  gallery: previewData?.performer?.gallery || [],
                }}
                onMediaChange={handleMediaChange}
              />
            )}

            {activeTab === 'pricing' && (
              <PricingTab
                formData={formData}
                onChange={handleChange}
              />
            )}

            {activeTab === 'location' && (
              <LocationTab
                formData={formData}
                onChange={handleChange}
              />
            )}

            {activeTab === 'bio' && (
              <BioTab
                formData={formData}
                onChange={handleChange}
              />
            )}

            {/* Premium tabs (with lock overlay if not premium) */}
            {activeTab === 'design' && (
              <PremiumTabWrapper hasPremium={hasPremium} tabName="Theme Customization">
                <DesignTab
                  designSettings={formData.design_settings}
                  onDesignChange={handleDesignChange}
                />
              </PremiumTabWrapper>
            )}

            {activeTab === 'layout' && (
              <PremiumTabWrapper hasPremium={hasPremium} tabName="Layout Settings">
                <LayoutTab
                  layoutSettings={formData.design_settings.layout_settings}
                  onLayoutChange={handleLayoutChange}
                  onDesignChange={handleDesignChange}
                  showReviews={formData.design_settings.show_reviews}
                  showCalendar={formData.design_settings.show_calendar}
                  showBookingButton={formData.design_settings.show_booking_button}
                />
              </PremiumTabWrapper>
            )}

            {activeTab === 'social' && (
              <PremiumTabWrapper hasPremium={hasPremium} tabName="Social Links">
                <SocialLinksTab
                  socialLinks={formData.design_settings.social_links}
                  onSocialChange={handleSocialChange}
                />
              </PremiumTabWrapper>
            )}

            {activeTab === 'gigs' && (
              <PremiumTabWrapper hasPremium={hasPremium} tabName="External Gigs">
                <ExternalGigsTab
                  gigs={gigsData?.data || []}
                  showForm={showGigForm}
                  editingGig={editingGig}
                  gigForm={gigForm}
                  onGigFormChange={(field, value) => setGigForm((prev) => ({ ...prev, [field]: value }))}
                  onShowForm={() => setShowGigForm(true)}
                  onCancelForm={resetGigForm}
                  onSubmitForm={handleGigSubmit}
                  onEditGig={openEditGig}
                  onDeleteGig={(gigId) => deleteGigMutation.mutate(gigId)}
                  isSubmitting={createGigMutation.isPending || updateGigMutation.isPending}
                />
              </PremiumTabWrapper>
            )}

            {activeTab === 'analytics' && (
              <PremiumTabWrapper hasPremium={hasPremium} tabName="Analytics">
                <AnalyticsTab analytics={analyticsData} />
              </PremiumTabWrapper>
            )}
        </div>

        {/* Right Panel - Preview (only shown for profile/microsite tabs) */}
        {showPreview && (
          <div className="flex-1 flex flex-col bg-slate-100">
            {/* Device Switcher */}
            <div className="bg-white border-b border-slate-200 px-4 py-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <Eye className="w-4 h-4 text-slate-500" />
                <span className="text-sm font-medium text-slate-700">Live Preview</span>
              </div>
              <div className="flex items-center gap-1 bg-slate-100 rounded-lg p-1">
                {[
                  { id: 'desktop' as DevicePreview, icon: Monitor, label: 'Desktop' },
                  { id: 'tablet' as DevicePreview, icon: Tablet, label: 'Tablet' },
                  { id: 'mobile' as DevicePreview, icon: Smartphone, label: 'Mobile' },
                ].map(({ id, icon: Icon, label }) => (
                  <button
                    key={id}
                    onClick={() => setDevicePreview(id)}
                    className={clsx(
                      'p-2 rounded-md transition-colors',
                      devicePreview === id
                        ? 'bg-white shadow-sm text-primary-600'
                        : 'text-slate-500 hover:text-slate-700'
                    )}
                    title={label}
                  >
                    <Icon className="w-4 h-4" />
                  </button>
                ))}
              </div>
            </div>

            {/* Preview Frame */}
            <div className="flex-1 overflow-auto p-4 sm:p-6 flex justify-center">
              <div
                className={clsx(
                  'bg-white rounded-lg shadow-lg w-full transition-all duration-300 overflow-y-auto max-h-[calc(100dvh-180px)]',
                  getDeviceWidth()
                )}
              >
                <PreviewContent
                  previewData={previewData}
                  designSettings={formData.design_settings}
                  externalGigs={gigsData?.data || []}
                />
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}

// Tab Components

// Premium Tab Wrapper - shows lock overlay for non-premium users
interface PremiumTabWrapperProps {
  hasPremium: boolean;
  tabName: string;
  children: React.ReactNode;
}

// Feature benefits for each premium tab
const premiumFeatureBenefits: Record<string, { headline: string; benefits: string[] }> = {
  'Theme Customization': {
    headline: 'Make your page uniquely yours',
    benefits: [
      'Choose from 4 professional templates',
      'Customize colors to match your brand',
      'Select from premium font families',
    ],
  },
  'Layout Settings': {
    headline: 'Control how your page looks',
    benefits: [
      '3 hero layout styles to choose from',
      'Reorder sections with drag & drop',
      'Show/hide reviews, calendar & booking',
    ],
  },
  'Social Links': {
    headline: 'Connect with your audience',
    benefits: [
      'Add links to 6 social platforms',
      'Display social icons on your page',
      'Drive traffic to your other channels',
    ],
  },
  'External Gigs': {
    headline: 'Show off all your bookings',
    benefits: [
      'Add gigs booked outside the platform',
      'Display upcoming shows on your calendar',
      'Build credibility with a busy schedule',
    ],
  },
  'Analytics': {
    headline: 'Understand your audience',
    benefits: [
      'See page views & unique visitors',
      'Track where your traffic comes from',
      'Measure booking button clicks',
    ],
  },
};

function PremiumTabWrapper({ hasPremium, tabName, children }: PremiumTabWrapperProps) {
  if (hasPremium) {
    return <>{children}</>;
  }

  const featureInfo = premiumFeatureBenefits[tabName] || {
    headline: 'Unlock premium features',
    benefits: ['Custom themes', 'Advanced layouts', 'Analytics'],
  };

  // Show static upsell content instead of blurred actual content
  // This prevents jumping between tabs and ensures consistent height
  return (
    <div className="flex flex-col items-center justify-center py-8">
      <div className="text-center max-w-sm">
        <div className="inline-flex items-center justify-center w-14 h-14 rounded-full bg-gradient-to-br from-amber-400 to-amber-600 text-white mb-4 shadow-lg">
          <Crown className="w-7 h-7" />
        </div>
        <h3 className="text-lg font-bold text-slate-900 mb-1">
          {featureInfo.headline}
        </h3>
        <p className="text-sm text-slate-500 mb-4">
          Upgrade to Premium to unlock {tabName.toLowerCase()}
        </p>

        {/* Benefits list */}
        <ul className="text-left space-y-2 mb-5">
          {featureInfo.benefits.map((benefit, i) => (
            <li key={i} className="flex items-start gap-2 text-sm text-slate-600">
              <Check className="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" />
              {benefit}
            </li>
          ))}
        </ul>

        <Button
          className="w-full bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700"
          icon={<Crown className="w-4 h-4" />}
        >
          Upgrade to Premium
        </Button>
        <p className="text-xs text-slate-400 mt-2">
          Starting at $9.99/month
        </p>
      </div>
    </div>
  );
}

// Shared form data type for all tabs
type FormDataType = {
  stage_name: string;
  tagline: string;
  bio: string;
  hourly_rate: number;
  minimum_booking: number;
  deposit_percentage: number;
  sale_price: number;
  sale_active: boolean;
  location_city: string;
  location_state: string;
  travel_willing: boolean;
  travel_radius: number;
  slug: string;
  status: MicrositeStatus;
  meta_title: string;
  meta_description: string;
  custom_domain: string;
  design_settings: typeof defaultDesignSettings;
};

type FormChangeHandler = <T extends keyof FormDataType>(field: T, value: FormDataType[T]) => void;

// ============================================================================
// DASHBOARD TABS
// ============================================================================

// Overview Tab - Stats and quick actions
interface OverviewTabProps {
  performer?: {
    id?: number;
    stage_name?: string;
    completed_bookings?: number;
    average_rating?: number;
    total_reviews?: number;
    tier?: string;
  };
  microsite?: {
    status?: string;
    views?: number;
  };
  onNavigate?: (tab: TabId) => void;
}

function OverviewTab({ performer, microsite, onNavigate }: OverviewTabProps) {
  const performerId = performer?.id;

  const { data, isLoading } = useQuery({
    queryKey: ['performer-overview', performerId],
    queryFn: () => performersApi.getOverview(performerId!),
    enabled: !!performerId,
  });

  const overview = data?.data;

  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(amount);
  };

  const formatRelativeTime = (timestamp: string) => {
    const date = new Date(timestamp);
    const now = new Date();
    const diffInSeconds = Math.floor((now.getTime() - date.getTime()) / 1000);

    if (diffInSeconds < 60) return 'just now';
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)}m ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)}h ago`;
    if (diffInSeconds < 604800) return `${Math.floor(diffInSeconds / 86400)}d ago`;
    return format(date, 'MMM d');
  };

  const getActivityIcon = (type: string) => {
    switch (type) {
      case 'booking':
        return <CalendarDays className="w-4 h-4 text-blue-500" />;
      case 'review':
        return <Star className="w-4 h-4 text-amber-500" />;
      case 'message':
        return <MessageSquare className="w-4 h-4 text-green-500" />;
      case 'payout':
        return <DollarSign className="w-4 h-4 text-emerald-500" />;
      default:
        return <Clock className="w-4 h-4 text-slate-400" />;
    }
  };

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="animate-pulse">
          <div className="h-6 bg-slate-200 rounded w-1/3 mb-4" />
          <div className="h-4 bg-slate-100 rounded w-2/3 mb-6" />
          <div className="grid grid-cols-2 gap-3">
            {Array.from({ length: 4 }).map((_, i) => (
              <div key={i} className="h-20 bg-slate-100 rounded-lg" />
            ))}
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-4">Dashboard Overview</h3>
        <p className="text-sm text-slate-500 mb-6">
          Welcome back! Here's a quick snapshot of your performer account.
        </p>
      </div>

      {/* Quick Stats Grid */}
      <div className="grid grid-cols-2 gap-3">
        {/* Upcoming Bookings */}
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <CalendarDays className="w-4 h-4 text-blue-500" />
            <span className="text-xs text-slate-500">Upcoming</span>
          </div>
          <p className="text-2xl font-bold text-slate-900">{overview?.bookings.upcoming || 0}</p>
          {overview?.bookings.pending_approval ? (
            <p className="text-xs text-amber-600 mt-1">
              {overview.bookings.pending_approval} pending approval
            </p>
          ) : null}
        </Card>

        {/* Reviews */}
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <Star className="w-4 h-4 text-amber-500 fill-amber-500" />
            <span className="text-xs text-slate-500">Rating</span>
          </div>
          <p className="text-2xl font-bold text-slate-900">
            {overview?.reviews.average?.toFixed(1) || '—'}
          </p>
          <p className="text-xs text-slate-500 mt-1">
            {overview?.reviews.total || 0} reviews
            {overview?.reviews.recent_count ? (
              <span className="text-green-600"> (+{overview.reviews.recent_count} new)</span>
            ) : null}
          </p>
        </Card>

        {/* Earnings */}
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <DollarSign className="w-4 h-4 text-emerald-500" />
            <span className="text-xs text-slate-500">Total Earned</span>
          </div>
          <p className="text-2xl font-bold text-slate-900">
            {formatCurrency(overview?.earnings.total || 0)}
          </p>
          {overview?.earnings.pending ? (
            <p className="text-xs text-amber-600 mt-1">
              {formatCurrency(overview.earnings.pending)} pending
            </p>
          ) : null}
        </Card>

        {/* Messages */}
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-2">
            <MessageSquare className="w-4 h-4 text-green-500" />
            <span className="text-xs text-slate-500">Messages</span>
          </div>
          <p className="text-2xl font-bold text-slate-900">{overview?.messages.unread || 0}</p>
          <p className="text-xs text-slate-500 mt-1">unread messages</p>
        </Card>
      </div>

      {/* Next Booking */}
      {overview?.next_booking && (
        <div className="p-4 bg-blue-50 border border-blue-100 rounded-lg">
          <div className="flex items-center gap-2 mb-3">
            <CalendarDays className="w-4 h-4 text-blue-600" />
            <p className="text-sm font-medium text-blue-900">Next Booking</p>
          </div>
          <div className="flex items-start justify-between">
            <div>
              <p className="font-medium text-slate-900">{overview.next_booking.event_title}</p>
              <p className="text-sm text-slate-600">{overview.next_booking.customer_name}</p>
              <p className="text-xs text-slate-500 mt-1">
                {format(new Date(overview.next_booking.event_date), 'EEEE, MMMM d, yyyy')}
              </p>
            </div>
            <Badge
              variant={overview.next_booking.status === 'confirmed' ? 'success' : 'warning'}
              className="capitalize"
            >
              {overview.next_booking.status}
            </Badge>
          </div>
        </div>
      )}

      {/* Recent Activity */}
      {overview?.recent_activity && overview.recent_activity.length > 0 && (
        <div>
          <h4 className="text-sm font-medium text-slate-700 mb-3">Recent Activity</h4>
          <div className="space-y-2">
            {overview.recent_activity.map((activity, index) => (
              <div
                key={index}
                className="flex items-start gap-3 p-3 bg-slate-50 rounded-lg"
              >
                {getActivityIcon(activity.type)}
                <div className="flex-1 min-w-0">
                  <p className="text-sm text-slate-700">{activity.description}</p>
                  <p className="text-xs text-slate-400">{formatRelativeTime(activity.timestamp)}</p>
                </div>
                {activity.meta?.amount && (
                  <span className="text-sm font-medium text-emerald-600">
                    +{formatCurrency(activity.meta.amount)}
                  </span>
                )}
                {activity.meta?.rating && (
                  <div className="flex items-center gap-1">
                    <Star className="w-3 h-3 text-amber-500 fill-amber-500" />
                    <span className="text-sm font-medium">{activity.meta.rating}</span>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Microsite Status */}
      {microsite && (
        <div className="p-4 bg-gradient-to-r from-primary-50 to-primary-100 rounded-lg">
          <div className="flex items-center gap-2 mb-2">
            <TrendingUp className="w-4 h-4 text-primary-600" />
            <p className="text-sm font-medium text-primary-900">Your Microsite</p>
          </div>
          <p className="text-xs text-primary-700">
            {microsite.status === 'active'
              ? 'Your page is live and accepting bookings!'
              : 'Complete your profile to go live.'}
          </p>
          {microsite.views !== undefined && (
            <p className="text-xs text-primary-600 mt-1">
              {microsite.views.toLocaleString()} total page views
            </p>
          )}
        </div>
      )}

      {/* Quick Actions */}
      <div>
        <h4 className="text-sm font-medium text-slate-700 mb-3">Quick Actions</h4>
        <div className="space-y-2">
          <button
            onClick={() => onNavigate?.('availability')}
            className="w-full flex items-center gap-3 p-3 text-left text-sm bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <CalendarDays className="w-4 h-4 text-primary-600" />
            <span>Update availability</span>
          </button>
          <button
            onClick={() => onNavigate?.('messages')}
            className="w-full flex items-center gap-3 p-3 text-left text-sm bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <MessageSquare className="w-4 h-4 text-primary-600" />
            <span>Check messages</span>
            {overview?.messages.unread ? (
              <Badge variant="danger" className="ml-auto text-xs">
                {overview.messages.unread}
              </Badge>
            ) : null}
          </button>
          <button
            onClick={() => onNavigate?.('profile')}
            className="w-full flex items-center gap-3 p-3 text-left text-sm bg-slate-50 hover:bg-slate-100 rounded-lg transition-colors"
          >
            <Settings className="w-4 h-4 text-primary-600" />
            <span>Edit profile</span>
          </button>
        </div>
      </div>
    </div>
  );
}

// ============================================================================
// BUSINESS TABS
// ============================================================================

// Bookings Tab
interface BookingsTabProps {
  performerId: number;
}

function BookingsTab({ performerId }: BookingsTabProps) {
  const [statusFilter, setStatusFilter] = useState<string>('');

  const { data, isLoading } = useQuery({
    queryKey: ['performer-bookings', performerId, statusFilter],
    queryFn: () => performersApi.getBookings(performerId, { status: statusFilter || undefined }),
    enabled: !!performerId,
  });

  const bookings = data?.data || [];
  const stats = data?.stats || { upcoming: 0, pending: 0, completed: 0, cancelled: 0, total_earned: 0 };

  const getStatusColor = (status: string) => {
    switch (status) {
      case 'confirmed': return 'bg-green-100 text-green-700';
      case 'pending': return 'bg-amber-100 text-amber-700';
      case 'completed': return 'bg-blue-100 text-blue-700';
      case 'cancelled': return 'bg-red-100 text-red-700';
      default: return 'bg-slate-100 text-slate-700';
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-2">Your Bookings</h3>
        <p className="text-sm text-slate-500">
          View and manage all your upcoming and past bookings.
        </p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-4 gap-2 text-center">
        <button
          onClick={() => setStatusFilter('')}
          className={clsx(
            'p-3 rounded-lg transition-colors',
            statusFilter === '' ? 'bg-primary-100 ring-2 ring-primary-500' : 'bg-slate-50 hover:bg-slate-100'
          )}
        >
          <p className="text-lg font-bold text-slate-900">{stats.upcoming + stats.pending + stats.completed}</p>
          <p className="text-xs text-slate-500">All</p>
        </button>
        <button
          onClick={() => setStatusFilter('confirmed')}
          className={clsx(
            'p-3 rounded-lg transition-colors',
            statusFilter === 'confirmed' ? 'bg-green-100 ring-2 ring-green-500' : 'bg-slate-50 hover:bg-slate-100'
          )}
        >
          <p className="text-lg font-bold text-green-600">{stats.upcoming}</p>
          <p className="text-xs text-slate-500">Upcoming</p>
        </button>
        <button
          onClick={() => setStatusFilter('pending')}
          className={clsx(
            'p-3 rounded-lg transition-colors',
            statusFilter === 'pending' ? 'bg-amber-100 ring-2 ring-amber-500' : 'bg-slate-50 hover:bg-slate-100'
          )}
        >
          <p className="text-lg font-bold text-amber-600">{stats.pending}</p>
          <p className="text-xs text-slate-500">Pending</p>
        </button>
        <button
          onClick={() => setStatusFilter('completed')}
          className={clsx(
            'p-3 rounded-lg transition-colors',
            statusFilter === 'completed' ? 'bg-blue-100 ring-2 ring-blue-500' : 'bg-slate-50 hover:bg-slate-100'
          )}
        >
          <p className="text-lg font-bold text-blue-600">{stats.completed}</p>
          <p className="text-xs text-slate-500">Completed</p>
        </button>
      </div>

      {/* Total Earnings */}
      {stats.total_earned > 0 && (
        <div className="p-4 bg-gradient-to-r from-green-50 to-emerald-50 rounded-lg border border-green-200">
          <div className="flex items-center justify-between">
            <span className="text-sm font-medium text-green-800">Total Earned</span>
            <span className="text-xl font-bold text-green-700">${stats.total_earned.toLocaleString()}</span>
          </div>
        </div>
      )}

      {/* Loading state */}
      {isLoading && (
        <div className="text-center py-8">
          <RefreshCw className="w-6 h-6 mx-auto mb-2 text-slate-400 animate-spin" />
          <p className="text-sm text-slate-500">Loading bookings...</p>
        </div>
      )}

      {/* Empty state */}
      {!isLoading && bookings.length === 0 && (
        <div className="text-center py-8">
          <CalendarClock className="w-10 h-10 mx-auto mb-3 text-slate-300" />
          <p className="text-sm text-slate-500 mb-2">
            {statusFilter ? `No ${statusFilter} bookings` : 'No bookings yet'}
          </p>
          <p className="text-xs text-slate-400">
            When customers book you, they'll appear here.
          </p>
        </div>
      )}

      {/* Bookings list */}
      {!isLoading && bookings.length > 0 && (
        <div className="space-y-3">
          {bookings.map((booking) => (
            <div
              key={booking.id}
              className="p-4 bg-white border border-slate-200 rounded-lg hover:border-slate-300 transition-colors"
            >
              <div className="flex items-start justify-between mb-2">
                <div>
                  <h4 className="font-medium text-slate-900">{booking.event_title}</h4>
                  <p className="text-xs text-slate-500">#{booking.booking_number}</p>
                </div>
                <span className={clsx('text-xs font-medium px-2 py-1 rounded-full', getStatusColor(booking.booking_status))}>
                  {booking.booking_status}
                </span>
              </div>
              <div className="grid grid-cols-2 gap-2 text-sm">
                <div>
                  <p className="text-slate-500">Date</p>
                  <p className="font-medium text-slate-700">
                    {format(new Date(booking.event_date), 'MMM d, yyyy')}
                  </p>
                </div>
                <div>
                  <p className="text-slate-500">Time</p>
                  <p className="font-medium text-slate-700">
                    {booking.event_time_start} - {booking.event_time_end}
                  </p>
                </div>
                <div>
                  <p className="text-slate-500">Customer</p>
                  <p className="font-medium text-slate-700">{booking.customer_name || 'Unknown'}</p>
                </div>
                <div>
                  <p className="text-slate-500">Amount</p>
                  <p className="font-medium text-slate-700">${booking.total_amount.toLocaleString()}</p>
                </div>
              </div>
              {booking.event_location && (
                <div className="mt-2 pt-2 border-t border-slate-100">
                  <p className="text-xs text-slate-500 flex items-center gap-1">
                    <MapPin className="w-3 h-3" />
                    {booking.event_location}{booking.event_city && `, ${booking.event_city}`}{booking.event_state && `, ${booking.event_state}`}
                  </p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// Availability Tab
interface AvailabilityTabProps {
  performerId: number;
}

interface CalendarEvent {
  id: number | string;
  title: string;
  start: Date;
  end: Date;
  allDay: boolean;
  resource: AvailabilityEvent;
}

function AvailabilityTab({ performerId }: AvailabilityTabProps) {
  const toast = useToast();
  const queryClient = useQueryClient();
  const [currentDate, setCurrentDate] = useState(new Date());
  const [view, setView] = useState<View>('month');
  const [selectedSlot, setSelectedSlot] = useState<{ start: Date; end: Date } | null>(null);
  const [selectedEvent, setSelectedEvent] = useState<CalendarEvent | null>(null);
  const [blockTitle, setBlockTitle] = useState('');
  const [isBlocking, setIsBlocking] = useState(false);

  // Edit mode state
  const [isEditing, setIsEditing] = useState(false);
  const [editEventName, setEditEventName] = useState('');
  const [editVenue, setEditVenue] = useState('');
  const [editNotes, setEditNotes] = useState('');
  const [editBlockType, setEditBlockType] = useState<'manual' | 'external_gig'>('manual');

  // Calculate date range for fetching
  const startDate = format(startOfMonth(subMonths(currentDate, 1)), 'yyyy-MM-dd');
  const endDate = format(endOfMonth(addMonths(currentDate, 2)), 'yyyy-MM-dd');

  // Fetch availability
  const { data, isLoading } = useQuery({
    queryKey: ['performer-availability', performerId, startDate, endDate],
    queryFn: () => performersApi.getAvailability(performerId, { start_date: startDate, end_date: endDate }),
    enabled: !!performerId,
  });

  // Block dates mutation with optimistic updates
  const blockMutation = useMutation({
    mutationFn: (blockData: { dates: string[]; title?: string }) =>
      performersApi.blockDates(performerId, blockData),
    onMutate: async (blockData) => {
      // Cancel any outgoing refetches
      await queryClient.cancelQueries({ queryKey: ['performer-availability', performerId] });

      // Snapshot the previous value
      const previousData = queryClient.getQueryData<AvailabilityEvent[]>(['performer-availability', performerId, startDate, endDate]);

      // Optimistically update with new blocked dates
      queryClient.setQueryData(
        ['performer-availability', performerId, startDate, endDate],
        (old: AvailabilityEvent[] | undefined) => {
          if (!old) return [];
          const newEvents: AvailabilityEvent[] = blockData.dates.map((date, idx) => ({
            id: `temp-${date}-${idx}`,
            title: blockData.title || 'Blocked',
            start: date,
            end: date,
            allDay: true,
            type: 'manual' as const,
            status: 'blocked',
            canDelete: true,
          }));
          return [...old, ...newEvents];
        }
      );

      return { previousData };
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performer-availability', performerId] });
      toast.success('Dates blocked successfully');
      setSelectedSlot(null);
      setBlockTitle('');
    },
    onError: (err, _vars, context) => {
      // Rollback on error
      if (context?.previousData) {
        queryClient.setQueryData(
          ['performer-availability', performerId, startDate, endDate],
          context.previousData
        );
      }
      console.error('Block dates error:', err);
      const message = err instanceof Error ? err.message : 'Failed to block dates';
      toast.error(message);
    },
  });

  // Unblock mutation
  const unblockMutation = useMutation({
    mutationFn: (slotId: number) => performersApi.unblockDate(performerId, slotId),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performer-availability', performerId] });
      toast.success('Date unblocked successfully');
      setSelectedEvent(null);
    },
    onError: () => {
      toast.error('Failed to unblock date');
    },
  });

  // Update availability slot mutation
  const updateSlotMutation = useMutation({
    mutationFn: (data: { slotId: number; event_name?: string; venue_name?: string; notes?: string; block_type?: 'manual' | 'external_gig' }) =>
      performersApi.updateAvailabilitySlot(performerId, data.slotId, {
        event_name: data.event_name,
        venue_name: data.venue_name,
        notes: data.notes,
        block_type: data.block_type,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['performer-availability', performerId] });
      toast.success('Updated successfully');
      setSelectedEvent(null);
      setIsEditing(false);
    },
    onError: () => {
      toast.error('Failed to update');
    },
  });

  // Convert API events to calendar events (data is already unwrapped by interceptor)
  // CRITICAL: Use parseLocalDate to ensure dates are interpreted as local time, not UTC
  const events: CalendarEvent[] = (data || []).map((event: AvailabilityEvent) => {
    const parsedStart = parseLocalDate(event.start);
    const parsedEnd = parseLocalDate(event.end);

    // Debug logging for the first few events
    if ((data as AvailabilityEvent[]).indexOf(event) < 3) {
      console.log('=== EVENT PARSING DEBUG ===');
      console.log('API event.start:', event.start);
      console.log('API event.end:', event.end);
      console.log('Parsed start:', parsedStart.toString());
      console.log('Parsed start (ISO):', parsedStart.toISOString());
      console.log('Formatted start:', format(parsedStart, 'yyyy-MM-dd'));
      console.log('=== END EVENT DEBUG ===');
    }

    return {
      id: event.id,
      title: event.title,
      start: parsedStart,
      end: parsedEnd,
      allDay: event.allDay,
      resource: event,
    };
  });

  // Handle selecting a date range to block
  const handleSelectSlot = useCallback(({ start, end }: { start: Date; end: Date }) => {
    setSelectedSlot({ start, end });
    setSelectedEvent(null);
    setIsEditing(false);
  }, []);

  // Handle clicking on an existing event
  const handleSelectEvent = useCallback((event: CalendarEvent) => {
    setSelectedEvent(event);
    setSelectedSlot(null);
    setIsEditing(false);
    // Pre-populate edit form with current values
    setEditEventName(event.resource.title || '');
    setEditVenue(event.resource.venue_name || '');
    setEditNotes(event.resource.notes || '');
    setEditBlockType(event.resource.type === 'external_gig' ? 'external_gig' : 'manual');
  }, []);

  // Block the selected dates
  const handleBlockDates = () => {
    if (!selectedSlot) return;

    setIsBlocking(true);

    // CRITICAL: Use startOfDay to normalize dates to midnight local time
    // This ensures consistency between what's displayed and what's blocked
    const start = startOfDay(selectedSlot.start);
    const end = startOfDay(selectedSlot.end);

    const dates: string[] = [];

    // Use date-fns functions exclusively for date manipulation
    // This avoids timezone bugs that occur with raw Date methods
    let current = start;

    console.log('=== DATE BLOCKING DEBUG ===');
    console.log('Raw selectedSlot.start:', selectedSlot.start.toISOString());
    console.log('Normalized start:', start.toISOString());
    console.log('Start formatted (local):', format(start, 'yyyy-MM-dd'));
    console.log('End formatted (local):', format(end, 'yyyy-MM-dd'));

    // Loop using date-fns isBefore and addDays for proper date arithmetic
    while (isBefore(current, end)) {
      const dateStr = format(current, 'yyyy-MM-dd');
      dates.push(dateStr);
      console.log('Adding date:', dateStr);
      current = addDays(current, 1);
    }

    console.log('Final dates to block:', dates);
    console.log('=== END DEBUG ===');

    blockMutation.mutate({ dates, title: blockTitle || undefined }, {
      onSettled: () => setIsBlocking(false),
    });
  };

  // Quick block helpers
  const blockToday = () => {
    const now = new Date();
    const today = format(now, 'yyyy-MM-dd');
    console.log('=== BLOCK TODAY DEBUG ===');
    console.log('Current time (local):', now.toString());
    console.log('Current time (ISO):', now.toISOString());
    console.log('Today formatted:', today);
    console.log('=== END DEBUG ===');
    blockMutation.mutate({ dates: [today], title: 'Blocked' });
  };

  const blockThisWeek = () => {
    const dates: string[] = [];
    const today = new Date();
    for (let i = 0; i < 7; i++) {
      const date = new Date(today);
      date.setDate(today.getDate() + i);
      dates.push(format(date, 'yyyy-MM-dd'));
    }
    blockMutation.mutate({ dates, title: 'Blocked' });
  };

  // Save edited availability slot
  const handleSaveEdit = () => {
    if (!selectedEvent || typeof selectedEvent.id !== 'number') return;
    updateSlotMutation.mutate({
      slotId: selectedEvent.id,
      event_name: editEventName || undefined,
      venue_name: editVenue || undefined,
      notes: editNotes || undefined,
      block_type: editBlockType,
    });
  };

  // Event styling
  const eventStyleGetter = (event: CalendarEvent) => {
    const type = event.resource.type;
    let backgroundColor = '#94a3b8'; // default slate
    let borderColor = '#64748b';

    if (type === 'booking') {
      backgroundColor = '#22c55e'; // green for bookings
      borderColor = '#16a34a';
    } else if (type === 'external_gig') {
      backgroundColor = '#8b5cf6'; // purple for external gigs
      borderColor = '#7c3aed';
    } else if (type === 'manual') {
      backgroundColor = '#ef4444'; // red for manual blocks
      borderColor = '#dc2626';
    }

    return {
      style: {
        backgroundColor,
        borderColor,
        borderRadius: '4px',
        opacity: 0.9,
        color: 'white',
        border: `1px solid ${borderColor}`,
        display: 'block',
      },
    };
  };

  // Check if a date is in the selected range
  const isDateInSelectedRange = useCallback((date: Date) => {
    if (!selectedSlot) return false;
    const dateStart = new Date(date);
    dateStart.setHours(0, 0, 0, 0);
    const slotStart = new Date(selectedSlot.start);
    slotStart.setHours(0, 0, 0, 0);
    const slotEnd = new Date(selectedSlot.end);
    slotEnd.setHours(0, 0, 0, 0);
    return dateStart >= slotStart && dateStart < slotEnd;
  }, [selectedSlot]);

  // Day cell styling - highlight selected days
  const dayPropGetter = useCallback((date: Date) => {
    if (isDateInSelectedRange(date)) {
      return {
        className: 'rbc-selected-day',
        style: {
          backgroundColor: '#bfdbfe',
        },
      };
    }
    return {};
  }, [isDateInSelectedRange]);

  // Calculate number of selected days for display
  const selectedDaysCount = selectedSlot
    ? Math.ceil((selectedSlot.end.getTime() - selectedSlot.start.getTime()) / 86400000)
    : 0;

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="h-6 bg-slate-200 rounded animate-pulse w-32" />
        <div className="h-4 bg-slate-100 rounded animate-pulse w-48" />
        <div className="h-96 bg-slate-100 rounded-lg animate-pulse" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-2">Availability</h3>
        <p className="text-sm text-slate-500">
          Click on dates to block them. Click on events to view details.
        </p>
      </div>

      {/* Calendar Legend */}
      <div className="flex flex-wrap gap-4 text-xs">
        <div className="flex items-center gap-1.5">
          <div className="w-3 h-3 rounded bg-green-500" />
          <span className="text-slate-600">Bookings</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-3 h-3 rounded bg-red-500" />
          <span className="text-slate-600">Blocked</span>
        </div>
        <div className="flex items-center gap-1.5">
          <div className="w-3 h-3 rounded bg-purple-500" />
          <span className="text-slate-600">External Gigs</span>
        </div>
      </div>

      {/* Calendar */}
      <div className="bg-white rounded-lg border border-slate-200 p-4">
        <style>{`
          .rbc-calendar {
            font-family: inherit;
          }
          .rbc-toolbar {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 12px;
            border-bottom: 1px solid #e2e8f0;
          }
          .rbc-toolbar-label {
            font-weight: 600;
            font-size: 1rem;
            color: #1e293b;
          }
          .rbc-btn-group {
            display: flex;
            gap: 4px;
          }
          .rbc-btn-group button {
            font-size: 0.875rem;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: white;
            cursor: pointer;
            transition: all 0.15s;
          }
          .rbc-btn-group button:hover {
            background-color: #f1f5f9;
            border-color: #cbd5e1;
          }
          .rbc-btn-group button.rbc-active {
            background-color: #3b82f6;
            color: white;
            border-color: #3b82f6;
          }
          .rbc-header {
            padding: 8px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: #475569;
          }
          .rbc-today {
            background-color: #f0f9ff;
          }
          .rbc-off-range-bg {
            background-color: #f8fafc;
          }
          .rbc-event {
            font-size: 0.75rem;
            padding: 2px 4px;
          }
          .rbc-day-slot .rbc-event {
            border: none;
          }
          .rbc-month-view {
            border: none;
          }
          .rbc-month-row {
            min-height: 80px;
          }
          .rbc-selected-day {
            background-color: #bfdbfe !important;
            position: relative;
          }
          .rbc-day-bg.rbc-selected-day {
            background-color: #bfdbfe !important;
          }
          .rbc-selecting .rbc-day-bg {
            background-color: #eff6ff;
          }
          .rbc-slot-selection {
            background-color: rgba(59, 130, 246, 0.3) !important;
          }
          .rbc-day-bg:hover {
            background-color: #f8fafc;
          }
        `}</style>
        <BigCalendar
          localizer={localizer}
          events={events}
          startAccessor="start"
          endAccessor="end"
          style={{ height: 550 }}
          view={view}
          onView={setView}
          date={currentDate}
          onNavigate={setCurrentDate}
          selectable
          onSelectSlot={handleSelectSlot}
          onSelectEvent={handleSelectEvent}
          eventPropGetter={eventStyleGetter}
          dayPropGetter={dayPropGetter}
          views={['month', 'week']}
          popup
          toolbar={true}
          longPressThreshold={10}
        />
      </div>

      {/* Block Date Modal */}
      {selectedSlot && (
        <Card className="mt-4 p-4 border-2 border-primary-200 bg-primary-50">
          <div className="flex items-start justify-between mb-3">
            <div>
              <div className="flex items-center gap-2">
                <h4 className="font-medium text-slate-900">Block Selected Dates</h4>
                <Badge variant="primary" className="text-xs">
                  {selectedDaysCount} day{selectedDaysCount > 1 ? 's' : ''}
                </Badge>
              </div>
              <p className="text-sm text-slate-500 mt-1">
                {format(selectedSlot.start, 'MMM d, yyyy')}
                {selectedDaysCount > 1 &&
                  ` → ${format(new Date(selectedSlot.end.getTime() - 86400000), 'MMM d, yyyy')}`}
              </p>
            </div>
            <button onClick={() => setSelectedSlot(null)} className="text-slate-400 hover:text-slate-600">
              <X className="w-5 h-5" />
            </button>
          </div>
          <Input
            placeholder="Reason (optional, e.g., 'Vacation', 'Personal')"
            value={blockTitle}
            onChange={(e) => setBlockTitle(e.target.value)}
          />
          <div className="flex gap-2 mt-4">
            <Button
              onClick={handleBlockDates}
              loading={isBlocking}
              icon={<Lock className="w-4 h-4" />}
            >
              Block {selectedDaysCount > 1 ? `${selectedDaysCount} Days` : 'Date'}
            </Button>
            <Button variant="ghost" onClick={() => setSelectedSlot(null)}>
              Cancel
            </Button>
          </div>
        </Card>
      )}

      {/* Event Detail Modal */}
      {selectedEvent && (
        <Card className="p-4 border-2 border-slate-200">
          <div className="flex items-start justify-between mb-3">
            <div>
              <h4 className="font-medium text-slate-900">{selectedEvent.title}</h4>
              <p className="text-sm text-slate-500">
                {format(selectedEvent.start, 'EEEE, MMMM d, yyyy')}
              </p>
            </div>
            <button onClick={() => { setSelectedEvent(null); setIsEditing(false); }} className="text-slate-400 hover:text-slate-600">
              <X className="w-5 h-5" />
            </button>
          </div>

          {!isEditing ? (
            <>
              {/* View Mode */}
              <div className="space-y-2 text-sm mb-4">
                <div className="flex items-center gap-2">
                  <span className="text-slate-500">Type:</span>
                  <Badge variant={
                    selectedEvent.resource.type === 'booking' ? 'success' :
                    selectedEvent.resource.type === 'external_gig' ? 'default' : 'danger'
                  }>
                    {selectedEvent.resource.type === 'booking' ? 'Booking' :
                     selectedEvent.resource.type === 'external_gig' ? 'External Gig' : 'Blocked'}
                  </Badge>
                </div>
                {selectedEvent.resource.customer_name && (
                  <div className="flex items-center gap-2">
                    <span className="text-slate-500">Customer:</span>
                    <span className="text-slate-700">{selectedEvent.resource.customer_name}</span>
                  </div>
                )}
                {selectedEvent.resource.venue_name && (
                  <div className="flex items-center gap-2">
                    <span className="text-slate-500">Venue:</span>
                    <span className="text-slate-700">{selectedEvent.resource.venue_name}</span>
                  </div>
                )}
                {selectedEvent.resource.notes && (
                  <div className="flex items-center gap-2">
                    <span className="text-slate-500">Notes:</span>
                    <span className="text-slate-700">{selectedEvent.resource.notes}</span>
                  </div>
                )}
              </div>

              {selectedEvent.resource.canDelete && (
                <div className="flex gap-2">
                  <Button
                    variant="outline"
                    onClick={() => setIsEditing(true)}
                    icon={<Edit2 className="w-4 h-4" />}
                  >
                    Edit
                  </Button>
                  <Button
                    variant="outline"
                    onClick={() => {
                      if (typeof selectedEvent.id === 'number') {
                        unblockMutation.mutate(selectedEvent.id);
                      }
                    }}
                    loading={unblockMutation.isPending}
                    icon={<Trash2 className="w-4 h-4" />}
                    className="text-red-600 border-red-200 hover:bg-red-50"
                  >
                    Delete
                  </Button>
                </div>
              )}
            </>
          ) : (
            <>
              {/* Edit Mode */}
              <div className="space-y-3">
                <Select
                  label="Type"
                  value={editBlockType}
                  onChange={(e) => setEditBlockType(e.target.value as 'manual' | 'external_gig')}
                  options={[
                    { value: 'manual', label: 'Blocked (Personal)' },
                    { value: 'external_gig', label: 'External Gig' },
                  ]}
                />
                <Input
                  label="Event/Reason"
                  placeholder={editBlockType === 'external_gig' ? 'e.g., Wedding at Grand Hotel' : 'e.g., Vacation, Personal'}
                  value={editEventName}
                  onChange={(e) => setEditEventName(e.target.value)}
                />
                {editBlockType === 'external_gig' && (
                  <Input
                    label="Venue"
                    placeholder="e.g., Grand Ballroom"
                    value={editVenue}
                    onChange={(e) => setEditVenue(e.target.value)}
                  />
                )}
                <Input
                  label="Notes"
                  placeholder="Additional notes..."
                  value={editNotes}
                  onChange={(e) => setEditNotes(e.target.value)}
                />
              </div>
              <div className="flex gap-2 mt-4">
                <Button
                  onClick={handleSaveEdit}
                  loading={updateSlotMutation.isPending}
                  icon={<Check className="w-4 h-4" />}
                >
                  Save
                </Button>
                <Button variant="ghost" onClick={() => setIsEditing(false)}>
                  Cancel
                </Button>
              </div>
            </>
          )}
        </Card>
      )}

      {/* Quick block options */}
      <div>
        <h4 className="text-sm font-medium text-slate-700 mb-3">Quick Actions</h4>
        <div className="flex flex-wrap gap-2">
          <Button
            size="sm"
            variant="outline"
            onClick={blockToday}
            loading={blockMutation.isPending}
            icon={<Clock className="w-4 h-4" />}
          >
            Block Today
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={blockThisWeek}
            loading={blockMutation.isPending}
            icon={<CalendarDays className="w-4 h-4" />}
          >
            Block This Week
          </Button>
        </div>
      </div>
    </div>
  );
}

// Messages Tab
interface MessagesTabProps {
  performerId: number;
}

function MessagesTab({ performerId }: MessagesTabProps) {
  const [selectedConversation, setSelectedConversation] = useState<number | null>(null);
  const messagesEndRef = useRef<HTMLDivElement>(null);

  // Fetch conversations
  const { data: conversationsData, isLoading: conversationsLoading } = useQuery({
    queryKey: ['performer-conversations', performerId],
    queryFn: () => performersApi.getConversations(performerId),
    enabled: !!performerId,
  });

  // Fetch messages for selected conversation
  const { data: messagesData, isLoading: messagesLoading } = useQuery({
    queryKey: ['performer-messages', performerId, selectedConversation],
    queryFn: () => performersApi.getConversationMessages(performerId, selectedConversation!),
    enabled: !!performerId && !!selectedConversation,
  });

  const conversations = conversationsData?.data || [];
  const messages = messagesData?.data || [];

  // Scroll to bottom when messages change
  useEffect(() => {
    if (messagesEndRef.current) {
      messagesEndRef.current.scrollIntoView({ behavior: 'smooth' });
    }
  }, [messages]);

  // Format relative time
  const formatRelativeTime = (dateString: string | null) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return format(date, 'MMM d');
  };

  if (conversationsLoading) {
    return (
      <div className="space-y-4">
        <div className="h-6 bg-slate-200 rounded animate-pulse w-32" />
        <div className="h-4 bg-slate-100 rounded animate-pulse w-48" />
        <div className="space-y-3 mt-6">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-16 bg-slate-100 rounded-lg animate-pulse" />
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-2">Messages</h3>
        <p className="text-sm text-slate-500">
          Communicate with customers about bookings and inquiries.
        </p>
      </div>

      {conversations.length === 0 ? (
        <div className="text-center py-8">
          <MessageSquare className="w-10 h-10 mx-auto mb-3 text-slate-300" />
          <p className="text-sm text-slate-500 mb-2">No messages yet</p>
          <p className="text-xs text-slate-400">
            When customers message you, they'll appear here.
          </p>
        </div>
      ) : (
        <div className="flex flex-col h-[500px] border border-slate-200 rounded-lg overflow-hidden">
          {/* Conversation List / Message View Toggle for Mobile */}
          {selectedConversation ? (
            // Message View
            <div className="flex flex-col h-full">
              {/* Header */}
              <div className="flex items-center gap-3 p-3 border-b border-slate-200 bg-slate-50">
                <button
                  onClick={() => setSelectedConversation(null)}
                  className="p-1 hover:bg-slate-200 rounded transition-colors"
                >
                  <ArrowLeft className="w-5 h-5 text-slate-600" />
                </button>
                <div className="flex-1">
                  <p className="font-medium text-slate-900">
                    {conversations.find(c => c.id === selectedConversation)?.other_user_name || 'Unknown'}
                  </p>
                </div>
              </div>

              {/* Messages */}
              <div className="flex-1 overflow-y-auto p-4 space-y-3 bg-slate-50">
                {messagesLoading ? (
                  <div className="flex justify-center py-8">
                    <Loader2 className="w-6 h-6 animate-spin text-slate-400" />
                  </div>
                ) : messages.length === 0 ? (
                  <div className="text-center py-8 text-sm text-slate-500">
                    No messages in this conversation
                  </div>
                ) : (
                  messages.map((message) => (
                    <div
                      key={message.id}
                      className={clsx(
                        'flex',
                        message.is_from_me ? 'justify-end' : 'justify-start'
                      )}
                    >
                      <div
                        className={clsx(
                          'max-w-[80%] rounded-lg px-3 py-2',
                          message.is_from_me
                            ? 'bg-primary-600 text-white'
                            : 'bg-white border border-slate-200 text-slate-800'
                        )}
                      >
                        <p className="text-sm whitespace-pre-wrap">{message.content}</p>
                        <p
                          className={clsx(
                            'text-xs mt-1',
                            message.is_from_me ? 'text-primary-200' : 'text-slate-400'
                          )}
                        >
                          {format(new Date(message.created_at), 'MMM d, h:mm a')}
                        </p>
                      </div>
                    </div>
                  ))
                )}
                <div ref={messagesEndRef} />
              </div>

              {/* Input placeholder */}
              <div className="p-3 border-t border-slate-200 bg-white">
                <div className="flex items-center gap-2 p-2 bg-slate-100 rounded-lg text-sm text-slate-500">
                  <MessageSquare className="w-4 h-4" />
                  <span>Reply functionality coming soon</span>
                </div>
              </div>
            </div>
          ) : (
            // Conversation List
            <div className="flex-1 overflow-y-auto">
              {conversations.map((conversation) => (
                <button
                  key={conversation.id}
                  onClick={() => setSelectedConversation(conversation.id)}
                  className={clsx(
                    'w-full flex items-start gap-3 p-4 text-left border-b border-slate-100',
                    'hover:bg-slate-50 transition-colors',
                    conversation.unread_count > 0 && 'bg-primary-50'
                  )}
                >
                  {/* Avatar */}
                  <div className="w-10 h-10 rounded-full bg-slate-200 flex items-center justify-center flex-shrink-0">
                    <User className="w-5 h-5 text-slate-500" />
                  </div>

                  {/* Content */}
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center justify-between gap-2 mb-1">
                      <p className={clsx(
                        'font-medium text-slate-900 truncate',
                        conversation.unread_count > 0 && 'font-semibold'
                      )}>
                        {conversation.other_user_name}
                      </p>
                      <span className="text-xs text-slate-400 flex-shrink-0">
                        {formatRelativeTime(conversation.last_message_at)}
                      </span>
                    </div>
                    <p className={clsx(
                      'text-sm truncate',
                      conversation.unread_count > 0 ? 'text-slate-700' : 'text-slate-500'
                    )}>
                      {conversation.last_message || 'No messages'}
                    </p>
                  </div>

                  {/* Unread badge */}
                  {conversation.unread_count > 0 && (
                    <div className="flex-shrink-0">
                      <span className="inline-flex items-center justify-center w-5 h-5 text-xs font-medium bg-primary-600 text-white rounded-full">
                        {conversation.unread_count}
                      </span>
                    </div>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  );
}

// Reviews Tab
interface ReviewsTabProps {
  performerId: number;
}

function ReviewsTab({ performerId }: ReviewsTabProps) {
  const { data, isLoading } = useQuery({
    queryKey: ['performer-reviews', performerId],
    queryFn: () => performersApi.getReviews(performerId),
    enabled: !!performerId,
  });

  const reviews = data?.data || [];
  const stats = data?.stats || {
    total_reviews: 0,
    average_rating: 0,
    rating_distribution: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 },
  };

  const renderStars = (rating: number) => {
    return (
      <div className="flex items-center gap-0.5">
        {[1, 2, 3, 4, 5].map((star) => (
          <Star
            key={star}
            className={clsx(
              'w-3.5 h-3.5',
              star <= rating ? 'text-amber-500 fill-amber-500' : 'text-slate-300'
            )}
          />
        ))}
      </div>
    );
  };

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="h-6 bg-slate-200 rounded animate-pulse w-32" />
        <div className="h-4 bg-slate-100 rounded animate-pulse w-48" />
        <div className="space-y-3 mt-6">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-24 bg-slate-100 rounded-lg animate-pulse" />
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-2">Reviews</h3>
        <p className="text-sm text-slate-500">
          See what customers are saying about your performances.
        </p>
      </div>

      {/* Rating Summary */}
      <div className="p-4 bg-slate-50 rounded-lg">
        <div className="flex items-start gap-6">
          {/* Overall Rating */}
          <div className="text-center">
            <div className="text-4xl font-bold text-slate-900">
              {stats.total_reviews > 0 ? stats.average_rating.toFixed(1) : '—'}
            </div>
            <div className="flex items-center justify-center gap-0.5 mt-1">
              {[1, 2, 3, 4, 5].map((star) => (
                <Star
                  key={star}
                  className={clsx(
                    'w-4 h-4',
                    star <= Math.round(stats.average_rating)
                      ? 'text-amber-500 fill-amber-500'
                      : 'text-slate-300'
                  )}
                />
              ))}
            </div>
            <p className="text-xs text-slate-500 mt-1">
              {stats.total_reviews} review{stats.total_reviews !== 1 ? 's' : ''}
            </p>
          </div>

          {/* Rating Distribution */}
          <div className="flex-1 space-y-1.5">
            {[5, 4, 3, 2, 1].map((rating) => {
              const count = stats.rating_distribution[rating as keyof typeof stats.rating_distribution] || 0;
              const percentage = stats.total_reviews > 0 ? (count / stats.total_reviews) * 100 : 0;
              return (
                <div key={rating} className="flex items-center gap-2">
                  <span className="text-xs text-slate-500 w-3">{rating}</span>
                  <Star className="w-3 h-3 text-amber-500 fill-amber-500" />
                  <div className="flex-1 h-2 bg-slate-200 rounded-full overflow-hidden">
                    <div
                      className="h-full bg-amber-500 rounded-full transition-all"
                      style={{ width: `${percentage}%` }}
                    />
                  </div>
                  <span className="text-xs text-slate-500 w-6 text-right">{count}</span>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Reviews List */}
      {reviews.length === 0 ? (
        <div className="text-center py-8">
          <Star className="w-10 h-10 mx-auto mb-3 text-slate-300" />
          <p className="text-sm text-slate-500 mb-2">No reviews yet</p>
          <p className="text-xs text-slate-400">
            After your first booking, customers can leave reviews.
          </p>
        </div>
      ) : (
        <div className="space-y-4">
          {reviews.map((review) => (
            <div key={review.id} className="p-4 bg-white border border-slate-200 rounded-lg">
              <div className="flex items-start justify-between mb-2">
                <div>
                  <p className="font-medium text-slate-900">{review.reviewer_name || 'Anonymous'}</p>
                  <p className="text-xs text-slate-500">
                    {new Date(review.created_at).toLocaleDateString('en-US', {
                      month: 'short',
                      day: 'numeric',
                      year: 'numeric',
                    })}
                  </p>
                </div>
                {renderStars(review.rating)}
              </div>
              {review.content && (
                <p className="text-sm text-slate-600 mt-2">{review.content}</p>
              )}
              {review.response && (
                <div className="mt-3 pl-4 border-l-2 border-primary-200 bg-primary-50 p-3 rounded-r-lg">
                  <p className="text-xs font-medium text-primary-700 mb-1">Your Response</p>
                  <p className="text-sm text-slate-600">{review.response}</p>
                </div>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// Payouts Tab
interface PayoutsTabProps {
  performerId: number;
}

function PayoutsTab({ performerId }: PayoutsTabProps) {
  const [statusFilter, setStatusFilter] = useState<string>('all');

  // Fetch payouts
  const { data, isLoading } = useQuery({
    queryKey: ['performer-payouts', performerId, statusFilter],
    queryFn: () => performersApi.getPayouts(performerId, { status: statusFilter }),
    enabled: !!performerId,
  });

  const payouts = data?.data || [];
  const stats = data?.stats || {
    total_earned: 0,
    total_released: 0,
    total_pending: 0,
    pending_count: 0,
    released_count: 0,
  };

  // Format currency
  const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
    }).format(amount);
  };

  if (isLoading) {
    return (
      <div className="space-y-4">
        <div className="h-6 bg-slate-200 rounded animate-pulse w-32" />
        <div className="h-4 bg-slate-100 rounded animate-pulse w-48" />
        <div className="grid grid-cols-3 gap-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-20 bg-slate-100 rounded-lg animate-pulse" />
          ))}
        </div>
        <div className="space-y-3 mt-6">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-16 bg-slate-100 rounded-lg animate-pulse" />
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div>
        <h3 className="text-lg font-semibold text-slate-900 mb-2">Payouts</h3>
        <p className="text-sm text-slate-500">
          Track your earnings and payout history.
        </p>
      </div>

      {/* Earnings summary */}
      <div className="grid grid-cols-3 gap-3">
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-1">
            <TrendingUp className="w-4 h-4 text-green-500" />
            <p className="text-xs text-slate-500">Total Earned</p>
          </div>
          <p className="text-xl font-bold text-slate-900">
            {formatCurrency(stats.total_earned)}
          </p>
        </Card>
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-1">
            <Wallet className="w-4 h-4 text-green-500" />
            <p className="text-xs text-slate-500">Released</p>
          </div>
          <p className="text-xl font-bold text-green-600">
            {formatCurrency(stats.total_released)}
          </p>
          <p className="text-xs text-slate-400">{stats.released_count} payout(s)</p>
        </Card>
        <Card className="p-4">
          <div className="flex items-center gap-2 mb-1">
            <Clock className="w-4 h-4 text-amber-500" />
            <p className="text-xs text-slate-500">Pending</p>
          </div>
          <p className="text-xl font-bold text-amber-600">
            {formatCurrency(stats.total_pending)}
          </p>
          <p className="text-xs text-slate-400">{stats.pending_count} payout(s)</p>
        </Card>
      </div>

      {/* Filter tabs */}
      <div className="flex gap-2 border-b border-slate-200">
        {[
          { value: 'all', label: 'All' },
          { value: 'pending', label: 'Pending' },
          { value: 'released', label: 'Released' },
        ].map((tab) => (
          <button
            key={tab.value}
            onClick={() => setStatusFilter(tab.value)}
            className={clsx(
              'px-4 py-2 text-sm font-medium border-b-2 -mb-px transition-colors',
              statusFilter === tab.value
                ? 'border-primary-600 text-primary-600'
                : 'border-transparent text-slate-500 hover:text-slate-700'
            )}
          >
            {tab.label}
          </button>
        ))}
      </div>

      {/* Payout history */}
      {payouts.length === 0 ? (
        <div className="text-center py-8">
          <Wallet className="w-10 h-10 mx-auto mb-3 text-slate-300" />
          <p className="text-sm text-slate-500 mb-2">No payouts yet</p>
          <p className="text-xs text-slate-400">
            Complete bookings to start earning.
          </p>
        </div>
      ) : (
        <div className="space-y-3">
          {payouts.map((payout) => (
            <div
              key={payout.booking_id}
              className="flex items-center gap-4 p-4 bg-white border border-slate-200 rounded-lg"
            >
              {/* Status indicator */}
              <div
                className={clsx(
                  'w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0',
                  payout.escrow_status === 'released'
                    ? 'bg-green-100'
                    : 'bg-amber-100'
                )}
              >
                {payout.escrow_status === 'released' ? (
                  <Check className="w-5 h-5 text-green-600" />
                ) : (
                  <Clock className="w-5 h-5 text-amber-600" />
                )}
              </div>

              {/* Details */}
              <div className="flex-1 min-w-0">
                <p className="font-medium text-slate-900 truncate">
                  {payout.event_title}
                </p>
                <p className="text-xs text-slate-500">
                  {payout.customer_name} • {format(new Date(payout.event_date), 'MMM d, yyyy')}
                </p>
                {payout.escrow_status === 'full_held' && payout.auto_release_date && (
                  <p className="text-xs text-amber-600 mt-1">
                    Auto-releases {format(new Date(payout.auto_release_date), 'MMM d, yyyy')}
                  </p>
                )}
              </div>

              {/* Amount */}
              <div className="text-right flex-shrink-0">
                <p className={clsx(
                  'font-bold',
                  payout.escrow_status === 'released' ? 'text-green-600' : 'text-slate-900'
                )}>
                  {formatCurrency(payout.payout_amount)}
                </p>
                {payout.commission_amount > 0 && (
                  <p className="text-xs text-slate-400">
                    -{formatCurrency(payout.commission_amount)} fee
                  </p>
                )}
              </div>

              {/* Status badge */}
              <Badge
                variant={payout.escrow_status === 'released' ? 'success' : 'warning'}
                className="flex-shrink-0"
              >
                {payout.escrow_status === 'released' ? 'Released' : 'Pending'}
              </Badge>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

// ============================================================================
// PROFILE TABS
// ============================================================================

// Profile Tab - basic performer information
interface ProfileTabProps {
  formData: Pick<FormDataType, 'stage_name' | 'tagline' | 'slug' | 'status'>;
  onChange: FormChangeHandler;
}

function ProfileTab({ formData, onChange }: ProfileTabProps) {
  return (
    <div className="space-y-6">
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Stage Name
        </label>
        <Input
          value={formData.stage_name || ''}
          onChange={(e) => onChange('stage_name', e.target.value)}
          placeholder="Your performer name"
        />
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Tagline
        </label>
        <Input
          value={formData.tagline || ''}
          onChange={(e) => onChange('tagline', e.target.value)}
          placeholder="A short catchy description..."
        />
        <p className="text-xs text-slate-500 mt-1">Shows below your name on your profile</p>
      </div>

      <div className="pt-4 border-t border-slate-200">
        <h4 className="font-medium text-slate-900 mb-4">Profile URL</h4>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            URL Slug
          </label>
          <Input
            value={formData.slug || ''}
            onChange={(e) => onChange('slug', e.target.value)}
            placeholder="your-name"
          />
          <p className="text-xs text-slate-500 mt-1">/comedy/{formData.slug || 'your-name'}/</p>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Profile Status
        </label>
        <Select
          options={[
            { value: 'active', label: 'Active - Visible to bookers' },
            { value: 'inactive', label: 'Inactive - Hidden from search' },
          ]}
          value={formData.status || 'active'}
          onChange={(e) => onChange('status', e.target.value as MicrositeStatus)}
        />
      </div>
    </div>
  );
}

// Pricing Tab
interface PricingTabProps {
  formData: Pick<FormDataType, 'hourly_rate' | 'minimum_booking' | 'deposit_percentage' | 'sale_price' | 'sale_active'>;
  onChange: FormChangeHandler;
}

function PricingTab({ formData, onChange }: PricingTabProps) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Hourly Rate ($)
          </label>
          <Input
            type="number"
            value={formData.hourly_rate || ''}
            onChange={(e) => onChange('hourly_rate', Number(e.target.value))}
            placeholder="150"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            Minimum Hours
          </label>
          <Input
            type="number"
            value={formData.minimum_booking || ''}
            onChange={(e) => onChange('minimum_booking', Number(e.target.value))}
            placeholder="2"
          />
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Deposit Percentage (%)
        </label>
        <Input
          type="number"
          value={formData.deposit_percentage || ''}
          onChange={(e) => onChange('deposit_percentage', Number(e.target.value))}
          placeholder="25"
        />
        <p className="text-xs text-slate-500 mt-1">Required upfront to confirm booking</p>
      </div>

      <div className="pt-4 border-t border-slate-200">
        <h4 className="font-medium text-slate-900 mb-4">Sale Pricing</h4>
        <div className="space-y-4">
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={formData.sale_active || false}
              onChange={(e) => onChange('sale_active', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Enable sale pricing</span>
          </label>
          {formData.sale_active && (
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Sale Price ($)
              </label>
              <Input
                type="number"
                value={formData.sale_price || ''}
                onChange={(e) => onChange('sale_price', Number(e.target.value))}
                placeholder="100"
              />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// Location Tab
interface LocationTabProps {
  formData: Pick<FormDataType, 'location_city' | 'location_state' | 'travel_willing' | 'travel_radius'>;
  onChange: FormChangeHandler;
}

function LocationTab({ formData, onChange }: LocationTabProps) {
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4">
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            City
          </label>
          <Input
            value={formData.location_city || ''}
            onChange={(e) => onChange('location_city', e.target.value)}
            placeholder="Los Angeles"
          />
        </div>
        <div>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            State
          </label>
          <Input
            value={formData.location_state || ''}
            onChange={(e) => onChange('location_state', e.target.value)}
            placeholder="CA"
          />
        </div>
      </div>

      <div className="pt-4 border-t border-slate-200">
        <h4 className="font-medium text-slate-900 mb-4">Travel</h4>
        <div className="space-y-4">
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={formData.travel_willing || false}
              onChange={(e) => onChange('travel_willing', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Willing to travel for gigs</span>
          </label>
          {formData.travel_willing && (
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Travel Radius (miles)
              </label>
              <Input
                type="number"
                value={formData.travel_radius || ''}
                onChange={(e) => onChange('travel_radius', Number(e.target.value))}
                placeholder="50"
              />
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

// Bio Tab
interface BioTabProps {
  formData: Pick<FormDataType, 'bio'>;
  onChange: FormChangeHandler;
}

function BioTab({ formData, onChange }: BioTabProps) {
  return (
    <div className="space-y-6">
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1" htmlFor="micrositeeditor-biography">
          Biography
        </label>
        <textarea id="micrositeeditor-biography"
          value={formData.bio || ''}
          onChange={(e) => onChange('bio', e.target.value)}
          placeholder="Tell bookers about yourself, your experience, and what makes your performances special..."
          className="w-full px-3 py-2 border border-slate-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 min-h-[200px]"
          rows={10}
        />
        <p className="text-xs text-slate-500 mt-1">
          {(formData.bio?.length || 0)} characters
        </p>
      </div>

      <div className="p-4 bg-slate-50 rounded-lg">
        <h4 className="font-medium text-slate-900 mb-2">Tips for a great bio</h4>
        <ul className="text-sm text-slate-600 space-y-1">
          <li>• Share your performance style and specialties</li>
          <li>• Mention notable venues or events you've performed at</li>
          <li>• Include any awards or special recognitions</li>
          <li>• Keep it conversational and engaging</li>
        </ul>
      </div>
    </div>
  );
}

interface DesignTabProps {
  designSettings: typeof defaultDesignSettings;
  onDesignChange: <T extends keyof typeof defaultDesignSettings>(
    field: T,
    value: (typeof defaultDesignSettings)[T]
  ) => void;
}

// Template thumbnail previews
const TemplateThumbnail = ({ template }: { template: MicrositeTemplate }) => {
  const styles: Record<MicrositeTemplate, { hero: string; body: string; btn: string; accent: string }> = {
    classic: { hero: 'h-8 bg-slate-700', body: 'space-y-1', btn: 'h-2 bg-slate-600 rounded-sm', accent: 'border-t border-slate-300' },
    modern: { hero: 'h-10 bg-gradient-to-t from-slate-800 to-slate-600', body: 'space-y-2', btn: 'h-2 bg-blue-500 rounded-full', accent: '' },
    bold: { hero: 'h-12 bg-slate-900 flex items-center justify-center', body: 'space-y-2', btn: 'h-3 bg-red-500 rounded-full shadow', accent: '' },
    minimal: { hero: 'h-6 bg-slate-400', body: 'space-y-3', btn: 'h-2 border border-slate-400', accent: 'border-l-2 border-slate-400 pl-1' },
  };
  const s = styles[template];

  return (
    <div className="w-full h-24 bg-white rounded border border-slate-200 overflow-hidden mb-2">
      <div className={s.hero}>
        {template === 'bold' && <div className="w-8 h-1.5 bg-white/80 rounded" />}
      </div>
      <div className={clsx('p-2', s.body)}>
        <div className={clsx('h-1 bg-slate-200 w-3/4 rounded-sm', s.accent)} />
        <div className="h-1 bg-slate-100 w-full rounded-sm" />
        <div className={s.btn + ' w-full mt-1'} />
      </div>
    </div>
  );
};

function DesignTab({ designSettings, onDesignChange }: DesignTabProps) {
  return (
    <div className="space-y-6">
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-3">
          Template
        </label>
        <div className="grid grid-cols-2 gap-3">
          {templateOptions.map((template) => (
            <button
              key={template.value}
              onClick={() => onDesignChange('template', template.value)}
              className={clsx(
                'p-3 rounded-lg border-2 text-left transition-all',
                designSettings.template === template.value
                  ? 'border-primary-600 bg-primary-50'
                  : 'border-slate-200 hover:border-slate-300'
              )}
            >
              <TemplateThumbnail template={template.value} />
              <p className="font-medium text-slate-900 text-sm">{template.label}</p>
              <p className="text-xs text-slate-500">{template.description}</p>
            </button>
          ))}
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-3">
          Colors
        </label>
        <div className="space-y-3">
          {[
            { key: 'primary_color', label: 'Primary Color' },
            { key: 'secondary_color', label: 'Secondary Color' },
            { key: 'background_color', label: 'Background Color' },
            { key: 'text_color', label: 'Text Color' },
          ].map(({ key, label }) => (
            <div key={key} className="flex items-center gap-3">
              <input
                type="color"
                value={(designSettings as any)[key]}
                onChange={(e) => onDesignChange(key as any, e.target.value)}
                className="w-10 h-10 rounded border border-slate-300 cursor-pointer"
              />
              <div className="flex-1">
                <p className="text-sm font-medium text-slate-700">{label}</p>
                <Input
                  value={(designSettings as any)[key]}
                  onChange={(e) => onDesignChange(key as any, e.target.value)}
                  className="mt-1"
                />
              </div>
            </div>
          ))}
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Font Family
        </label>
        <Select
          options={[
            { value: 'Inter', label: 'Inter' },
            { value: 'Roboto', label: 'Roboto' },
            { value: 'Open Sans', label: 'Open Sans' },
            { value: 'Lato', label: 'Lato' },
            { value: 'Montserrat', label: 'Montserrat' },
            { value: 'Poppins', label: 'Poppins' },
          ]}
          value={designSettings.font_family}
          onChange={(e) => onDesignChange('font_family', e.target.value)}
        />
      </div>
    </div>
  );
}

interface LayoutTabProps {
  layoutSettings: LayoutSettings;
  onLayoutChange: <T extends keyof LayoutSettings>(field: T, value: LayoutSettings[T]) => void;
  onDesignChange: <T extends keyof typeof defaultDesignSettings>(
    field: T,
    value: (typeof defaultDesignSettings)[T]
  ) => void;
  showReviews: boolean;
  showCalendar: boolean;
  showBookingButton: boolean;
}

// Sortable Section Item Component
interface SortableSectionItemProps {
  section: MicrositeSection;
  index: number;
}

function SortableSectionItem({ section, index }: SortableSectionItemProps) {
  const {
    attributes,
    listeners,
    setNodeRef,
    transform,
    transition,
    isDragging,
  } = useSortable({ id: section });

  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className={clsx(
        'flex items-center gap-3 p-3 bg-slate-50 rounded-lg',
        isDragging && 'shadow-lg bg-white ring-2 ring-primary-500 z-10'
      )}
    >
      <button
        type="button"
        className="cursor-grab active:cursor-grabbing touch-none p-1 -m-1 text-slate-400 hover:text-slate-600"
        {...attributes}
        {...listeners}
      >
        <GripVertical className="w-4 h-4" />
      </button>
      <span className="flex-1 text-sm text-slate-700">{sectionLabels[section]}</span>
      <span className="text-xs text-slate-400 tabular-nums">{index + 1}</span>
    </div>
  );
}

function LayoutTab({
  layoutSettings,
  onLayoutChange,
  onDesignChange,
  showReviews,
  showCalendar,
  showBookingButton,
}: LayoutTabProps) {
  const sensors = useSensors(
    useSensor(PointerSensor, {
      activationConstraint: {
        distance: 8,
      },
    }),
    useSensor(KeyboardSensor, {
      coordinateGetter: sortableKeyboardCoordinates,
    })
  );

  const handleDragEnd = (event: DragEndEvent) => {
    const { active, over } = event;
    if (over && active.id !== over.id) {
      const oldIndex = layoutSettings.sections_order.indexOf(active.id as MicrositeSection);
      const newIndex = layoutSettings.sections_order.indexOf(over.id as MicrositeSection);
      const newOrder = arrayMove(layoutSettings.sections_order, oldIndex, newIndex);
      onLayoutChange('sections_order', newOrder);
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-3">
          Hero Style
        </label>
        <div className="grid grid-cols-3 gap-3">
          {/* Full Width Hero */}
          <button
            onClick={() => onLayoutChange('hero_style', 'full_width')}
            className={clsx(
              'p-2 rounded-lg border-2 transition-all',
              layoutSettings.hero_style === 'full_width'
                ? 'border-primary-600 bg-primary-50'
                : 'border-slate-200 hover:border-slate-300'
            )}
          >
            <div className="w-full h-16 bg-slate-100 rounded overflow-hidden mb-2">
              {/* Full width wireframe */}
              <div className="h-10 bg-slate-700" />
              <div className="p-1.5 space-y-1">
                <div className="h-1 bg-slate-300 w-3/4 rounded-sm" />
                <div className="h-1 bg-slate-200 w-1/2 rounded-sm" />
              </div>
            </div>
            <p className="text-xs font-medium text-slate-700">Full Width</p>
          </button>

          {/* Contained Hero */}
          <button
            onClick={() => onLayoutChange('hero_style', 'contained')}
            className={clsx(
              'p-2 rounded-lg border-2 transition-all',
              layoutSettings.hero_style === 'contained'
                ? 'border-primary-600 bg-primary-50'
                : 'border-slate-200 hover:border-slate-300'
            )}
          >
            <div className="w-full h-16 bg-slate-100 rounded overflow-hidden mb-2 p-1.5">
              {/* Contained wireframe */}
              <div className="h-8 bg-slate-700 rounded" />
              <div className="mt-1 space-y-0.5">
                <div className="h-1 bg-slate-300 w-3/4 rounded-sm" />
                <div className="h-1 bg-slate-200 w-1/2 rounded-sm" />
              </div>
            </div>
            <p className="text-xs font-medium text-slate-700">Contained</p>
          </button>

          {/* Split Hero */}
          <button
            onClick={() => onLayoutChange('hero_style', 'split')}
            className={clsx(
              'p-2 rounded-lg border-2 transition-all',
              layoutSettings.hero_style === 'split'
                ? 'border-primary-600 bg-primary-50'
                : 'border-slate-200 hover:border-slate-300'
            )}
          >
            <div className="w-full h-16 bg-slate-100 rounded overflow-hidden mb-2 flex">
              {/* Split wireframe - Image left with icon */}
              <div className="w-1/2 bg-slate-700 flex items-center justify-center">
                <Image className="w-4 h-4 text-slate-400" />
              </div>
              <div className="w-1/2 bg-slate-500 flex items-center p-1.5">
                <div className="space-y-1 w-full">
                  <div className="h-1.5 bg-white/70 w-full rounded-sm" />
                  <div className="h-1 bg-white/40 w-2/3 rounded-sm" />
                </div>
              </div>
            </div>
            <p className="text-xs font-medium text-slate-700">Split</p>
          </button>
        </div>
      </div>

      <div>
        <label className="block text-sm font-medium text-slate-700 mb-3">
          Section Order
        </label>
        <p className="text-xs text-slate-500 mb-3">
          Drag to reorder sections on your microsite
        </p>
        <DndContext
          sensors={sensors}
          collisionDetection={closestCenter}
          onDragEnd={handleDragEnd}
        >
          <SortableContext
            items={layoutSettings.sections_order}
            strategy={verticalListSortingStrategy}
          >
            <div className="space-y-2">
              {layoutSettings.sections_order.map((section, index) => (
                <SortableSectionItem key={section} section={section} index={index} />
              ))}
            </div>
          </SortableContext>
        </DndContext>
      </div>

      <div className="pt-4 border-t border-slate-200">
        <h4 className="font-medium text-slate-900 mb-4">Display Options</h4>
        <div className="space-y-3">
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={showReviews}
              onChange={(e) => onDesignChange('show_reviews', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Show reviews section</span>
          </label>
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={showCalendar}
              onChange={(e) => onDesignChange('show_calendar', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Show availability calendar</span>
          </label>
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={showBookingButton}
              onChange={(e) => onDesignChange('show_booking_button', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Show booking button</span>
          </label>
          <label className="flex items-center gap-3">
            <input
              type="checkbox"
              checked={layoutSettings.show_external_gigs}
              onChange={(e) => onLayoutChange('show_external_gigs', e.target.checked)}
              className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
            />
            <span className="text-sm text-slate-700">Show external gigs on calendar</span>
          </label>
        </div>
      </div>
    </div>
  );
}

interface SocialLinksTabProps {
  socialLinks: SocialLinks;
  onSocialChange: (platform: keyof SocialLinks, value: string) => void;
}

function SocialLinksTab({ socialLinks, onSocialChange }: SocialLinksTabProps) {
  const platforms: { key: keyof SocialLinks; label: string; placeholder: string }[] = [
    { key: 'facebook', label: 'Facebook', placeholder: 'https://facebook.com/username' },
    { key: 'instagram', label: 'Instagram', placeholder: 'https://instagram.com/username' },
    { key: 'tiktok', label: 'TikTok', placeholder: 'https://tiktok.com/@username' },
    { key: 'youtube', label: 'YouTube', placeholder: 'https://youtube.com/channel/...' },
    { key: 'twitter', label: 'X (Twitter)', placeholder: 'https://x.com/username' },
    { key: 'linkedin', label: 'LinkedIn', placeholder: 'https://linkedin.com/in/username' },
  ];

  return (
    <div className="space-y-4">
      <p className="text-sm text-slate-500">
        Add your social media links to display on your microsite.
      </p>
      {platforms.map(({ key, label, placeholder }) => (
        <div key={key}>
          <label className="block text-sm font-medium text-slate-700 mb-1">
            {label}
          </label>
          <Input
            value={socialLinks[key] || ''}
            onChange={(e) => onSocialChange(key, e.target.value)}
            placeholder={placeholder}
          />
        </div>
      ))}
    </div>
  );
}

interface MediaTabProps {
  mediaOverrides: MediaOverrides;
  performerMedia: {
    profile_photo?: string;
    gallery: Array<{ id: number; url: string }>;
  };
  onMediaChange: <T extends keyof MediaOverrides>(field: T, value: MediaOverrides[T]) => void;
}

function MediaTab({ mediaOverrides, performerMedia, onMediaChange }: MediaTabProps) {
  const openMediaLibrary = (type: 'image' | 'gallery', multiple: boolean = false) => {
    if (!window.wp?.media) {
      // Fallback: prompt for URL if WordPress media library isn't available
      const url = prompt(`Enter ${type === 'gallery' ? 'image' : 'hero image'} URL:`);
      if (url) {
        if (type === 'gallery') {
          const newGallery = [...(mediaOverrides.gallery || []), { id: Date.now(), url }];
          onMediaChange('gallery', newGallery);
        } else {
          onMediaChange('hero_image', url);
        }
      }
      return;
    }

    const frame = window.wp.media({
      title: type === 'gallery' ? 'Select Gallery Images' : 'Select Hero Image',
      library: { type: 'image' },
      multiple,
      button: { text: 'Use Image' },
    });

    frame.on('select', () => {
      const selection = frame.state().get('selection').toJSON();
      if (type === 'gallery') {
        const newImages = Array.isArray(selection)
          ? selection.map((img) => ({ id: img.id, url: img.url }))
          : [{ id: selection.id, url: selection.url }];
        const newGallery = [...(mediaOverrides.gallery || []), ...newImages];
        onMediaChange('gallery', newGallery);
      } else {
        const img = Array.isArray(selection) ? selection[0] : selection;
        onMediaChange('hero_image', img.url);
      }
    });

    frame.open();
  };

  const removeGalleryImage = (imageId: number) => {
    const newGallery = (mediaOverrides.gallery || []).filter((img) => img.id !== imageId);
    onMediaChange('gallery', newGallery);
  };

  const currentHeroImage = mediaOverrides.hero_image || performerMedia.profile_photo;
  const currentGallery = mediaOverrides.gallery?.length ? mediaOverrides.gallery : performerMedia.gallery;

  return (
    <div className="space-y-6">
      <p className="text-sm text-slate-500">
        Override profile media for your microsite. Leave empty to use your profile defaults.
      </p>

      {/* Hero Image */}
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-2">
          Hero Image
        </label>
        <div className="space-y-3">
          {currentHeroImage ? (
            <div className="relative group">
              <img
                src={currentHeroImage}
                alt="Hero"
                className="w-full h-40 object-cover rounded-lg"
              />
              <div className="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity rounded-lg flex items-center justify-center gap-2">
                <Button
                  size="sm"
                  variant="outline"
                  className="bg-white"
                  onClick={() => openMediaLibrary('image')}
                >
                  Change
                </Button>
                {mediaOverrides.hero_image && (
                  <Button
                    size="sm"
                    variant="outline"
                    className="bg-white"
                    onClick={() => onMediaChange('hero_image', '')}
                  >
                    Reset
                  </Button>
                )}
              </div>
              {mediaOverrides.hero_image && (
                <Badge variant="success" className="absolute top-2 left-2">
                  Custom
                </Badge>
              )}
            </div>
          ) : (
            <button
              onClick={() => openMediaLibrary('image')}
              className="w-full h-40 border-2 border-dashed border-slate-300 rounded-lg flex flex-col items-center justify-center text-slate-500 hover:border-primary-500 hover:text-primary-600 transition-colors"
            >
              <Upload className="w-8 h-8 mb-2" />
              <span className="text-sm">Upload hero image</span>
            </button>
          )}
        </div>
      </div>

      {/* Gallery Images */}
      <div>
        <div className="flex items-center justify-between mb-2">
          <label className="block text-sm font-medium text-slate-700">
            Gallery Images
          </label>
          <Button
            size="sm"
            variant="outline"
            onClick={() => openMediaLibrary('gallery', true)}
            icon={<Plus className="w-4 h-4" />}
          >
            Add Images
          </Button>
        </div>
        {currentGallery.length > 0 ? (
          <div className="grid grid-cols-3 gap-2">
            {currentGallery.map((img) => (
              <div key={img.id} className="relative group">
                <img
                  src={img.url}
                  alt="Gallery"
                  className="w-full h-24 object-cover rounded-lg"
                />
                {mediaOverrides.gallery?.some((g) => g.id === img.id) && (
                  <button
                    onClick={() => removeGalleryImage(img.id)}
                    className="absolute top-1 right-1 p-1 bg-red-500 text-white rounded-full opacity-0 group-hover:opacity-100 transition-opacity"
                  >
                    <X className="w-3 h-3" />
                  </button>
                )}
              </div>
            ))}
          </div>
        ) : (
          <div className="text-center py-8 text-slate-400">
            <Image className="w-8 h-8 mx-auto mb-2 opacity-50" />
            <p className="text-sm">No gallery images</p>
          </div>
        )}
        {mediaOverrides.gallery?.length ? (
          <Button
            variant="ghost"
            size="sm"
            className="mt-2"
            onClick={() => onMediaChange('gallery', [])}
          >
            Reset to profile gallery
          </Button>
        ) : null}
      </div>

      {/* Video URL */}
      <div>
        <label className="block text-sm font-medium text-slate-700 mb-1">
          Video URL
        </label>
        <div className="flex gap-2">
          <div className="flex-1">
            <Input
              value={mediaOverrides.video_url || ''}
              onChange={(e) => onMediaChange('video_url', e.target.value)}
              placeholder="https://youtube.com/watch?v=..."
            />
          </div>
          <Button
            variant="outline"
            className="flex-shrink-0"
            icon={<Video className="w-4 h-4" />}
          >
            Preview
          </Button>
        </div>
        <p className="text-xs text-slate-500 mt-1">
          YouTube or Vimeo URL for your promo video
        </p>
      </div>
    </div>
  );
}

interface ExternalGigsTabProps {
  gigs: ExternalGig[];
  showForm: boolean;
  editingGig: ExternalGig | null;
  gigForm: {
    date: string;
    event_name: string;
    venue_name: string;
    event_location: string;
    event_time: string;
    is_public: boolean;
    ticket_url: string;
  };
  onGigFormChange: (field: string, value: string | boolean) => void;
  onShowForm: () => void;
  onCancelForm: () => void;
  onSubmitForm: () => void;
  onEditGig: (gig: ExternalGig) => void;
  onDeleteGig: (gigId: number) => void;
  isSubmitting: boolean;
}

function ExternalGigsTab({
  gigs,
  showForm,
  editingGig,
  gigForm,
  onGigFormChange,
  onShowForm,
  onCancelForm,
  onSubmitForm,
  onEditGig,
  onDeleteGig,
  isSubmitting,
}: ExternalGigsTabProps) {
  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <p className="text-sm text-slate-500">
          Add shows that aren't booked through the platform.
        </p>
        {!showForm && (
          <Button size="sm" onClick={onShowForm} icon={<Plus className="w-4 h-4" />}>
            Add Gig
          </Button>
        )}
      </div>

      {showForm && (
        <Card className="p-4 space-y-4">
          <h4 className="font-medium text-slate-900">
            {editingGig ? 'Edit External Gig' : 'Add External Gig'}
          </h4>

          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Date *
              </label>
              <Input
                type="date"
                value={gigForm.date}
                onChange={(e) => onGigFormChange('date', e.target.value)}
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-slate-700 mb-1">
                Time
              </label>
              <Input
                type="time"
                value={gigForm.event_time}
                onChange={(e) => onGigFormChange('event_time', e.target.value)}
              />
            </div>
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Event Name *
            </label>
            <Input
              value={gigForm.event_name}
              onChange={(e) => onGigFormChange('event_name', e.target.value)}
              placeholder="Comedy Night at..."
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Venue
            </label>
            <Input
              value={gigForm.venue_name}
              onChange={(e) => onGigFormChange('venue_name', e.target.value)}
              placeholder="The Comedy Club"
            />
          </div>

          <div>
            <label className="block text-sm font-medium text-slate-700 mb-1">
              Location
            </label>
            <Input
              value={gigForm.event_location}
              onChange={(e) => onGigFormChange('event_location', e.target.value)}
              placeholder="123 Main St, City, State"
            />
          </div>

          <div className="pt-3 border-t border-slate-200">
            <label className="flex items-center gap-3 mb-3">
              <input
                type="checkbox"
                checked={gigForm.is_public}
                onChange={(e) => onGigFormChange('is_public', e.target.checked)}
                className="w-4 h-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500"
              />
              <span className="text-sm text-slate-700">
                Show public details (otherwise shows as "Private Event")
              </span>
            </label>

            {gigForm.is_public && (
              <div>
                <label className="block text-sm font-medium text-slate-700 mb-1">
                  Ticket URL
                </label>
                <Input
                  value={gigForm.ticket_url}
                  onChange={(e) => onGigFormChange('ticket_url', e.target.value)}
                  placeholder="https://tickets.example.com/..."
                />
              </div>
            )}
          </div>

          <div className="flex justify-end gap-3 pt-3">
            <Button variant="ghost" onClick={onCancelForm}>
              Cancel
            </Button>
            <Button
              onClick={onSubmitForm}
              loading={isSubmitting}
              disabled={!gigForm.date || !gigForm.event_name}
            >
              {editingGig ? 'Update Gig' : 'Add Gig'}
            </Button>
          </div>
        </Card>
      )}

      {gigs.length > 0 ? (
        <div className="space-y-2">
          {gigs.map((gig) => (
            <div
              key={gig.id}
              className="flex items-center justify-between p-3 bg-slate-50 rounded-lg"
            >
              <div>
                <p className="font-medium text-slate-900">{gig.event_name}</p>
                <p className="text-sm text-slate-500">
                  {format(new Date(gig.date), 'MMM d, yyyy')}
                  {gig.venue_name && ` at ${gig.venue_name}`}
                </p>
                {!gig.is_public && (
                  <Badge variant="default" className="mt-1">Private</Badge>
                )}
              </div>
              <div className="flex items-center gap-2">
                <Button variant="ghost" size="sm" onClick={() => onEditGig(gig)}>
                  Edit
                </Button>
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={() => onDeleteGig(gig.id)}
                  icon={<Trash2 className="w-4 h-4 text-red-500" />}
                />
              </div>
            </div>
          ))}
        </div>
      ) : (
        !showForm && (
          <div className="text-center py-8 text-slate-500">
            <Calendar className="w-8 h-8 mx-auto mb-2 opacity-50" />
            <p>No external gigs yet</p>
          </div>
        )
      )}
    </div>
  );
}

interface AnalyticsTabProps {
  analytics?: MicrositeAnalytics;
}

function AnalyticsTab({ analytics }: AnalyticsTabProps) {
  if (!analytics) {
    return (
      <div className="text-center py-8">
        <RefreshCw className="w-6 h-6 mx-auto mb-2 text-slate-400 animate-spin" />
        <p className="text-slate-500">Loading analytics...</p>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="grid grid-cols-2 gap-4">
        <Card className="p-4 text-center">
          <p className="text-2xl font-bold text-slate-900">{analytics.total_views}</p>
          <p className="text-sm text-slate-500">Total Views</p>
        </Card>
        <Card className="p-4 text-center">
          <p className="text-2xl font-bold text-slate-900">{analytics.unique_visitors}</p>
          <p className="text-sm text-slate-500">Unique Visitors</p>
        </Card>
        <Card className="p-4 text-center">
          <p className="text-2xl font-bold text-slate-900">{analytics.booking_clicks}</p>
          <p className="text-sm text-slate-500">Booking Clicks</p>
        </Card>
        <Card className="p-4 text-center">
          <p className="text-2xl font-bold text-slate-900">
            {analytics.total_views > 0
              ? ((analytics.booking_clicks / analytics.total_views) * 100).toFixed(1)
              : 0}
            %
          </p>
          <p className="text-sm text-slate-500">Click Rate</p>
        </Card>
      </div>

      {analytics.top_referrers.length > 0 && (
        <div>
          <h4 className="font-medium text-slate-900 mb-3">Top Referrers</h4>
          <div className="space-y-2">
            {analytics.top_referrers.slice(0, 5).map((ref, i) => (
              <div key={i} className="flex items-center justify-between text-sm">
                <span className="text-slate-600">{ref.domain || 'Direct'}</span>
                <span className="font-medium text-slate-900">{ref.count}</span>
              </div>
            ))}
          </div>
        </div>
      )}

      <p className="text-xs text-slate-400 text-center">
        Data from {analytics.date_range.start} to {analytics.date_range.end}
      </p>
    </div>
  );
}

// Preview Content Component

interface PreviewContentProps {
  previewData: MicrositePreviewData;
  designSettings: typeof defaultDesignSettings;
  externalGigs: ExternalGig[];
}

// Template-specific styles
const templateStyles = {
  classic: {
    heroOverlay: 'bg-black/30',
    heroTextAlign: 'items-end' as const,
    headingFont: 'font-serif',
    headingSize: 'text-2xl',
    bodySpacing: 'space-y-6',
    cardStyle: 'border border-slate-200 rounded',
    buttonStyle: 'rounded',
    sectionTitle: 'text-lg font-semibold border-b pb-2 mb-3',
    galleryRounded: 'rounded',
    socialStyle: 'border border-slate-300',
  },
  modern: {
    heroOverlay: 'bg-gradient-to-t from-black/70 via-black/30 to-transparent',
    heroTextAlign: 'items-end' as const,
    headingFont: 'font-sans',
    headingSize: 'text-3xl',
    bodySpacing: 'space-y-8',
    cardStyle: 'bg-slate-50/50 rounded-2xl shadow-sm',
    buttonStyle: 'rounded-xl',
    sectionTitle: 'text-base font-medium uppercase tracking-wide text-slate-500 mb-4',
    galleryRounded: 'rounded-xl',
    socialStyle: 'bg-slate-100 hover:bg-slate-200 transition-colors',
  },
  bold: {
    heroOverlay: 'bg-gradient-to-b from-transparent via-black/20 to-black/80',
    heroTextAlign: 'items-center justify-center' as const,
    headingFont: 'font-black',
    headingSize: 'text-4xl',
    bodySpacing: 'space-y-8',
    cardStyle: 'rounded-xl shadow-lg',
    buttonStyle: 'rounded-full shadow-lg transform hover:scale-105 transition-transform',
    sectionTitle: 'text-xl font-black uppercase mb-4',
    galleryRounded: 'rounded-xl shadow-md',
    socialStyle: 'shadow-md hover:shadow-lg transition-shadow',
  },
  minimal: {
    heroOverlay: 'bg-black/20',
    heroTextAlign: 'items-end' as const,
    headingFont: 'font-light',
    headingSize: 'text-2xl',
    bodySpacing: 'space-y-10',
    cardStyle: 'border-l-2 pl-4',
    buttonStyle: 'rounded-none border-2 bg-transparent hover:bg-black hover:text-white transition-colors',
    sectionTitle: 'text-sm font-medium uppercase tracking-widest text-slate-400 mb-4',
    galleryRounded: 'rounded-none',
    socialStyle: 'border border-slate-200 hover:border-slate-400 transition-colors',
  },
};

function PreviewContent({ previewData, designSettings, externalGigs: _externalGigs }: PreviewContentProps) {
  const performer = previewData.performer;
  const template = designSettings.template || 'classic';
  const styles = templateStyles[template];
  const heroStyle = designSettings.layout_settings?.hero_style || 'full_width';
  const sectionsOrder = designSettings.layout_settings?.sections_order || ['hero', 'bio', 'gallery', 'reviews', 'calendar', 'social', 'booking'];

  // Use media overrides if available, otherwise fall back to performer media
  const mediaOverrides = designSettings.media_overrides || {};
  const heroImage = mediaOverrides.hero_image || performer?.profile_photo;
  const galleryImages = mediaOverrides.gallery?.length ? mediaOverrides.gallery : (performer?.gallery || []);

  if (!performer) {
    return (
      <div className="p-8 text-center text-slate-500">
        <p>No performer data available</p>
      </div>
    );
  }

  // Hero section component
  const HeroSection = () => (
    <div
      className={clsx(
        'relative bg-gradient-to-b from-slate-900 to-slate-700',
        template === 'bold' ? 'h-80' : template === 'minimal' ? 'h-48' : 'h-64',
        heroStyle === 'contained' && 'mx-4 mt-4 rounded-xl overflow-hidden',
        heroStyle === 'split' && 'h-auto min-h-48'
      )}
      style={{
        backgroundImage: heroStyle !== 'split' && heroImage ? `url(${heroImage})` : undefined,
        backgroundSize: 'cover',
        backgroundPosition: 'center',
      }}
    >
      {heroStyle === 'split' ? (
        <div className="flex">
          {heroImage && (
            <div
              className="w-1/2 h-48 bg-cover bg-center"
              style={{ backgroundImage: `url(${heroImage})` }}
            />
          )}
          <div className={clsx('flex-1 flex items-center p-6', !heroImage && 'w-full')} style={{ backgroundColor: designSettings.primary_color }}>
            <div className="text-white">
              <h1 className={clsx(styles.headingSize, styles.headingFont)}>
                {performer.stage_name}
              </h1>
              {performer.tagline && (
                <p className="mt-2 opacity-90 text-sm">{performer.tagline}</p>
              )}
            </div>
          </div>
        </div>
      ) : (
        <>
          <div className={clsx('absolute inset-0', styles.heroOverlay, heroStyle === 'contained' && 'rounded-xl')} />
          <div className={clsx('absolute inset-0 flex', styles.heroTextAlign)}>
            <div className={clsx('p-6 text-white', template === 'bold' && 'text-center')}>
              <h1 className={clsx(styles.headingSize, styles.headingFont)}>
                {performer.stage_name}
              </h1>
              {performer.tagline && (
                <p className={clsx('mt-2 opacity-90', template === 'bold' ? 'text-lg' : 'text-sm')}>
                  {performer.tagline}
                </p>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  );

  // Bio section
  const BioSection = () => performer.bio ? (
    <div>
      <h2 className={styles.sectionTitle}>About</h2>
      <p className={clsx('opacity-80', template === 'bold' ? 'text-base leading-relaxed' : 'text-sm')}>
        {performer.bio.slice(0, 200)}...
      </p>
      {performer.hourly_rate > 0 && (
        <div
          className={clsx('p-4 mt-4', styles.cardStyle)}
          style={{
            backgroundColor: template === 'minimal' ? 'transparent' : designSettings.primary_color + '15',
            borderColor: template === 'minimal' ? designSettings.primary_color : undefined,
          }}
        >
          <p className={clsx('opacity-70', template === 'bold' ? 'text-base font-medium' : 'text-sm')}>Starting at</p>
          <p className={clsx('font-bold', template === 'bold' ? 'text-4xl' : 'text-2xl')} style={{ color: designSettings.primary_color }}>
            ${performer.hourly_rate}/hr
          </p>
        </div>
      )}
    </div>
  ) : null;

  // Gallery section
  const GallerySection = () => galleryImages.length > 0 ? (
    <div>
      <h2 className={styles.sectionTitle}>Gallery</h2>
      <div className={clsx('grid gap-2', template === 'minimal' ? 'grid-cols-2 gap-4' : 'grid-cols-3')}>
        {galleryImages.slice(0, template === 'minimal' ? 2 : 3).map((img) => (
          <img
            key={img.id}
            src={img.url}
            alt="Gallery"
            className={clsx('w-full object-cover', styles.galleryRounded, template === 'bold' ? 'h-24' : 'h-20')}
          />
        ))}
      </div>
    </div>
  ) : null;

  // Reviews section
  const ReviewsSection = () => designSettings.show_reviews && previewData.reviews.length > 0 ? (
    <div>
      <h2 className={styles.sectionTitle}>Reviews</h2>
      <div className="space-y-3">
        {previewData.reviews.slice(0, 2).map((review) => (
          <div
            key={review.id}
            className={clsx('p-3', template === 'minimal' ? 'border-l-2 pl-4' : styles.cardStyle)}
            style={{
              backgroundColor: template === 'minimal' ? 'transparent' : template === 'bold' ? designSettings.secondary_color + '10' : '#f8fafc',
              borderColor: template === 'minimal' ? designSettings.primary_color : undefined,
            }}
          >
            <div className="flex items-center gap-1 mb-1">
              {Array.from({ length: 5 }).map((_, i) => (
                <span key={i} className={clsx(i < review.rating ? 'text-yellow-400' : 'text-slate-300', template === 'bold' && 'text-lg')}>★</span>
              ))}
            </div>
            <p className="text-sm opacity-80">{review.content.slice(0, 100)}...</p>
            <p className="text-xs opacity-60 mt-1">- {review.reviewer}</p>
          </div>
        ))}
      </div>
    </div>
  ) : null;

  // Calendar section (placeholder)
  const CalendarSection = () => designSettings.show_calendar ? (
    <div>
      <h2 className={styles.sectionTitle}>Availability</h2>
      <div className={clsx('p-4 text-center text-sm opacity-60', styles.cardStyle)} style={{ backgroundColor: '#f8fafc' }}>
        Calendar preview
      </div>
    </div>
  ) : null;

  // Social section
  const SocialSection = () => Object.values(designSettings.social_links).some(Boolean) ? (
    <div className={clsx('flex gap-4', template === 'minimal' ? 'justify-start' : 'justify-center')}>
      {designSettings.social_links.facebook && (
        <span className={clsx('w-8 h-8 flex items-center justify-center text-slate-600', styles.socialStyle, template === 'bold' ? 'rounded-lg' : 'rounded-full')}>f</span>
      )}
      {designSettings.social_links.instagram && (
        <span className={clsx('w-8 h-8 flex items-center justify-center text-slate-600', styles.socialStyle, template === 'bold' ? 'rounded-lg' : 'rounded-full')}>ig</span>
      )}
      {designSettings.social_links.tiktok && (
        <span className={clsx('w-8 h-8 flex items-center justify-center text-slate-600', styles.socialStyle, template === 'bold' ? 'rounded-lg' : 'rounded-full')}>tt</span>
      )}
      {designSettings.social_links.youtube && (
        <span className={clsx('w-8 h-8 flex items-center justify-center text-slate-600', styles.socialStyle, template === 'bold' ? 'rounded-lg' : 'rounded-full')}>yt</span>
      )}
      {designSettings.social_links.twitter && (
        <span className={clsx('w-8 h-8 flex items-center justify-center text-slate-600', styles.socialStyle, template === 'bold' ? 'rounded-lg' : 'rounded-full')}>x</span>
      )}
    </div>
  ) : null;

  // Booking button section
  const BookingSection = () => designSettings.show_booking_button ? (
    <button
      className={clsx('w-full font-semibold transition-all', styles.buttonStyle, template === 'bold' ? 'py-4 text-lg' : 'py-3')}
      style={{
        backgroundColor: template === 'minimal' ? 'transparent' : designSettings.primary_color,
        color: template === 'minimal' ? designSettings.primary_color : 'white',
        borderColor: template === 'minimal' ? designSettings.primary_color : undefined,
      }}
    >
      {template === 'bold' ? 'BOOK NOW' : 'Book Now'}
    </button>
  ) : null;

  // Map section IDs to components
  const sectionComponents: Record<MicrositeSection, React.ReactNode> = {
    hero: null, // Rendered separately
    bio: <BioSection key="bio" />,
    gallery: <GallerySection key="gallery" />,
    reviews: <ReviewsSection key="reviews" />,
    calendar: <CalendarSection key="calendar" />,
    social: <SocialSection key="social" />,
    booking: <BookingSection key="booking" />,
  };

  return (
    <div
      className="min-h-[600px]"
      style={{
        backgroundColor: designSettings.background_color,
        color: designSettings.text_color,
        fontFamily: designSettings.font_family,
      }}
    >
      {/* Render sections in order */}
      {sectionsOrder.map((sectionId) => {
        if (sectionId === 'hero') {
          return <HeroSection key="hero" />;
        }
        return (
          <div key={sectionId} className={clsx('px-6', sectionId === sectionsOrder.filter(s => s !== 'hero')[0] ? 'pt-6' : 'pt-0', 'pb-6')}>
            {sectionComponents[sectionId]}
          </div>
        );
      })}
    </div>
  );
}
