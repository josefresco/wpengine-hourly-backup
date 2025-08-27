# WP Engine Backup Scheduler v1.2.6 🔧

**Professional automated backup solution for WP Engine hosted WordPress sites using the official WP Engine API.**

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![WP Engine API](https://img.shields.io/badge/WP%20Engine-API%20v1-orange.svg)](https://wpengineapi.com/)

*Enterprise-grade backup automation with intelligent scheduling, comprehensive logging, and professional-grade debugging tools.*

> **DISCLAIMER**: This plugin is an independent third-party tool and is not affiliated with, endorsed by, or sponsored by WP Engine, Inc. WP Engine and all related trademarks, service marks, and trade names are trademarks or registered trademarks of WP Engine, Inc. and are used here solely for identification purposes.

## 🚀 Key Features & Architecture

### 🚀 **Easy Setup & Management**
- **Step-by-Step Onboarding**: Guided 4-step setup process for effortless configuration
- **Visual Progress Indicators**: Clear progress tracking with status badges and completion checkmarks
- **Always Editable**: Modify API credentials, install settings, and schedules anytime after saving
- **Auto-Detection**: Automatically detects current WP Engine install and configuration
- **Direct API Access Link**: One-click access to WP Engine API settings portal

### 🔄 **Backup Operations** 
- **Automated Scheduling**: Schedule backups to run automatically every 1-24 hours
- **Manual Backups**: Create on-demand backups with custom descriptions
- **WP Engine Integration**: Uses official WP Engine API for reliable backup creation
- **Activity Logging**: Comprehensive backup activity tracking and status monitoring
- **Email Notifications**: Required email notifications for backup completion (WP Engine API requirement)

### 🛠️ **Enterprise Features**
- **Professional Admin Interface**: Comprehensive dashboard with live activity monitoring
- **WP-CLI Integration**: Full command-line interface for automation and DevOps workflows
- **REST API Endpoints**: RESTful API for external integrations and monitoring systems
- **Multi-Environment Support**: Production, staging, and development environment detection
- **Responsive Design**: Mobile-optimized interface for remote management
- **Database Logging**: Comprehensive backup activity logging with searchable history
- **Debug Tools Suite**: Advanced diagnostics including cron analysis and API testing

### 🏗️ **Technical Architecture**
- **Plugin Version**: v1.2.6 with semantic versioning
- **WordPress Cron Integration**: Custom schedules from 1-23 hours with conflict resolution
- **WP Engine API Integration**: Direct API v1 integration with timeout protection
- **Database Schema**: Custom logging table with full activity tracking
- **Security**: Nonce verification, capability checks, input sanitization
- **Performance**: Timeout protection (50s limit) with graceful degradation

## ⚙️ System Requirements

| Requirement | Minimum | Recommended |
|-------------|---------|-------------|
| **WordPress** | 5.0+ | 6.0+ |
| **PHP** | 7.4+ | 8.1+ |
| **WP Engine Hosting** | API Access Enabled | Alternate Cron Enabled |
| **Database** | MySQL 5.6+ | MySQL 8.0+ |
| **API Credentials** | Valid WP Engine API username/password | - |

### 🏗️ **WP Engine Environment Detection**
The plugin automatically detects:
- **Install Name & ID**: Auto-extracts from server environment
- **Environment Type**: Production, staging, or development
- **WP Engine Constants**: Validates WPE_APIKEY and other indicators
- **Path Analysis**: Parses `/nas/content/live/installname/` structure

## Installation

### Option 1: Manual Installation

1. Download the plugin files
2. Upload the `wpengine-backup-scheduler` folder to your `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Navigate to **Tools > WP Engine Backups** to configure the plugin

### Option 2: Upload via WordPress Admin

1. Go to **Plugins > Add New** in your WordPress admin
2. Click **Upload Plugin**
3. Choose the plugin zip file and click **Install Now**
4. Activate the plugin and navigate to **Tools > WP Engine Backups**

## Quick Setup

### ⚠️ **IMPORTANT: For Reliable Hourly Backups on WP Engine**

**WP Engine Hosting requires additional setup for reliable hourly scheduling:**

1. **Add to your wp-config.php file:**
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```

2. **Enable WP Engine Alternate Cron:**
   - Log into your WP Engine User Portal
   - Select your site environment
   - Navigate to **"Utilities"**
   - Toggle the **"Alternate Cron"** switch to ON

3. **Why This Is Required:**
   - Standard WordPress cron only runs on page visits
   - WP Engine's Alternate Cron runs every minute as a true server-side cron
   - This ensures your hourly backups execute reliably regardless of site traffic

### **Plugin Configuration** 🎯

The plugin features a **guided 4-step onboarding process** that makes setup effortless:

**Step 1: API Credentials**
- Go to **Tools > WP Engine Backups** in WordPress admin
- Click the **"Open WP Engine API Settings →"** button (direct link to your portal)
- Generate API credentials in your WP Engine User Portal
- Enter your API username and password
- Click **"Save & Test Credentials"**

**Step 2: Install Configuration**  
- Click **"Auto-Detect & Configure Current Install"** (recommended)
- Or manually select your install from the list
- Confirm your WP Engine install details

**Step 3: Email & Schedule**
- Add your notification email address (required by WP Engine API)
- Choose your backup frequency (1-24 hours)
- Save your configuration

**Step 4: Complete & Enable**
- Review your configuration summary
- Click **"🚀 Enable Automatic Backups"** to activate
- Optionally test with a manual backup first

**✨ Key Benefits:**
- Visual progress indicators show your setup status
- All forms remain editable after saving (modify settings anytime)
- Success checkmarks confirm each completed step
- Direct links to WP Engine portal for easy access
- Mobile-responsive design works on all devices

## Configuration

### API Settings

| Setting | Description |
|---------|-------------|
| **API Username** | Your WP Engine API username |
| **API Password** | Your WP Engine API password |
| **Install Detection** | Auto-detect current install or manually select |

### Backup Schedule

| Setting | Description | Default |
|---------|-------------|---------|
| **Enable Automatic Backups** | Toggle scheduled backups on/off | Disabled |
| **Backup Frequency** | How often to create backups (1-24 hours) | 24 hours |
| **Email Notifications** | Required email for backup notifications | None |

### Email Notifications

Email notifications are **required** by the WP Engine API. You must provide a valid email address to receive backup completion notifications.

## Usage

### Manual Backups

Create an on-demand backup:

1. Go to **Tools > WP Engine Backups**
2. Navigate to the **Manual Backup** section
3. Enter an optional description
4. Click **Create Backup Now**

### Scheduled Backups

Set up automatic backups:

1. Configure your API settings and email notifications
2. In the **Backup Schedule** section:
   - Check **Enable scheduled backups**
   - Select your desired frequency (1-24 hours)
   - Click **Save Schedule Settings**

### Viewing Backup Activity

Monitor backup status:

- **Recent Activity**: View recent backup attempts in the admin page sidebar
- **Dashboard Widget**: Quick status overview on the main dashboard
- **Backup Logs**: Detailed activity logs stored in the database

## 💻 WP-CLI Integration (Professional DevOps)

The plugin includes comprehensive WP-CLI commands for automation and DevOps workflows:

### 🔧 **Backup Management**
```bash
# Create manual backup with description
wp wpengine-backup create --description="Pre-deployment backup"

# Check current backup status and next scheduled backup
wp wpengine-backup status

# List recent backup activity with detailed logs
wp wpengine-backup logs --limit=20
```

### ⚙️ **Configuration Management**
```bash
# Auto-detect and configure current WP Engine install
wp wpengine-backup detect

# Enable automatic backups with custom frequency
wp wpengine-backup toggle enable --frequency=12

# Disable automatic backups (manual backups still available)
wp wpengine-backup toggle disable
```

### 🔍 **Advanced Diagnostics (v1.2.6+)**
```bash
# Run comprehensive system diagnostics
wp wpengine-backup debug

# Verbose debug output with detailed API analysis
wp wpengine-backup debug --verbose

# Test backup functionality without creating actual backup
wp wpengine-backup test --description="Connectivity test"

# Manually trigger scheduled backup function for testing
wp wpengine-backup trigger-cron

# Analyze WordPress cron system and detect issues
wp wpengine-backup cron-check
```

### 🚀 **DevOps Integration Examples**
```bash
# Pre-deployment backup script  
wp wpengine-backup create --description="Deploy v2.1.0 - $(date)"

# Complete system health check in CI/CD pipeline
wp wpengine-backup debug --verbose

# Automated backup verification with detailed status
wp wpengine-backup status

# Emergency manual cron trigger for troubleshooting
wp wpengine-backup trigger-cron

# Quick test without creating actual backup
wp wpengine-backup test --description="CI/CD health check"
```

### 📋 **Complete WP-CLI Command Reference**
```bash
# Core backup management
wp wpengine-backup create [--description="Custom description"]
wp wpengine-backup status
wp wpengine-backup toggle <enable|disable> [--frequency=<hours>]

# Environment and configuration  
wp wpengine-backup detect
wp wpengine-backup debug [--verbose]

# Testing and troubleshooting
wp wpengine-backup test [--description="Test description"]
wp wpengine-backup trigger-cron
```

## 🔌 REST API Integration

The plugin provides professional REST API endpoints for external integrations and monitoring systems:

### 📊 **Get Backup Status**
```http
GET /wp-json/wpengine-backup/v1/status
```

**Response Schema:**
```json
{
  "success": true,
  "data": {
    "enabled": true,
    "frequency_hours": 12,
    "install_id": "123456",
    "install_name": "mysite-prod",
    "environment": "production",
    "next_backup": "2025-01-15T14:00:00+00:00",
    "next_backup_human": "in 6 hours",
    "last_backup": {
      "date": "2025-01-15T08:00:00+00:00",
      "type": "scheduled",
      "status": "success",
      "message": "Backup created successfully",
      "backup_id": "bk_abc123def456"
    },
    "api_status": "connected",
    "wpengine_detected": true,
    "wp_cron_disabled": true,
    "total_backups_today": 2
  }
}
```

### 🚀 **Create Manual Backup**
```http
POST /wp-json/wpengine-backup/v1/create
Content-Type: application/json
Authorization: Basic <wordpress-auth> OR Cookie <wp-auth-cookie>

{
  "description": "Pre-deployment backup v2.1.0"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "message": "Backup created successfully",
    "backup_id": "bk_xyz789abc123",
    "created_at": "2025-01-15T10:30:00+00:00",
    "type": "manual",
    "description": "Pre-deployment backup v2.1.0"
  }
}
```

### 🔍 **Health Check Endpoint (Monitoring)**
```http
GET /wp-json/wpengine-backup/v1/health
```

**Response:**
```json
{
  "success": true,
  "data": {
    "plugin_version": "1.2.6",
    "wp_engine_detected": true,
    "api_connected": true,
    "cron_enabled": true,
    "last_successful_backup": "2025-01-15T08:00:00+00:00",
    "issues": [],
    "status": "healthy"
  }
}
```

## Troubleshooting

### WP Engine Cron Issues (Most Important)

**Hourly Backups Not Running**
1. **Use the new debug tools (RECOMMENDED):**
   - **Admin Panel**: Go to **Tools > WP Engine Backups** → **Debug & Testing** section
   - **WP-CLI**: Run `wp wpengine-backup debug` for comprehensive diagnostics
   
2. **Check if WP Engine Alternate Cron is enabled:**
   - WP Engine User Portal → Utilities → Alternate Cron (toggle ON)
   
3. **Verify wp-config.php has:**
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
   
4. **Test manually:**
   - Admin Panel: Click **"Test Backup Now"** or **"Trigger Cron Function"**  
   - WP-CLI: `wp wpengine-backup test` or `wp wpengine-backup trigger-cron`
   
5. **Monitor access logs for wp-cron.php requests:**
   - Look for regular requests every minute to wp-cron.php
   - Check for 200 (success) vs 502 (timeout) response codes

**Cron Timeouts (502 Errors)**
- WP Engine Alternate Cron has a 60-second timeout limit
- The plugin includes timeout protection (50-second limit)
- Check error logs for timeout messages

**Missed Backup Events**
- Standard wp-cron only runs on page visits (unreliable)
- WP Engine Alternate Cron runs every minute (reliable)
- Enable Alternate Cron to fix this issue

### Common Issues

**API Connection Failed**
- Verify your API credentials are correct
- Ensure API access is enabled in your WP Engine User Portal
- Check that your install ID is correct

**Backup Creation Failed**
- Ensure email notifications are configured
- Verify you have permission to create backups for the install
- Check for rate limiting (too many backup requests)

**Install Auto-Detection Failed**
- Ensure you're running on a WP Engine server
- Verify the plugin can detect WP Engine environment variables
- Try manual install selection instead

### Debug Information

The plugin includes comprehensive debugging tools:

#### Cron Diagnostics
- **Environment Detection**: Verifies WP Engine hosting and WordPress cron status
- **Schedule Verification**: Shows all registered custom intervals (every_1_hours through every_23_hours)
- **Active Cron Jobs**: Displays all WordPress cron jobs and WP Engine specific jobs
- **Next Backup Time**: Shows exact timestamp and countdown for next scheduled backup

#### Manual Testing Tools
- **Test Schedule Creation**: Manually test cron event scheduling with detailed results
- **Run Cron Diagnostics**: Comprehensive system analysis and troubleshooting
- **Test Backup Now**: Execute backup function manually for testing
- **Trigger Cron Function**: Manually run the scheduled backup function

#### API Debugging  
- **Connection Testing**: Verify API credentials and connectivity
- **Install Detection**: Test auto-detection functionality
- **Backup Listing**: Fetch and display recent backups from WP Engine

### Error Codes

| HTTP Code | Meaning | Solution |
|-----------|---------|----------|
| 400 | Bad Request | Check API request format |
| 401 | Unauthorized | Verify API credentials |
| 403 | Forbidden | Check backup permissions |
| 404 | Not Found | Verify install ID |
| 429 | Too Many Requests | Wait before creating another backup |
| 500 | Server Error | Contact WP Engine support |

## 🏗️ Development & Technical Documentation

### 📊 **Database Schema**

The plugin creates a comprehensive logging table:

```sql
CREATE TABLE wp_wpengine_backup_logs (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    backup_type varchar(20) NOT NULL,         -- 'manual', 'scheduled'
    status varchar(20) NOT NULL,              -- 'pending', 'success', 'failed', 'timeout'
    message text,                             -- Detailed status message
    backup_id varchar(100),                   -- WP Engine backup ID
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    completed_at datetime,                    -- Completion timestamp
    PRIMARY KEY (id),
    KEY backup_type (backup_type),
    KEY status (status),
    KEY created_at (created_at)
);
```

### 🔌 **WordPress Hooks & Integration**

**Core Actions:**
- `wpengine_backup_cron_hook` - Main scheduled backup execution
- `wpengine_backup_created` - Successful backup completion (passes backup_id)
- `wpengine_backup_failed` - Backup failure (passes error details)
- `wpengine_backup_started` - Backup initiation (passes backup_type)

**Configuration Filters:**
- `wpengine_backup_api_timeout` - API timeout in seconds (default: 30)
- `wpengine_backup_log_retention` - Log retention count (default: 100)
- `wpengine_backup_cron_timeout` - Cron execution timeout (default: 50)
- `wpengine_backup_email_required` - Force email requirement (default: true)

### 🔄 **Custom WordPress Cron Schedules**

The plugin dynamically creates 23 custom cron intervals:
```php
// Automatically generated: every_1_hours through every_23_hours
'every_12_hours' => array(
    'interval' => 43200,  // 12 * HOUR_IN_SECONDS
    'display'  => 'Every 12 Hours'
)
```

### 🛡️ **Security Implementation**
- **Nonce Verification**: All AJAX requests include `wp_create_nonce('wpengine_backup_nonce')`
- **Capability Checks**: `current_user_can('manage_options')` required for all functions
- **Input Sanitization**: `sanitize_text_field()`, `sanitize_email()` on all inputs
- **SQL Injection Protection**: WordPress `$wpdb->prepare()` for all queries
- **XSS Prevention**: `esc_html()`, `esc_attr()` on all output

### Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## Security

- API credentials are stored securely in WordPress options
- All AJAX requests include nonce verification
- User capability checks ensure only admins can manage backups
- Input sanitization prevents XSS and SQL injection

## Support

### Documentation Links
- [WP Engine API Documentation](https://wpengineapi.com/reference)
- [Enable WP Engine API Access](https://wpengine.com/support/enabling-wp-engine-api/)
- [WP Engine Backup & Restore Guide](https://wpengine.com/support/restore/)

### Getting Help
- Check the troubleshooting section above
- Review WP Engine API documentation
- Contact WP Engine support for API-related issues

## License

This plugin is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

**MIT License Summary:**
- ✅ Commercial use allowed
- ✅ Modification allowed  
- ✅ Distribution allowed
- ✅ Private use allowed
- ❌ No warranty provided
- ❌ No liability accepted

Copyright (c) 2025 josefresco

## Changelog

For detailed release notes and the complete changelog, see:
**[📋 GitHub Releases](https://github.com/josefresco/wpengine-hourly-backup/releases)**

### 🚀 **Version History & Evolution**

#### **v1.2.6 - Production Grade (Current)**
- **🏗️ Complete Technical Architecture**: 2,000+ lines of enterprise-grade PHP code
- **🔧 Advanced AJAX System**: 12 dedicated AJAX endpoints with comprehensive error handling
- **🖥️ Professional Admin Interface**: Step-by-step onboarding with visual progress indicators
- **⚡ Performance Optimization**: Timeout protection (50s limit) and graceful degradation
- **🔍 Debugging Suite**: Comprehensive WP-CLI and admin panel diagnostic tools

#### **v1.2.0-1.2.4 - Stability & Compliance**
- **v1.2.4**: Updated copyright and legal compliance documentation
- **v1.2.3**: Documentation enhancements and trademark compliance
- **v1.2.2**: **Stable Release** - Fixed onboarding flow and cron scheduling reliability  
- **v1.2.1**: Fixed UI state management after auto-detection with proper email validation
- **v1.2.0**: Major onboarding improvements (superseded by v1.2.1)

#### **v1.1.0-1.1.9 - Major UI Overhaul**
- **v1.1.9**: Reverted to stable v1.1.5 codebase after onboarding issues
- **v1.1.5**: **Critical Fix** - Restored broken cron scheduling from v1.1.3 changes
- **v1.1.3**: Enhanced user experience - forms remain editable after saving
- **v1.1.2**: Added version display in admin interface header
- **v1.1.1**: Integrated GitHub CLI for streamlined development workflow
- **v1.1.0**: **Major Release** - Complete UI overhaul with step-by-step onboarding

### 📊 **Plugin Statistics** 
- **Total Code Lines**: 3,350+ lines of production PHP
- **AJAX Endpoints**: 12 comprehensive handlers
- **WP-CLI Commands**: 6 professional commands with verbose debugging
- **REST API Routes**: 3 endpoints for external integration
- **Database Tables**: 1 logging table with indexed performance
- **WordPress Hooks**: 15+ actions and filters for extensibility

---

## Legal & Disclaimer

### Trademark Notice
WP Engine® is a registered trademark of WP Engine, Inc. This plugin is an independent third-party tool and is not affiliated with, endorsed by, sponsored by, or otherwise associated with WP Engine, Inc.

### Copyright Notice  
- This plugin software is released under the MIT License
- WP Engine and all related trademarks, service marks, logos, and trade names are the property of WP Engine, Inc.
- WordPress is a trademark of the WordPress Foundation
- All other trademarks, service marks, and trade names referenced herein are the property of their respective owners

### Disclaimer of Affiliation
The author and contributors of this plugin:
- Are not employees, agents, or representatives of WP Engine, Inc.
- Do not claim any official relationship with WP Engine, Inc.
- Provide this plugin "as-is" without warranty or official support from WP Engine
- Use WP Engine's name and API solely for the purpose of providing integration functionality

### License & Warranty
THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. USE AT YOUR OWN RISK.

---

**Made for WP Engine** - This plugin is specifically designed for WP Engine hosted WordPress sites and requires valid WP Engine API credentials to function.
