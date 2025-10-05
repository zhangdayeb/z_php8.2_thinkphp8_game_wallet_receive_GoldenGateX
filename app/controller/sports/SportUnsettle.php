<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportUnsettle extends BaseController
{
    // 未结算投注：一项将投注交易恢复为未结算状态的操作
    public function set_unsettle()
    {
        return 'it work!';
    }
}