# Changelog

All notable changes to `nvl/tasks` are documented here.

## [Unreleased]

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
