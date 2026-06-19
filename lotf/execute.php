<?php 

// error_reporting(E_ALL);
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');

require_once('../classes/PegasusLotfSheets.php');
$rel_path = $_SERVER['SERVER_NAME'] == 'btheo8.pegcloud.io' ? '../' : '';
require_once('../include.php');
require_once($rel_path. '../core_libs00/class.html2text.inc');
if(file_exists('../vendor/autoload.php')){
	require_once '../vendor/autoload.php';
}else{
    //Για να μην ανεβάζω όλο το vendor
    require_once '../../query_libs/vendor/autoload.php';
}
header('Content-Type: application/json');
$resp = array();
$action = $_REQUEST['action'] ?? '';
$sheets_api = new PegasusLotfSheets();
switch($action) {
    case 'create_excel':
        $params = array(
            'path' => $_REQUEST['path'],
            'json_name' => $_REQUEST['json_name'],
            'decimal_number_separator' => $_REQUEST['decimal_number_separator'],
            'copy' => $_REQUEST['copy']
        );
        $resp = $sheets_api->createExcel($params);
        break;
    case 'oauthcheck':
        $resp['ok'] = 0;
        $resp['msg'] = 'Not authorized';
        if($sheets_api->oauthCheck()){
            $resp['ok'] = 1;
            $resp['msg'] = 'Επιτυχής επαλήθευση';
        }
        break;
    case 'what_is_my_ip':
        $resp = $sheets_api->whatIsMyIp();
        break;
    default:
        header('HTTP/1.0 400 Bad Request');
        echo json_encode(array('ok' => 0, 'msg' => 'Unknown action'));
        return;
}
echo json_encode($resp);