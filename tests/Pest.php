<?php
/*
 * Pest bootstrap.
 */

require_once __DIR__ . '/cacti_paths.php';

uses(PHPUnit\Framework\TestCase::class)->in('Unit', 'integration', 'mutation', 'handoff');
