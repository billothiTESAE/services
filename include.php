<?php
$rel_path = $_SERVER['SERVER_NAME'] == 'btheo8.pegcloud.io' ? '../' : '';
require_once($rel_path . '../core_libs00/utilities.php');
require_once($rel_path . '../core_libs00/server.php');
require_once($rel_path . '../core_libs00/error_handler.php');
require_once($rel_path . '../core_libs00/pegasus_request.php');
require_once($rel_path . '../core_libs00/pegasus_functions.php');
require_once($rel_path . '../core_libs00/pdo.php');
require_once($rel_path . '../core_libs00/main_db.php');
require_once($rel_path . '../core_libs00/s3.php');
