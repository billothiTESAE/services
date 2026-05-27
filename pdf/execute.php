<?php 
$rel_path = $_SERVER['SERVER_NAME'] == 'btheo8.pegcloud.io' ? '../' : '';
require_once('../include.php');
require_once('../functions.php');
require_once('../classes/PegasusPdf.php');
require_once $rel_path.'../core_libs00/FPDF/fpdf.php';
require_once $rel_path.'../core_libs00/FPDI/fpdi.php';

header('Content-Type: application/json');
$resp = array();

$action = $_REQUEST['action'] ?? '';
$pdf = new PegasusPdf();
switch($action) {
    case 'oauthcheck':
        $resp['ok'] = 0;
        $resp['msg'] = 'Not authorized';
        if($pdf->oauthCheck()){
            $resp['ok'] = 1;
            $resp['msg'] = 'Επιτυχής επαλήθευση';
        }
        break;
    case 'merge_pdfs':
        $resp = $pdf->mergePdfs($_REQUEST['pdfs'], $_REQUEST['dir']);
        break;
    default:
        header('HTTP/1.0 400 Bad Request');
        echo json_encode(array('error' => 'Unknown action '.$action));
        return;
}
echo json_encode($resp);