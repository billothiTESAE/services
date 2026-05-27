<?php 
    /**
     * @param string $dirname
     */
    function pegasus_delete_directory($dirname) {
        //Προσθέτουμε το παρακάτω log για να δούμε αν η εφαρμογή προσπαθεί να διαγράψει το tmp
        $tmp = rtrim($dirname, '/');

        $dir_handle = false;
        if (is_dir($dirname)){
            $dir_handle = opendir($dirname);
        }
        if (!$dir_handle){
            return false;
        }

        while($file = readdir($dir_handle)) {
            if ($file != "." && $file != "..") {
                if (!is_dir($dirname."/".$file)){
                    unlink($dirname."/".$file);
                }
                else{
                    pegasus_delete_directory($dirname.'/'.$file); // phpcs:ignore PUDStandard.Functions.ForbiddenFunctions
                }
            }
        }
        closedir($dir_handle);
        rmdir($dirname);
        return true;
    }