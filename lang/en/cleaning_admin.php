<?php

declare(strict_types=1);

return [
    'nav_group' => 'Cleaning Section',

    'overview' => [
        'title' => 'Live Command Center',
        'tooltip' => 'Live overview: KPIs, today\'s bookings, disputes, SOS and system alerts with quick actions.',
        'subheading' => 'Live overview of KPIs, today\'s bookings, open disputes, SOS and system alerts with quick actions for contact or resolution.',
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
];
