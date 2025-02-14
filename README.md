# WP-Admin-Cache
Cache for WP Admin Pages

Based on WP Admin Cache on the WP Repo

https://wordpress.org/plugins/wp-admin-cache/

Features:

# Admin-Side Caching

Caches WordPress admin pages to improve performance.

# Output Buffering & Transient Storage

Uses PHP output buffering (with a callback) to capture page output.

Saves cached pages as transients with configurable durations.

# URL Normalization & Scheme-Unification

Strips “http://” and “https://” to ensure consistent URL matching.

# Flexible Caching Modes

Supports both whitelist and blacklist modes.

Allows manual specification of URLs to cache via a “Manual Pages” tab.

Provides an exact matching option for manual URLs.

# Dynamic Cache Duration

Enables setting custom cache durations for specific pages based on URL patterns.

# Automatic & Manual Purge Options

Automatically purges caches based on various WordPress events.

Includes manual purge buttons on the settings page.

Admin Bar Integration:

Adds two admin bar menu items to clear either the current page’s cache or all admin caches.

# Prefetch Support with Security Checks

Supports prefetching of cached pages (with nonce verification and capability checks) to enhance responsiveness.

# Registry for Cached Pages

Maintains a registry of cache keys to ease the management and cleanup of cached data.
Uses transient locks to mitigate race conditions during registry updates.

# Debug Logging

Provides debug logs (with basic log rotation when exceeding 5MB) for troubleshooting cache-related events.

# Performance Optimizations

Avoids large SQL LIKE queries by using a refined query and cleanup mechanism for expired transients.

# Early Plugin Initialization

Optionally moves itself to the top of the active plugins list for optimal operation.
