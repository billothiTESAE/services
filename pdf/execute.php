<?php 
error_reporting(E_ALL);
ini_set('display_errors', 1);   
require_once('../include.php');
require_once('../functions.php');
require_once('../classes/PegasusPdf.php');
if(file_exists('../vendor/autoload.php')){
	require_once '../vendor/autoload.php';
}else{
    //Για να μην ανεβάζω όλο το vendor
    require_once '../../prints_libs/FPDF/fpdf.php';
    require_once '../../prints_libs/FPDI/fpdi.php';
}
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