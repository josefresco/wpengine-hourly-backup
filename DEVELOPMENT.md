# WP Engine Backup Scheduler - Development Guide

This document provides information for developers working on the WP Engine Backup Scheduler plugin.

## 🚀 Quick Start

### GitHub CLI Setup for Releases

The project includes GitHub CLI (gh) for easy release management:

1. **Setup GitHub CLI authentication:**
   ```bash
   ./setup-github-cli.sh
   ```

2. **Authenticate with GitHub:**
   ```bash
   ./gh_2.76.2_linux_amd64/bin/gh auth login
   ```

3. **Create releases easily:**
   ```bash
   ./release-helper.sh 1.2.0 "New Features" "Added backup scheduling improvements"
   ```

### Development Workflow

1. **Make your changes to the plugin**
2. **Update version numbers:**
   - Plugin header in `wpengine-backup-scheduler.php`
   - Version constant in the same file
3. **Commit your changes:**
   ```bash
   git add .
   git commit -m "Your commit message"
   git push origin main
   ```
4. **Create a release:**
   ```bash
   ./release-helper.sh <version> "<title>" "<description>"
   ```

## 📁 Project Structure

```
wpengine-backup-scheduler/
├── wpengine-backup-scheduler.php    # Main plugin file
├── README.md                        # User documentation
├── LICENSE                          # MIT license
├── DEVELOPMENT.md                   # This file
├── .gitignore                      # Git ignore rules
├── gh_2.76.2_linux_amd64/         # GitHub CLI (ignored in git)
├── release-helper.sh               # Release automation script (ignored)
└── setup-github-cli.sh            # GitHub CLI setup script (ignored)
```

## 🛠️ GitHub CLI Commands

### Authentication
```bash
./gh_2.76.2_linux_amd64/bin/gh auth login     # Login to GitHub
./gh_2.76.2_linux_amd64/bin/gh auth status    # Check auth status
```

### Release Management
```bash
# Create release with helper script
./release-helper.sh 1.2.0 "Bug Fixes" "Fixed critical authentication issues"

# Manual release creation
./gh_2.76.2_linux_amd64/bin/gh release create v1.2.0 \
  --title "v1.2.0 - Bug Fixes" \
  --notes "Fixed critical authentication issues and improved error handling"
```

### Repository Management
```bash
./gh_2.76.2_linux_amd64/bin/gh repo view                    # View repo info
./gh_2.76.2_linux_amd64/bin/gh release list                 # List all releases
./gh_2.76.2_linux_amd64/bin/gh pr list                      # List pull requests
./gh_2.76.2_linux_amd64/bin/gh issue list                   # List issues
```

## 🔧 Development Tools

### Release Helper Script
The `release-helper.sh` script automates the release process:
- Creates git tags with proper formatting
- Pushes tags to GitHub
- Creates GitHub releases with standardized notes
- Includes Claude Code attribution

### Setup Script
The `setup-github-cli.sh` script provides setup instructions and verification steps.

## 📋 Release Checklist

1. ✅ Update plugin version in header
2. ✅ Update WPENGINE_BACKUP_VERSION constant  
3. ✅ Test plugin functionality
4. ✅ Commit and push changes
5. ✅ Run `./release-helper.sh <version> "<title>" "<notes>"`
6. ✅ Verify release on GitHub
7. ✅ Test plugin installation from new release

## 🔐 Security Notes

- GitHub CLI binary and helper scripts are excluded from releases via `.gitignore`
- Authentication tokens are handled securely by GitHub CLI
- Never commit authentication credentials to the repository

## 🤖 Automation

The project uses Claude Code for development assistance and includes proper attribution in all commits and releases.

### Commit Message Format
```
Brief description of changes

- Detailed change 1
- Detailed change 2
- Detailed change 3

🤖 Generated with [Claude Code](https://claude.ai/code)

Co-Authored-By: Claude <noreply@anthropic.com>
```

## 📚 Additional Resources

- [GitHub CLI Documentation](https://cli.github.com/manual/)
- [WP Engine API Reference](https://wpengineapi.com/reference)
- [WordPress Plugin Development](https://developer.wordpress.org/plugins/)