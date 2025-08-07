# WP Engine Backup Scheduler

A WordPress plugin that provides automated backup scheduling for WP Engine hosted sites using the official WP Engine API.

![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)
![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)
![License](https://img.shields.io/badge/License-MIT-green.svg)

## Features

- **Automated Scheduling**: Schedule backups to run automatically every 1-24 hours
- **Manual Backups**: Create on-demand backups with custom descriptions
- **WP Engine Integration**: Uses official WP Engine API for reliable backup creation
- **Auto-Detection**: Automatically detects current WP Engine install and configuration
- **Activity Logging**: Comprehensive backup activity tracking and status monitoring
- **Email Notifications**: Required email notifications for backup completion (WP Engine API requirement)
- **Dashboard Widget**: Quick backup status overview in WordPress admin dashboard
- **WP-CLI Support**: Command-line interface for backup management
- **REST API**: RESTful endpoints for external integrations
- **Multi-Environment Support**: Works with production, staging, and development environments

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- WP Engine hosting account with API access enabled
- Valid WP Engine API credentials

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

### **Plugin Configuration**

1. **Enable WP Engine API Access**
   - Log into your WP Engine User Portal
   - Go to **Account Settings > API Access**
   - Generate API credentials (username and password)

2. **Configure the Plugin**
   - Go to **Tools > WP Engine Backups** in WordPress admin
   - Enter your API credentials
   - Click **Auto-Detect & Configure Current Install**
   - Add your notification email address
   - Configure your backup schedule

3. **Test the Setup**
   - Use **Test API Connection** to verify credentials
   - Create a manual backup to ensure everything works
   - Enable automatic backups if desired

The plugin will automatically detect WP Engine hosting and show setup reminders if Alternate Cron is not properly configured.

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

## WP-CLI Commands

The plugin provides comprehensive WP-CLI support:

### Create a Manual Backup
```bash
wp wpengine-backup create --description="Pre-update backup"
```

### Check Backup Status
```bash
wp wpengine-backup status
```

### Enable/Disable Automatic Backups
```bash
# Enable with 12-hour frequency
wp wpengine-backup toggle enable --frequency=12

# Disable automatic backups
wp wpengine-backup toggle disable
```

### Auto-Detect Current Install
```bash
wp wpengine-backup detect
```

## REST API Endpoints

### Get Backup Status
```http
GET /wp-json/wpengine-backup/v1/status
```

**Response:**
```json
{
  "enabled": true,
  "frequency_hours": 24,
  "install_id": "12345",
  "install_name": "mysite",
  "next_backup": "2024-01-15T10:00:00+00:00",
  "last_backup": {
    "date": "2024-01-14T10:00:00",
    "type": "scheduled",
    "status": "success",
    "message": "Backup created successfully"
  }
}
```

### Create Manual Backup
```http
POST /wp-json/wpengine-backup/v1/create
Content-Type: application/json

{
  "description": "API backup"
}
```

## Troubleshooting

### WP Engine Cron Issues (Most Important)

**Hourly Backups Not Running**
1. **Check if WP Engine Alternate Cron is enabled:**
   - WP Engine User Portal → Utilities → Alternate Cron (toggle ON)
2. **Verify wp-config.php has:**
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
3. **Use WP-CLI to check status:**
   ```bash
   wp wpengine-backup status
   ```
4. **Monitor access logs for wp-cron.php requests:**
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

Use the **Debug Settings** button in the admin interface to view current configuration and troubleshoot issues.

### Error Codes

| HTTP Code | Meaning | Solution |
|-----------|---------|----------|
| 400 | Bad Request | Check API request format |
| 401 | Unauthorized | Verify API credentials |
| 403 | Forbidden | Check backup permissions |
| 404 | Not Found | Verify install ID |
| 429 | Too Many Requests | Wait before creating another backup |
| 500 | Server Error | Contact WP Engine support |

## Development

### Database Schema

The plugin creates a `wp_wpengine_backup_logs` table:

```sql
CREATE TABLE wp_wpengine_backup_logs (
    id mediumint(9) NOT NULL AUTO_INCREMENT,
    backup_type varchar(20) NOT NULL,
    status varchar(20) NOT NULL,
    message text,
    backup_id varchar(100),
    created_at datetime DEFAULT CURRENT_TIMESTAMP,
    completed_at datetime,
    PRIMARY KEY (id)
);
```

### Hooks and Filters

**Actions:**
- `wpengine_backup_cron_hook` - Scheduled backup execution
- `wpengine_backup_created` - Fired after successful backup creation
- `wpengine_backup_failed` - Fired after backup failure

**Filters:**
- `wpengine_backup_api_timeout` - Modify API timeout (default: 30 seconds)
- `wpengine_backup_log_retention` - Change log retention count (default: 100)

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

### 1.0.0
- Initial release
- Automated backup scheduling (1-24 hour intervals)
- Manual backup creation with descriptions
- WP Engine install auto-detection
- Comprehensive activity logging
- Dashboard widget for backup status
- WP-CLI command support
- REST API endpoints
- Multi-environment support (production, staging, development)

---

**Made for WP Engine** - This plugin is specifically designed for WP Engine hosted WordPress sites and requires valid WP Engine API credentials to function.
