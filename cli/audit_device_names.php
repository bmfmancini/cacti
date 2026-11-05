#!/usr/bin/env php
<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

require(__DIR__ . '/../include/cli_check.php');
require_once($config['base_path'] . '/lib/api_data_source.php');
require_once($config['base_path'] . '/lib/api_device.php');
require_once($config['base_path'] . '/lib/api_graph.php');
require_once($config['base_path'] . '/lib/data_query.php');
require_once($config['base_path'] . '/lib/snmp.php');
require_once($config['base_path'] . '/lib/poller.php');
require_once($config['base_path'] . '/lib/utility.php');
require_once($config['base_path'] . '/lib/variables.php');

/* switch to main database for cli's */
if ($config['poller_id'] > 1) {
	db_switch_remote_to_main();
}

/* process calling arguments */
$parms = $_SERVER['argv'];
array_shift($parms);

if (!cacti_sizeof($parms)) {
	display_help();
	exit(0);
}

/* setup defaults */
$host_template_id = '';
$description      = '';
$hostname         = '';
$report           = false;
$remove           = false;
$update           = false;
$confirm          = false;
$debug            = false;

foreach ($parms as $parameter) {
	if (strpos($parameter, '=')) {
		list($arg, $value) = explode('=', $parameter, 2);
	} else {
		$arg   = $parameter;
		$value = '';
	}

	switch ($arg) {
	case '--host-template-id':
		$host_template_id = trim($value);

		break;
	case '--device-description':
	case '--device_description':
		$description = trim($value);

		break;
	case '--hostname':
		$hostname = trim($value);

		break;
	case '--report':
		$report = true;

		break;
	case '--remove-mismatch':
		$remove = true;

		break;
	case '--update-description':
		$update = true;

		break;
	case '--confirm':
		$confirm = true;

		break;
	case '-d':
	case '--debug':
		$debug = true;

		break;
	case '--version':
	case '-V':
	case '-v':
		display_version();
		exit(0);
	case '--help':
	case '-H':
	case '-h':
		display_help();
		exit(0);
	default:
		print "ERROR: Invalid Argument: ($arg)" . PHP_EOL . PHP_EOL;
		display_help();
		exit(1);
	}
}

if ($host_template_id == '' && $description == '' && $hostname == '') {
	print 'ERROR: You must supply one of --host-template-id, --device-description or --hostname' . PHP_EOL . PHP_EOL;
	display_help();
	exit(1);
}

if ($host_template_id != '' && !is_numeric($host_template_id)) {
	print "ERROR: --host-template-id must be numeric ($host_template_id)" . PHP_EOL;
	exit(1);
}

if ($remove && $update) {
	print 'ERROR: --remove-mismatch and --update-description can not be used together' . PHP_EOL;
	exit(1);
}

/* build the device filter */
$sql_where  = array("deleted = ''");
$sql_params = array();

if ($host_template_id != '') {
	if (!db_fetch_cell_prepared('SELECT id FROM host_template WHERE id = ?', array($host_template_id))) {
		print "ERROR: Unable to find Device Template with id ($host_template_id)" . PHP_EOL;
		exit(1);
	}

	$sql_where[]  = 'host_template_id = ?';
	$sql_params[] = $host_template_id;
}

if ($description != '') {
	$sql_where[]  = 'description = ?';
	$sql_params[] = $description;
}

if ($hostname != '') {
	$sql_where[]  = 'hostname = ?';
	$sql_params[] = $hostname;
}

$devices = db_fetch_assoc_prepared('SELECT *
	FROM host
	WHERE ' . implode(' AND ', $sql_where) . '
	ORDER BY description',
	$sql_params);

if (!cacti_sizeof($devices)) {
	print 'ERROR: No Devices found matching the supplied filter' . PHP_EOL;
	exit(1);
}

audit_debug('Found ' . cacti_sizeof($devices) . ' Device(s) to check');

$results    = array();
$mismatched = array();

