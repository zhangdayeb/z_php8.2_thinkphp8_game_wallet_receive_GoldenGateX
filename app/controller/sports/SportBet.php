<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportBet extends BaseController
{
    // 投注：一个从钱包余额中扣款的投注交易
    public function set_bet()
    {
        return 'it work!';
    }
}