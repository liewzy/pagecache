<?php
//
// Copyright (c) 2013 Kerry Schwab & BudgetNeon.com. All rights reserved.
// This program is free software; you can redistribute it and/or modify it
// under the terms of the the FreeBSD License .
// You may obtain a copy of the full license at:
//     http://www.freebsd.org/copyright/freebsd-license.html
//
//
class PageCache {
    private $expire='14400'   ; // expire time, in seconds 14400 = 4 hours
    private $lang='en'        ; // default language for site
    private $currency='USD'   ; // default currency for site
    private $addcomment = true; // set to true to add a comment to the bottom
                                // of cached pages with info and expire time
    private $skip_urls= array('#checkout/#','#product/compare#');

    private $cachefile=null;   // null specifically meaning "not known yet"
    private $oktocache=null;   // null specifically meaning "not known yet"

    // contstructor
    public function PageCache() {
        $this->cachefolder=DIR_CACHE. 'pagecache/';
        // store cacheability in a private variable
        $this->oktocache=$this->OkToCache();
    }
  
    // null error handler to trap specific errors
    public function nullhandler($errno, $errstr, $errfile, $errline) {
        ;
    }

    //
    // returns true if the url being requested is something
    // we're allowed to cache.  We don't, for example, cache
    // https pages, or pages where the user is logged in, etc.
    public function OkToCache() {
        // don't retest if called more than once
        if ($this->oktocache != null) {
            return $this->oktocache;
        }
        // only cache GET requests
        if(!empty($_SERVER['REQUEST_METHOD']) &&
            $_SERVER['REQUEST_METHOD'] != 'GET') {
            $this->oktocache=false;
            return $this->oktocache;
        } 
        // don't cache secure pages
        if(!empty($_SERVER['HTTPS']) || 
            (array_key_exists('HTTPS', $_SERVER) && $_SERVER['HTTPS']=='on')) {
            $this->oktocache=false;
            return $this->oktocache;
        }
        // start session
        if (!session_id()) {
            ini_set('session.use_cookies', 'On');
            ini_set('session.use_trans_sid', 'Off');
            session_set_cookie_params(0, '/');
            session_start();
        }
        // don't cache for logged in customers or affiliates
        if(!empty($_SESSION['customer_id']) ||
            !empty($_SESSION['affiliate_id'])) {
            $this->oktocache=false;
            return $this->oktocache;
        }  
        // don't cache if affiliate page, or cart has items in it
        if (!empty($_GET['affiliate']) || !empty($_SESSION['cart']))  {
            $this->oktocache=false;
            return $this->oktocache;
        } 
        // don't cache if we match one of the url patterns to skip
        foreach ($this->skip_urls as $urlpattern) {
            if (preg_match($urlpattern,$_SERVER['REQUEST_URI'])) {
                $this->oktocache=false;
                return $this->oktocache;
            }
        }
        // got here, so it must be okay to cache
        // not that while "ok to cache", other problems, later,
        // may cause this not to be cached. Like a 404 response,
        // for example
        $this->oktocache=true;
        return $this->oktocache;
    }

    public function ServeFromCache() {
        if (! $this->OkToCache()) {
            return false;
        }
        $domain = $_SERVER['HTTP_HOST'];
        $url = http_build_query($_GET);
        $md5=md5($url);
        $subfolder=substr($md5,0,1).'/'.substr($md5,1,1).'/';
        $cacheFile = $this->cachefolder . $subfolder . $domain . '_' . 
            $this->lang . '_' . $this->currency . '_' . $md5 . '.html';
        if (file_exists($cacheFile)) {
            if (time() - $this->expire < filemtime($cacheFile) ){
                // flush and disable the output buffer
                while(@ob_end_flush());
                readfile($cacheFile);
                return true;
            } else {
                // remove the stale cache file
                @unlink($cacheFile);
                $this->cachefile=$cacheFile;
                return false;
            }
        } else {
            $this->cachefile=$cacheFile;
            return false;
        }
    }

    private function RedirectOutput($buffer) {
        $this->buffer=$buffer;
        fwrite($this->outfp, $this->buffer);
        if ($this->addcomment == true) {
            fwrite($this->outfp, 
                  "\n<!--cache [". htmlspecialchars($_SERVER['REQUEST_URI']) . 
                  "] expires: ".
                  date("Y-m-d H:i:s e",time()+$this->expire).'-->'
            );
        }
    }

    public function CachePage($response) {
        // only cache good pages...for example, don't cache 404 responses
        if (http_response_code() != 200) {
            return false;
        }
        if ($this->cachefile != null) {
            $temp=$this->cachefile . '.lock';
            $pparts = pathinfo($temp);
            // make the directory path if it doesn't exist
            if (!is_dir($pparts['dirname'])) {
                mkdir($pparts['dirname'], 0755, true);
            }
            // get opencart to be quiet about fopen failures
            $ohandler=set_error_handler(array($this, 'nullhandler'));
            // prevent race conditions by opening first as a 
            // lockfile (via the 'x' flag to fopen), then renaming 
            // the file once we're done
            $fp=@fopen($temp,'x');
            set_error_handler($ohandler);
            if ($fp != false) {
                $this->outfp=$fp;
                ob_start(array($this,'RedirectOutput'));
                $response->output();
                while(@ob_end_flush());
                fclose($fp);
                rename($temp,$this->cachefile);
                return true;
            }  
            return false;
        } 
        return false;
    }
}
?>
