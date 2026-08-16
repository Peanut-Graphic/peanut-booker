# Changelog

All notable changes to Peanut Booker will be documented in this file.

## [1.7.2] - 2026-01-01

### Fixed
- Tooltips now render through a React portal to `document.body` with fixed
  positioning, so they appear above surrounding content regardless of a parent's
  `overflow`.

### Note on this entry
- Reconstructed after the fact from git history, and the history between 1.5.4
  and 1.7.2 is thin: two commits, one of them the version bump. There is no
  record of what distinguished 1.6.x, and no 1.6.x tag exists. Rather than
  invent entries to fill the gap, this says what can actually be evidenced.

## [1.5.4] - 2025-12-27

### Note on this entry
- Recorded as a release for completeness. Its single commit is an
  import-shaped "Peanut Booker v1.5.4 - Complete source", so the individual
  changes it carried are not recoverable from this repository's history.

## [1.5.0] - 2024-12-19

### Added
- **Peanut License Server Integration** - Full licensing and auto-update support
  - License Client SDK integrated for activation/deactivation
  - Automatic plugin updates when license is active
  - New "License" tab in Settings for license management
  - License status display with tier, expiration, and site count
  - Configurable license server URL for self-hosted installations
- **Helper Functions** for license checks
  - `peanut_booker_license()` - Get license client instance
  - `peanut_booker_is_licensed()` - Check if plugin has active license

### Changed
- Moved to commercial plugin model with license validation
- Plugin updates now served through Peanut License Server

### Integration
- Full compatibility with Peanut Suite analytics (existing)
- Auto-updates served from Peanut License Server
- License activation reports to central license server

## [1.4.2] - 2024-12-19

### Added
- **Clean Performer Editor** - User-friendly admin interface for editing performers
  - Tab-based layout: Basic Info, Photos & Videos, Pricing, Location, Categories
  - Card-based sections with clean visual design
  - Profile photo upload with media library integration
  - Gallery photo management (drag-to-add, click-to-remove)
  - Video link management for Pro tier
  - Toggle switches for sale pricing and travel options
  - Visual checkbox cards for categories and service areas
  - AJAX save with sticky save bar
  - "View Profile" button to preview live page
  - "Advanced Edit" link for WordPress admin when needed
- Edit links from performers list now use the clean editor

### Changed
- Performer edit experience is now much simpler for non-technical users
- Standard WordPress post editor preserved as "Advanced Edit" option

## [1.4.1] - 2024-12-19

### Added
- **Performer Meta Boxes** - Edit performer details directly in WordPress admin
  - Performer Details: User link, stage name, tagline, experience, website, phone, email
  - Pricing & Booking: Hourly rate, minimum hours, deposit %, sale pricing
  - Location & Travel: City, state, travel willing, travel radius
- **Additional Plugin Pages** in settings
  - Login / Sign Up page (`[pb_login]`)
  - Performer Sign Up page (`[pb_performer_signup]`)
  - Customer Sign Up page (`[pb_customer_signup]`)
  - "Create All Pages" button now creates all 6 pages with correct slugs

### Fixed
- Page slugs now properly set when using "Create All Pages" button

## [1.4.0] - 2024-12-19

### Added
- **Custom Login Page** - New `[pb_login]` shortcode for branded login experience
  - Tab-based interface switching between Login and Sign Up
  - Google OAuth integration (when enabled)
  - "Remember me" and "Forgot password" options
  - Redirect back to original page after login
  - "Continue as Guest" option for browsing
- **Auth Widget** - New `[pb_auth]` shortcode for header/navigation
  - Shows Login/Sign Up buttons for guests
  - Shows avatar, name, Dashboard link, and Log Out for logged-in users
  - Compact styling for navigation bars
- **Role Selection Flow** - Guided signup experience
  - Visual role cards: "I'm a Performer" vs "I'm Booking Entertainment"
  - Benefits list for each role type
  - Directs to appropriate signup form
- **Guest Access Restrictions** - Performer profiles require signup for full access
  - Guests see: Name, photo, category, rating, stats, pricing, general location
  - Hidden for guests: Full bio, gallery (blurred), videos, reviews, availability, booking
  - Prominent signup prompt with account benefits
- **Signup Form Handlers** - Backend processing for performer and customer registration
  - Input validation and error handling
  - Automatic role assignment
  - Auto-login after registration
  - Redirect to dashboard

### Changed
- Single performer template now checks login status for content visibility
- Archive templates updated for consistency with post type queries
- Login redirects now use custom `/login/` page instead of `wp-login.php`

