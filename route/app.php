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
Route::rule('api/balance', 'wallet.WalletBalance/get_balance');                                         // 检索用户的最新余额
Route::rule('api/transaction', 'wallet.WalletTransaction/get_transaction');                             // 单次交易
Route::rule('api/batch-transactions', 'wallet.WalletBatchTransactions/get_batch_transactions');         // 批量交易