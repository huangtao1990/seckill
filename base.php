<?php

class Base
{
    static $redisObj;

    static function conRedis($config = array())
    {
        if (self::$redisObj) return self::$redisObj;
        self::$redisObj = new \Redis();
        self::$redisObj->connect("127.0.0.1", 6379);
        return self::$redisObj;
    }

    static function output($data = array(), $errNo = 0, $errMsg = 'ok')
    {
        $res['errno'] = $errNo;
        $res['errmsg'] = $errMsg;
        $res['data'] = $data;
        echo json_encode($res);exit();
    }
}

?>
