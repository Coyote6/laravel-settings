<?php

// Larastan boots a full Laravel application in every PHPStan worker, and
// PHP's default 128M memory limit was not enough for a parallel worker.
// PHPStan has no neon option for the limit, and the shared stop hook and
// "composer types:check" run plain "phpstan analyse" without
// --memory-limit. PHPStan runs bootstrap files inside every worker before
// it analyses anything, so raising the limit here covers all of them.
ini_set('memory_limit', '1G');
