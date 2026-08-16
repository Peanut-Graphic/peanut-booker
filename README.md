# Peanut Booker

A membership and booking platform for WordPress connecting performers with event organizers. Features performer profiles, booking engine, bidding market, reviews, and escrow payments via WooCommerce.

## Features

### Performer Management
- **Performer Profiles** - Detailed profiles with bio, media gallery, and performance types
- **Tier System** - Bronze, Silver, Gold, Platinum tiers with different features
- **Availability Calendar** - Performers set their available dates and blackout periods
- **Portfolio** - Photo/video galleries, audio samples, and press kit downloads
- **Microsites** - Custom landing pages for each performer

### Booking System
- **Direct Bookings** - Event organizers book performers directly
- **Booking Requests** - Request/confirm workflow with messaging
- **Calendar Integration** - Sync with Google Calendar
- **Contracts** - Digital contract generation and e-signatures
- **Deposits & Payments** - Escrow payments via WooCommerce

### Bidding Market
- **Open Opportunities** - Event organizers post gigs for bidding
- **Performer Bids** - Performers submit proposals with pricing
- **Bid Management** - Accept, reject, or counter-offer bids
- **Automatic Matching** - Match performers to opportunities based on criteria

### Reviews & Ratings
- **Two-Way Reviews** - Both parties can review after events
- **Verification** - Only verified bookings can leave reviews
- **Rating Categories** - Professionalism, performance quality, communication
- **Review Moderation** - Admin approval workflow

### Messaging & Conversations
- **Direct Messages** - Secure messaging between users
- **Booking Threads** - Conversation history tied to bookings
- **Notifications** - Email and in-app notifications
- **File Sharing** - Share documents within conversations

### Payments & Payouts
- **WooCommerce Integration** - Leverages WC payment gateways
- **Escrow Payments** - Funds held until event completion
- **Platform Fees** - Configurable commission rates
- **Payout Management** - Manual or automatic performer payouts
- **Tax Documentation** - 1099 support for US performers

## Requirements

- WordPress 6.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.3+
- WooCommerce 8.0+

The installed plugin supports PHP 8.0 and later. Local dependency resolution
and the PHP test tooling require PHP 8.1 or later because the locked PHPUnit
graph includes `doctrine/instantiator` 2.x. Required CI separately proves the
complete tracked PHP tree on exact PHP 8.0, the property suite on exact PHP
8.1, the current PHP 8.3 lane, and the real WordPress/MySQL contract on PHP 8.3.

## Installation

1. Ensure WooCommerce is installed and activated
2. Upload the `peanut-booker` folder to `/wp-content/plugins/`
3. Activate the plugin through the WordPress admin
4. Navigate to **Peanut Booker** in the admin menu
5. Complete the setup wizard

## Quick Start

1. Go to **Peanut Booker > Settings** and configure platform options
2. Set up performer tiers under **Settings > Tiers**
3. Configure payment settings (fees, escrow, payouts)
4. Create performer and customer registration pages
5. Add the booking shortcodes to your pages

## Shortcodes

| Shortcode | Description |
|-----------|-------------|
| `[pb_performer_directory]` | Display searchable performer directory |
| `[pb_performer_profile]` | Show single performer profile |
| `[pb_booking_form]` | Booking request form |
| `[pb_market]` | Bidding marketplace for open opportunities |
| `[pb_dashboard]` | User dashboard (performer or customer) |
| `[pb_calendar]` | Availability calendar widget |
| `[pb_register_performer]` | Performer registration form |
| `[pb_register_customer]` | Customer registration form |

## REST API

The plugin provides a REST API at `/wp-json/peanut-booker/v1/`:

### Public Endpoints
```
GET  /performers              # List performers (filtered)
GET  /performers/{id}         # Single performer profile
GET  /performers/{id}/calendar # Performer availability
GET  /market                  # Open opportunities
POST /bookings/request        # Submit booking request
```

### Authenticated Endpoints
```
GET  /admin/dashboard         # Dashboard statistics
GET  /admin/performers        # Manage performers
GET  /admin/bookings          # Manage bookings
GET  /admin/market            # Manage market listings
GET  /admin/reviews           # Manage reviews
POST /admin/payouts           # Process payouts
GET  /admin/conversations     # View messages
```

## Database Tables

The plugin creates tables with the `pb_` prefix:
- `pb_performers` - Performer profiles and settings
- `pb_bookings` - Booking records and status
- `pb_market_listings` - Open opportunity listings
- `pb_bids` - Performer bids on listings
- `pb_reviews` - Reviews and ratings
- `pb_conversations` - Message threads
- `pb_messages` - Individual messages
- `pb_payouts` - Payout records
- `pb_calendar_blocks` - Availability blocks

## User Roles

| Role | Capabilities |
|------|-------------|
| **Performer** | Manage own profile, respond to bookings, bid on market |
| **Customer** | Book performers, post to market, leave reviews |
| **Booker Admin** | Full platform management |

## Hooks & Filters

### Actions
```php
do_action('pb_booking_created', $booking_id, $performer_id, $customer_id);
do_action('pb_booking_confirmed', $booking_id);
do_action('pb_booking_completed', $booking_id);
do_action('pb_payout_processed', $payout_id, $performer_id, $amount);
do_action('pb_review_submitted', $review_id, $booking_id);
do_action('pb_bid_submitted', $bid_id, $listing_id, $performer_id);
```

### Filters
```php
apply_filters('pb_performer_tiers', $tiers);
apply_filters('pb_platform_fee', $fee, $booking_id);
apply_filters('pb_booking_statuses', $statuses);
apply_filters('pb_performer_search_args', $args);
apply_filters('pb_payout_minimum', $minimum, $performer_id);
```

## WooCommerce Integration

Peanut Booker uses WooCommerce for:
- **Payment Processing** - All configured WC gateways
- **Product Creation** - Auto-creates booking products
- **Order Management** - Bookings linked to WC orders
- **Refunds** - Process refunds through WC
- **Subscriptions** - Optional performer subscription tiers (with WC Subscriptions)

## Google Calendar Sync

Performers can sync their availability with Google Calendar:
1. Connect Google account in performer dashboard
2. Select calendars to sync
3. Bookings automatically create calendar events
4. External events block availability

## Security

- All database queries use prepared statements
- Input sanitization on all endpoints
- Output escaping throughout templates
- Nonce verification on all forms
- Role-based capability checks
- Rate limiting on public endpoints
- Encrypted storage for sensitive data

## Development

### Prerequisites
- PHP 8.1+ for Composer dependencies and test tooling
- Composer
- Node.js 18+
- WooCommerce (for full functionality)

### Setup
```bash
# Clone the repository
git clone https://github.com/peanutgraphic/peanut-booker.git
cd peanut-booker

# Install dependencies (if applicable)
composer install

# Build assets (if applicable)
cd frontend && npm install && npm run build
```

### File Structure
```
peanut-booker/
├── includes/           # Core PHP classes
│   ├── class-booking.php
│   ├── class-performer.php
│   ├── class-market.php
│   ├── class-reviews.php
│   ├── class-rest-api.php
│   ├── class-rest-api-admin.php
│   └── rest-api-admin/  # REST API traits
├── admin/              # Admin functionality
├── public/             # Public-facing code
│   └── partials/       # Template parts
├── templates/          # Template files
├── assets/             # CSS, JS, images
└── languages/          # Translation files
```

## Changelog

See plugin header and GitHub releases for version history.

## License

GPL-2.0+ - See LICENSE file for details.

## Support

For issues and feature requests, please [open an issue](https://github.com/peanutgraphic/peanut-booker/issues).
