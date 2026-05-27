<?php   
class PegasusPdf{
    /**
     * @var PegasusS3
     */
    private $s3;
    /**
     * @var string Τοπικός φάκελος για προσωρινή αποθήκευση των pdfs που θα γίνουν merge
     */
    private $local_dir;
    /**
     * @var string Όνομα φακέλου για προσωρινή αποθήκευση των pdfs που θα γίνουν merge
     */
    private $folder;
    /**
     * @var string Όνομα merged αρχείου
     */
    private $merged_file;

    public function oauthCheck(){
        return Utilities::oauthcheck();
    }

    /**
     * @param string $pdfs base64 encoded json με τα pdf που θα γίνουν merge
     * @param string $dir Το directory στο s3 όπου θα ανέβει το merged αρχείο
     */
    public function mergePdfs($pdfs, $dir){
        if(empty($pdfs)){
            return array('ok' => 0, 'msg' => 'No pdfs provided');
        }

        if(empty($dir)){
            return array('ok' => 0, 'msg' => 'No directory provided');
        }

        $token = Utilities::get_bearer_token();
        if(!$this->oauthCheck() || empty($token)){
            return array('ok' => 0, 'msg' => 'Unauthorized');
        }
        $main = new main_db();
        $app = $main->get_app_by_token($token);
        $this->folder = explode('.', $app)[0].'_'.time();
        $this->local_dir = '../../tmp/pdf_merge/' . $this->folder . '/';
        if(!is_dir($this->local_dir)){
            mkdir($this->local_dir, 0777, true);
        }
        $s3_info = $main->get_s3_info($app);
        $this->s3 = new PegasusS3(array(
            'key'   =>$s3_info['s3key'],
            'secret' =>$s3_info['s3pass'],
            'region' => $s3_info['s3reg'],
            'bucket' => $s3_info['s3bucket'],
            'location' => $s3_info['s3loc']
        ));

        $paths = json_decode(base64_decode($pdfs), true);
        if(!$this->download_locally($paths)){
            return array('ok' => 0, 'msg' => 'Error downloading files');
        }

        $merged = $this->merge_locally($paths);
        if(!$merged){
            return array('ok' => 0, 'msg' => 'Error merging files');
        }
        $dir = trim($dir, '/') . '/' . basename($this->merged_file);
        $upload = $this->s3->uploadFile($dir, file_get_contents($this->merged_file));
        pegasus_delete_directory($this->local_dir);
        if(!$upload){
            return array('ok' => 0, 'msg' => 'Error uploading merged file');
        }
        return array('ok' => 1, 'msg' => 'Files merged successfully', 'merged_file' => $dir );
    }

    /**
     * Κατεβάζει τα αρχεία τοπικά για να γίνει το merge. Επιστρέφει το path του merged αρχείου
     * @param array $paths array με τα paths των pdfs στο s3
    */
    private function download_locally($paths){
        foreach($paths as $path){
            $this->s3->getFile(ltrim($path, '/'), $this->local_dir . basename($path));         
        }
        return true;
    }

    /**
     * Κάνει το merge των pdfs που κατέβηκαν τοπικά. Επιστρέφει το path του merged αρχείου
     * @param array $paths array με τα paths των pdfs στο s3
     */
    private function merge_locally($paths){
        $no_http_header=1;
		$pdf = new FPDI();
		// $pdf->setPrintHeader(false);
		// $pdf->setPrintFooter(false);
        foreach ($paths as $file) {
			
			$pageCount = $pdf->setSourceFile($this->local_dir . basename($file));
			for($x=1; $x<=$pageCount; $x++){
				$pdf->AddPage();
				$pdf->useTemplate($pdf->importPage($x), null, null, 0, 0, true);
				// $pdf->endPage();
			}
		}
        $this->merged_file = $this->local_dir . $this->folder . '_merged.pdf';
        $pdf->Output($this->merged_file, 'F');
        return $this->merged_file;
    }
}