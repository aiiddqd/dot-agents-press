---
kind: task
id: daily-backup-check
name: Daily Backup Verification
intervalMinutes: 1440
enabled: false
runOnStartup: false
---

Every day, verify that backups are running:

1. Check if a database backup was created in the last 24 hours.
2. Verify the backup file is non-empty and has a reasonable size.
3. Check if file backups (if configured) are up to date.
4. Report status: OK, missing backup, or stale backup.
