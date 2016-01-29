<?php 
/**
 * Cache file class .
 *
 * @ignore
 *
 * @author lesorb@hotmail.com
 * @version 1.0.0.1
 * @copyright Copyright (c) 2015-2017
 * @license (GPL)General Public License
 */
class CacheFile extends BeeCache{

    public $cachePath;
    public $cacheFileSuffix='.bin';
    protected $directoryLevel;//$directoryLevel;
    private $_gcProbability=100;
    private $_gced=false;

    protected function init() {
        parent::init();
        if($this->cachePath===null)
            $this->cachePath= dirname(__FILE__).DIRECTORY_SEPARATOR.'cache';
        if(!is_dir($this->cachePath))
            mkdir($this->cachePath,0755,true);
    }

    protected function setValue($key, $value, $expire = 0) {
        if(!$this->_gced && mt_rand(0,1000000)<$this->_gcProbability) {
            $this->gc();
            $this->_gced=true;
        }

        if($expire<=0)
            $expire=31536000;// 1 year
        $expire+=time();

        $cacheFile=$this->getCacheFile($key);
        if($this->directoryLevel>0)
            @mkdir(dirname($cacheFile),0777,true);
        if(@file_put_contents($cacheFile,$value,LOCK_EX)!==false) {
            @chmod($cacheFile,0777);
            return @touch($cacheFile,$expire);
        } else
            return false;
    }
    protected function getValue($key) {
        $cacheFile=$this->getCacheFile($key);
        if(($time=@filemtime($cacheFile))>time())
            return @file_get_contents($cacheFile);
        elseif($time>0)
            @unlink($cacheFile);
        return false;
    }
    protected function getCacheFile($key) {
        if($this->directoryLevel>0) {
            $base=$this->cachePath;
            for($i=0;$i<$this->directoryLevel;++$i) {
                if(($prefix=substr($key,$i+$i,2))!==false)
                    $base.=DIRECTORY_SEPARATOR.$prefix;
            }
            return $base.DIRECTORY_SEPARATOR.$key.$this->cacheFileSuffix;
        } else
            return $this->cachePath.DIRECTORY_SEPARATOR.$key.$this->cacheFileSuffix;
    }
}

abstract class BeeCache {
    public static $enable = true;
    public $keyPrefix;
    public $serializer;
    public $hashKey=true;
    public function __construct() {
        $this->init();
    }
    protected function init() {}
    protected function generateUniqueKey($key) {
        return $this->hashKey ? md5($this->keyPrefix.$key) : $this->keyPrefix.$key;
    }
    public function get($key) {
        if( self::$enable === false )
            return false;
        $value = $this->getValue($this->generateUniqueKey($key));
        if($value===false || $this->serializer===false)
            return $value;
        if($this->serializer===null)
            $value=unserialize($value);
        else
            $value=call_user_func($this->serializer[1], $value);
        if( is_array($value) ) {
            return $value[0];
        } else
            return false;
    }
    public function set($key, $value, $expire = 0) {
        if(self::$enable === false)
            return ;
        if ($this->serializer === null)
            $value = serialize(array($value));
        elseif ($this->serializer !== false)
            $value = call_user_func($this->serializer[0], array($value));
        return $this->setValue($this->generateUniqueKey($key), $value, $expire);
    }
    public function delete($id) {
        return $this->deleteValue($this->generateUniqueKey($id));
    }
    protected function setValue($key,$value,$expire) {
        throw new Exception('className does not support set() functionality.' );
    }

    protected function addValue($key,$value,$expire) {
        throw new Exception('className does not support add() functionality.' );
    }

    protected function deleteValue($key) {
        throw new Exception('className does not support delete() functionality.' );
    }
}