foreach ($devices as $device) {
	$result = array(
		'id'          => $device['id'],
		'description' => $device['description'],
		'hostname'    => $device['hostname'],
		'sysname'     => '',
		'source'      => '',
		'status'      => ''
	);

	if ($device['disabled'] == 'on') {
		$result['status'] = 'SKIPPED (disabled)';
	} elseif ($device['snmp_version'] == 0) {
		$result['status'] = 'SKIPPED (no snmp)';
	} else {
		list($sysname, $source) = audit_get_sysname($device);

		$result['sysname'] = $sysname;
		$result['source']  = $source;

		if ($sysname == '') {
			$result['status'] = 'UNKNOWN (no sysName)';
		} elseif (audit_names_match($device['description'], $sysname)) {
			$result['status'] = 'OK';
		} else {
			$result['status'] = 'MISMATCH';
			$mismatched[]     = $result;
		}
	}

	audit_debug(sprintf('Device[%d] description:\'%s\' sysName:\'%s\' source:%s status:%s',
		$result['id'], $result['description'], $result['sysname'], $result['source'], $result['status']));

	$results[] = $result;
}

/* the report only shows mismatches, otherwise show every device checked */
display_results($report ? $mismatched : $results);

print PHP_EOL . 'Checked: ' . cacti_sizeof($results) . ', Mismatched: ' . cacti_sizeof($mismatched) . PHP_EOL;

if (!cacti_sizeof($mismatched)) {
	exit(0);
}

if ($update) {
	foreach ($mismatched as $result) {
		audit_update_description($result);
	}
} elseif ($remove) {
	$ids = array_column($mismatched, 'id');

	if (!$confirm) {
		print 'Please use --confirm to remove Device(s) with ids: ' . implode(', ', $ids) . PHP_EOL;
		exit(0);
	}

	print 'Removing Device(s) with ids: ' . implode(', ', $ids) . PHP_EOL;

	api_device_remove_multi($ids);

	if (is_error_message()) {
		print 'ERROR: Failed to remove Device(s)' . PHP_EOL;
		exit(1);
	}

	foreach ($mismatched as $result) {
		cacti_log('Device Removed via audit_device_names.php - Device ID: ' . $result['id'] . ', Hostname: ' . $result['hostname'] . ', Description: ' . $result['description'] . ', sysName: ' . $result['sysname'], false, 'CLI');
	}

	print 'Success - removed ' . cacti_sizeof($ids) . ' Device(s)' . PHP_EOL;
}

exit(0);

/**
 * Get the sysName of a device.  A live SNMP query is attempted first, and if
 * the device does not answer, the sysName cached by the poller is used.
 *
 * @param array $device The host table row.
 *
 * @return array The sysName and the source it was read from ('snmp' or 'cache').
 */
function audit_get_sysname($device) {
	$sysname = cacti_snmp_get($device['hostname'], $device['snmp_community'], '.1.3.6.1.2.1.1.5.0',
		$device['snmp_version'], $device['snmp_username'], $device['snmp_password'],
		$device['snmp_auth_protocol'], $device['snmp_priv_passphrase'], $device['snmp_priv_protocol'],
		$device['snmp_context'], $device['snmp_port'], $device['snmp_timeout'], read_config_option('snmp_retries'),
		'SNMP', $device['snmp_engine_id']);

	$sysname = trim(strtr($sysname, '"', ' '));

	if ($sysname != '' && $sysname != 'U') {
		return array($sysname, 'snmp');
	}

	audit_debug('Device[' . $device['id'] . '] SNMP query failed, falling back to cached sysName');

	return array(trim($device['snmp_sysName']), ($device['snmp_sysName'] != '' ? 'cache' : ''));
}

/**
 * Compare a Cacti description against a sysName.  The comparison is case
 * insensitive, and a short name matches its FQDN in either direction.
 *
 * @param string $description The Cacti description.
 * @param string $sysname The sysName reported by the device.
 *
 * @return bool True if the names match.
 */
function audit_names_match($description, $sysname) {
	$description = strtolower(trim($description));
	$sysname     = strtolower(trim($sysname));

	if ($description == $sysname) {
		return true;
	}

	if (filter_var($description, FILTER_VALIDATE_IP) || filter_var($sysname, FILTER_VALIDATE_IP)) {
		return false;
	}

	return strtok($description, '.') == strtok($sysname, '.');
}

