<?php

/*
 * Ephemeral configuration used only by the database release test. The test
 * links this file to config.php and supplies every connection value through
 * its local MariaDB service environment.
 */

$required_environment = [
    'N45_CI_DB_HOST',
    'N45_CI_DB_USER',
    'N45_CI_DB_PASSWORD',
    'N45_CI_DB_NAME',
];
foreach ($required_environment as $environment_name) {
    if (getenv($environment_name) === false || getenv($environment_name) === '') {
        throw new RuntimeException("Missing release-test environment variable: $environment_name");
    }
}

$dbhost = getenv('N45_CI_DB_HOST');
$dbusername = getenv('N45_CI_DB_USER');
$dbpassword = getenv('N45_CI_DB_PASSWORD');
$database = getenv('N45_CI_DB_NAME');
$database_port = intval(getenv('N45_CI_DB_PORT') ?: 3306);
$mysqli = mysqli_connect($dbhost, $dbusername, $dbpassword, $database, $database_port);
if (!$mysqli) {
    throw new RuntimeException('Release-test database connection failed: ' . mysqli_connect_error());
}

$config_app_name = 'ITFlow N45 Release Test';
$config_base_url = 'localhost';
$config_https_only = true;
$repo_branch = 'n45-release-test';
$installation_id = 'n45-release-test';
