<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletRollback extends BaseController
{
    /**
     * 对下注交易的回滚操作
     * POST /wallet/rollback
     * 由游戏厂商调用，撤销先前的下注交易，恢复用户余额
     */
    public function set_rollback()
    {
        Log::info('Wallet ==> WalletRollback::set_rollback 开始处理交易回滚');
        Log::info('Wallet set_rollback - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_rollback - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_rollback - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_rollback - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_rollback - 请求数据获取完成');
            Log::debug('Wallet set_rollback - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_rollback - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_rollback - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_rollback - Content-Type不正确');
                Log::warning('Wallet set_rollback - 当前Content-Type: ' . $contentType);
                Log::warning('Wallet set_rollback - 期望Content-Type: application/json');
                
                return $this->errorResponse('', 'SC_INVALID_REQUEST');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_rollback - JSON解析失败');
                Log::error('Wallet set_rollback - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_rollback - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_INVALID_REQUEST');
            }

            Log::debug('Wallet set_rollback - JSON解析成功');
            Log::debug('Wallet set_rollback - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_rollback - 签名验证失败');
                Log::error('Wallet set_rollback - TraceId: ' . $traceId);
                Log::error('Wallet set_rollback - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数
            $requiredParams = [
                'traceId', 'transactionId', 'betId', 'externalTransactionId', 
                'roundId', 'gameCode', 'username', 'currency', 'timestamp'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || $requestData[$param] === '') {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_rollback - 缺少必需参数');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 缺少的参数: ' . implode(', ', $missingParams));
                Log::warning('Wallet set_rollback - 所有参数: ' . json_encode($requestData ?? []));
                
                return $this->errorResponse($traceId, 'SC_INVALID_REQUEST');
            }

            // 提取参数
            $transactionId = $requestData['transactionId'];
            $betId = $requestData['betId'];
            $externalTransactionId = $requestData['externalTransactionId'];
            $roundId = $requestData['roundId'];
            $gameCode = $requestData['gameCode'];
            $username = $requestData['username'];
            $currency = $requestData['currency'];
            $timestamp = intval($requestData['timestamp']);

            Log::info('Wallet set_rollback - 参数验证通过');
            Log::info('Wallet set_rollback - TraceId: ' . $traceId);
            Log::info('Wallet set_rollback - 用户名: ' . $username);
            Log::info('Wallet set_rollback - 交易ID: ' . $transactionId);
            Log::info('Wallet set_rollback - 下注ID: ' . $betId);
            Log::info('Wallet set_rollback - 游戏代码: ' . $gameCode);
            Log::info('Wallet set_rollback - 回合ID: ' . $roundId);

            // 第六步：业务参数验证
            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_rollback - 不支持的货币');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 用户名: ' . $username);
                Log::warning('Wallet set_rollback - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            // 第七步：检查回滚幂等性
            $existingRollback = $this->checkRollbackExists($transactionId);
            if ($existingRollback) {
                Log::info('Wallet set_rollback - 检测到重复回滚，返回已处理结果');
                Log::info('Wallet set_rollback - TraceId: ' . $traceId);
                Log::info('Wallet set_rollback - 交易ID: ' . $transactionId);
                Log::info('Wallet set_rollback - 原处理时间: ' . $existingRollback['created_at']);

                // 获取用户当前余额返回
                $currentUser = Db::name('ntp_common_user')
                    ->field('money,updated_at')
                    ->where('name', $username)
                    ->find();

                if ($currentUser) {
                    $currentTimestamp = strtotime($currentUser['updated_at']) * 1000;
                    
                    return json([
                        'traceId' => $traceId,
                        'status' => 'SC_OK',
                        'data' => [
                            'username' => $username,
                            'currency' => $this->getUserCurrency($currency, $username),
                            'balance' => moneyFloor($currentUser['money']),
                            'timestamp' => $currentTimestamp
                        ]
                    ]);
                }
            }

            // 第八步：查询用户信息
            $user = Db::name('ntp_common_user')
                ->field('id,name,money,status,updated_at')
                ->where('name', $username)
                ->find();

            if (!$user) {
                Log::warning('Wallet set_rollback - 用户不存在');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_rollback - 用户已被禁用');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 用户名: ' . $username);
                Log::warning('Wallet set_rollback - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            $currentBalance = floatval($user['money']);

            Log::info('Wallet set_rollback - 用户验证通过');
            Log::info('Wallet set_rollback - TraceId: ' . $traceId);
            Log::info('Wallet set_rollback - 用户名: ' . $username);
            Log::info('Wallet set_rollback - 用户ID: ' . $user['id']);
            Log::info('Wallet set_rollback - 用户余额: ' . $currentBalance);

            // 第九步：查找原始交易和对应的资金日志
            $originalData = $this->findOriginalTransactionWithMoneyLog($betId, $roundId, $gameCode, $username);
            
            if (!$originalData['transaction']) {
                Log::warning('Wallet set_rollback - 原始交易不存在');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - betId: ' . $betId);
                Log::warning('Wallet set_rollback - roundId: ' . $roundId);
                Log::warning('Wallet set_rollback - gameCode: ' . $gameCode);
                Log::warning('Wallet set_rollback - username: ' . $username);

                return $this->errorResponse($traceId, 'SC_TRANSACTION_NOT_EXISTS');
            }
            
            $originalTransaction = $originalData['transaction'];
            $originalMoneyLog = $originalData['money_log'];
            
            // 检查是否找到资金日志
            if (!$originalMoneyLog) {
                Log::warning('Wallet set_rollback - 原始交易对应的资金日志不存在');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 原交易ID: ' . $originalTransaction['id']);
                Log::warning('Wallet set_rollback - money_log_id: ' . ($originalTransaction['money_log_id'] ?? 'null'));

                return $this->errorResponse($traceId, 'SC_TRANSACTION_NOT_EXISTS');
            }
            
            Log::info('Wallet set_rollback - 原始交易验证通过');
            Log::info('Wallet set_rollback - TraceId: ' . $traceId);
            Log::info('Wallet set_rollback - 下注ID: ' . $betId);
            Log::info('Wallet set_rollback - 原交易详情');
            Log::info('Wallet set_rollback - 原交易ID: ' . $originalTransaction['id']);
            Log::info('Wallet set_rollback - 原交易类型: ' . $originalTransaction['type']);
            Log::info('Wallet set_rollback - 原交易金额: ' . $originalTransaction['amount']);
            Log::info('Wallet set_rollback - 原交易状态: ' . $originalTransaction['status']);
            Log::info('Wallet set_rollback - 资金日志详情');
            Log::info('Wallet set_rollback - 资金日志ID: ' . $originalMoneyLog['id']);
            Log::info('Wallet set_rollback - 操作金额: ' . $originalMoneyLog['money']);
            Log::info('Wallet set_rollback - 操作类型: ' . $originalMoneyLog['number_type']);
            Log::info('Wallet set_rollback - 操作前余额: ' . $originalMoneyLog['money_before']);
            Log::info('Wallet set_rollback - 操作后余额: ' . $originalMoneyLog['money_after']);

            // 第十步：精确回滚金额计算（修复版）
            $rollbackCalculation = $this->calculateRollbackAmountFixed($originalMoneyLog, $currentBalance);
            $rollbackAmount = $rollbackCalculation['rollbackAmount'];
            $newBalance = $rollbackCalculation['newBalance'];
            
            Log::info('Wallet set_rollback - 回滚金额计算完成');
            Log::info('Wallet set_rollback - TraceId: ' . $traceId);
            Log::info('Wallet set_rollback - 回滚金额: ' . $rollbackAmount);
            Log::info('Wallet set_rollback - 回滚后余额: ' . $newBalance);
            Log::info('Wallet set_rollback - 计算说明: ' . $rollbackCalculation['description']);
            
            // 检查余额是否为负（余额保护）
            if ($newBalance < 0) {
                Log::warning('Wallet set_rollback - 回滚后余额为负数，余额不足');
                Log::warning('Wallet set_rollback - TraceId: ' . $traceId);
                Log::warning('Wallet set_rollback - 用户名: ' . $username);
                Log::warning('Wallet set_rollback - 当前余额: ' . $currentBalance);
                Log::warning('Wallet set_rollback - 回滚后余额: ' . $newBalance);
                Log::warning('Wallet set_rollback - 回滚金额: ' . $rollbackAmount);

                return $this->errorResponse($traceId, 'SC_INSUFFICIENT_FUNDS');
            }

            // 第十一步：准备回滚元数据
            $rollbackData = [
                'traceId' => $traceId,
                'transactionId' => $transactionId,
                'betId' => $betId,
                'externalTransactionId' => $externalTransactionId,
                'roundId' => $roundId,
                'gameCode' => $gameCode,
                'username' => $username,
                'currency' => $currency,
                'timestamp' => $timestamp,
                'originalTransaction' => [
                    'id' => $originalTransaction['id'],
                    'type' => $originalTransaction['type'],
                    'amount' => $originalTransaction['amount'],
                    'created_at' => $originalTransaction['created_at']
                ],
                'originalMoneyLog' => [
                    'id' => $originalMoneyLog['id'],
                    'money' => $originalMoneyLog['money'],
                    'number_type' => $originalMoneyLog['number_type'],
                    'money_before' => $originalMoneyLog['money_before'],
                    'money_after' => $originalMoneyLog['money_after']
                ],
                'rollbackCalculation' => $rollbackCalculation
            ];

            Log::info('Wallet set_rollback - 开始执行回滚操作');
            Log::info('Wallet set_rollback - TraceId: ' . $traceId);
            Log::info('Wallet set_rollback - 用户ID: ' . $user['id']);
            Log::info('Wallet set_rollback - 当前余额: ' . $currentBalance);

            // 第十二步：执行回滚操作（事务）
            Db::startTrans();
            try {
                $currentDateTime = date('Y-m-d H:i:s');
                $logId = null;

                Log::info('Wallet set_rollback - 执行精确回滚操作');
                Log::info('Wallet set_rollback - TraceId: ' . $traceId);
                Log::info('Wallet set_rollback - 回滚前余额: ' . $currentBalance);
                Log::info('Wallet set_rollback - 回滚后余额: ' . $newBalance);
                Log::info('Wallet set_rollback - 余额变化: ' . $rollbackAmount);

                // 更新用户余额
                $updateResult = Db::name('ntp_common_user')
                    ->where('id', $user['id'])
                    ->where('money', $currentBalance) // 乐观锁，防止并发修改
                    ->update([
                        'money' => $newBalance,
                        'updated_at' => $currentDateTime
                    ]);

                if (!$updateResult) {
                    throw new \Exception('更新用户余额失败，可能存在并发修改');
                }

                // 记录回滚资金日志
                $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                    'member_id' => $user['id'],
                    'money' => abs($rollbackAmount), // 取绝对值
                    'money_before' => moneyFloor($currentBalance),
                    'money_after' => $newBalance,
                    'money_type' => 'money',
                    'number_type' => $rollbackAmount > 0 ? 1 : -1, // 正数为加，负数为减
                    'operate_type' => 12, // 12=交易回滚
                    'admin_id' => 0, // 系统操作
                    'game_code' => $gameCode, // 游戏类型
                    'model_name' => 'GameRollback',
                    'model_id' => $originalTransaction['id'],
                    'description' => '交易回滚：' . $rollbackCalculation['description'],
                    'remark' => json_encode($rollbackData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 更新原交易状态为已回滚
                Db::name('ntp_api_game_transactions')
                    ->where('id', $originalTransaction['id'])
                    ->update([
                        'status' => 'rolled_back',
                        'updated_at' => $currentDateTime
                    ]);

                // 记录回滚交易记录（防重复）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'rollback',
                    'amount' => $rollbackAmount,
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => $betId,
                    'external_transaction_id' => $externalTransactionId,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'remark' => json_encode($rollbackData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_rollback - 回滚操作成功完成');
                Log::info('Wallet set_rollback - TraceId: ' . $traceId);
                Log::info('Wallet set_rollback - 用户名: ' . $username);
                Log::info('Wallet set_rollback - 最终余额: ' . $newBalance);
                Log::info('Wallet set_rollback - 回滚金额: ' . $rollbackAmount);
                Log::info('Wallet set_rollback - 资金日志ID: ' . $logId);

                // 返回成功响应
                $responseTimestamp = strtotime($currentDateTime) * 1000;
                
                return json([
                    'traceId' => $traceId,
                    'status' => 'SC_OK',
                    'data' => [
                        'username' => $username,
                        'currency' => $this->getUserCurrency($currency, $username),
                        'balance' => moneyFloor($newBalance),
                        'timestamp' => $responseTimestamp
                    ]
                ]);

            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                
                Log::error('Wallet set_rollback - 回滚操作失败，事务已回滚');
                Log::error('Wallet set_rollback - TraceId: ' . $traceId);
                Log::error('Wallet set_rollback - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_rollback - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_rollback - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_rollback - 交易回滚发生异常');
            Log::error('Wallet set_rollback - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_rollback - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_rollback - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_rollback - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse('', 'SC_INTERNAL_ERROR');
        }
    }

    /**
     * 查找原始交易和对应的资金日志（保持不变）
     * @param string $betId 下注ID
     * @param string $roundId 回合ID
     * @param string $gameCode 游戏代码
     * @param string $username 用户名
     * @return array 包含交易和资金日志的数组
     */
    private function findOriginalTransactionWithMoneyLog(string $betId, string $roundId, string $gameCode, string $username)
    {
        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 查找原始交易和资金日志');
        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 下注ID: ' . $betId);
        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 回合ID: ' . $roundId);
        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 游戏代码: ' . $gameCode);
        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 用户名: ' . $username);

        $result = [
            'transaction' => null,
            'money_log' => null
        ];

        try {
            // 查找原始交易
            $transaction = Db::name('ntp_api_game_transactions')
                ->alias('gt')
                ->leftJoin('ntp_common_user m', 'gt.member_id = m.id')
                ->where('gt.bet_id', $betId)
                ->where('gt.round_id', $roundId)
                ->where('gt.game_code', $gameCode)
                ->where('m.name', $username)
                ->where('gt.status', 'completed')
                ->whereIn('gt.type', ['bet', 'bet_result', 'bet_credit', 'bet_debit'])
                ->field('gt.*')
                ->order('gt.created_at', 'desc')
                ->find();

            if ($transaction) {
                $result['transaction'] = $transaction;
                
                // 如果有关联的资金日志ID，尝试获取资金日志
                if (!empty($transaction['money_log_id'])) {
                    $moneyLog = Db::name('ntp_game_user_money_logs')
                        ->where('id', $transaction['money_log_id'])
                        ->find();
                    
                    if ($moneyLog) {
                        $result['money_log'] = $moneyLog;
                        
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 找到交易和资金日志');
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 交易ID: ' . $transaction['id']);
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 资金日志ID: ' . $moneyLog['id']);
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 交易前余额: ' . $moneyLog['money_before']);
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 交易后余额: ' . $moneyLog['money_after']);
                    } else {
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 找到交易但未找到对应的资金日志');
                        Log::debug('Wallet findOriginalTransactionWithMoneyLog - 资金日志ID: ' . $transaction['money_log_id']);
                    }
                } else {
                    Log::debug('Wallet findOriginalTransactionWithMoneyLog - 找到交易但没有关联的资金日志ID');
                }
            } else {
                Log::debug('Wallet findOriginalTransactionWithMoneyLog - 未找到原始交易');
            }

            return $result;

        } catch (\Exception $e) {
            Log::error('Wallet findOriginalTransactionWithMoneyLog - 查找异常: ' . $e->getMessage());
            return $result;
        }
    }

    /**
     * 精确回滚金额计算（修复版 - 仅基于资金日志）
     * @param array $originalMoneyLog 原始资金日志
     * @param float $currentBalance 当前余额
     * @return array 回滚计算结果
     */
    private function calculateRollbackAmountFixed($originalMoneyLog, $currentBalance)
    {
        Log::debug('Wallet calculateRollbackAmountFixed - 开始精确回滚计算');
        Log::debug('Wallet calculateRollbackAmountFixed - 当前余额: ' . $currentBalance);
        Log::debug('Wallet calculateRollbackAmountFixed - 原操作金额: ' . $originalMoneyLog['money']);
        Log::debug('Wallet calculateRollbackAmountFixed - 原操作类型: ' . $originalMoneyLog['number_type']);

        $originalMoney = floatval($originalMoneyLog['money']);
        $originalNumberType = intval($originalMoneyLog['number_type']);
        
        // 精确反向计算
        if ($originalNumberType == 1) {
            // 原来是加钱操作（+），回滚时需要减钱（-）
            $rollbackAmount = -$originalMoney;
            $description = "回滚加钱操作，扣除 {$originalMoney} 元";
        } elseif ($originalNumberType == -1) {
            // 原来是减钱操作（-），回滚时需要加钱（+）
            $rollbackAmount = $originalMoney;
            $description = "回滚减钱操作，退还 {$originalMoney} 元";
        } else {
            // 异常的number_type，保守处理
            $rollbackAmount = 0;
            $description = "异常的操作类型 {$originalNumberType}，不进行余额变动";
            Log::warning('Wallet calculateRollbackAmountFixed - 异常的number_type: ' . $originalNumberType);
        }

        $newBalance = moneyFloor($currentBalance + $rollbackAmount);

        $result = [
            'rollbackAmount' => $rollbackAmount,
            'newBalance' => $newBalance,
            'description' => $description,
            'method' => 'money_log_precise',
            'originalMoneyLog' => [
                'money' => $originalMoney,
                'number_type' => $originalNumberType,
                'money_before' => $originalMoneyLog['money_before'],
                'money_after' => $originalMoneyLog['money_after']
            ]
        ];

        Log::info('Wallet calculateRollbackAmountFixed - 精确计算完成');
        Log::info('Wallet calculateRollbackAmountFixed - 原操作: ' . ($originalNumberType == 1 ? '加钱' : '减钱') . ' ' . $originalMoney);
        Log::info('Wallet calculateRollbackAmountFixed - 回滚操作: ' . ($rollbackAmount > 0 ? '加钱' : '减钱') . ' ' . abs($rollbackAmount));
        Log::info('Wallet calculateRollbackAmountFixed - 当前余额: ' . $currentBalance);
        Log::info('Wallet calculateRollbackAmountFixed - 回滚后余额: ' . $newBalance);

        return $result;
    }

    /**
     * 检查回滚是否已存在（幂等性检查）
     * @param string $transactionId 回滚交易ID
     * @return array|null 已存在的回滚记录
     */
    private function checkRollbackExists(string $transactionId)
    {
        Log::debug('Wallet checkRollbackExists - 检查回滚幂等性');
        Log::debug('Wallet checkRollbackExists - 交易ID: ' . $transactionId);

        try {
            $rollback = Db::name('ntp_api_game_transactions')
                ->where('transaction_id', $transactionId)
                ->where('type', 'rollback')
                ->where('status', 'completed')
                ->find();

            if ($rollback) {
                Log::debug('Wallet checkRollbackExists - 找到已处理的回滚');
                Log::debug('Wallet checkRollbackExists - 交易ID: ' . $transactionId);
                Log::debug('Wallet checkRollbackExists - 处理时间: ' . $rollback['created_at']);
            } else {
                Log::debug('Wallet checkRollbackExists - 未找到重复回滚');
                Log::debug('Wallet checkRollbackExists - 交易ID: ' . $transactionId);
            }

            return $rollback;

        } catch (\Exception $e) {
            Log::error('Wallet checkRollbackExists - 检查回滚异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 验证货币是否支持
     * @param string $currency 货币代码
     * @return bool 验证结果
     */
    private function validateCurrency(string $currency): bool
    {
        Log::debug('Wallet validateCurrency - 验证货币类型');
        Log::debug('Wallet validateCurrency - 货币: ' . $currency);

        $supportedCurrencies = ['CNY', 'USD', 'EUR', 'THB', 'JPY', 'KRW', 'VND'];
        $isSupported = in_array(strtoupper($currency), $supportedCurrencies);
        
        Log::debug('Wallet validateCurrency - 验证结果: ' . ($isSupported ? '支持' : '不支持'));
        
        return $isSupported;
    }

    /**
     * 获取用户实际使用的货币
     * @param string $requestCurrency 请求中的货币
     * @param string $username 用户名
     * @return string 实际货币代码
     */
    private function getUserCurrency(string $requestCurrency, string $username): string
    {
        Log::debug('Wallet getUserCurrency - 获取用户货币');
        Log::debug('Wallet getUserCurrency - 请求货币: ' . $requestCurrency);
        Log::debug('Wallet getUserCurrency - 用户名: ' . $username);

        // 暂时默认返回CNY，后续可根据用户配置或系统配置返回
        return 'CNY';
    }

    /**
     * 构建错误响应
     * @param string $traceId 追踪ID
     * @param string $statusCode 状态码
     * @return \think\Response
     */
    private function errorResponse(string $traceId, string $statusCode)
    {
        $response = [
            'traceId' => $traceId,
            'status' => $statusCode
        ];

        Log::warning('Wallet errorResponse - 返回错误响应');
        Log::warning('Wallet errorResponse - TraceId: ' . $traceId);
        Log::warning('Wallet errorResponse - 状态码: ' . $statusCode);

        return json($response);
    }
}