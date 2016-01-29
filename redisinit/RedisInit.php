<?php
/**
 * Redis initialization class , it's compatible with redis 2.8* and 3.0*.
 *
 * @ignore
 *
 * @author lesorb@hotmail.com
 * @version 2.0.0.1
 * @copyright Copyright (c) 2015-2017
 * @license (GPL)General Public License
 */
class RedisInit {
    
    //redis
    private $redis = null;

    private $__timeout = 0;

    private $__cluster = false;

    private $__is_pipe_ready = false;

    private $__pipe_result = array();

    private $_master_servers = array();
    
    /**
     * single model 
     */
    private static $__models = array();
    
    public static function getInstance($className=__CLASS__) {
        if(isset(self::$__models[$className]))
            return self::$__models[$className];
        else
            return self::$__models[$className]=new $className();
    }
    
    protected function prefixed($k) {
        return $k;
    }

   /**
    * Gets the redis connection to use for caching
    * $config = array(
    *  'server' => '127.0.0.1' 
    *  'port'   => '6379' 
    * )
    * @return RedisConnection
    */
    public function getConnection( $key = '' ) {
        $_redis_config = include('conf/config.php');
        if (empty($_redis_config)) {
            throw new Exception(_('REDIS configuration error.'));
        }

        if ($this->redis === null) {
            $this->redis=new Redis();
            if(!$this->isConnected())
                $this->__connectActive($_redis_config);
            if ($_redis_config['cluster']) {
                $this->flushServers();
            } else {
                //select database.when is set not equal zreo
                if (!empty($_redis_config['database'])) {
                    if (!$this->redis->select(intval($_redis_config['database']))) {
                        throw new RedisException(sprintf(_("select database[%s] failed."),$_redis_config['database']));
                    }
                }
            }
            
            $this->__cluster = $_redis_config['cluster'];
            $this->__timeout = $_redis_config['timeout'];
        }

        if ($_redis_config['cluster'] && $key) {
            $master = les_getmaster($key, $this->redis, $this->_master_servers);
            $this->__connect($master, $this->__timeout);
        }

        return $this->redis;
    }
    
    private function __connectActive(array $_config) {
        foreach ($_config['master'] as $val) {
            $_val = explode(':', $val);
            if($this->__connect($_val, $_config['timeout'])) {
                break;
            }
        }
    }

    private function __connect($config, $timeout) {
        try {
            if(!$this->redis->connect($config[0], $config[1], $timeout)) {
                throw new Exception(_('REDIS configuration errors.'));
            } else
                return true;
        } catch (RedisException $e) {
            //to do throw exception
            // if ( $this->redis->IsConnected() ) {
            //  $this->redis->close();
            // }
        }
    }

    //to do cache the node ~ slots
    private function flushServers( $force = false ) {
        $fileCache = new CacheFile();
        if ( $force ) {
            # del cache this configuration file
            $fileCache->delete('bee2:redis3.configuration.nodes');
        }
        // retrieve this configuration from cache        
        if ( false === ($servers = $fileCache->get('bee2:redis3.configuration.nodes')) ) {

            $res = $this->getConnection()->rawCommand( 'CLUSTER', 'NODES' );
            if ( empty( $res ) ) {
                return false;
            }

            $servers = array();
            $_slave_server = array();
            $res = explode( "\n", $res );
            foreach( $res as $v ) {
                if ( empty( $v ) ) {
                    continue;
                }

                $item = explode( ' ', $v );
                if ( false === strpos( $item[2], 'master' ) ) {
                    $_slave_server[$item[3]] = $item;
                    continue;
                }

                $server = array( 'id' => $item[0],'master' => $item[1] );
                $min_max = explode( '-', end($item) );
                if (count($min_max) == 2) {
                    $slot['min'] = $min_max[0];
                    $slot['max'] = $min_max[1];
                } else {
                    $slot['min'] = $min_max[0];
                    $slot['max'] = $min_max[0];
                }
                $server['slots'] = $slot;

                $servers[$slot['min']] = $server;
            }

            ksort($servers);

            if ( empty( $servers ) ) {
                return false;
            }

            $_servers = $servers;
            $servers = array();
            foreach ($_servers as $_k=>$_server) {
                $_server['slave'] = $_slave_server[$_server['id']][1];
                $servers[] = $_server;
            }
            $fileCache->set('bee2:redis3.configuration.nodes',$servers );
        }
        
        $this->_master_servers = $servers;
        
        return true;
    }

    /**
     * 
     * @param string $key KEY
     * @param string|array $value 
     * @param int $timeOut 
     */
    public function set($key, $value, $timeOut = 0,$isJson=false) {
        $key = $this->prefixed($key);
        $value = $isJson ? json_encode($value, TRUE) : $value;
        $result = $this->getConnection($key)->set($key, $value);
        if ($timeOut > 0) 
            $this->getConnection()->setTimeout($key, $timeOut);
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }

