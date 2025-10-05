<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportRefund extends BaseController
{
    // 退款：对投注交易的回滚操作
    public function set_refund()
    {
        return 'it work!';
    }
}