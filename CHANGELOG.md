# Changelog


All notable changes to `nvl/tasks` are documented here.

## [Unreleased]

### Changed

- Refresh the inherited Ownership deletion API reference for ordinary models and native pivots.

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Fixed

- Widen MySQL-family task target and due storage to DATETIME, preserve existing UTC instants, and reject lossy rollback. Validate calendar years against the portable 1000–9999 range before writing.

### Changed

- Add explicit disabled-by-default bounded delivered-outbox retention and an additive ready index, preserving pending and Activity evidence.
- Bound graph traversal while retaining the database mutex, current edge reads and PostgreSQL outer-snapshot conflict fencing.
- Align NVL peers and consumer contracts on the coordinated 7.x line. Review [UPGRADING.md](UPGRADING.md) for adoption and application-owned integration requirements.

## [6.0.1] - 2026-10-21

### Changed

- Share configured Task registration and native backfill while preserving the task graph, captured outbox ownership and canonical reference checks.
- Require optional Ownership >=6.0.1 when installed so new shared backfill callers cannot resolve an older runtime. Require Core >=6.0.1 for the shared model, queue or compatibility implementation.

## [6.0.0] - 2026-10-20

### Release fixes

- Include the existing native ownership default in published common configuration.

### Changed

- Integrate explicitly declared native Task roots and inherited children; fence attachments, outbox and worker work to the canonical Task owner. Preserve scheduling and authorization contracts.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.

## [5.0.1] - 2026-10-08

### Changed

- Correct published-family verification and adoption guidance for local Dagger CI; runtime contracts are unchanged.


## [5.0.0] - 2026-10-08

### Added

- Added focused injectable contracts for all 28 selected public workflows, with native signatures and conditional defaults preserving host bindings.


### Changed

- Classify the supported consumer PHP surface with explicit source annotations and restrict package model handles to declared identity and in-memory read fields; preserve existing workflow behavior and concrete signatures.
- Adopt lockstep major 5 with required and development NVL peer floors of `^5.0`.
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
