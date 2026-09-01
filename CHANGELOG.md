# Changelog

All notable changes to Verdict Console Livewire will be documented in this file.

## [Unreleased]

## [0.4.0] - 2026-09-01

- **Chained evidence sink rendered honestly (console ^0.8).** The decision feed now says a chained
  sink holds the decisions — naming the chain when the boundary names it — instead of falling
  through to "No decisions have been recorded."; the `^0.8` console pin rides this change.

## [0.3.0] - 2026-08-31

- **Require verdict-console `^0.7`.** The 0.7.0 boundary adds `EvidenceQuery::searchPage()`; the
  decision feed keeps reading the complete projection, and only its test double grows the method.

## [0.2.0] - 2026-08-31

- **Require verdict-console `^0.6`.** The bound moves to the current minor per the standing
  prefer-lowest reasoning; 0.6.0's only change is the Verdict `^0.14` floor, which reaches this
  package solely through the console.

## [0.1.0] - 2026-08-31

- **Live decision feed (VC-26, #4).** `<livewire:verdict-console-livewire::decision-feed />` —
  the reactive, newest-first slice of the core's evidence read boundary. Dispositions recorded by
  other processes stream in on the poll tick with no reload; the core audit page's honest states
  outrank the rows verbatim (recording off is blank by config even when the table holds rows,
  recorded-elsewhere names the writer, recording-on-but-empty reads differently from off); the
  feed length is a mount parameter. Pure function of `EvidenceQuery` — no table names, no
  recorder knowledge, no config interpretation of its own.

- **Reactive inbox (VC-25, #3).** `<livewire:verdict-console-livewire::inbox />` — the live
  upgrade of the core's approval inbox widget over the same scoped pending-approval query. A new
  pause appears on the next poll tick with no reload; every core lifecycle state renders with the
  core's markup contract (pending, lapsed, decided, unavailable, not-console-actionable), newest
  first; approve/reject/close are live controls driving the core resolution service — close is
  real here, this being the inbox where close lives — with the action's outcome surfaced in the
  core controller's status vocabulary (approved/rejected/not_actionable and the close outcomes).
  Verbs stay pinned to `ApprovalSurfaceContract`; guests, denied Gates, unknown rows, and
  host-scoped-out rows refuse exactly as the core endpoints do.

- **Chat with inline approval cards (VC-24, #2).** `<livewire:verdict-console-livewire::chat />` —
  the reactive upgrade of the core's reload-driven Blade chat. A turn sends without a reload; a
  paused run renders its approval card mid-thread from the core's approval item read-model; the
  card resolves in-flow through the core resolution service (host Gate governing, cards bound to
  their mounted conversation — a foreign thread's row answers not-found) and the resumed reply
  lands in the same cycle. The reply streams into a `wire:stream` seam (token streaming from the
  model is core #97); the thread polls at the configured interval on the polling transport and
  not at all when broadcasting. Every guard stays in core: ownership, entry key, verb policy
  (pinned against `ApprovalSurfaceContract`), and decision authority.

- **Package scaffold + transport (VC-23, #1).** Composer package depending on the console core
  (`fissible/verdict-console ^0.5`) and `livewire/livewire ^4.2` (the oldest Livewire 4 minor
  admitting Laravel 13, so the prefer-lowest matrix cell resolves on both supported majors);
  Testbench wiring booting Livewire + core + this adapter together; the 24-cell CI matrix; and the
  transport decision as code -- `Transport::fromConfig()` with polling default, broadcast opt-in,
  and loud refusal of unknown values.

[Unreleased]: https://github.com/fissible/verdict-console-livewire/compare/v0.4.0...HEAD
[0.4.0]: https://github.com/fissible/verdict-console-livewire/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/fissible/verdict-console-livewire/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/fissible/verdict-console-livewire/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fissible/verdict-console-livewire/releases/tag/v0.1.0
