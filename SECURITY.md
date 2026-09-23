# Security Policy

Report vulnerabilities privately to the package maintainers; do not disclose an unresolved issue publicly. Include the affected release, configuration, reproduction, and impact without real private data.

Task mutations fail closed without a host `TaskAuthorization` binding. The opt-in HTTP assignee endpoints additionally require a host `TaskPrincipalResolver`; applications must secure enabled routes and enforce access to assignment targets. System actors are only for trusted application code. Never trust tenant IDs, actor IDs, or owner types from request bodies. Media, Content, and Metafields retain their own authorization boundaries. Test tenant adoption and database isolation before enabling tenant traffic.
