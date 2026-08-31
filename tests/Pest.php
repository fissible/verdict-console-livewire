<?php

declare(strict_types=1);

use Fissible\VerdictConsoleLivewire\Tests\EndToEndTestCase;
use Fissible\VerdictConsoleLivewire\Tests\TestCase;

uses(TestCase::class)->in('Feature');

// The flagship surface drives the real Livewire + Laravel AI + Verdict + core stack.
uses(EndToEndTestCase::class)->in('EndToEnd');
