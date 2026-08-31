# Verdict Console Livewire

Livewire surfaces for [`fissible/verdict-console`](https://github.com/fissible/verdict-console):
end-user chat with inline approval cards, a reactive approval inbox, and a live decision feed, all
rendering the console's headless contracts (`ApprovalVerbs`, the approval item read-model, the
evidence query).

> **Status: scaffolded, surfaces in progress.** The package installs beside the console core
> (`composer require fissible/verdict-console-livewire:^0.1`), boots its provider, and carries the
> transport decision (polling default, broadcast opt-in via
> `verdict-console-livewire.transport`). The first surfaces are tracked in this repository's
> v0.1.0 milestone. The chat surface is available as
> `<livewire:verdict-console-livewire::chat />` and
> `<livewire:verdict-console-livewire::inbox />`, and
> `<livewire:verdict-console-livewire::decision-feed />`. Design of record: verdict-console
> [`docs/design/0001-verdict-console-design.md`](https://github.com/fissible/verdict-console/blob/main/docs/design/0001-verdict-console-design.md) §8–§9.

Depends on `fissible/verdict-console` (Packagist). No Livewire types may leak into the core package;
the dependency points one way.
