# Changelog

All notable changes to Verdict Console Livewire will be documented in this file.

## [Unreleased]

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