/**
 * Update the description of a device to its sysName and refresh the caches
 * that depend on it.
 *
 * @param array $result The audit result for the device.
 *
 * @return void No value is returned.
 */
function audit_update_description($result) {
	$poller_id = db_fetch_cell_prepared('SELECT poller_id
		FROM host
		WHERE id = ?',
		array($result['id']));

	db_execute_prepared('UPDATE host
		SET description = ?
		WHERE id = ?',
		array($result['sysname'], $result['id']));

	api_device_cache_crc_update($poller_id);

	push_out_host($result['id'], 0);

	kill_session_var('sess_host_cache_array');

	update_data_source_title_cache_from_host($result['id']);
	update_graph_title_cache_from_host($result['id']);

	set_config_option('time_last_change_device', time());

	cacti_log('Device Description Updated via audit_device_names.php - Device ID: ' . $result['id'] . ', Old: ' . $result['description'] . ', New: ' . $result['sysname'], false, 'CLI');

	print 'Updated Device[' . $result['id'] . '] description \'' . $result['description'] . '\' => \'' . $result['sysname'] . '\'' . PHP_EOL;
}

/**
 * Print the audit results as a table.
 *
 * @param array $results The audit results.
 *
 * @return void No value is returned.
 */
function display_results($results) {
	$format = '%-8s | %-30s | %-30s | %-30s | %-6s | %s' . PHP_EOL;

	printf($format, 'id', 'description', 'hostname', 'sysName', 'source', 'status');
	print str_repeat('-', 140) . PHP_EOL;

	foreach ($results as $result) {
		printf($format, $result['id'], $result['description'], $result['hostname'], $result['sysname'], $result['source'], $result['status']);
	}
}

/**
 * Print a debug message when --debug is set.
 *
 * @param string $message The message to print.
 *
 * @return void No value is returned.
 */
function audit_debug($message) {
	global $debug;

	if ($debug) {
		print 'DEBUG: ' . $message . PHP_EOL;
	}
}

/**
 * Displays version information. Used as part of Cacti's CLI functionality.
 *
 * @return void No value is returned.
 */
function display_version() {
	$version = get_cacti_cli_version();
	print "Cacti Audit Device Names Utility, Version $version, " . COPYRIGHT_YEARS . PHP_EOL;
}

/**
 * Displays the usage of the function. Used as part of Cacti's CLI functionality.
 *
 * @return void No value is returned.
 */
function display_help() {
	display_version();

	print PHP_EOL;
	print 'Compares each Device description against the sysName the Device reports via SNMP.' . PHP_EOL . PHP_EOL;

	print 'usage: audit_device_names.php --host-template-id=N | --device-description=\'S\' | --hostname=\'S\'' . PHP_EOL;
	print '    [--report] [--remove-mismatch [--confirm] | --update-description] [--debug]' . PHP_EOL . PHP_EOL;

	print 'Required: (one or more, combined with AND)' . PHP_EOL;
	print '    --host-template-id=N       Check all Devices using this Device Template.' . PHP_EOL;
	print '    --device-description=\'S\'   Check the Device with this exact description.' . PHP_EOL;
	print '    --hostname=\'S\'             Check the Device with this exact hostname or IP.' . PHP_EOL . PHP_EOL;

	print 'Optional:' . PHP_EOL;
	print '    --report                   Only list the Devices that are mismatched.' . PHP_EOL;
	print '    --remove-mismatch          Remove mismatched Devices, including their Graphs and Data Sources.' . PHP_EOL;
	print '    --confirm                  Required with --remove-mismatch to actually remove Devices.' . PHP_EOL;
	print '    --update-description      Set the description of mismatched Devices to their sysName.' . PHP_EOL;
	print '    --debug                    Display verbose output.' . PHP_EOL . PHP_EOL;

	print 'A short name matches its FQDN (e.g. router1 matches router1.example.com).  If the' . PHP_EOL;
	print 'live SNMP query fails, the sysName cached by the poller is used instead.' . PHP_EOL;
}

