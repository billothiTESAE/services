<?php 
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Reader\Xls;
    class PegasusLotfSheets{
        private $spreadsheet;
        private $data;
        private $decimal_number_separator;
        private $locdir;
        private $s3;
        /**
         * Παίρνει ένα αρχειό json και από αυτό δημιουργεί ένα excel και το αποθηκεύει στο s3
         * Το path στο οποίο αποθηκεύεται είναι $path/$json_name.xlsx
         * @param array{
         *      path: string,
         *      json_name: string,
         *      decimal_number_separator: 0|1,
         *      copy: string
         * } $params
         * @return array{
         *    ok: int,
         *    msg: string
         * }
         */
        public function createExcel($params){

            $this->decimal_number_separator = $params['decimal_number_separator'];

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

            $this->spreadsheet->getProperties()->setCreator($params['copy'])
                ->setLastModifiedBy($copy)
                ->setTitle(str_replace('.json', '', $params['json_name']))
                ->setSubject("Εισαγωγή αρχείου κινήσεων (Excel)")
                ->setDescription("Ηλεκτρονικό Μητρώο Επιτηδευματιών/Σύστημα Ταυτοποίησης Αλκοολούχων Ποτών (Lotify)");
            $this->spreadsheet->getActiveSheet()->setTitle("Lotify");

            $this->spreadsheet->setActiveSheetIndex(0);
            $this->_pushDataToObjectExcel();
            $writer = new Xlsx($this->spreadsheet);
            
            $dst = $params['path'].'/'.str_replace('.json', '', $params['json_name']).'.xlsx';
            $writer->save('s3://'.$s3_info['s3bucket'].'/'.$dst);
            // $s3->deleteFile($params['path'].'/'.$params['json_name']);
            return array('ok' => 1, 'msg' => 'File created successfully');
        }

        private function _pushDataToObjectExcel(){
            
            list ($startColumn, $startRow) = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::coordinateFromString("A1");
            
            $currentRow = $startRow;
        
            foreach ($this->data as $rowData){
                $currentColumn = $startColumn;
                foreach ($rowData as $key => $field){
                    
                    if(is_array($field) && isset($field['value'])){
                        $type = ['db_type' => $field['db_type']];
                        $this->_setExcelCell($this->spreadsheet->setActiveSheetIndex(0)->getCell($currentColumn . $currentRow), $field['value'], $type);
                    }else{
                        $this->spreadsheet->getActiveSheet()->setCellValue($currentColumn.($currentRow),$field);
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

    }