<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use think\facade\Route;

// 测试路由
Route::get('think', function () {
    return 'hello,ThinkPHP6!';
});

Route::get('hello/:name', 'index/hello');


// 钱包操作 
Route::rule('wallet/balance', 'wallet.WalletBalance/get_balance');              // 检索用户的最新余额
Route::rule('wallet/bet', 'wallet.WalletBet/set_bet');                          // 下注交易，从钱包余额中扣除金额
Route::rule('wallet/bet_result', 'wallet.WalletBetResult/set_bet_result');      // 下注交易请求，从用户余额中借记和/或贷记资金
Route::rule('wallet/rollback', 'wallet.WalletRollback/set_rollback');           // 对下注交易的回滚操作
Route::rule('wallet/adjustment', 'wallet.WalletAdjustment/set_adjustment');     // 对游戏回合中赢取金额的调整
Route::rule('wallet/bet_debit', 'wallet.WalletBetDebit/set_bet_debit');         // 扣款转账：入场时的扣除余额操作
Route::rule('wallet/bet_credit', 'wallet.WalletBetCredit/set_bet_credit');      // 加款转账：完成投注结算并更新用户余额操作


// 交易操作
Route::rule('transaction/list', 'transaction.TransactionList/get_list');        // 返回特定时间段内的交易列表
Route::rule('transaction/detail', 'transaction.TransactionDetail/get_detail');  // 获取特定投注交易的详细交易信息

// 体育博彩 API
Route::rule('sports/bet', 'sports.SportBet/set_bet');                           // 投注：一个从钱包余额中扣款的投注交易
Route::rule('sports/update-bet', 'sports.SportUpdateBet/set_update_bet');       // 确认投注：一个通过补充资金更新钱包余额的投注交易
Route::rule('sports/refund', 'sports.SportRefund/set_refund');                  // 退款：对投注交易的回滚操作
Route::rule('sports/settled', 'sports.SportSettled/set_settled');               // 结算投注：投注交易请求用于向用户的余额中增加资金或保持不变
Route::rule('sports/unsettle', 'sports.SportUnsettle/set_unsettle');            // 未结算投注：一项将投注交易恢复为未结算状态的操作
Route::rule('sports/resettle', 'sports.SportResettle/set_resettle');            // 重新结算：一项更新的投注交易请求用于调整用户余额包括资金的增加或扣除
Route::rule('sports/adjustment', 'sports.SportAdjustment/set_adjustment');      // 调整：对某一游戏回合中赢得金额的调整