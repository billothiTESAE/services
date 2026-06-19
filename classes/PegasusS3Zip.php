<?php

    final class ZipApi{
        /**
         * @var PegasusS3 $s3
         */
        private $s3;
        /**
         * @var string $local_dir το τοπικό directory όπου θα κατεβάζονται τα αρχεία απο το S3 για να γίνουν zip/unzip.
         */
        private $local_dir;
        /**
         * @var string $app το όνομα της εφαρμογής που κάνει το request
         */
        private $app;
        /**
         * @var string $file_location το τοπικό path του αρχείου zip
         */
        private $file_location;
        /**
         * Εκτελεί OAuth authentication και ελέγχει εαν η IP του client είναι αποδεκτή.
         * Εκτελείται πριν από κάθε API call
         */
        public function oauthCheck(){
            return Utilities::oauthcheck() && $this->validateAppByToken();
        }
        /**
         * Ελέγχει την εγκυρότητα του token. Κάθε εφαρμογή έχει το δικό της token στην main_db και από αυτό καταλαβαίνει ποια εφαρμογή κάνει το request.
         */
        private function validateAppByToken(){
            $token = Utilities::get_bearer_token();
            if (empty($token)) {
                return false;
            }
            $main = new main_db();
            $app = $main->get_app_by_token($token);
            unset($main);
            
            if(empty($app)){
                return false;
            }
            if(!empty($this->app) && $this->app !== $app){
                return false;
            }
            if(empty($this->app)){
                $this->app = $app;
            }
            if(empty($this->local_dir)){
                $this->local_dir = '../../tmp/ziptmp_' . date('Y-m-d') . '_' . $this->app . '/';
            }
            return true;
        }
        /**
         * Παίρνει έναν φάκελο από το s3 τον κατεβάζει τοπικά και τον συμπιέζει σε zip αρχείο
         * @param array{
         *    app: string,
         *    source: string,
         *    destination: string
         * } $params
         * @return array{
         *    ok: int,
         *    msg: string
         * }
         */
        public function zipS3Folder($params){
            ini_set('memory_limit', '1024M');
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized' . json_encode(Utilities::what_is_my_ip()));
            }
            if(empty($params['source']) || empty($params['destination'])){
                return array('ok' => 0, 'msg' => 'Parameters source and destination are required');
            }
            $source = trim($params['source'], '/');
            $destination = $params['destination'];
            $app = $this->app;
            if(empty($app)){
                return array('ok' => 0, 'msg' => 'Could not validate app');
            }
            $ret_arr = array('ok' => 0, 'msg' => '');

            if(pathinfo($destination, PATHINFO_EXTENSION) !== 'zip'){
                $ret_arr['msg'] = 'Destination must be a zip file';
                return $ret_arr;
            }

            $main = new main_db();
            $s3conf = $main->get_s3_info($app);
            unset($main);

            $this->s3 = new PegasusS3(array(
                'bucket' => $s3conf['s3bucket'],
                'key' => $s3conf['s3key'],
                'secret' => $s3conf['s3pass'],
                'location' => $s3conf['s3loc']
            ));
            unset($s3conf);
            try{
                //Download Locally
                $download = $this->downloadFilesLocally($source);
                if($download['ok'] === 0){
                    throw new Exception('Error downloading S3 folder');
                }
                //Zip Locally
                $zip_result = $this->zipDirectoryLocally($source);
                if(!$zip_result){
                    throw new Exception('Error creating zip file');
                }
                //Upload to S3
                $this->uploadZipToS3($destination);

                $ret_arr =  array('ok' => 1, 'msg' => 'Zip process completed successfully');

            }catch(Exception $e){
                Utilities::unset_globals();
                $ret_arr =  array('ok' => 0, 'msg' => 'Error during zip process: '.$e->getMessage());
            }            
            
            unset($this->s3);
            Utilities::unset_globals();
            return $ret_arr;

            
        }
        /**
         * @param array $params
         */
        public function unzipS3Folder($params){
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => Utilities::what_is_my_ip()['ip'][0]. ' - Unauthorized');
            }
            if(empty($params['source']) || empty($params['destination'])){
                return array('ok' => 0, 'msg' => 'Parameters source and destination are required');
            }
            $source = $params['source'];
            $destination = trim($params['destination'], '/'). '/';
            $app = $this->app;
            if(empty($app)){
                return array('ok' => 0, 'msg' => 'Could not validate app');
            }
            $ret_arr = array('ok' => 0, 'msg' => 'Unknown Error');
            if(pathinfo($source, PATHINFO_EXTENSION) !== 'zip'){
                $ret_arr['msg'] = 'Source must be a zip file';
                return $ret_arr;
            }
            $main = new main_db();
            $s3conf = $main->get_s3_info($app);
            unset($main);
            $this->s3 = new PegasusS3(array(
                'bucket' => $s3conf['s3bucket'],
                'key' => $s3conf['s3key'],
                'secret' => $s3conf['s3pass'],
                'location' => $s3conf['s3loc']
            ));
            unset($s3conf);
            try{
                //Download Zip Locally
                $downloaded_zip = $this->downloadZipLocally($source);
                //Unzip Locally
                $unzip = $this->unzipDirectoryLocally($downloaded_zip);
                if(!$unzip){
                    throw new Exception('Error during unzip process');
                }
                $this->uploadDirectoryToS3($destination);
                $ret_arr =  array('ok' => 1, 'msg' => 'Unzip process completed successfully');
            }catch(Exception $e){
                Utilities::unset_globals();
                $ret_arr =  array('ok' => 0, 'msg' => 'Error during Unzip process: '.$e->getMessage());
            }            
            unset($this->s3);
            Utilities::unset_globals();
            return $ret_arr;
        }
        /**
         * Κατεβάζει τοπικά τα αρχεία απο το S3
         * @param string $source Ο φάκελος στο S3 που θα κατέβει. By default σώζεται στο ../../tmp/ziptmp_{app}/
         */
        private function downloadFilesLocally($source){
            $ret_arr = array('ok' => 0, 'msg' => '');
            if(is_dir($this->local_dir)){
                pegasus_delete_directory($this->local_dir);
            }
            mkdir($this->local_dir, 0777, true);
            try{
                $paginator = $this->s3->getPaginator($source);
                foreach($paginator as $page){
                    foreach ($page['Contents'] ?? [] as $object) {
                        $key = $object['Key'];
                        if (substr($key, -1) === '/'){
                            $subdir = rtrim($this->local_dir, '/') . '/' . rtrim($key, '/');
                            if (!is_dir($subdir)) {
                                mkdir($subdir, 0777, true);
                            }
                            continue;
                        }

                        $localPath = rtrim($this->local_dir, '/') . '/' . $key;
                        if(!is_dir(dirname($localPath))){
                            mkdir(dirname($localPath), 0777, true);
                        }
                        $this->s3->getFile($key, $localPath);
                    }
                }
                $ret_arr = array('ok' => 1, 'msg' => 'Files downloaded successfully'); 
            }catch(Exception $e){
                throw new Exception('Could not Download from S3: ' . $e->getMessage()); 
            }
            return $ret_arr;
        }
        /**
         * Συμπιέζει έναν τοπικό φάκελο σε zip αρχείο
         * @param string $source Ο τοπικός φάκελος που θα συμπιεστεί
         */
        private function zipDirectoryLocally($source){
            $archive = rtrim($source, '/').'.zip';
            $source = rtrim($this->local_dir. ltrim($source, '/'), '/');
            $this->file_location = $this->local_dir . ltrim($archive, '/');
            if (!extension_loaded('zip') || !file_exists($source)) {
                return false;
            }
            
            $zip = new ZipArchive();
            if (!($zip->open($this->file_location, ZIPARCHIVE::CREATE))) {
                return false;
            }
            $source0 = str_replace('\\', '/', realpath($source.'../'));
            $source = str_replace('\\', '/', realpath($source));
            if (is_dir($source) === true){
                $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source), RecursiveIteratorIterator::SELF_FIRST);
                
                foreach ($files as $file) {
                    $file_name = basename($file);
                    $file = str_replace('\\', '/', realpath($file));
                    
                    if($file==$source || $source0 == $file){
                        continue;
                    }
                    
                    if($file_name=='.' || $file_name == '..'){
                        continue;
                    }
                    
                    if (is_dir($file) === true) {
                        
                        $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
                    }else if (is_file($file) === true){
                        // 	                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
                        $zip->addFile($file, str_replace($source . '/', '', $file));
                    }
                }
            }else if (is_file($source) === true){
                // 	        $zip->addFromString(basename($source), file_get_contents($source));
                $zip->addFile($source, basename($source));
            }
            
            return $zip->close();
        }
        /**
         * Ανεβάζει το αρχείο στο S3 και διαγράφει τα τοπικά αρχεία
         * @param string $destination Η διαδρομή στο S3 όπου θα ανέβει
         */
        private function uploadZipToS3($destination){
            try{
                $this->s3->uploadFileFromPhysicalDir($destination, $this->file_location);
                pegasus_delete_directory($this->local_dir);
                return true;
            }catch(Exception $e){
                throw new Exception('Could not Upload Zip to S3: ' . $e->getMessage()); 
            }
        }
        /**
         * Κατεβάζει ένα zip αρχείο τοπικά
         * @param string $source Η διαδρομή στο S3 από όπου θα κατέβει το αρχείο
         */
        private function downloadZipLocally($source){
            try{
                if(!is_dir($this->local_dir)){
                    mkdir($this->local_dir, 0777, true);
                }
                $this->s3->downloadFileToPhysicalDir($source, $this->local_dir.basename($source));
                return $this->local_dir.basename($source);
            }catch(Exception $e){
                throw new Exception('Could not Download Zip from S3: ' . $e->getMessage()); 
            }
        }
        /**
         * Αποσυμπιέζει ένα zip αρχείο τοπικά
         * @param string $source Η διαδρομή του zip αρχείου που θα αποσυμπιεστεί
         */
        private function unzipDirectoryLocally($source){
            $pathinfo = pathinfo($source);
            $file_dir = $pathinfo['dirname'];
            $filename = $pathinfo['basename'];
            $zip = new ZipArchive;
			if ($zip->open($source) === TRUE) {
				$new_folder = str_replace('.zip', '', $filename);
                $this->file_location = $file_dir.'/'.$new_folder.'/';

				if(!is_dir($this->file_location)){
					mkdir($this->file_location, 0777, true);
				}
				$zip->extractTo($this->file_location);
				$zip->close();
			}
            return true;
        }
        /**
         * @param string $destination
         */
        private function uploadDirectoryToS3($destination){
            try{
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($this->file_location, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );
    
                foreach ($iterator as $file) {
                    if ($file->isFile()) {
                        // Get relative path (preserve folder structure)
                        $filePath = $file->getPathname();
                        $key = ltrim($destination . str_replace($this->file_location, '', $filePath), '/\\');
                        $this->s3->uploadFileFromPhysicalDir($key, $filePath);
                    }
                }
                return true;
            }catch(Exception $e){
                throw new Exception('Could not Upload Directory to S3: ' . $e->getMessage()); 
            }
        }
        public function whatIsMyIp(){
            return Utilities::what_is_my_ip();
        }
    }
