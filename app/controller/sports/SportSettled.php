<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportSettled extends BaseController
{
    // 结算投注：投注交易请求用于向用户的余额中增加资金或保持不变
    public function set_settled()
    {
        return 'it work!';
    }
}