    /**
     * KEY
     * @param string $key KEY
     */
    public function get($key,$isJson=false) {
        $key = $this->prefixed($key);
        $result = $this->getConnection($key)->get($key);
        $result = $isJson ? json_decode($result, TRUE) : $result;
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }
    
    public function keys($key){
        $key = $this->prefixed($key);
        $result = $this->getConnection($key)->keys($key);
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }
   
    /**
     * 
     * @param string $key KEY
     * @param string|array $value 
     * @param int $timeOut 
     */
    public function hset($_table, $key, $value, $isSerialize = true) {
        $_table = $this->prefixed($_table);
        if ($isSerialize) $value = serialize($value);
        $result = $this->getConnection($_table)->hset($_table, $key, $value);
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }

    /**
     * KEY
     * @param string $key KEY
     */
    public function hget($_table, $key, $isSerialize = true) {
        $_table = $this->prefixed($_table);
        $result = $this->getConnection($_table)->hget($_table, $key);
        if ($isSerialize) $result = unserialize($result);
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }
    
    /**
     * 
     * @param string $key KEY
     * @param string|array $value 
     * @param bool $right 
     */
    public function push($key, $value ,$right = true) {
        $key = $this->prefixed($key);
        $value = json_encode($value);
        $result = $right ? $this->getConnection($key)->rPush($key, $value) : $this->getConnection($key)->lPush($key, $value);
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $result;
            return $this;
        } else
            return $result;
    }
    
    /**
     * 
     * @param string $key KEY
     * @param bool $left 
     */
    public function pop($key , $left = true) {
        $key = $this->prefixed($key);
        $val = $left ? $this->getConnection($key)->lPop($key) : $this->getConnection($key)->rPop($key);        
        if( $this->__is_pipe_ready ){
            $this->__pipe_result[] = json_decode($val);
            return $this;
        } else
            return json_decode($val);
    }

    /**
     * redis publish by key
     * @param string key
     * @param mixed msg
     * @param boolean isJson
     * @return boolean
     */
    public function publish($key, $msg = null, $isJson = true) {
        $key = $this->prefixed($key);
        $msg = $isJson ? json_encode($msg) : $msg;
        if( $this->__is_pipe_ready ) {
            $this->__pipe_result[] = $this->getConnection($key)->publish( $key, $msg );
            return $this;
        } else
            return $this->getConnection($key)->publish( $key, $msg );
    }
    
    /**
     * @inheritdoc
     */
    public function setValue($key, $value, $expire) {
        $key = $this->prefixed($key);
        $value = serialize($value);
        if ($expire == 0) {
            return (bool) $this->getConnection($key)->set($key, $value);
        } else {
            return (bool) $this->getConnection($key)->set($key, $value, $expire);
        }
    }

     /**
     * @inheritdoc
     */
    public function getValue($key) {
        $key = $this->prefixed($key);
        $res = $this->getConnection($key)->get($key);
        return unserialize($res);
    }

    public function pipeline() {
        if( $this->__cluster ) {
            $this->__is_pipe_ready = true;
            $this->__pipe_result = array();
        } else
            $this->getConnection()->multi(Redis::PIPELINE);
        return $this;
    }

    public function multi() {
        if( $this->__cluster ) {
            $this->__is_pipe_ready = true;
            $this->__pipe_result = array();
            return $this;
        } else
            return $this->getConnection()->multi(Redis::PIPELINE);
    }

    public function exec() {
        if( $this->__cluster ) {
            $this->__is_pipe_ready = false;
            return $this->__pipe_result;
        } else 
            return $this->redis->exec();
    }
    
    /*
    hmset
    hexists
    hdel
    delete
    flushAll
    hincrby
    incr
    decr   
    exists
    zadd
    zsize
    zcount
    zrem
    zRank
    zRange
    zRevRange
    zRangeByScore
    zRevRangeByScore
    zCard
    hgetall
    close
    */
    public function __call($method, $args) {
        if( isset($args[0]) ) {
            $args[0] = $this->prefixed($args[0]);
            $this->getConnection($args[0]);
        }
        if ( method_exists($this->redis, $method) ) {
            $result = call_user_func_array(array($this->redis, $method), $args);
            if( $this->__is_pipe_ready ) {
                $this->__pipe_result[] = $result;
                return $this;
            } else 
                return $result;
        } else {
            throw new Exception( sprintf(_("Invalid Function %s"),$method) );
        }
    }

    /**
     * redis
     * redis?
     * redis
     */
    public function redis() {
        return $this->getConnection();
    }
}

