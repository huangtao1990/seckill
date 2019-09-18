<?php
include('base.php');
class Api extends Base
{
    //共享信息，存储在redis中,以hash表的形式存储，%s变量代表的是商品id
    static $userId;
    static $productId;

    static $REDIS_REMOTE_HT_KEY         = "product_%s";     //共享信息key
    static $REDIS_REMOTE_TOTAL_COUNT    = "total_count";    //商品总库存
    static $REDIS_REMOTE_USE_COUNT      = "used_count";     //已售库存
    static $REDIS_REMOTE_QUEUE          = "c_order_queue";  //创建订单队列

    static $APCU_LOCAL_STOCK    = "apcu_stock_%s";       //总共剩余库存

    static $APCU_LOCAL_USE      = "apcu_stock_use_%s";   //本地已售多少
    static $APCU_LOCAL_COUNT    = "apcu_total_count_%s"; //本地分库存分摊总数

    public function __construct($productId, $userId)
    {
        self::$REDIS_REMOTE_HT_KEY  = sprintf(self::$REDIS_REMOTE_HT_KEY, $productId);
        self::$APCU_LOCAL_STOCK     = sprintf(self::$APCU_LOCAL_STOCK, $productId);
        self::$APCU_LOCAL_USE       = sprintf(self::$APCU_LOCAL_USE, $productId);
        self::$APCU_LOCAL_COUNT     = sprintf(self::$APCU_LOCAL_COUNT, $productId);
        self::$APCU_LOCAL_COUNT     = sprintf(self::$APCU_LOCAL_COUNT, $productId);
        self::$userId               = $userId;
        self::$productId            = $productId;
    }
    static  function clear(){
	apcu_delete(self::$APCU_LOCAL_STOCK);
	apcu_delete(self::$APCU_LOCAL_USE);
	apcu_delete(self::$APCU_LOCAL_COUNT);
		
	}
    /*查剩余库存*/
    static function getStock()
    {
	$stockNum = apcu_fetch(self::$APCU_LOCAL_STOCK);
        if ($stockNum === false) {
            $stockNum = self::initStock();
        }
        self::output(['stock_num' => $stockNum]);
    }

    /*抢购-减库存*/
    static function buy()
    {
        $localStockNum = apcu_fetch(self::$APCU_LOCAL_COUNT);
        if ($localStockNum === false) {
            $localStockNum = self::init();
        }

        $localUse = apcu_inc(self::$APCU_LOCAL_USE);//本已卖 + 1
        if ($localUse > $localStockNum) {//抢购失败 大部分流量在此被拦截
		echo 1;
            self::output([], -1, '该商品已售完');
        }

        //同步已售库存 + 1；
        if (!self::incUseCount()) {//改失败，返回商品已售完
            self::output([], -1, '该商品已售完');
        }

        //写入创建订单队列
        self::conRedis()->lPush(self::$REDIS_REMOTE_QUEUE, json_encode(['user_id' => self::$userId, 'product_id' => self::$productId]));
        //返回抢购成功
        self::output([], 0, '抢购成功，请从订单中心查看订单');
    }

    /*创建订单*/
    /*查询订单*/
    /*总剩余库存同步本地，定时执行就可以*/
    static function sync()
    {
	$data = self::conRedis()->hMGet(self::$REDIS_REMOTE_HT_KEY, [self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT]);
        $num = $data['total_count'] - $data["used_count"];
        apcu_add(self::$APCU_LOCAL_STOCK, $num);
        self::output([], 0, '同步库存成功');
    }
    /*私有方法*/
    //库存同步
    private static function incUseCount()
    {
        //同步远端库存时，需要经过lua脚本，保证不会出现超卖现象
        $script = <<<eof
            local key = KEYS[1]
            local field1 = KEYS[2]
            local field2 = KEYS[3]
            local field1_val = redis.call('hget', key, field1)
            local field2_val = redis.call('hget', key, field2)
            if(field1_val>field2_val) then
                return redis.call('HINCRBY', key, field2,1)
            end
            return 0
eof;
        return self::conRedis()->eval($script,[self::$REDIS_REMOTE_HT_KEY,  self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT] , 3);
    }
    /*初始化本地数据*/
    private static function init()
    {
        apcu_add(self::$APCU_LOCAL_COUNT, 150);
        apcu_add(self::$APCU_LOCAL_USE, 0);
    }
    static  function initStock(){
        $data = self::conRedis()->hMGet(self::$REDIS_REMOTE_HT_KEY, [self::$REDIS_REMOTE_TOTAL_COUNT, self::$REDIS_REMOTE_USE_COUNT]);
        $num = $data['total_count']- $data["used_count"];
        apcu_add(self::$APCU_LOCAL_STOCK, $num);
        return $num;
    }

}

try{
$act = $_GET['act'];
$product_id = $_GET['product_id'];
$user_id = $_GET['user_id'];

$obj = new Api($product_id, $user_id);
if (method_exists($obj, $act)) {
    $obj::$act();
    die;
}
echo 'method_error!';
} catch (\Exception $e) {
    echo 'exception_error!';
    var_dump($e);
}



