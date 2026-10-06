# Changelog

## Unreleased — consumer runtime integration

- Added focused consumer contract/testing guidance and shipped-factory usage limits.
- Versioned committed event payloads and documented canonical aliases, source connections, failure metadata and optional safe rendering.
- Added explicit first-use/installer and deployment guidance; new acceptance checks remain pending.


All notable changes to `nvl/tasks` are documented here.

## [Unreleased]

### Added

- Added focused injectable contracts for all 28 selected public workflows, with native signatures and conditional defaults preserving host bindings.


### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Prepare lockstep major 5 with required and development NVL peer floors of `^5.0`. This candidate has not been tagged or published.
- Retain the correctness-critical outbox schedule and diagnose scheduler heartbeat and distributed-lock readiness.
- Inherit queue destinations and use canonical names for package-owned global registrations.
- Review [UPGRADING.md](UPGRADING.md) before adopting the new names and infrastructure boundaries.

## [3.0.0] - 2026-09-28

### Added

- Add configurable model-casted status, priority, type, category, and importance enums, planning targets, firm due dates, and estimates.
- Add package-owned checklists, tags, time entries and timers, parent/subtask and blocker relationships, dashboard summaries, richer filtering, and task detail projections.
- Record task lifecycle and assignment events through Activity; expand tenant adoption and diagnostics for task-owned records.
- Stage Activity records in a durable task outbox with retryable queued and scheduled delivery.

### Changed

- Remove Content and Metafields integrations and dependencies. Keep Activity and Media as the collaboration integrations.
- Require Activity 2.3 for idempotent outbox delivery.

## [2.2.2] - 2026-09-26

### Documentation

- Clarify public support, contribution, and private security reporting paths.

## [2.2.1] - 2026-09-25

### Documentation

- Correct installation guidance for the independently published package.

## [2.2.0] - 2026-09-25

### Changed

- Prepare `nvl/tasks` for independent Composer and Git publication; require `nvl/core` for shared Support and Data services.

## [2.1.1] - 2026-09-23

### Added

- Added tenant-aware task roots, polymorphic assignees, enum-cast lifecycle fields, bounded metadata, optimistic revisions, soft deletion, and guarded restoration.
- Registered task owners with Content, Metafields, and a private Media attachments slot.
- Added fail-closed task authorization, opt-in management routes, a read-only doctor, TypeScript contracts, and standalone/tenant tests.

### Changed

- Use Data contracts for task management queries, mutations, assignment
  projections, and responses instead of Laravel HTTP Resources.