### Fixed
- Archive performer template: Fixed `is_active` to use `status` column properly
- Archive market template: Rewrote to use WordPress post queries for consistency
- Signup templates now correctly reference `display_name` field

## [1.3.0] - 2024-12-19

### Added
- **Profile Wizard** - Tab-based profile editor in performer dashboard
  - 6-tab wizard: Basic Info, Photos, Videos, Pricing, Location, Categories
  - WordPress Media Library integration for photo uploads
  - Progress bar showing profile completeness
  - Previous/Next navigation with Save on final tab
- **Google OAuth Login** - Sign up and log in with Google
  - New Google Login settings tab in admin
  - Google buttons on performer and customer signup pages
  - Automatic account linking for existing email addresses
  - OAuth callback handling with proper security
- **External Gigs Tracking** - Mark dates unavailable for outside bookings
  - "Quick Block" button for simple date blocking
  - "Add External Gig" modal with event details (name, venue, type, location)
  - Calendar shows external gigs in purple color
  - Shift+click to select date ranges
  - Updated calendar legend with all status types
- **Signup Templates** - Dedicated signup pages for performers and customers
  - `[pb_performer_signup]` and `[pb_customer_signup]` shortcodes
  - Google signup integration when enabled
  - Benefits list and role switching links

### Changed
- Photo limits updated: Free tier gets 1 photo, Pro gets 5 photos
- Video limits updated: Free tier gets 0 videos, Pro gets 3 videos
- Availability calendar now supports multi-select mode in dashboard

### Database
- Added columns to availability table: `block_type`, `event_name`, `venue_name`, `event_type`, `event_location`
- Database migration runs automatically on upgrade

## [1.2.5] - 2024-12-19

### Fixed
- **Admin Performers Page Critical Error** - Fixed undefined method and column references
  - Changed `calculate_achievement_level()` call to use stored `achievement_level` column directly
  - Fixed `is_active` column reference to use `status` column properly
- **Admin Bookings Page Blank Columns** - Fixed performer/customer lookup
  - Performer lookup now correctly queries `pb_performers` table by `performer_id` to get `user_id`
  - Customer lookup uses `customer_id` directly as user ID
- **Admin Market Events Page Blank Columns** - Fixed column name mismatches
  - Fixed `customer_user_id` → `customer_id`
  - Fixed `event_name` → `title`
  - Fixed `performer_category` → `category_id` with proper term lookup

## [1.2.4] - 2024-12-19

### Fixed
- **Demo Bookings Not Creating** - Fixed database column mismatches in demo data generation
  - Removed non-existent columns from bookings insert (performer_user_id, customer_user_id, duration_hours, deposit_percentage, confirmed_at)
  - Fixed transactions insert to use correct columns (payer_id, payee_id instead of performer_id, customer_id)
  - Demo bookings and reviews now generate properly

## [1.2.3] - 2024-12-19

### Added
- **Market Page Performer Tiles** - Market page now displays performer tiles in a grid layout
  - Shows performer name, category, tagline, rating, achievement badge, and pricing
  - Tiles link directly to performer profile pages
  - Category and location filters included
  - Responsive grid layout

### Fixed
- **Demo Data Market Events** - Fixed market events not appearing in frontend
  - Demo data now properly creates `pb_market_event` post types (not just table records)
  - Market events now display correctly with bids, deadlines, and statuses
- **Dashboard Shortcode** - Fixed dashboard showing "Dashboard section not found"
  - Dashboard now correctly routes to performer or customer templates based on user role
  - Added role selection prompt for users who haven't chosen a role yet
  - Added login prompt with link for non-logged-in users

## [1.2.2] - 2024-12-19

### Added
- **Plugin Pages Settings** - Easy page configuration in Settings > General
  - Dropdown selectors to assign pages for Performer Directory, Market, and Dashboard
  - "Create All Pages" button to automatically generate pages with shortcodes
  - View and Edit links for quick access to assigned pages
  - Shortcode hints displayed for each page type

### Enhanced
- **Comprehensive Demo Data for All Admin Tabs** - Restructured demo data generation to ensure every admin page tab has test data
  - **Bookings**: Explicit bookings for each tab - Pending (8), Confirmed (10), In Progress (3), Completed (28), Cancelled (6), Disputed (3)
  - **Payouts**: 8 completed bookings with pending payouts (escrow = full_held) for Performer Payouts page testing
  - **Reviews**: Added flagged reviews for Arbitration tab with realistic dispute scenarios
    - 5 detailed flagged review templates with dispute reasons
    - Various dispute types: accuracy disputes, professionalism claims, response to negative reviews
  - **Market Events**: All statuses represented (open, closed, filled)
