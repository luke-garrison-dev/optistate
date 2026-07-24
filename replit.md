# Optimal State – WordPress Plugin

## Project Overview

**Optimal State** (v1.4.3) is a WordPress plugin that serves as an all-in-one optimization, backup, caching, and security suite. It is written in PHP and designed to replace multiple popular WordPress plugins (WP Rocket, UpdraftPlus, WP-Optimize, Loginizer, Better Search Replace, Perfmatters, etc.).

### Key Features
- Database cleanup (20+ types of bloat removal)
- Backup & restore with chunked processing and zero-downtime swaps
- Server-side page caching with GDPR cookie support
- Two-Factor Authentication (TOTP-based)
- Login brute-force protection & IP blocking
- Search & replace with serialization-safe handling
- Performance tweaks (heartbeat control, emoji removal, lazy load, etc.)
- Health score dashboard (0–100), PageSpeed Insights integration
- Settings presets, import/export, activity logging

### Stack
- Pure PHP (requires PHP 7.4+, WordPress 5.5+)
- MySQL/MariaDB (via WordPress `$wpdb`)
- JavaScript (admin.js) + CSS (admin.css) for the admin UI

### File Structure
- `optistate.php` — main plugin entry point, autoloader
- `class-optistate-*.php` — individual feature classes (all at root level, loaded from `includes/` via autoloader — note: the class files are actually in the root)
- `admin.js` / `admin.css` — admin interface assets
- `readme.txt` — WordPress.org plugin readme

### Running / Testing
This plugin must be installed inside a WordPress site (`/wp-content/plugins/optistate/`). There is no standalone runner.

To develop/test:
1. Install WordPress locally or use a host
2. Copy this folder into `/wp-content/plugins/optistate/`
3. Activate from the WordPress admin panel

## User Preferences
- Goal: Auditing and improving the plugin code
