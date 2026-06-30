<?php
    class S3Actions {
        private $s3;

        public function __construct() {
            $config = array(
                "key" => getenv('masterS3key'),
                "secret" => getenv("masterS3secret"),
                "region" => getenv("masterS3region"),
                "bucket" => getenv("masterS3bucket"),
                "location" => getenv("masterS3location")
            );
            $this->s3 = new PegasusS3($config);
        }

        public function listBuckets() {
            if(!Utilities::oauthcheck()){
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            try{
                $buckets = $this->s3->listBuckets();
                if ($buckets === false) {
                    throw new Exception('Could not retrieve bucket list');
                }
            } catch (Exception $e) {
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => $e->getMessage());
            }
            return array('ok' => 1, 'buckets' => $buckets);
        }

        public function createBucket($bucketName) {
            if(!Utilities::oauthcheck()){
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            if(empty($bucketName)){
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => 'Bucket name is required');
            }
            try{
                $existsCheck = $this->bucketExists($bucketName);
                if($existsCheck['ok'] === 1 && $existsCheck['exists'] === true) {
                    return array('ok' => 0, 'msg' => 'Bucket already exists');
                }
                $result = $this->s3->createBucket($bucketName);
                if ($result === false) {
                    throw new Exception('Could not create bucket');
                }
            } catch (Exception $e) {
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => $e->getMessage());
            }
            return array('ok' => 1, 'msg' => 'Bucket created successfully');
        }


        private function bucketExists($bucketName) {
            try{
                $list = $this->listBuckets();
                if($list['ok'] === 1) {
                    return array('ok' => 1, 'exists' => in_array($bucketName, $list['buckets']));
                }
            } catch (Exception $e) {
                $this->unsetS3Globals();
                return array('ok' => 0, 'msg' => $e->getMessage());
            }
            
        }

        public function createKeys($bucketName){
            if(!Utilities::oauthcheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $ret = array('ok' => 0, 'msg' => '');
            $body = array(
                'name' => $bucketName.'-key',
                'grants' => array(
                    array(
                    'bucket' => $bucketName,
                    'permission' => 'readwrite'
                    )
                )
            );
            $body = json_encode($body);
            $url = "https://api.digitalocean.com/v2/spaces/keys";
            $do_apikey = getenv('do_apikey');
            $headers = array('Content-Type: application/json', 'Authorization: Bearer '.$do_apikey);

            $resp = pegasus_curl_request_post($url, $body, false, $headers);
            $resp = json_decode($resp, true);
            if(!isset($resp['key'])){
                $msg = 'Could not create keys';
                if(isset($resp['message'])){
                    $msg .= ': '.$resp['message'];
                }
                $ret ['msg'] = $msg;
            }else{
                $ret = array('ok' => 1, 'data' => array('s3key' => $resp['key']['access_key'], 's3secret' => $resp['key']['secret_key']));
            }
            // $this->unsetS3Globals();
            return $ret;
        }

        private function unsetS3Globals(){
            return;
            $unset = array('masterS3key', 'masterS3secret', 'masterS3region', 'masterS3bucket', 'masterS3location');

            foreach ($unset as $key => $value) {
                if(function_exists('putenv')){
                    putenv($value);
                }
                if(isset($_ENV[$value])){
                    unset($_ENV[$value]);
                }
                if(isset($_SERVER[$value])){
                    unset($_SERVER[$value]);
                }
            }
        }


        
    }