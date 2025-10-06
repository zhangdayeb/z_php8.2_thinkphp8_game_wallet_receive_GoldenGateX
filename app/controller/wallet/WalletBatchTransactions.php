<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBatchTransactions extends BaseController
{
    /**
     * 批量交易处理
     * POST /api/batch-transactions
     */
    public function set_bet_credit()
    {
        try {
            // 记录请求
            Log::info('批量交易请求 - 开始', [
                'ip' => $this->request->ip(),
                'time' => date('Y-m-d H:i:s')
            ]);

            // 1. 验证请求方法
            if (!$this->request->isPost()) {
                return $this->error(400, 'BAD_REQUEST');
            }

            // 2. 验证认证
            $authCheck = $this->checkAuth();
            if ($authCheck !== true) {
                return $authCheck;
            }

            // 3. 获取并验证参数
            $params = $this->request->param();
            
            if (empty($params['userCode'])) {
                return $this->error(400, 'BAD_REQUEST');
            }
            
            if (empty($params['transactions']) || !is_array($params['transactions'])) {
                return $this->error(400, 'BAD_REQUEST');
            }

            $mainUserCode = trim($params['userCode']);
            $transactions = $params['transactions'];

            Log::info('批量交易信息', [
                'userCode' => $mainUserCode,
                'transactionCount' => count($transactions)
            ]);

            // 4. 开启事务
            Db::startTrans();
            
            try {
                // 5. 查询并锁定用户
                $user = Db::name('common_user')
                    ->where('name', $mainUserCode)
                    ->lock(true)
                    ->field('id, money, status')
                    ->find();

                if (empty($user)) {
                    Db::rollback();
                    return $this->error(2, 'USER_DOES_NOT_EXIST');
                }

                if ($user['status'] != 1) {
                    Db::rollback();
                    return $this->error(2, 'USER_DOES_NOT_EXIST');
                }

                $currentBalance = floatval($user['money']);
                $userId = $user['id'];
                
                // 6. 处理每个交易
                $processedTransactions = [];
                $totalAmountChange = 0;

                foreach ($transactions as $index => $transaction) {
                    // 验证单个交易参数
                    $validateResult = $this->validateTransaction($transaction, $index);
                    if ($validateResult !== true) {
                        Db::rollback();
                        return $validateResult;
                    }

                    // 检查用户代码是否一致
                    if (trim($transaction['userCode']) !== $mainUserCode) {
                        Log::warning('批量交易用户不一致', [
                            'mainUser' => $mainUserCode,
                            'transactionUser' => $transaction['userCode'],
                            'index' => $index
                        ]);
                        Db::rollback();
                        return $this->error(400, 'BAD_REQUEST');
                    }

                    $transactionCode = trim($transaction['transactionCode']);
                    $amount = floatval($transaction['amount']);

                    // 检查交易是否重复
                    $existingTransaction = Db::name('api_game_transactions')
                        ->where('transaction_id', $transactionCode)
                        ->find();

                    if ($existingTransaction) {
                        Log::warning('批量交易中发现重复交易', [
                            'transactionCode' => $transactionCode,
                            'index' => $index
                        ]);
                        Db::rollback();
                        return $this->error(6, 'DUPLICATE_TRANSACTION');
                    }

                    // 累计金额变化
                    $totalAmountChange += $amount;
                    
                    // 保存交易信息供后续处理
                    $processedTransactions[] = [
                        'transaction' => $transaction,
                        'amount' => $amount,
                        'transactionCode' => $transactionCode,
                        'index' => $index
                    ];
                }

                // 7. 检查总体余额是否足够
                $newBalance = $currentBalance + $totalAmountChange;
                if ($newBalance < 0) {
                    Log::warning('批量交易余额不足', [
                        'currentBalance' => $currentBalance,
                        'totalChange' => $totalAmountChange,
                        'wouldBe' => $newBalance
                    ]);
                    Db::rollback();
                    return $this->error(4, 'INSUFFICIENT_USER_BALANCE');
                }

                // 8. 更新用户余额
                $updateResult = Db::name('common_user')
                    ->where('id', $userId)
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                if (!$updateResult) {
                    Db::rollback();
                    return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                }

                Log::info('批量交易余额更新', [
                    'before' => $currentBalance,
                    'after' => $newBalance,
                    'change' => $totalAmountChange
                ]);

                // 9. 记录每个交易到数据库
                $balanceTracker = $currentBalance;
                
                foreach ($processedTransactions as $item) {
                    $trans = $item['transaction'];
                    $amount = $item['amount'];
                    $transactionCode = $item['transactionCode'];
                    
                    // 计算该交易前后余额
                    $balanceBefore = $balanceTracker;
                    $balanceAfter = $balanceTracker + $amount;
                    $balanceTracker = $balanceAfter;

                    // 记录到交易表
                    $transactionData = [
                        'transaction_id' => $transactionCode,
                        'member_id' => $userId,
                        'type' => $this->getTransactionType(
                            $amount,
                            filter_var($trans['isCanceled'], FILTER_VALIDATE_BOOLEAN)
                        ),
                        'amount' => abs($amount),
                        'status' => filter_var($trans['isCanceled'], FILTER_VALIDATE_BOOLEAN) 
                            ? 'cancelled' : 'completed',
                        'trace_id' => generateUuid(),
                        'bet_id' => $trans['historyId'],
                        'external_transaction_id' => $transactionCode,
                        'game_code' => trim($trans['gameCode']),
                        'round_id' => trim($trans['roundId']),
                        'remark' => json_encode([
                            'vendor_code' => trim($trans['vendorCode']),
                            'game_type' => intval($trans['gameType']),
                            'is_finished' => filter_var($trans['isFinished'], FILTER_VALIDATE_BOOLEAN),
                            'is_canceled' => filter_var($trans['isCanceled'], FILTER_VALIDATE_BOOLEAN),
                            'detail' => $trans['detail'] ?? '{}',
                            'created_at' => $trans['createdAt'],
                            'batch_index' => $item['index']
                        ]),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ];

                    $transactionId = Db::name('api_game_transactions')->insertGetId($transactionData);

                    if (!$transactionId) {
                        Log::error('批量交易记录失败', ['index' => $item['index']]);
                        Db::rollback();
                        return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                    }

                    // 记录到资金流水表
                    $moneyLogData = [
                        'member_id' => $userId,
                        'money' => abs($amount),
                        'money_before' => $balanceBefore,
                        'money_after' => $balanceAfter,
                        'money_type' => 'money',
                        'number_type' => $amount < 0 ? -1 : 1,
                        'operate_type' => 501,  // 游戏类型
                        'admin_id' => 0,
                        'model_name' => 'GameTransaction',
                        'model_id' => $transactionId,
                        'game_code' => trim($trans['gameCode']),
                        'description' => $this->getDescription(
                            $amount,
                            trim($trans['gameCode']),
                            filter_var($trans['isCanceled'], FILTER_VALIDATE_BOOLEAN)
                        ),
                        'remark' => json_encode([
                            'transaction_code' => $transactionCode,
                            'vendor_code' => trim($trans['vendorCode']),
                            'round_id' => trim($trans['roundId']),
                            'history_id' => $trans['historyId'],
                            'batch_transaction' => true,
                            'batch_index' => $item['index']
                        ]),
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s'),
                        'fanyong_flag' => 0
                    ];

                    $moneyLogId = Db::name('game_user_money_logs')->insertGetId($moneyLogData);

                    if (!$moneyLogId) {
                        Log::error('批量交易资金日志失败', ['index' => $item['index']]);
                        Db::rollback();
                        return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                    }

                    // 更新关联
                    Db::name('api_game_transactions')
                        ->where('id', $transactionId)
                        ->update(['money_log_id' => $moneyLogId]);
                }

                // 10. 提交事务
                Db::commit();

                Log::info('批量交易成功', [
                    'userCode' => $mainUserCode,
                    'transactionCount' => count($transactions),
                    'finalBalance' => $newBalance
                ]);

                // 11. 返回成功
                return $this->success($newBalance);

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('批量交易异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error(500, 'UNKNOWN_SERVER_ERROR');
        }
    }

    /**
     * 验证单个交易参数
     */
    private function validateTransaction($transaction, $index)
    {
        $required = [
            'userCode', 'vendorCode', 'gameCode', 'historyId',
            'roundId', 'gameType', 'transactionCode', 'amount', 'createdAt'
        ];

        foreach ($required as $field) {
            if (!isset($transaction[$field]) || $transaction[$field] === '') {
                Log::warning('批量交易参数缺失', [
                    'index' => $index,
                    'missingField' => $field
                ]);
                return $this->error(400, 'BAD_REQUEST');
            }
        }

        if (!isset($transaction['isFinished']) || !isset($transaction['isCanceled'])) {
            Log::warning('批量交易布尔参数缺失', ['index' => $index]);
            return $this->error(400, 'BAD_REQUEST');
        }

        $gameType = intval($transaction['gameType']);
        if (!in_array($gameType, [1, 2, 3, 4])) {
            Log::warning('批量交易游戏类型无效', [
                'index' => $index,
                'gameType' => $gameType
            ]);
            return $this->error(400, 'BAD_REQUEST');
        }

        return true;
    }

    /**
     * 验证认证
     */
    private function checkAuth()
    {
        // 获取Authorization头
        $authHeader = $this->request->header('Authorization');
        if (empty($authHeader)) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        // 解析Basic Auth
        if (!preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        // 解码
        $decoded = base64_decode($matches[1]);
        if ($decoded === false) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        // 分离clientId和clientSecret
        $credentials = explode(':', $decoded, 2);
        if (count($credentials) !== 2) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        $clientId = $credentials[0];
        $clientSecret = $credentials[1];

        // 获取请求域名
        $domain = $this->getCurrentDomain();

        // 查询API配置
        $apiConfig = Db::name('api_code_set')
            ->where('qianbao_url', $domain)
            ->where('is_enabled', 1)
            ->field('api_key, api_secret')
            ->find();

        if (empty($apiConfig)) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        // 验证凭据
        if ($clientId !== $apiConfig['api_key'] || $clientSecret !== $apiConfig['api_secret']) {
            return $this->error(401, 'UNAUTHORIZED');
        }

        return true;
    }

    /**
     * 获取交易类型
     */
    private function getTransactionType($amount, $isCanceled)
    {
        if ($isCanceled) {
            return 'rollback';
        }
        if ($amount < 0) {
            return 'bet';
        }
        if ($amount > 0) {
            return 'bet_result';
        }
        return 'adjustment';
    }

    /**
     * 获取交易描述
     */
    private function getDescription($amount, $gameCode, $isCanceled)
    {
        $absAmount = abs($amount);
        if ($isCanceled) {
            return "游戏回滚 - {$gameCode} - 金额: {$absAmount}";
        }
        if ($amount < 0) {
            return "游戏下注 - {$gameCode} - 金额: {$absAmount}";
        }
        if ($amount > 0) {
            return "游戏赢钱 - {$gameCode} - 金额: {$absAmount}";
        }
        return "游戏调整 - {$gameCode}";
    }

    /**
     * 获取当前域名
     */
    private function getCurrentDomain()
    {
        if (isset($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }
        if (isset($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }
        return $this->request->host(true) ?: '';
    }

    /**
     * 成功响应
     */
    private function success($balance)
    {
        return json([
            'success' => true,
            'message' => $balance,
            'errorCode' => 0
        ]);
    }

    /**
     * 错误响应
     */
    private function error($code, $message)
    {
        return json([
            'success' => false,
            'message' => $message,
            'errorCode' => $code
        ]);
    }
}