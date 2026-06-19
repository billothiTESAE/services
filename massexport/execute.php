<?php 
require_once('../classes/PegasusSheets.php');
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
$sheets_api = new PegasusSheets();
switch($action) {
    case 'create_excel':
        $params = array(
            'path' => $_REQUEST['path'],
            'json_name' => $_REQUEST['json_name'],
            'decimal_number_separator' => $_REQUEST['decimal_number_separator'],
            'exp_no_titles' => $_REQUEST['exp_no_titles'],
            'copy' => $_REQUEST['copy'],
            'module' => $_REQUEST['module'],
            'table' => $_REQUEST['table'],
        );
        $resp = $sheets_api->createExcel($params);
        break;
    case 'excel_to_json':
        $resp = $sheets_api->excelToJson(ltrim($_REQUEST['path'], '/'));
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