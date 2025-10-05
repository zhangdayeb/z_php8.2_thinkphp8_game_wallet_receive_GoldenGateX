<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportAdjustment extends BaseController
{
    // 调整：对某一游戏回合中赢得金额的调整
    public function set_adjustment()
    {
        return 'it work!';
    }
}