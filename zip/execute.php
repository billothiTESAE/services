<?php
require_once('../classes/PegasusS3Zip.php');
require_once('../include.php');
header('Content-Type: application/json');
$resp = array();

$action = $_REQUEST['action'] ?? '';

$zip_api = new ZipApi();

switch ($action) {
    case 'oauthcheck':
        $resp['ok'] = 0;
        $resp['msg'] = 'Not authorized';
        if ($zip_api->oauthCheck()) {
            $resp['ok'] = 1;
            $resp['msg'] = 'Επιτυχής επαλήθευση';
        }
        break;
    case 'zip_s3_folder':
        $params = array(
            'source' => $_REQUEST['source'],
            'destination' => $_REQUEST['destination']
        );
        $resp = $zip_api->zipS3Folder($params);
        break;
    case 'unzip_s3_folder':
        $params = array(
            'source' => $_REQUEST['source'],
            'destination' => $_REQUEST['destination']
        );
        $resp = $zip_api->unzipS3Folder($params);
        break;
    case 'what_is_my_ip':
        $resp = $zip_api->whatIsMyIp();
        break;
    default:
        header('HTTP/1.0 400 Bad Request');
        echo json_encode(array('error' => 'Unknown action'));
        return;
}

echo json_encode($resp);
