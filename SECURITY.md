# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/tasks/security/advisories/new).

Report vulnerabilities privately to the package maintainers; do not disclose an unresolved issue publicly. Include the affected release, configuration, reproduction, and impact without real private data.

Task services fail closed without an explicit host `TaskAuthorization` binding for both persisted users and system actors. Implement SQL visibility in `scopeTasks` before exposing pages or aggregates. Applications own HTTP parsing and routes. Never trust owner, actor, primary-key or revision fields in payloads. Native Ownership admissions and canonical principal reads remain fresh. Activity delivery consumes immutable facts already authorized at the source writer; historical actor identifiers convey no new authority. Native Media integration remains staged and explicit enable fails closed until its reviewed adapter exists. Test adoption, database isolation, graph races and queue rollback/replay before production cutover.
