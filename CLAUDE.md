# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Banalytiq is a simple yet sufficient server-side analytics library for PHP that provides privacy-focused web analytics without external dependencies. It records visitor data locally using SQLite and provides a web dashboard for visualization.

## Technology Stack

- **Backend**: PHP 8.3+ with SQLite3 and sysvsem extensions
- **Frontend**: JavaScript (ES6+) with Bulma CSS framework
- **Database**: SQLite (analytics data and GeoLite2 geolocation data)
- **Dependencies**: Composer for development dependencies only

## Core Architecture

### Main Components

1. **banalytiq.php**: Core analytics library with `record_visit()` function for tracking page visits
2. **index.php**: Web dashboard that visualizes analytics data with charts, maps, and tables
3. **download.php**: CLI script for downloading analytics databases from FTP servers
4. **geo.php**: CLI script for enriching IP addresses with geolocation data
5. **defines.php**: Configuration constants for database file names

### Database Structure

- **banalytiq.db**: Main analytics database with visitor data
- **geolite2.db**: GeoLite2 database for IP geolocation (imported from MaxMind CSV files)

Analytics table schema:
```sql
CREATE TABLE analytics (
    ip TEXT NOT NULL,           -- Anonymized IP (last octet set to 0)
    dt INTEGER NOT NULL,        -- Unix timestamp
    url TEXT NOT NULL,
    referer TEXT DEFAULT "",
    ua TEXT DEFAULT "",
    status INT DEFAULT NULL,
    country TEXT DEFAULT NULL,  -- Populated by geo.php
    city TEXT DEFAULT NULL,     -- Populated by geo.php
    latitude REAL DEFAULT NULL, -- Populated by geo.php
    longitude REAL DEFAULT NULL, -- Populated by geo.php
    PRIMARY KEY(ip, dt, url)
);
```

## Development Commands

### Database Setup
```bash
# Create a new analytics database
php -r "require 'banalytiq.php'; create_db();"

# Create with custom name
php -r "require 'banalytiq.php'; create_db('custom.db');"
```

### Data Management
```bash
# Download analytics data from FTP server
php download.php

# Download without merging
php download.php --no-merge

# Merge existing timestamped databases only
php download.php --merge-only

# Add geolocation data to IP addresses
php geo.php

# Process specific database file
php geo.php custom.db
```

### Local Development
```bash
# Start PHP development server for dashboard
php -S localhost:8000

# View custom database
# Navigate to: http://localhost:8000/?db=custom-db-file.db
```

### GeoLite2 Database Setup
```bash
# Import GeoLite2 CSV data (after downloading from MaxMind)
sqlite3 geolite2.db
.mode csv
.import GeoLite2-City-Blocks-IPv4.csv blocks
.import GeoLite2-City-Locations-en.csv locations
```

## Integration

### Basic Integration
Add to your application's index.php:
```php
<?php
require_once __DIR__ . '/banalytiq/banalytiq.php';
record_visit();
?>
```

### Advanced Integration
```php
<?php
require_once __DIR__ . '/banalytiq/banalytiq.php';

// Custom database name
record_visit('custom-site.db');

// With IP filtering (feature planned)
$skip_ips = ['192.168.1.1', '10.0.0.1'];
record_visit(null, null, $skip_ips);
?>
```

## Key Features

- **Privacy-focused**: Anonymizes IP addresses by zeroing last octet
- **Bot filtering**: Automatic detection and separation of bot traffic
- **Geolocation mapping**: Visual map of visitor locations using Leaflet.js
- **Time-based filtering**: Dashboard supports 1D, 1W, 1M, 3M, 6M, and ALL time windows
- **Responsive dashboard**: Charts, tables, and statistics with dark/light theme toggle
- **Concurrent access**: Uses semaphores for thread-safe database operations

## Configuration

### Required Files
- **config.yaml**: Domain configuration
  ```yaml
  domain: example.com
  ```

- **ftp_config.yaml**: FTP server configuration for remote database downloads
  ```yaml
  host: example.com
  username: your_username
  password: your_password
  remote_path: /path/to/analytics
  port: 21  # optional
  ```

## File Structure

```
banalytiq/
├── banalytiq.php       # Core analytics library
├── index.php          # Web dashboard
├── download.php        # FTP download script
├── geo.php            # Geolocation enrichment
├── defines.php        # Configuration constants
├── config.yaml        # Domain configuration
├── ftp_config.yaml    # FTP configuration
├── banalytiq.db       # Main analytics database
├── geolite2.db        # GeoLite2 database
├── composer.json      # PHP dependencies
└── GeoLite2-City-CSV_*/ # GeoLite2 data directories
```

## Development Principles

- **Minimal changes**: Make the smallest possible diff to implement features
- **Code deletion**: Actively look for opportunities to remove unused code
- **Privacy first**: Never log or expose sensitive information
- **Local-only dashboard**: Analytics dashboard only accessible on localhost/127.0.0.1
- **CLI safety**: Download and geo scripts include HTTP access protection

## Bot Detection

The system includes comprehensive bot detection patterns for major crawlers:
- Search engines (Google, Bing, Yandex, Baidu)
- Social media crawlers (Facebook, Twitter, LinkedIn)
- SEO tools (Ahrefs, SEMrush, Majestic)
- Monitoring services (UptimeRobot, Pingdom)
- Generic patterns (curl, wget, python-requests)

## Deployment Notes

- Only deploy the main PHP files to production
- The `vendor/` directory is only needed for development
- Ensure PHP has SQLite3 and sysvsem extensions enabled
- GeoLite2 database requires periodic updates from MaxMind