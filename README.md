# Verdict Console Livewire

Livewire surfaces for [`fissible/verdict-console`](https://github.com/fissible/verdict-console):
end-user chat with inline approval cards, a reactive approval inbox, and a live decision feed, all
rendering the console's headless contracts (`ApprovalVerbs`, the approval item read-model, the
evidence query).

> **Status: repository stood up, package not yet scaffolded.** The scaffold, transport wiring
> (polling default, broadcast opt-in), and the first surfaces are tracked in this repository's
> v0.1.0 milestone. Design of record: verdict-console
> [`docs/design/0001-verdict-console-design.md`](https://github.com/fissible/verdict-console/blob/main/docs/design/0001-verdict-console-design.md) §8–§9.

Depends on `fissible/verdict-console` (Packagist). No Livewire types may leak into the core package;
the dependency points one way.
