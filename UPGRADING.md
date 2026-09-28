# Upgrading NVL Tasks

## 3.0.0

Upgrade `nvl/activity` to 2.3 or later in the 2.x series before installing Tasks 3.0. Install the new Activity and Tasks releases together, then run the Tasks migrations and restart workers. Configure a queue worker and the scheduler for durable Activity delivery.

Run the new task management migrations before deploying code that reads classifications or child records. The migrations add type, category, importance, target time, and estimate columns, plus checklist, tag, time-entry, hierarchy, and dependency tables. Preserve the package's migration ownership choice, bind `TaskAuthorization`, and run `nvl:tasks:doctor --strict`.

Content and Metafields are no longer Tasks dependencies or model integrations. Existing records in those packages are not deleted by Tasks migrations. If an application stored task details there, move them into task descriptions, metadata, checklists, tags, or application-owned storage before removing access to the old integrations.

The management API is disabled by default. If enabled, secure its middleware and bind `TaskPrincipalResolver` for assignee lookup. Existing applications may keep HTTP routes in the host and call Tasks actions directly.

For optional tenant adoption, classify existing task roots with reviewed ownership mappings. Adopt Media and Activity first; then backfill Tasks and its inherited assignments, checklists, tags, time entries, hierarchy links, and blocker links through Tenancy's prepare, backfill, verify, and activate phases. Restart workers after a completed adoption. Never copy client-supplied tenant IDs into task records.
