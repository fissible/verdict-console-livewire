# Changelog

All notable changes to Verdict Console Livewire will be documented in this file.

## [Unreleased]

- **Package scaffold + transport (VC-23, #1).** Composer package depending on the console core
  (`fissible/verdict-console ^0.5`) and `livewire/livewire ^4.2` (the oldest Livewire 4 minor
  admitting Laravel 13, so the prefer-lowest matrix cell resolves on both supported majors);
  Testbench wiring booting Livewire + core + this adapter together; the 24-cell CI matrix; and the
  transport decision as code -- `Transport::fromConfig()` with polling default, broadcast opt-in,
  and loud refusal of unknown values.
