<?php
namespace app\controller\sports;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class SportResettle extends BaseController
{
    // 重新结算：一项更新的投注交易请求用于调整用户余额包括资金的增加或扣除
    public function set_resettle()
    {
        return 'it work!';
    }
}