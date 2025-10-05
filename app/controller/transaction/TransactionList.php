<?php
namespace app\controller\transaction;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class TransactionList extends BaseController
{
    // 返回特定时间段内的交易列表
    public function get_list()
    {
        return 'it work!';
    }
}