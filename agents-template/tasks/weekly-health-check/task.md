---
kind: task
id: weekly-health-check
name: Weekly Site Health Check
intervalMinutes: 10080
enabled: false
runOnStartup: false
---

Every Monday, perform a comprehensive site health check:

1. Run `wp core version` — check if WordPress is up to date.
2. Run `wp plugin list --status=updates` — list plugins needing updates.
3. Run `wp theme list --status=updates` — list themes needing updates.
4. Run `wp db check` — verify database integrity.
5. Check site availability — request homepage and admin URL.
6. Review error logs for the past week.
7. Summarize findings and recommend actions.