if(false === function_exists('les_getmaster'))
{
    function les_getmaster($key, Redis $Redis, $_master_servers) {
        if(method_exists($Redis, 'getMasterByKey')) {
            $address = $Redis->getMasterByKey($key, $_master_servers);
		} else {
            $slot = les_crc16($key) % 16384;
            $address = floor($slot / intval(16384/count($_master_servers)));
        }
		return explode(':', $_master_servers[$address]['master']);
    }
}
if(false === function_exists('les_crc16'))
{
    function les_crc16($ptr) {
        $crc_table = array(
            0x0,  0x1021,  0x2042,  0x3063,  0x4084,  0x50a5,  0x60c6,  0x70e7,
            0x8108,  0x9129,  0xa14a,  0xb16b,  0xc18c,  0xd1ad,  0xe1ce,  0xf1ef,
            0x1231,  0x210,  0x3273,  0x2252,  0x52b5,  0x4294,  0x72f7,  0x62d6,
            0x9339,  0x8318,  0xb37b,  0xa35a,  0xd3bd,  0xc39c,  0xf3ff,  0xe3de,
            0x2462,  0x3443,  0x420,  0x1401,  0x64e6,  0x74c7,  0x44a4,  0x5485,
            0xa56a,  0xb54b,  0x8528,  0x9509,  0xe5ee,  0xf5cf,  0xc5ac,  0xd58d,
            0x3653,  0x2672,  0x1611,  0x630,  0x76d7,  0x66f6,  0x5695,  0x46b4,
            0xb75b,  0xa77a,  0x9719,  0x8738,  0xf7df,  0xe7fe,  0xd79d,  0xc7bc,
            0x48c4,  0x58e5,  0x6886,  0x78a7,  0x840,  0x1861,  0x2802,  0x3823,
            0xc9cc,  0xd9ed,  0xe98e,  0xf9af,  0x8948,  0x9969,  0xa90a,  0xb92b,
            0x5af5,  0x4ad4,  0x7ab7,  0x6a96,  0x1a71,  0xa50,  0x3a33,  0x2a12,
            0xdbfd,  0xcbdc,  0xfbbf,  0xeb9e,  0x9b79,  0x8b58,  0xbb3b,  0xab1a,
            0x6ca6,  0x7c87,  0x4ce4,  0x5cc5,  0x2c22,  0x3c03,  0xc60,  0x1c41,
            0xedae,  0xfd8f,  0xcdec,  0xddcd,  0xad2a,  0xbd0b,  0x8d68,  0x9d49,
            0x7e97,  0x6eb6,  0x5ed5,  0x4ef4,  0x3e13,  0x2e32,  0x1e51,  0xe70,
            0xff9f,  0xefbe,  0xdfdd,  0xcffc,  0xbf1b,  0xaf3a,  0x9f59,  0x8f78,
            0x9188,  0x81a9,  0xb1ca,  0xa1eb,  0xd10c,  0xc12d,  0xf14e,  0xe16f,
            0x1080,  0xa1,  0x30c2,  0x20e3,  0x5004,  0x4025,  0x7046,  0x6067,
            0x83b9,  0x9398,  0xa3fb,  0xb3da,  0xc33d,  0xd31c,  0xe37f,  0xf35e,
            0x2b1,  0x1290,  0x22f3,  0x32d2,  0x4235,  0x5214,  0x6277,  0x7256,
            0xb5ea,  0xa5cb,  0x95a8,  0x8589,  0xf56e,  0xe54f,  0xd52c,  0xc50d,
            0x34e2,  0x24c3,  0x14a0,  0x481,  0x7466,  0x6447,  0x5424,  0x4405,
            0xa7db,  0xb7fa,  0x8799,  0x97b8,  0xe75f,  0xf77e,  0xc71d,  0xd73c,
            0x26d3,  0x36f2,  0x691,  0x16b0,  0x6657,  0x7676,  0x4615,  0x5634,
            0xd94c,  0xc96d,  0xf90e,  0xe92f,  0x99c8,  0x89e9,  0xb98a,  0xa9ab,
            0x5844,  0x4865,  0x7806,  0x6827,  0x18c0,  0x8e1,  0x3882,  0x28a3,
            0xcb7d,  0xdb5c,  0xeb3f,  0xfb1e,  0x8bf9,  0x9bd8,  0xabbb,  0xbb9a,
            0x4a75,  0x5a54,  0x6a37,  0x7a16,  0xaf1,  0x1ad0,  0x2ab3,  0x3a92,
            0xfd2e,  0xed0f,  0xdd6c,  0xcd4d,  0xbdaa,  0xad8b,  0x9de8,  0x8dc9,
            0x7c26,  0x6c07,  0x5c64,  0x4c45,  0x3ca2,  0x2c83,  0x1ce0,  0xcc1,
            0xef1f,  0xff3e,  0xcf5d,  0xdf7c,  0xaf9b,  0xbfba,  0x8fd9,  0x9ff8,
            0x6e17,  0x7e36,  0x4e55,  0x5e74,  0x2e93,  0x3eb2,  0xed1,  0x1ef0);
        $crc = 0x0000;
        for ($i = 0; $i < strlen($ptr); $i++)
            $crc =  $crc_table[(($crc>>8) ^ ord($ptr[$i]))] ^ (($crc<<8) & 0x00FFFF);
        return $crc;
    }
}
