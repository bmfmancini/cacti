<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Behavior tests for cacti_authorize_resource().
 *
 * Root-cause mitigation for IDOR (GHSA-8p2f and future reports). The
 * helper must:
 *   - return false for zero/negative user or resource ids
 *   - return true when the user owns the row OR is an admin
 *   - return false for unknown resource types (fail closed)
 *   - cache admin status per-request to avoid DB hammering
 *
 * Tests use source-scan invariants plus an isolated reimplementation of
 * the authorization decision (no DB) to cover edge cases.
 */

/**
 * Pure-function mirror of cacti_authorize_resource() for testing the
 * decision logic without a database. Accepts the inputs the real helper
 * would derive from the DB (owner_id, is_admin).
 */
function decide_authorize($user_id, $resource_id, $resource_type, $owner_of_resource, $user_is_admin) {
	$user_id     = (int) $user_id;
	$resource_id = (int) $resource_id;

	if ($user_id <= 0 || $resource_id <= 0) {
		return false;
	}

	if ($user_is_admin) {
		return true;
	}

	switch ($resource_type) {
		case 'reports':
		case 'graph_tree':
			return $owner_of_resource !== null && (int) $owner_of_resource === $user_id;

		case 'settings_user':
			return $resource_id === $user_id;

		default:
			return false; // fail closed
	}
}

$src = file_get_contents(CACTI_PATH_LIBRARY . '/auth.php');

test('cacti_authorize_resource source: casts user_id and resource_id to int before any decision', function () use ($src) {
	expect($src)->toContain('$user_id     = (int) $user_id');
	expect($src)->toContain('$resource_id = (int) $resource_id');
});

test('cacti_authorize_resource source: rejects non-positive ids before any further work', function () use ($src) {
	expect($src)->toMatch('/if\s*\(\$user_id\s*<=\s*0\s*\|\|\s*\$resource_id\s*<=\s*0\)\s*\{[^}]*return\s+false/s');
});

test('cacti_authorize_resource source: falls through to false for unknown resource types', function () use ($src) {
	expect($src)->toContain('default:')
		->and($src)->toMatch('/default:\s*\/\/[^\n]*fail closed\s*\n\s*return\s+false/');
});

test('cacti_authorize_resource source: uses prepared queries for ownership lookups', function () use ($src) {
	expect($src)->toContain('db_fetch_cell_prepared');
	expect($src)->not->toMatch('/db_fetch_cell\s*\([^)]*\$user_id/');
});

test('cacti_authorize_resource source: caches admin status per-request', function () use ($src) {
	expect($src)->toContain('static $admin_cache');
});

test('authorization decision logic: rejects zero user id', function () {
	expect(decide_authorize(0, 10, 'reports', 5, false))->toBeFalse();
});

test('authorization decision logic: rejects negative user id', function () {
	expect(decide_authorize(-1, 10, 'reports', 5, false))->toBeFalse();
});

test('authorization decision logic: rejects zero resource id', function () {
	expect(decide_authorize(5, 0, 'reports', 5, false))->toBeFalse();
});

test('authorization decision logic: accepts owner of reports', function () {
	expect(decide_authorize(5, 10, 'reports', 5, false))->toBeTrue();
});

test('authorization decision logic: rejects non-owner of reports', function () {
	expect(decide_authorize(7, 10, 'reports', 5, false))->toBeFalse();
});

test('authorization decision logic: accepts owner of graph_tree', function () {
	expect(decide_authorize(5, 10, 'graph_tree', 5, false))->toBeTrue();
});

test('authorization decision logic: rejects non-owner of graph_tree', function () {
	expect(decide_authorize(99, 10, 'graph_tree', 5, false))->toBeFalse();
});

test('authorization decision logic: allows admin to bypass ownership', function () {
	expect(decide_authorize(99, 10, 'reports', 5, true))->toBeTrue();
});

test('authorization decision logic: allows user to modify own settings_user row', function () {
	expect(decide_authorize(5, 5, 'settings_user', null, false))->toBeTrue();
});

test('authorization decision logic: rejects user modifying another user settings_user row', function () {
	expect(decide_authorize(5, 7, 'settings_user', null, false))->toBeFalse();
});

test('authorization decision logic: fails closed for unknown resource types', function () {
	expect(decide_authorize(5, 10, 'totally_unknown', 5, false))->toBeFalse();
});

test('authorization decision logic: rejects null owner (missing resource row) for non-admin', function () {
	expect(decide_authorize(5, 10, 'reports', null, false))->toBeFalse();
});

test('authorization decision logic: rejects when owner is 0 (orphaned row) for non-admin', function () {
	expect(decide_authorize(5, 10, 'reports', 0, false))->toBeFalse();
});

test('IDOR attack scenarios: blocks cross-user report modification', function () {
	$attacker = 7;
	$victim_report_id = 42;
	$victim_user_id = 5;

	expect(decide_authorize($attacker, $victim_report_id, 'reports', $victim_user_id, false))
		->toBeFalse();
});

test('IDOR attack scenarios: blocks cross-user graph_tree modification', function () {
	expect(decide_authorize(7, 42, 'graph_tree', 5, false))->toBeFalse();
});

test('IDOR attack scenarios: blocks enumeration attack via non-existent resource id', function () {
	expect(decide_authorize(7, 999999, 'reports', null, false))->toBeFalse();
});
