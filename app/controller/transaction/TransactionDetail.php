<?php
namespace app\controller\transaction;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class TransactionDetail extends BaseController
{
    // 获取特定投注交易的详细交易信息
    public function get_detail()
    {
        return 'it work!';
    }
}