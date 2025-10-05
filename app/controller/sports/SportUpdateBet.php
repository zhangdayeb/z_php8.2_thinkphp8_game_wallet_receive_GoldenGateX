<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportUpdateBet extends BaseController
{
    // 确认投注：一个通过补充资金更新钱包余额的投注交易
    public function set_update_bet()
    {
        return 'it work!';
    }
}