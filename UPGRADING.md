# Upgrading NVL Tasks

This is a new package and has no previous schema to migrate. Before enabling Tasks in an existing application, choose one migration owner, bind `TaskAuthorization`, and run `nvl:tasks:doctor --strict`.

The management API is disabled by default. If enabled, secure its middleware and bind `TaskPrincipalResolver` for assignee lookup. Existing applications may keep HTTP routes in the host and call Tasks actions directly.

For optional tenant adoption, classify existing task roots with reviewed ownership mappings. Adopt Media, Content, and Metafields first; then backfill Tasks and its inherited assignments through Tenancy's prepare, backfill, verify, and activate phases. Restart workers after a completed adoption. Never copy client-supplied tenant IDs into task records.
