---
name: wp-maintenance
description: Site health checks, updates, backups, and troubleshooting
enabled: true
---

Keep the WordPress site healthy and secure.

## Health check

```bash
# Core version
wp core version

# Plugin status
wp plugin list --status=active --format=table
wp plugin list --status=inactive --format=table
wp plugin list --status=updates --format=table

# Theme status
wp theme list --status=active

# Database status
wp db size --tables
wp db check
```

## Backup procedure

```bash
# Full database backup
wp db export /path/to/backups/site-$(date +%Y%m%d).sql

# Files backup (if needed)
tar -czf /path/to/backups/site-files-$(date +%Y%m%d).tar.gz /path/to/site/
```

## Update workflow

1. Create a backup (database + files).
2. Check for available updates:
   ```bash
   wp core check-update
   wp plugin update --all --dry-run
   wp theme update --all --dry-run
   ```
3. Apply updates:
   ```bash
   wp core update
   wp plugin update --all
   wp theme update --all
   ```
4. Flush cache:
   ```bash
   wp cache flush
   wp rewrite flush
   ```
5. Verify site is working — check homepage, admin panel, key pages.

## Troubleshooting common issues

| Symptom | Check |
|---------|-------|
| White screen | Enable WP_DEBUG, check error log |
| Slow site | Check caching, object cache, CDN, DB queries |
| 404 errors | Flush permalinks: `wp rewrite flush` |
| Login issues | Reset password via WP-CLI: `wp user update <id> --user_pass=newpass` |
| Plugin conflict | Deactivate all plugins, reactivate one by one |
