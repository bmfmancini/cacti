<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/*
 * Lightweight path constants for source-scan unit tests.
 *
 * These mirrors the CACTI_PATH_* constants defined by include/global.php,
 * but are computed from the tests/ directory without loading global.php so
 * that tests that only inspect PHP source files stay database-free.
 */

if (!defined('CACTI_PATH_BASE')) {
	$__cacti_test_base = dirname(__DIR__);

	define('CACTI_PATH_BASE', $__cacti_test_base);
	define('CACTI_PATH_LIBRARY', $__cacti_test_base . '/lib');
	define('CACTI_PATH_INCLUDE', $__cacti_test_base . '/include');
	define('CACTI_PATH_SCRIPTS', $__cacti_test_base . '/scripts');
	define('CACTI_PATH_RESOURCE', $__cacti_test_base . '/resource');
	define('CACTI_PATH_RRA', $__cacti_test_base . '/rra');
	define('CACTI_PATH_FORMATS', $__cacti_test_base . '/formats');
	define('CACTI_PATH_CACHE', $__cacti_test_base . '/cache');
	define('CACTI_PATH_LOG', $__cacti_test_base . '/log');

	unset($__cacti_test_base);
}