- Improved booking data realism with proper date ranges and transaction records

### Fixed
- Demo data now properly populates all status-based tabs in admin interface

## [1.2.1] - 2024-12-19

### Fixed
- Added custom template loader for performer profile pages (single-performer.php)
- Added custom template loader for archive pages (performers, market)
- Fixed rewrite rules not being flushed properly on activation
- Added null check for $post in template filter to prevent errors
- Templates now load correctly from plugin directory

## [1.2.0] - 2024-12-19

### Added
- **Demo Mode Banner** - Prominent "DEMO MODE" banner displayed across all frontend and admin pages when demo mode is active
  - Animated pulsing badge for visibility
  - Gradient purple styling that stands out
  - Links to manage demo mode in admin banner
  - Fallback JavaScript injection for themes without `wp_body_open` support
- **Comprehensive Demo Data** - Complete test data for end-to-end platform showcase
  - 12 demo performers across all categories with varied achievement levels (Platinum, Gold, Silver, Bronze)
  - 10 demo customers including individual bookers and corporate accounts
  - 50-70 demo bookings with all statuses (pending, confirmed, in_progress, completed, cancelled, disputed)
  - Full transaction history including deposits, balance payments, payouts, and refunds
  - Reviews with responses and varied ratings (3-5 stars with realistic distribution)
  - 10 market events with various statuses (open, closed, filled)
  - Multiple bids per market event with different bid statuses
  - 120 days of availability data per performer (past 30 days + next 90 days)
- **Enhanced Performer Profiles**
  - Featured performers flag for homepage display
  - Pre-set achievement scores based on tier
  - Detailed bios with realistic content
  - Location and service area assignments

### Changed
- Demo mode now generates significantly more realistic and comprehensive test data
- Improved cleanup when disabling demo mode - properly removes all related data

## [1.1.1] - 2024-12-19

### Fixed
- Added missing `get_customer_bookings()` method to booking class
- Added missing `get_performer_bookings()` method to booking class
- Added missing `customer_has_reviewed()` method to reviews class
- Added missing `get_customer_reviews()` method to reviews class
- Added missing `get_total_earnings()` method to performer class
- Fixed critical error on plugin activation caused by undefined methods

## [1.1.0] - 2024-12-19

### Added
- **Demo/Test Mode** - Admin can enable demo mode to populate the site with realistic test data
  - 10 demo performers across various categories (Musicians, DJs, Magicians, Comedians, Speakers, Dancers, Variety Acts)
  - 6 demo customer accounts
  - 20-30 sample bookings with various statuses
  - Reviews with realistic content and performer responses
  - 6 market events with multiple bids
  - Availability calendars pre-populated for all performers
- Demo mode indicator in admin menu when active
- Demo mode notice on dashboard when enabled
- One-click disable to remove all demo data

### Changed
- Updated admin menu to show demo mode status badge

## [1.0.0] - 2024-12-19

### Added
- Initial release
- **Performer Profiles** - Custom post type with full profile pages
  - Free tier: 1 photo, 1 video, basic features
  - Pro tier: Unlimited media, market access, lower commission
- **User Roles** - pb_performer and pb_customer roles
- **Booking Engine** - Full booking system with WooCommerce integration
  - Escrow payment handling
  - Configurable deposit percentages
  - Auto-release after event completion
- **Market System** - Event posting and bidding
  - Customers post events seeking performers
  - Pro performers can bid on opportunities
  - Automatic and manual bid deadlines
- **Reviews & Ratings** - Dual rating system
  - Customers rate performers
  - Performers rate customers
  - Response capability
  - Admin arbitration for disputes
- **Achievement System** - Bronze/Silver/Gold/Platinum levels
  - Based on bookings, ratings, and profile completeness
- **Availability Calendar** - Visual calendar management
  - Green/red availability indicators
  - Full-day and half-day slots
- **Subscriptions** - Pro membership via WooCommerce Subscriptions
  - Monthly and annual pricing options
- **Email Notifications** - Comprehensive notification system
- **Admin Dashboard** - Full management interface
  - Stats overview
  - Performer management
  - Booking management
  - Payout processing
  - Review arbitration
  - Configurable settings
- **REST API** - Endpoints for all major features
- **Peanut Suite Integration** - Hooks for analytics platform
- **Shortcodes** - Frontend display shortcodes
  - `[pb_performer_directory]`
  - `[pb_market]`
  - `[pb_my_dashboard]`
  - `[pb_booking_form]`
  - `[pb_featured_performers]`
