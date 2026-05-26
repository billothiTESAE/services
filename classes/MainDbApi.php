<?php

    class MainDbApi{
        /**
         * Εκτελεί OAuth authentication και ελέγχει εαν η IP του client είναι αποδεκτή.
         * Εκτελείται πριν από κάθε API call
         */
        public function oauthCheck(){
            return Utilities::oauthcheck();
        }
        /**
         * Ελέγχει εαν μια εφαρμογή είναι ήδη εγκατεστημένη
         * @param string $app_name Το όνομα της εφαρμογής
         * @return array{
         *     ok: int,
         *     data: array{
         *         exists: bool
         *     }
         * }
         */

        public function isAppInstalled($app_name){
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_db();
            if((int) $main->get_db()->pegasus_printfld('users', 'nr01', 'app = :app', array('app' => $app_name)) > 0){
                return array('ok' => 1, 'data' => array('exists' => true));
            }
            unset($main);
            /**
             * Επειδή μπορεί να χρησιμοποιηθεί μέσα από την mainInit δεν κάνουμε unset_globals εδώ
             * Ωστόσο επειδή χρησιμποποιείται και ανεξάρτητα, θα πρέπει να το κάνουμε εκεί
             * TODO: να δω εαν υπάρχει καλύτερος τρόπος
             */
            // Utilities::unset_globals();
            return array('ok' => 1, 'data' => array('exists' => false));
        }
        /**
         * Αρχικοποιεί μία νέα εφαρμογή στην main_db
         * @param array{
         *     app: string,
         *    } $params
         * - $params['app']: Το όνομα της εφαρμογής
         * @return array{
         *     ok: int,
         *     msg: string
         * }
         */
        public function mainInit($params) {
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            if(empty($params['app'])){
                return array('ok' => 0, 'msg' => 'App name is required');
            }
            $check_app = $this->isAppInstalled($params['app']);
            if($check_app['ok'] === 0){
                return array('ok' => 0, 'msg' => 'An unexpected error occurred during app check');
            }
            if($check_app['data']['exists'] === true){
                return array('ok' => 0, 'msg' => 'App already installed');
            }
            $main = new main_installation();
            $res = $main->main_db_new_record($params['app']);
            unset($main);
            Utilities::unset_globals();
            return $res;
        }
        /**
         * Επιστρέφει στατιστικά για μια εφαρμογή
         * @param string $app Το όνομα της εφαρμογής
         * @return array{
         *     ok: int,
         *     data: array{
         *         dbsize: float,
         *         s3size: float,
         *         cache: float
         *     }
         * }
         */
        public function metrics($app) {
            $info = array();
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_db();
            $main->get_db()->pegasus_use('SELECT dbname, s3bucket, s3key, s3pass, s3loc, redishost, redisport, redispass, salt from users where app = :app', array('app' => $app), $info);
            if( empty($info)){
                return array('ok' => 0, 'msg' => 'Δεν υπάρχει εγγραφή για την συγκεκριμένη εφαρμογή');
            }
            $sum = array('size' => 0);
            if(!empty($info['dbname'])){
                $main->get_db()->pegasus_use('SELECT
                                                sum((`DATA_LENGTH` + `INDEX_LENGTH`) / 1024 / 1024) AS size
                                                FROM
                                                information_schema.TABLES
                                                WHERE
                                                TABLE_SCHEMA = :dbname',
                                                array('dbname' => $info['dbname']), $sum);
            }
            unset($main);
            Utilities::unset_globals();
            $s3_size = 0;
            if(!empty($info['s3bucket'])){                
                $config = array('bucket' => $info['s3bucket'], 
                                'key' => peg_decrypt00($info['s3key'], 'digital_ocean_cloud_app', $info['salt']), 
                                'secret' => peg_decrypt00($info['s3pass'], 'digital_ocean_cloud_app', $info['salt']), 
                                'location' => $info['s3loc']
                                );
                $s3 = new PegasusS3($config);
                $s3_size = $s3->bucketSize();
                if($s3_size === false){
                    return array('ok' => 0, 'msg' => 'Error fetching S3 size');
                }
                unset($config);
                unset($s3);                
            }
            $cache = 0;
            if(!empty($info['redishost'])){
                $config = array('host' => $info['redishost'], 
                                'port' => $info['redisport'], 
                                'password' => peg_decrypt00($info['redispass'], 'digital_ocean_cloud_app', $info['salt']));
                $redis_info = $this->redis_info($config);
                $cache = $redis_info /(1024*1024);
            }
            return array('ok'=>1, 'data' => array('dbsize' => (float) $sum['size'] ?? 0, 's3size' => $s3_size, 'cache' => $cache));
        }
        /**
         * Δημιουργεί νέα βάση δεδομένων για μια εφαρμογή
         * @param string $app_name Το όνομα της εφαρμογής
         * @return array{
         *     ok: int,
         *     msg: string
         * }
         */
        public function createDb($app_name) {
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_installation();
            if(!$main->db_created($app_name)){
                //* CREATE DATABASE
                return $main->init_user_db($app_name);
            }

            unset($main);
            return array('ok' => 0, 'msg' => 'Database already exists');
	    }
        /**
         * Ενημερώνει την main db με τα credentials για το tesae.gr και το serial number της εγκατάστασης
         * @param array{
         *     i31p02: string,
         *     tesae_user: string,
         *     tesae_pass: string,
         *     app: string
         * } $params
         * - $params['i31p02']: Το serial number της εγκατάστασης 
         * - $params['tesae_user']: Το username για το tesae.gr
         * - $params['tesae_pass']: Το password για το tesae.gr
         * - $params['app']: Το όνομα της εφαρμογής
         * @return array{
         *     ok: int,
         *     msg: string
         * }
         */
        public function addTesaeCredentials($params){
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_db();
            $info = array();
            $main->get_db()->pegasus_use('SELECT i31p02, tesae_user, tesae_pass FROM users WHERE app = :app', array('app' => $params['app']), $info);
            if(!empty($info['i31p02'] || !empty($info['tesae_user']) || !empty($info['tesae_pass']))){
                return array('ok' => 0, 'msg' => 'Credentials already exist for this app');
            }
            $res = $main->get_db()->pegasus_query('UPDATE users SET i31p02 = ?, tesae_user = ?, tesae_pass = ? WHERE app = ?', array($params['i31p02'], $params['tesae_user'], $params['tesae_pass'], $params['app']));
            unset($main);
            Utilities::unset_globals();
            if($res === false) {
                return array('ok' => 0, 'msg' => 'Error updating credentials');
            }else{
                return array('ok' => 1, 'msg' => 'Credentials updated successfully');
            }
            return $res;
        }
        /**
         * Προσθέτει επιπλέον πακέτα σε μια εφαρμογή
         * @param array{
         *     app: string,
         *     addon: string
         * } $params
         * - $params['app']: Το όνομα της εφαρμογής
         * - $params['addon']: Το όνομα του πακέτου που θα προστεθεί (π.χ. 's3', 'redis')
         * @return array{
         *     ok: int,
         *     msg: string,
         *     next: string
         * }
         * - $next: Το επόμενο βήμα μετά την επιτυχή προσθήκη (π.χ. 'submit', 's3')
         */
        public function addExtras($params) {
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_db();
            // $res = $main->add_extras($params['app'], $params['addon']);
            $salt = $main->get_db()->pegasus_printfld('users', 'salt', 'app = :app', array('app' => $params['app']));
            $resp = array('ok' => 0, 'msg' => 'Πρόβλημα κατά την προσθήκη επιπλέον πακέτων');
            switch($params['addon']) {
                case 's3':
                    $bucket_name = strtolower('pegsl-'.$params['app'].'-'.rand(1000, 9999));
                    $s3_actions = new S3Actions();
                    $create_bucket = $s3_actions->createBucket($bucket_name);
                    
                    if($create_bucket['ok'] === 0){
                        $resp = array('ok' => 0, 'msg' => 'Σφάλμα κατά τη δημιουργία του S3 bucket: '.$create_bucket['msg']);
                        break;
                    }

                    $create_keys = $s3_actions->createKeys($bucket_name);
                    if($create_keys['ok'] === 0){
                        $resp = array('ok' => 0, 'msg' => 'Σφάλμα κατά τη δημιουργία των S3 keys: '.$create_keys['msg']);
                        break; 
                    }
                    unset($s3_actions);
                    
                    $s3key = peg_encrypt00($create_keys['data']['s3key'], 'digital_ocean_cloud_app', $salt);
                    $s3pass = peg_encrypt00($create_keys['data']['s3secret'], 'digital_ocean_cloud_app', $salt);
                    $res = $main->get_db()->pegasus_query('UPDATE users SET s3bucket =?, s3key = ?, s3pass = ?, s3loc = ?, s3reg = ? WHERE app = ?', 
                       array($bucket_name, $s3key, $s3pass,getenv('masterS3location'),getenv('masterS3region'), $params['app']));
                    if($res){
                        $resp = array('ok' => 1, 'msg' => 'Επιτυχής προσθήκη S3', 'next' => 'submit');
                    }
                    break;
                case 'redis':
                    // $salt = Utilities::password_generator();
                    $res = $main->get_db()->pegasus_query('UPDATE users SET redishost = ?, redisport = ?, redispass = ? WHERE app = ?', 
                    array('db-valkey-fra1-36274-do-user-27879497-0.e.db.ondigitalocean.com', 
                    '25061',
                    peg_encrypt00(getenv('masterRedisPass'), 'digital_ocean_cloud_app', $salt),
                    $params['app'])
                    );
                    if($res){
                        $resp = array('ok' => 1, 'msg' => 'Επιτυχής προσθήκη Redis', 'next' => 's3');
                    }
                    break;
            }
            unset($main);
            Utilities::unset_globals();
            return $resp;
        }
        /**
         * Επιστρέφει τη λίστα με όλες τις εφαρμογές
         * @return array{
         *     ok: int,
         *     data: array<array{app: string}>
         * }
         */
        public function getApps() {
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }
            $main = new main_db();
            $query = $main->get_db()->pegasus_query('SELECT app FROM users ORDER BY nr01', array()); 
            if($query === false) {
                return array('ok' => 0, 'msg' => 'Error fetching apps');
            }
            $apps = $query->fetchAll(PDO::FETCH_ASSOC);
            unset($main);
            //TODO: Δες σχόλια στην isAppInstalled
            // Utilities::unset_globals();
            return array('ok' => 1, 'data' => $apps);
        }
        /**
         * Επιστρέφει συνοπτικά στατιστικά για όλες τις εφαρμογές εντός ενός εύρους
         * @param array{
         *    from: int, 
         *    to: int
         * }$params
         * 
         * Εαν κάποια από τις παραμέτρους είναι κενή γυρίζει όλες τις εφαρμογές
         * @return array{
         *    ok: int,
         *    data: array<array{
         *       app: string,
         *       cluster: string,
         *       main_app: string,
         *       date: int,
         *       dbsize: int,
         *       s3size: int,
         *       cache: int,
         *       code: int
         *    }>
         * }
         * - data['code']: 200 εαν λάβαμε στατιστικά, 500 εαν υπήρξε σφάλμα
         */
        public function appSummaries($params) {
            if(!$this->oauthCheck()){
                return array('ok' => 0, 'msg' => 'Unauthorized');
            }

            $apps = $this->getApps();
            if($apps['ok'] === 0) {
                return array('ok' => 0, 'msg' => 'Error in fetching apps');
            }
            
            if( (isset($params['from']) && !empty($params['from'])) && 
                (isset($params['to']) && !empty($params['to'])) 
            ){
                $applications = array_slice($apps['data'], $params['from'], $params['to'] + 1);
            }else{
                $applications = $apps['data'];
            }

            $resp = array();
            $main = new main_db();
            foreach($applications as $app) {
                $rec = array();
                $metrics = $this->metrics($app['app']);

                $rec['app'] = $app['app'];
                $rec['cluster'] = $main->get_db()->pegasus_printfld('users', 'cluster', 'app = :app', array('app' => $app['app']));
                $rec['date'] = time();
                if($metrics['ok'] == 1){
                    $rec['dbsize'] = $metrics['data']['dbsize'] ?? 0;
                    $rec['s3size'] = $metrics['data']['s3size'] ?? 0;
                    $rec['cache'] = $metrics['data']['cache'] ?? 0;
                    $rec['code'] = 200;
                }else{
                    $rec['dbsize'] = 0;
                    $rec['s3size'] = 0;
                    $rec['cache'] = 0;
                    $rec['code'] = 500;
                }
                $resp[] = $rec; 

            }
            unset($main);
            Utilities::unset_globals();
            return array('ok' => 1, 'data' => $resp);
        }
        
        public function whatIsMyIp(){
            return Utilities::what_is_my_ip();
        }

        /**
         * Επιστρέφει πληροφορίες για το redis της εφαρμογής
         * @param array $config{
         *    host: string,
         *    port: int,
         *    password: string
         * }
         * @return int
         */
        private function redis_info($config){
            $redis = new Redis();
            $redis->connect("tls://".$config['host'], $config['port']);
            $redis->auth($config['password']);
            $info = $redis->info();
            unset($config);
            unset($redis);
            return isset($info['used_memory']) ? $info['used_memory'] : 0;

        }

    }    