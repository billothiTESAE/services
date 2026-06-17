<?php 
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Reader\Xls;
    class PegasusSheets{
        private $spreadsheet;
        private $data;
        private $decimal_number_separator;
        private $exp_no_titles;
        private $locdir;
        private $s3;
        /**
         * Παίρνει ένα αρχειό json και από αυτό δημιουργεί ένα excel και το αποθηκεύει στο s3
         * Το path στο οποίο αποθηκεύεται είναι $path/$json_name.xlsx
         * @param array{
         *      path: string
         *      json_name: string
         *      decimal_number_separator: 0|1,
         *      exp_no_titles: 0|1|2,
         *      copy: string,
         *      module: string,
         *      table: string,
         * } $params
         * @return array{
         *    ok: int,
         *    msg: string
         * }
         */
        public function createExcel($params){
            $this->decimal_number_separator = $params['decimal_number_separator'];
            $this->exp_no_titles = $params['exp_no_titles'];
            $token = Utilities::get_bearer_token();
            if(!$this->oauthCheck() || empty($token)){
                return array('ok' => 0, 'msg' => 'Unauthorized '. json_encode($this->whatIsMyIp()));
            }
            $main = new main_db();
            $app = $main->get_app_by_token($token);
            $s3_info = $main->get_s3_info($app);
            $s3 = new PegasusS3(array(
                'key'   =>$s3_info['s3key'],
                'secret' =>$s3_info['s3pass'],
                'region' => $s3_info['s3reg'],
                'bucket' => $s3_info['s3bucket'],
                'location' => $s3_info['s3loc']
            ));
            $s3->registerStreamWrapper();
            $json_contents = $s3->getFile($params['path'].'/'.$params['json_name'])['Body']->getContents();
            $this->data = json_decode($json_contents, true);

            $this->spreadsheet = new Spreadsheet();
            $copy = !is_null($params['copy']) ? $params['copy'] : '';
            $this->spreadsheet->getProperties()->setCreator($copy)
                ->setLastModifiedBy($copy)
                ->setTitle(str_replace('.json', '', $params['json_name']))
                ->setSubject("Office 2007 XLSX Export")
                ->setDescription("Export for module ".$params['module'] ." table ".$params['table']." document for Office 2007 XLSX")
                ->setKeywords("Export for module ".$params['module'] ." table ".$params['table']." Pegasus Hermes Application");
            $title_len = $this->spreadsheet->getActiveSheet()::SHEET_TITLE_MAXIMUM_LENGTH;
            $this->spreadsheet->getActiveSheet()->setTitle(substr($params['module'].'-' .$params['table'], 0, $title_len));

            $this->spreadsheet->setActiveSheetIndex(0);
            $this->_pushDataToObjectExcel();
            $writer = new Xlsx($this->spreadsheet);
            
            $dst = $params['path'].'/'.str_replace('.json', '', $params['json_name']).'.xlsx';
            $writer->save('s3://'.$s3_info['s3bucket'].'/'.$dst);
            $s3->deleteFile($params['path'].'/'.$params['json_name']);
            return array('ok' => 1, 'msg' => 'File created successfully');
        }

        private function _pushDataToObjectExcel(){
            
            list ($startColumn, $startRow) = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString("A1");
            
            $currentRow = $startRow;    
            foreach ($this->data as $rowData){
				$currentColumn = $startColumn;
                foreach ($rowData as $key => $field){
                    if($currentRow == $startRow && $this->exp_no_titles == 0){
                        //Οι κωδικοί έρχονται σε non assosiative array οπότε στο field είναι το όνομα του πεδίου
                        $this->spreadsheet->getActiveSheet()->setCellValue($currentColumn . $startRow, $field);
                    }else{
                        if(isset($field['value'])){
                            $type = ['db_type' => $field['db_type'], 'type' => $field['type']];
                            $this->_setExcelCell($this->spreadsheet->setActiveSheetIndex(0)->getCell($currentColumn . $currentRow), $field['value'], $type);
                        }elseif(isset($field['sum'])){
                            /**
                             * $exp_no_titles = 0 -> 2 στήλες με Ονόματα/Τίτλους, από την 3 τα δεδομένα
                             * $exp_no_titles = 1 -> 1 στήλη με τίτλο, από την 2η τα δεδομένα
                             * $exp_no_titles = 2 -> δεν υπάρχει στήλη με τίτλο, από την 1η τα δεδομένα
                             */
                            $startDataRow = 3 - $this->exp_no_titles; 
                            if($field['sum']){
                                $this->spreadsheet->getActiveSheet()->setCellValue($currentColumn . $currentRow, '=SUM('.$currentColumn.$startDataRow.':'.$currentColumn.($currentRow-1).')');
                            }
                            
                        }
                    }
					++$currentColumn;
				}
				++$currentRow;
			}
        }

        private function _setExcelCell($cel ,$value, $type = array()){
            if(!($cel instanceof \PhpOffice\PhpSpreadsheet\Cell\Cell)){
                throw new Exception("Not PHPExcel_Cell");
            }
            if(strtoupper($type['db_type']) == 'V' || strtoupper($type['db_type']) == 'C' ||  ( strtoupper($type['db_type']) == 'M' and  strtoupper($type['type'])!='TINYMCE')){
                if(!empty($value)){
                    //Εδώ υπάρχουν περιπτώσεις που έχουμε μεταβλητή Variable και είναι δεκαδικός αριθμός οπότε θέλω να εμφανίζεται όπως είναι και όχι ως string
                    if(strlen($value) < 12 or strpos($value, '.') == true){
                        if(strtoupper($type['db_type']) == 'V'){
                            $objText = new \PhpOffice\PhpSpreadsheet\RichText\TextElement($value);
                        
                            $cel->setValue($objText->getText());
                        }else{
                            // ΠΠΥ: 10041245. Επειδή η setValue εσωτερικά "αποφασίζει" για τον τύπο του δεδομένου, έχουμε θέμα με strings 25.00050 -> 25.0005
                            // Οπότε αν δεν είναι V το κάνουμε explicit string  
                            $cel->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                        }
                    }else{
                        //ΠΠΥ 10029768 - Σε νούμερα με μέγεθος < 12 τα εμφάνιζε ως scientific
                        $cel->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                }
            }elseif ( strtoupper($type['db_type']) == 'M' and  strtoupper($type['type'])=='TINYMCE'  ){
                
                $text = html_entity_decode($value, ENT_COMPAT , "UTF-8");
                $ttt = new html2text($text);
                $text = $ttt->getText();
                $type['type'] = 'MEMO';
                $this->_setExcelCell($cel, $text ,$type);
            }elseif (strtoupper($type['db_type']) == 'N'){
                // E: 10000458
                if($this->decimal_number_separator == 1) {
                    $value = str_replace('.',',',$value);
                    $cel->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                } else {
                    $cel->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                }
            }elseif (strtoupper($type['db_type']) == 'L'){
                if($value)
                    $cel->setValue(true);
                else 
                    $cel->setValue(false);
            }elseif (strtoupper($type['db_type']) == 'D'){
                $cel->setValueExplicit($value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }else {
                $cel->setValue("Underfinted Type ='".$type['db_type']."' " );
            }
        }
        public function oauthCheck(){
            return Utilities::oauthcheck();
        }
        public function whatIsMyIp(){
            return Utilities::what_is_my_ip();
        }
        public function excelToJson($excel_path){
            try{
                $json_path = str_replace('.xlsx', '.json', $excel_path);
                $token = Utilities::get_bearer_token();
                if(!$this->oauthCheck() || empty($token)){
                    return array('ok' => 0, 'msg' => 'Unauthorized');
                }
                $excel_tmp = $this->download_excel_locally($excel_path);
                $json_tmp = $this->generate_json($excel_tmp);
                $this->s3->uploadFile($json_path, file_get_contents($json_tmp));

                unlink($excel_tmp);
                unlink($json_tmp);
                return array('ok' => 1, 'msg' => 'File created successfully', 'file' => $json_path);

            }catch(Exception $e){
                return array('ok' => 0, 'msg' => 'Error: '.$e->getMessage());
            }
            
        }

        private function download_excel_locally($filename){
            $main = new main_db();
            $app = $main->get_app_by_token(Utilities::get_bearer_token());
            $s3conf = $main->get_s3_info($app);
            unset($main);

            $this->s3 = new PegasusS3(array(
                'bucket' => $s3conf['s3bucket'],
                'key' => $s3conf['s3key'],
                'secret' => $s3conf['s3pass'],
                'location' => $s3conf['s3loc']
            ));

            $this->locdir = '../../tmp/massexport_' . date('Ymd') . '_' . $app .'/';
            if(!is_dir($this->locdir)){
                mkdir($this->locdir, 0777, true);
            }
            $local_path = $this->locdir . basename($filename);
            $this->s3->getFile(ltrim($filename, '/'), $local_path);
            return $local_path;
            

        }
        
        private function generate_json($filepath){
            $inputFileType = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filepath);
			$objReader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($inputFileType);

			$objReader->setReadDataOnly(false);
			$objPHPExcel = $objReader->load($filepath);
			$objWorksheet = $objPHPExcel->getActiveSheet();
			
			foreach ($objWorksheet->getRowIterator() as $row) {
				$row_ = [];
				$cellIterator = $row->getCellIterator();
				$cellIterator->setIterateOnlyExistingCells(false);
				foreach ($cellIterator as $cell) {
					$row_[] = $this->getFieldValue_($cell);
				}
				$data[] = $row_;
			}
            file_put_contents($this->locdir.str_replace('.xlsx', '.json', basename($filepath)), json_encode($data));
            return $this->locdir.str_replace('.xlsx', '.json', basename($filepath));
        }

        private function getFieldValue_(&$cell){

            if(		$cell instanceof \PhpOffice\PhpSpreadsheet\Cell\Cell and 
                    $cell->getDataType() == \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC and 
                    \PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)===TRUE
                ){
                    $cell->getStyle()->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_DATE_DDMMYYYY);
                    $tmp = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp($cell->getValue());
                    return date('Y-m-d', $tmp);
            }
            $value =(string) $cell->getValue();
            if(substr($value,0,1) == '=') {
                $value = (string) $cell->getCalculatedValue();
            }
            return $value;
        }
    }
