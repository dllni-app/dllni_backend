<?php

declare(strict_types=1);

return [
    'nav_group' => 'Cleaning Section',
    'nav_groups' => [
        'operations' => 'Cleaning Operations',
        'settings' => 'Cleaning Settings',
    ],

    'overview' => [
        'title' => 'Live Command Center',
        'tooltip' => 'Live overview: KPIs, today\'s bookings, disputes, SOS and system alerts with quick actions.',
        'subheading' => 'Live overview of KPIs, today\'s bookings, open disputes, SOS and system alerts with quick actions for contact or resolution.',
        'kpis' => [
            'cleaning_bookings' => 'Cleaning bookings',
            'event_bookings' => 'Event bookings',
            'open_disputes' => 'Open disputes',
            'open_sos' => 'SOS alerts',
            'new_system_alerts' => 'New system alerts',
        ],
        'alerts' => [
            'sos_heading' => 'Critical alerts (SOS)',
            'system_heading' => 'System alerts',
            'none' => 'No current alerts.',
            'call_customer' => 'Call customer',
            'call_worker' => 'Call worker',
            'resolve' => 'Resolve / close',
            'booking_ref' => 'Booking #:number',
        ],
    ],

    'workers' => [
        'nav_label' => 'Workers',
        'tooltip' => 'List of service providers: name, photo, trust score, completed tasks, average rating, status; view profile and suspend account.',
        'model' => 'Worker',
        'plural' => 'Workers',
        'add' => 'Add worker',
        'view' => 'View',
        'edit' => 'Edit',
        'sections' => [
            'profile' => 'Profile',
            'trust_card' => 'Trust Score Card',
            'performance' => 'Performance Stats',
            'preferred_zones' => 'Preferred Work Zones',
            'availability' => 'Availability Schedule',
        ],
        'fields' => [
            'name' => 'Name',
            'phone' => 'Phone',
            'average_rating' => 'Average Rating',
            'total_completed_jobs' => 'Completed Tasks',
            'is_verified' => 'Verified',
            'is_featured' => 'Featured',
            'trust_score' => 'Trust Score',
            'trust_log' => 'Trust Log',
            'reason' => 'Reason',
            'score_delta' => 'Change',
            'date' => 'Date',
            'acceptance_rate' => 'Acceptance Rate',
            'cancellation_rate' => 'Cancellation Rate',
            'open_disputes_count' => 'Open Disputes',
            'zone' => 'Zone',
            'is_active' => 'Active',
            'suspended' => 'Suspended',
            'id' => 'ID',
        ],
        'reviews' => 'Customer Reviews',
        'customer_ratings' => 'Worker Ratings of Customers',
        'reviews_fields' => [
            'booking_number' => 'Booking #',
            'rating' => 'Rating',
            'comment' => 'Comment',
            'rating_type' => 'Type',
        ],
        'availability_fields' => [
            'date' => 'Date',
            'type' => 'Availability Type',
            'start' => 'From',
            'end' => 'To',
        ],
    ],

    'disputes' => [
        'nav_label' => 'Disputes & Complaints',
        'tooltip' => 'Resolve disputes and complaints: ticket #, booking #, customer, worker, reason, status; reply, partial refund, deduct from worker, close.',
        'sections' => [
            'ticket' => 'Ticket',
            'booking' => 'Booking',
            'messages' => 'Message Thread',
        ],
        'fields' => [
            'ticket_number' => 'Ticket Number',
            'description' => 'Problem Details',
            'category' => 'Category',
            'status' => 'Status',
            'resolution' => 'Resolution',
            'worker_earnings_frozen' => 'Worker Earnings Frozen',
            'booking_number' => 'Booking #',
            'customer' => 'Customer',
            'worker' => 'Worker',
            'sender' => 'Sender',
            'body' => 'Message',
            'created_at' => 'Opened at',
        ],
        'actions' => [
            'reply' => 'Reply',
            'refund_partial' => 'Partial Refund',
            'deduct_worker' => 'Deduct from Worker',
            'close' => 'Close Dispute',
        ],
        'modals' => [
            'refund_heading' => 'Partial Refund to Customer',
            'deduct_heading' => 'Deduct from Worker Balance',
            'close_heading' => 'Close Dispute',
        ],
        'notifications' => [
            'reply_sent' => 'Reply sent',
            'resolution_saved' => 'Resolution recorded',
            'dispute_closed' => 'Dispute closed',
        ],
    ],

    'system_alerts' => [
        'nav_label' => 'System Alerts',
        'tooltip' => 'Delayed mutual rating, frozen location, SOS, time exceeded without end; contact or resolve actions.',
    ],

    'time_warnings' => [
        'nav_label' => 'Time-End Warnings',
        'tooltip' => 'Log of time-end warnings: booking #, type (cleaning/event), sent at, customer response (extend/commit/finish early), worker response.',
    ],

    'automation' => [
        'nav_label' => 'Automation Rules',
        'tooltip' => 'Service automation rules: auto suspend by trust score, featured badge or commission reduction for top performers, conditions and actions.',
    ],

    'cleaning_bookings' => [
        'nav_label' => 'Cleaning Bookings',
        'tooltip' => 'View and manage all cleaning bookings: booking #, customer, worker, date and time, status, total price, assign worker or cancel.',
    ],

    'event_bookings' => [
        'nav_label' => 'Event Bookings',
        'tooltip' => 'View and manage event bookings: booking #, customer, event type, date and time, status, team size, total price.',
        'tooltip_full' => 'View and manage event bookings: family dinner, birthday, large gathering, funeral; guest range, team size, status and price.',
    ],

    'billing_policies' => [
        'nav_label' => 'Billing Policies',
        'tooltip' => 'Manage cleaning billing policies: billing mode, default, active.',
        'tooltip_full' => 'Manage billing policies: name, description, billing mode (full booked time / actual work time), minimum minutes, default.',
    ],

    'cleaning_services' => [
        'nav_label' => 'Cleaning Services',
        'tooltip' => 'Manage cleaning services and base pricing.',
        'tooltip_full' => 'Define cleaning service types: name, description, link to base pricing and available add-ons.',
    ],

    'service_addons' => [
        'nav_label' => 'Service Add-ons',
        'tooltip' => 'Manage optional add-ons: name, description, pricing type (fixed or percentage of service cost).',
    ],

    'travel_cost_configs' => [
        'nav_label' => 'Travel Cost Rules',
        'tooltip' => 'Travel cost calculation rules: per km rate, minimum travel fee, distance start point (worker location / home address / system auto).',
    ],

    'financial' => [
        'nav_label' => 'Financial Settings',
        'title' => 'Financial Settings',
        'tooltip' => 'Base pricing, add-ons, commission, travel costs, distance start point, time billing policy and minimum billable minutes.',
        'subheading' => 'Manage base pricing, add-ons, commission, travel costs, distance calculation start point, time billing policy and minimum billable minutes.',
        'saved' => 'Financial settings saved',
    ],

    'overview_alerts' => [
        'resolved' => 'Alert resolved',
    ],

    'alert_types' => [
        'delayed_rating' => 'Delayed mutual rating',
        'frozen_gps' => 'Frozen location',
        'sos' => 'SOS',
        'time_expired' => 'Time exceeded',
        'overdue_completion' => 'Time exceeded without action',
        'anomaly' => 'Anomaly',
    ],

    'booking' => [
        'sections' => [
            'main' => 'Booking',
            'pricing' => 'Pricing',
            'execution_times' => 'Execution Times',
            'parties' => 'Parties',
            'disputes' => 'Disputes',
        ],
        'fields' => [
            'booking_number' => 'Booking #',
            'status' => 'Status',
            'terms_accepted' => 'Terms Accepted',
            'cancelled_at' => 'Cancelled At',
            'cancellation_policy' => 'Cancellation Policy',
            'property_type' => 'Property Type',
            'estimated_sqm' => 'Estimated SQM',
            'estimated_hours' => 'Estimated Hours',
            'scheduled_date' => 'Date',
            'scheduled_time' => 'Time',
            'base_price' => 'Base Price',
            'addons_total' => 'Add-ons',
            'travel_fee' => 'Travel Fee',
            'total_price' => 'Total',
            'work_started_at' => 'Work Started',
            'work_finished_at' => 'Work Finished',
            'customer_confirmed_at' => 'Customer Confirmed',
            'customer' => 'Customer',
            'worker' => 'Worker',
            'disputes_count' => 'Disputes Count',
        ],
    ],

    'boolean' => [
        'yes' => 'Yes',
        'no' => 'No',
    ],

    'pages' => [
        'overview' => [
            'description' => 'Live overview of KPIs, today\'s bookings, open disputes, SOS and system alerts with quick actions for contact or resolution.',
        ],
        'financial' => [
            'description' => 'Manage base pricing, add-ons, commission, travel costs, distance start point, time billing policy and minimum billable minutes.',
        ],
        'geographic_coverage' => [
            'description' => 'View demand vs worker coverage by geographic area to identify service gaps (low/good/high) and worker count per zone.',
        ],
        'cleaning_bookings' => [
            'list' => 'View and manage all cleaning bookings: booking #, customer, worker, date and time, status, total price, assign worker or cancel.',
            'view' => 'Full booking details: parties, pricing, execution times and related disputes.',
            'create' => 'Create a new cleaning booking and set customer, service, date and time.',
            'edit' => 'Edit booking data, status or assigned worker.',
        ],
        'event_bookings' => [
            'list' => 'View and manage event bookings: family dinner, birthday, large gathering, funeral; guest range, team size, status and price.',
            'view' => 'Event booking details: event type, guests, team and pricing.',
            'create' => 'Create a new event booking and set type, date and team size.',
            'edit' => 'Edit event booking data or status.',
        ],
        'workers' => [
            'list' => 'List of service providers: name, photo, trust score, completed tasks, average rating, status; view profile and suspend account.',
            'view' => 'Full worker profile: performance, preferred zones, availability and reviews.',
            'create' => 'Add a new worker and link to a user account.',
            'edit' => 'Edit worker data, trust score or status.',
        ],
        'disputes' => [
            'list' => 'View disputes and complaints: ticket #, booking #, customer, worker, reason, status, opened at; reply, partial refund, deduct from worker, close.',
            'view' => 'Dispute details, message thread and option to reply or resolve.',
            'edit' => 'Record resolution: partial refund, deduct from worker or close dispute.',
        ],
        'system_alerts' => [
            'list' => 'Delayed mutual rating, frozen location, SOS, time exceeded without end; contact or resolve actions.',
        ],
        'time_warnings' => [
            'list' => 'Log of time-end warnings: booking #, type (cleaning/event), sent at, customer response (extend/commit/finish early), worker response.',
            'view' => 'Time-end warning details and customer/worker responses.',
        ],
        'cleaning_services' => [
            'list' => 'Manage cleaning services: name, description, pricing (base rate, minimum hours), and link to add-ons.',
            'view' => 'Service details, pricing and linked add-ons.',
            'create' => 'Define a new cleaning service and base pricing.',
            'edit' => 'Edit service, pricing or add-ons.',
        ],
        'service_addons' => [
            'list' => 'Manage optional add-ons: name, type (fixed or percentage), price, and link to cleaning services.',
            'view' => 'Add-on details, pricing type and linked services.',
            'create' => 'Add a new optional add-on (fixed or percentage of service cost).',
            'edit' => 'Edit add-on, price or links.',
        ],
        'billing_policies' => [
            'list' => 'Manage billing policies: name, description, billing mode (full booked time / actual work time), minimum minutes, default.',
            'view' => 'Billing policy details and accounting mode.',
            'create' => 'Create a new billing policy.',
            'edit' => 'Edit billing policy or set as default.',
        ],
        'travel_cost_configs' => [
            'list' => 'Travel cost rules: per km rate, minimum travel fee, distance start point (worker location / home address / system auto).',
            'view' => 'Travel cost rule details and distance start point.',
            'edit' => 'Edit per km rate, minimum or start point.',
        ],
        'automation_rules' => [
            'list' => 'Automation rules: e.g. suspend worker when trust drops, or grant featured badge when rating exceeds threshold.',
            'view' => 'Rule details: conditions and actions (suspend, reward, commission reduction).',
            'create' => 'Create a new automation rule (auto suspend, featured badge, commission reduction).',
            'edit' => 'Edit rule conditions or actions.',
        ],
        'users' => [
            'list' => 'Manage admin users who can access the cleaning dashboard and their assigned roles.',
            'view' => 'User details, roles and permissions.',
            'create' => 'Add a new admin user and assign roles.',
            'edit' => 'Edit user data or roles.',
        ],
        'roles' => [
            'list' => 'Manage roles and permissions: define who can do what in the dashboard (admin, support, accountant, etc.).',
            'view' => 'Role details and associated permissions.',
            'create' => 'Create a new role and assign permissions.',
            'edit' => 'Edit role permissions.',
        ],
    ],

    'column_descriptions' => [
        'booking_number' => 'Unique identifier for the cleaning or event booking.',
        'status' => 'Current status of the booking, dispute or alert.',
        'customer' => 'Customer who requested the service.',
        'worker' => 'Worker assigned to perform the service (if any).',
        'scheduled_date' => 'Scheduled date for the service.',
        'scheduled_time' => 'Scheduled start time for the service.',
        'total_price' => 'Total amount (base + add-ons + travel) in SAR.',
        'disputes_count' => 'Number of open or closed disputes linked to this booking.',
        'ticket_number' => 'Dispute or complaint ticket number.',
        'category' => 'Dispute category (quality, damage, conduct, billing, other).',
        'resolution' => 'Resolution decision (partial refund, deduct from worker, close).',
        'created_at' => 'Record creation date and time.',
        'first_name' => 'Worker first name.',
        'trust_score' => 'Worker current trust score.',
        'average_rating' => 'Average customer rating for the worker.',
        'total_completed_jobs' => 'Total completed tasks successfully.',
        'is_active' => 'Whether the account is active and can receive requests.',
        'is_suspended' => 'Whether the account is temporarily suspended.',
        'id' => 'Record identifier.',
        'phone' => 'Phone number for contact.',
        'billing_mode' => 'Mode used for billing (full booked vs actual time).',
        'availability_type' => 'Worker availability status (available, blocked, vacation).',
        'event_type' => 'Type of event (family dinner, birthday, etc.).',
        'alert_severity' => 'Alert severity level (low, medium, high, critical).',
        'system_alert_status' => 'Status of the system alert (new, acknowledged, resolved).',
        'time_warning_response' => 'Response to a time warning (extend, commit, finish early).',
    ],

    'enums' => [
        'cleaning_booking_status' => [
            'pending' => 'Pending',
            'worker_assigned' => 'Worker assigned',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ],
        'dispute_status' => [
            'open' => 'Open',
            'under_review' => 'Under review',
            'resolved' => 'Resolved',
            'closed' => 'Closed',
        ],
        'dispute_category' => [
            'poor_quality' => 'Service quality',
            'property_damage' => 'Property damage',
            'unprofessional' => 'Unprofessional conduct',
            'billing_issue' => 'Billing issue',
            'other' => 'Other',
        ],
        'dispute_resolution' => [
            'full_refund' => 'Full refund',
            'partial_refund' => 'Partial refund',
            'worker_penalty' => 'Deduct from worker',
            'dismissed' => 'Dismissed',
        ],
        'alert_type' => [
            'delayed_rating' => 'Delayed mutual rating',
            'frozen_gps' => 'Frozen location',
            'sos_triggered' => 'SOS',
            'time_expired' => 'Time exceeded',
            'overdue_completion' => 'Time exceeded without end',
            'anomaly_detected' => 'Anomaly',
        ],
        'event_booking_status' => [
            'pending' => 'Pending',
            'worker_assigned' => 'Worker assigned',
            'in_progress' => 'In progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ],
        'availability_type' => [
            'available' => 'Available',
            'blocked' => 'Blocked',
            'vacation' => 'Vacation',
        ],
        'alert_severity' => [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
            'critical' => 'Critical',
        ],
        'system_alert_status' => [
            'new' => 'New',
            'acknowledged' => 'Acknowledged',
            'resolved' => 'Resolved',
        ],
        'cleaning_billing_mode' => [
            'full_booked_time' => 'Full booked time',
            'actual_working_time' => 'Actual working time',
        ],
        'addon_pricing_type' => [
            'fixed' => 'Fixed',
            'percentage' => 'Percentage',
        ],
        'time_warning_response' => [
            'extend_time' => 'Extend time',
            'commit_current_time' => 'Commit current time',
            'finish_early' => 'Finish early',
        ],
        'event_type' => [
            'family_dinner' => 'Family dinner',
            'birthday' => 'Birthday',
            'large_gathering' => 'Large gathering',
            'funeral' => 'Funeral',
            'other' => 'Other',
        ],
    ],
];
