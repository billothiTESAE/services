<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);    
// require_once('../peg-config.php');
// define('DEBUGMODE', 1);
// define('MAIN_DB_EN', 1);
require_once('../include.php');
require_once('../classes/MainDbApi.php');
require_once('../classes/S3Actions.php');
header('Content-Type: application/json');
$resp = array();

$action = $_REQUEST['action'] ?? '';
//!Θα μπορούσαν τα set/unset των globals να γίνονται εδώ και όχι μέσα σε κάθε συνάρτηση 
$main_api = new MainDbApi();
switch($action) {
    case 'oauthcheck':
        $resp['ok'] = 0;
        $resp['msg'] = 'Not authorized';
        if($main_api->oauthCheck()){
            $resp['ok'] = 1;
            $resp['msg'] = 'Επιτυχής επαλήθευση';
        }
        break;
    case 'main_init':
        $params = array('app' => $_REQUEST['app']);
        $resp = $main_api->mainInit($params);
        break;
    case 'metrics':
        $resp = $main_api->metrics($_REQUEST['app']);
        break;
    case 'create_db':
        $resp = $main_api->createDb($_REQUEST['app']);
        break;
    case 'add_tesae_credentials':
        $params = array(
            'i31p02' => $_REQUEST['i31p02'],
            'tesae_user' => $_REQUEST['tesae_user'],
            'tesae_pass' => $_REQUEST['tesae_pass'],
            'app' => $_REQUEST['app'],
        );
        $resp = $main_api->addTesaeCredentials($params);
        break;
    case 'is_app_installed':
        $resp = $main_api->isAppInstalled($_REQUEST['app']);
        break;
    case 'add_extras':
        $params = array(
            'app' => $_REQUEST['app'],
            'addon' => $_REQUEST['addon']
        );
        $resp = $main_api->addExtras($params);
        break;
    case 'get_apps':
        $resp = $main_api->getApps();
        break;
    case 'app_summaries':
        $params = [];
        if(isset($_REQUEST['from'])){
            $params['from'] = $_REQUEST['from'];
        }
        if(isset($_REQUEST['to'])){
            $params['to'] = $_REQUEST['to'];
        }
        $resp = $main_api->appSummaries($params);
        break;
    case 'what_is_my_ip':
        $resp = $main_api->whatIsMyIp();
        break;  
    case 's3_list_buckets':
        $s3 = new S3Actions();
        $resp = $s3->listBuckets();
        unset($s3);
        break;   
    case 's3_create_bucket':
        $s3 = new S3Actions();
        $resp = $s3->createBucket($_REQUEST['bucket_name']);
        unset($s3);
        break; 
    case 's3_create_keys':
        $s3 = new S3Actions();
        $resp = $s3->createKeys($_REQUEST['bucket_name']);
        unset($s3);
        break;
    default:
        header('HTTP/1.0 400 Bad Request');
        echo json_encode(array('error' => 'Unknown action'));
        return;
}

echo json_encode($resp);