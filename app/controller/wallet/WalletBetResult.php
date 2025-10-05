<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBetResult extends BaseController
{
    /**
     * 下注交易请求，从用户余额中借记和/或贷记资金
     * POST /wallet/bet_result
     * 由游戏厂商调用，根据游戏结果更新用户余额
     */
    public function set_bet_result()
    {
        Log::info('Wallet ==> WalletBetResult::set_bet_result 开始处理游戏结算');
        Log::info('Wallet set_bet_result - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_bet_result - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_bet_result - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_bet_result - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_bet_result - 请求数据获取完成');
            Log::debug('Wallet set_bet_result - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_bet_result - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_bet_result - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_bet_result - Content-Type不正确');
                Log::warning('Wallet set_bet_result - 当前Content-Type: ' . $contentType);
                
                return $this->errorResponse('', 'SC_INVALID_REQUEST');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_bet_result - JSON解析失败');
                Log::error('Wallet set_bet_result - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_bet_result - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_INVALID_REQUEST');
            }

            Log::debug('Wallet set_bet_result - JSON解析成功');
            Log::debug('Wallet set_bet_result - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_bet_result - 签名验证失败');
                Log::error('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_result - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数
            $requiredParams = [
                'traceId', 'username', 'transactionId', 'betId', 'externalTransactionId', 'roundId',
                'betAmount', 'winAmount', 'effectiveTurnover', 'resultType', 'isFreespin',
                'isEndRound', 'currency', 'token', 'gameCode', 'betTime'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param])) {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_bet_result - 缺少必需参数');
                Log::warning('Wallet set_bet_result - 缺少的参数: ' . implode(', ', $missingParams));
                
                return $this->errorResponse($traceId, 'SC_INVALID_REQUEST');
            }

            // 提取参数
            $username = $requestData['username'];
            $transactionId = $requestData['transactionId'];
            $betId = $requestData['betId'];
            $externalTransactionId = $requestData['externalTransactionId'];
            $roundId = $requestData['roundId'];
            $betAmount = floatval($requestData['betAmount']);
            $winAmount = floatval($requestData['winAmount']);
            $effectiveTurnover = floatval($requestData['effectiveTurnover']);
            $jackpotAmount = moneyFloor($requestData['jackpotAmount'] ?? 0);
            $resultType = $requestData['resultType'];
            $isFreespin = intval($requestData['isFreespin']);
            $isEndRound = intval($requestData['isEndRound']);
            $currency = $requestData['currency'];
            $token = $requestData['token'];
            $gameCode = $requestData['gameCode'];
            $betTime = intval($requestData['betTime']);
            $settledTime = intval($requestData['settledTime'] ?? 0);

            // 计算净赢金额
            $winLoss = $winAmount - $betAmount;

            Log::info('Wallet set_bet_result - 参数验证通过');
            Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_result - 用户名: ' . $username);
            Log::info('Wallet set_bet_result - 交易ID: ' . $transactionId);
            Log::info('Wallet set_bet_result - 下注ID: ' . $betId);
            Log::info('Wallet set_bet_result - 结果类型: ' . $resultType);
            Log::info('Wallet set_bet_result - 下注金额: ' . $betAmount);
            Log::info('Wallet set_bet_result - 获胜金额: ' . $winAmount);
            Log::info('Wallet set_bet_result - 奖池金额: ' . $jackpotAmount);
            Log::info('Wallet set_bet_result - 净赢金额: ' . $winLoss);
            Log::info('Wallet set_bet_result - 游戏代码: ' . $gameCode);
            Log::info('Wallet set_bet_result - 回合ID: ' . $roundId);
            Log::info('Wallet set_bet_result - 是否免费游戏: ' . ($isFreespin ? '是' : '否'));

            // 第六步：业务参数验证
            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_bet_result - 不支持的货币');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            if (!$this->validateUserToken($username, $token)) {
                Log::warning('Wallet set_bet_result - Token验证失败');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_INVALID_TOKEN');
            }

            if (!$this->validateResultType($resultType)) {
                Log::warning('Wallet set_bet_result - 不支持的结果类型');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 结果类型: ' . $resultType);

                return $this->errorResponse($traceId, 'SC_INVALID_REQUEST');
            }

            // 第七步：检查交易幂等性
            $existingTransaction = $this->checkTransactionExists($transactionId);
            if ($existingTransaction) {
                Log::info('Wallet set_bet_result - 检测到重复交易，返回已处理结果');
                Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_result - 交易ID: ' . $transactionId);
                Log::info('Wallet set_bet_result - 原处理时间: ' . $existingTransaction['created_at']);

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
                Log::warning('Wallet set_bet_result - 用户不存在');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_bet_result - 用户已被禁用');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 用户名: ' . $username);
                Log::warning('Wallet set_bet_result - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            $currentBalance = floatval($user['money']);

            Log::info('Wallet set_bet_result - 用户验证通过');
            Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_result - 用户名: ' . $username);
            Log::info('Wallet set_bet_result - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_result - 当前余额: ' . $currentBalance);

            // 修复点1：当resultType为END时，验证betId对应的投注是否存在
            if (strtoupper($resultType) === 'END') {
                Log::info('Wallet set_bet_result - END类型：开始验证betId对应的投注是否存在');
                Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_result - betId: ' . $betId);
                
                $betTransaction = $this->validateBetIdExists($betId, $user['id'], $gameCode);
                if (!$betTransaction) {
                    Log::warning('Wallet set_bet_result - END类型：找不到对应的投注记录');
                    Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                    Log::warning('Wallet set_bet_result - betId: ' . $betId);
                    Log::warning('Wallet set_bet_result - 用户ID: ' . $user['id']);
                    Log::warning('Wallet set_bet_result - 游戏代码: ' . $gameCode);
                    
                    return $this->errorResponse($traceId, 'SC_TRANSACTION_NOT_EXISTS');
                }
                
                Log::info('Wallet set_bet_result - END类型：找到对应的投注记录');
                Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_result - 投注记录ID: ' . $betTransaction['id']);
                Log::info('Wallet set_bet_result - 投注交易ID: ' . $betTransaction['transaction_id']);
            }

            // 第九步：检查是否存在提前扣款记录
            $existingBetTransaction = $this->checkExistingBetTransaction($betId, $roundId, $user['id'], $gameCode, $betAmount);
            
            Log::info('Wallet set_bet_result - 提前扣款检查完成');
            Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_result - 是否找到提前扣款: ' . ($existingBetTransaction ? '是' : '否'));
            
            if ($existingBetTransaction) {
                Log::info('Wallet set_bet_result - 提前扣款记录详情');
                Log::info('Wallet set_bet_result - 原交易ID: ' . $existingBetTransaction['transaction_id']);
                Log::info('Wallet set_bet_result - 原交易金额: ' . $existingBetTransaction['amount']);
                Log::info('Wallet set_bet_result - 原交易时间: ' . $existingBetTransaction['created_at']);
            }

            // 第十步：根据 resultType 计算余额变化
            $balanceCalculation = $this->calculateBalanceChangeByResultType(
                $resultType,
                $currentBalance,
                $betAmount,
                $winAmount,
                $jackpotAmount,
                $winLoss,
                $isFreespin,
                $existingBetTransaction
            );

            // 检查余额不足的情况（仅当需要扣款时）
            if ($balanceCalculation['needCheck'] && $currentBalance < $balanceCalculation['requiredBalance']) {
                Log::warning('Wallet set_bet_result - 用户余额不足，无法进行游戏结算');
                Log::warning('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_result - 用户名: ' . $username);
                Log::warning('Wallet set_bet_result - 当前余额: ' . $currentBalance);
                Log::warning('Wallet set_bet_result - 需要余额: ' . $balanceCalculation['requiredBalance']);
                Log::warning('Wallet set_bet_result - 结果类型: ' . $resultType);

                return $this->errorResponse($traceId, 'SC_INSUFFICIENT_FUNDS');
            }

            Log::info('Wallet set_bet_result - 余额计算完成');
            Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_result - 计算结果: ' . json_encode($balanceCalculation, JSON_UNESCAPED_UNICODE));

            // 第十一步：准备元数据记录到remark
            $remarkData = [
                'transactionId' => $transactionId,
                'betId' => $betId,
                'externalTransactionId' => $externalTransactionId,
                'roundId' => $roundId,
                'betAmount' => $betAmount,
                'winAmount' => $winAmount,
                'winLoss' => $winLoss,
                'effectiveTurnover' => $effectiveTurnover,
                'jackpotAmount' => $jackpotAmount,
                'resultType' => $resultType,
                'isFreespin' => $isFreespin,
                'isEndRound' => $isEndRound,
                'gameCode' => $gameCode,
                'betTime' => $betTime,
                'settledTime' => $settledTime,
                'traceId' => $traceId,
                'balanceCalculation' => $balanceCalculation,
                'existingBetTransaction' => $existingBetTransaction ? [
                    'transaction_id' => $existingBetTransaction['transaction_id'],
                    'amount' => $existingBetTransaction['amount'],
                    'created_at' => $existingBetTransaction['created_at']
                ] : null
            ];

            Log::info('Wallet set_bet_result - 开始执行结算操作');
            Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_result - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_result - 操作前余额: ' . $currentBalance);
            Log::info('Wallet set_bet_result - 总余额变化: ' . $balanceCalculation['totalChange']);

            // 第十二步：执行结算操作（事务）
            Db::startTrans();
            try {
                $newBalance = moneyFloor($currentBalance + $balanceCalculation['totalChange']);
                $currentDateTime = date('Y-m-d H:i:s');
                $logId = null;

                // 如果有余额变化，才更新余额和记录日志
                if ($balanceCalculation['totalChange'] != 0) {
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

                    // 记录资金变动日志
                    $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                        'member_id' => $user['id'],
                        'money' => abs($balanceCalculation['totalChange']), // 取绝对值
                        'money_before' => moneyFloor($currentBalance),
                        'money_after' => $newBalance,
                        'money_type' => 'money',
                        'number_type' => $balanceCalculation['totalChange'] > 0 ? 1 : -1, // 正数为加，负数为减
                        'operate_type' => 11, // 11=游戏结算
                        'admin_id' => 0, // 系统操作
                        'game_code' => $gameCode, // 游戏类型
                        'model_name' => 'GameBetResult',
                        'model_id' => 0,
                        'description' => $balanceCalculation['description'],
                        'remark' => json_encode($remarkData, JSON_UNESCAPED_UNICODE),
                        'created_at' => $currentDateTime,
                        'updated_at' => $currentDateTime
                    ]);

                    Log::info('Wallet set_bet_result - 余额更新完成');
                    Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                    Log::info('Wallet set_bet_result - 操作后余额: ' . $newBalance);
                    Log::info('Wallet set_bet_result - 资金日志ID: ' . $logId);
                } else {
                    // 无余额变动，只记录交易
                    Log::info('Wallet set_bet_result - 无余额变动，仅记录交易');
                    Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                    Log::info('Wallet set_bet_result - 结果类型: ' . $resultType);
                    Log::info('Wallet set_bet_result - 计算说明: ' . $balanceCalculation['description']);
                }

                // 记录交易处理记录（防重复）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'bet_result',
                    'amount' => $balanceCalculation['totalChange'],
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => $betId,
                    'external_transaction_id' => $externalTransactionId,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'remark' => json_encode($remarkData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_bet_result - 结算操作成功完成');
                Log::info('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_result - 用户名: ' . $username);
                Log::info('Wallet set_bet_result - 最终余额: ' . $newBalance);
                Log::info('Wallet set_bet_result - 余额变化: ' . $balanceCalculation['totalChange']);

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
                
                Log::error('Wallet set_bet_result - 结算操作失败，事务已回滚');
                Log::error('Wallet set_bet_result - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_result - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_bet_result - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_bet_result - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_bet_result - 游戏结算发生异常');
            Log::error('Wallet set_bet_result - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_bet_result - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_bet_result - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_bet_result - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse('', 'SC_INTERNAL_ERROR');
        }
    }

    /**
     * 修复点1：验证betId对应的投注是否存在（用于END类型）
     * @param string $betId 下注ID
     * @param int $userId 用户ID
     * @param string $gameCode 游戏代码
     * @return array|null 投注记录
     */
    private function validateBetIdExists(string $betId, int $userId, string $gameCode)
    {
        Log::debug('Wallet validateBetIdExists - 验证betId投注是否存在');
        Log::debug('Wallet validateBetIdExists - betId: ' . $betId);
        Log::debug('Wallet validateBetIdExists - 用户ID: ' . $userId);
        Log::debug('Wallet validateBetIdExists - 游戏代码: ' . $gameCode);

        try {
            $transaction = Db::name('ntp_api_game_transactions')
                ->where('bet_id', $betId)
                ->where('member_id', $userId)
                ->where('game_code', $gameCode)
                ->where('status', 'completed')
                ->whereIn('type', ['bet', 'bet_result']) // 查找投注或结算记录
                ->find();

            if ($transaction) {
                Log::debug('Wallet validateBetIdExists - 找到对应的投注记录');
                Log::debug('Wallet validateBetIdExists - 交易ID: ' . $transaction['id']);
                Log::debug('Wallet validateBetIdExists - 交易类型: ' . $transaction['type']);
                Log::debug('Wallet validateBetIdExists - 创建时间: ' . $transaction['created_at']);
            } else {
                Log::debug('Wallet validateBetIdExists - 未找到对应的投注记录');
                Log::debug('Wallet validateBetIdExists - betId: ' . $betId);
            }

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Wallet validateBetIdExists - 验证betId异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查是否存在提前扣款记录
     * @param string $betId 下注ID
     * @param string $roundId 回合ID
     * @param int $userId 用户ID
     * @param string $gameCode 游戏代码
     * @param float $betAmount 下注金额
     * @return array|null 提前扣款记录
     */
    private function checkExistingBetTransaction(string $betId, string $roundId, int $userId, string $gameCode, float $betAmount)
    {
        Log::debug('Wallet checkExistingBetTransaction - 检查提前扣款记录');
        Log::debug('Wallet checkExistingBetTransaction - 下注ID: ' . $betId);
        Log::debug('Wallet checkExistingBetTransaction - 回合ID: ' . $roundId);
        Log::debug('Wallet checkExistingBetTransaction - 用户ID: ' . $userId);
        Log::debug('Wallet checkExistingBetTransaction - 游戏代码: ' . $gameCode);
        Log::debug('Wallet checkExistingBetTransaction - 下注金额: ' . $betAmount);

        try {
            $transaction = Db::name('ntp_api_game_transactions')
                ->where('bet_id', $betId)
                ->where('round_id', $roundId)
                ->where('member_id', $userId)
                ->where('game_code', $gameCode)
                ->where('type', 'bet')
                ->where('status', 'completed')
                ->where('amount', $betAmount) // 扣款金额应该与下注金额相等
                ->find();

            if ($transaction) {
                Log::debug('Wallet checkExistingBetTransaction - 找到提前扣款记录');
                Log::debug('Wallet checkExistingBetTransaction - 交易ID: ' . $transaction['transaction_id']);
                Log::debug('Wallet checkExistingBetTransaction - 交易金额: ' . $transaction['amount']);
                Log::debug('Wallet checkExistingBetTransaction - 创建时间: ' . $transaction['created_at']);
            } else {
                Log::debug('Wallet checkExistingBetTransaction - 未找到提前扣款记录');
            }

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Wallet checkExistingBetTransaction - 检查提前扣款记录异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 根据 resultType 计算余额变化（修复版 - 增加jackpotAmount参数）
     * @param string $resultType 结果类型
     * @param float $currentBalance 当前余额
     * @param float $betAmount 下注金额
     * @param float $winAmount 获胜金额
     * @param float $jackpotAmount 奖池金额
     * @param float $winLoss 净赢金额
     * @param int $isFreespin 是否免费游戏
     * @param array|null $existingBetTransaction 提前扣款记录
     * @return array 计算结果
     */
    private function calculateBalanceChangeByResultType(
        string $resultType, 
        float $currentBalance, 
        float $betAmount, 
        float $winAmount, 
        float $jackpotAmount, // 新增参数
        float $winLoss, 
        int $isFreespin, 
        $existingBetTransaction = null
    ) {
        Log::debug('Wallet calculateBalanceChangeByResultType - 开始根据resultType计算余额变化');
        Log::debug('Wallet calculateBalanceChangeByResultType - 结果类型: ' . $resultType);
        Log::debug('Wallet calculateBalanceChangeByResultType - 当前余额: ' . $currentBalance);
        Log::debug('Wallet calculateBalanceChangeByResultType - 下注金额: ' . $betAmount);
        Log::debug('Wallet calculateBalanceChangeByResultType - 获胜金额: ' . $winAmount);
        Log::debug('Wallet calculateBalanceChangeByResultType - 奖池金额: ' . $jackpotAmount); // 新增日志
        Log::debug('Wallet calculateBalanceChangeByResultType - 净赢金额: ' . $winLoss);
        Log::debug('Wallet calculateBalanceChangeByResultType - 是否免费游戏: ' . $isFreespin);
        Log::debug('Wallet calculateBalanceChangeByResultType - 是否有提前扣款: ' . ($existingBetTransaction ? '是' : '否'));

        $result = [
            'totalChange' => 0,
            'description' => '',
            'details' => [],
            'needCheck' => false,      // 是否需要检查余额
            'requiredBalance' => 0     // 需要的最低余额
        ];

        // 核心逻辑：根据 resultType 决定操作
        switch (strtoupper($resultType)) {
            case 'WIN':
                // WIN - 赢了，加额度（无需扣除下注额度，直接加奖金）
                $result = $this->handleWinResult($winAmount, $jackpotAmount, $isFreespin, $existingBetTransaction);
                break;

            case 'BET_WIN':
                // BET_WIN - 下注+赢，需要先扣除下注额度，再加奖金
                $result = $this->handleBetWinResult($betAmount, $winAmount, $jackpotAmount, $winLoss, $isFreespin, $existingBetTransaction, $currentBalance);
                break;

            case 'BET_LOSE':
                // BET_LOSE - 下注+输，只扣除下注额度，不加奖金
                $result = $this->handleBetLoseResult($betAmount, $jackpotAmount, $isFreespin, $existingBetTransaction, $currentBalance);
                break;

            case 'LOSE':
                // LOSE - 输了，无需任何动作
                $result = $this->handleLoseResult($jackpotAmount, $isFreespin, $existingBetTransaction);
                break;

            case 'END':
                // END - 结算通知，无需加减额度
                $result = $this->handleEndResult($jackpotAmount);
                break;

            default:
                // 未知的 resultType，不进行任何操作
                $result['description'] = '未知的结果类型：' . $resultType;
                $result['details'] = ['resultType' => $resultType, 'action' => 'no_action'];
                Log::warning('Wallet calculateBalanceChangeByResultType - 未知的结果类型: ' . $resultType);
                break;
        }

        Log::info('Wallet calculateBalanceChangeByResultType - 计算完成');
        Log::info('Wallet calculateBalanceChangeByResultType - 结果类型: ' . $resultType);
        Log::info('Wallet calculateBalanceChangeByResultType - 总变化: ' . $result['totalChange']);
        Log::info('Wallet calculateBalanceChangeByResultType - 描述: ' . $result['description']);
        Log::info('Wallet calculateBalanceChangeByResultType - 是否需要检查余额: ' . ($result['needCheck'] ? '是' : '否'));

        return $result;
    }

    /**
     * 处理 WIN 结果类型
     * WIN - 赢了，加额度（无需扣除下注额度，直接加奖金）
     */
    private function handleWinResult(float $winAmount, float $jackpotAmount, int $isFreespin, $existingBetTransaction = null)
    {
        $totalWinAmount = $winAmount + $jackpotAmount; // 累加奖池金额
        
        $result = [
            'totalChange' => $totalWinAmount,
            'description' => 'WIN结果：直接增加获胜金额和奖池金额',
            'details' => [
                'resultType' => 'WIN',
                'winAmount' => $winAmount,
                'jackpotAmount' => $jackpotAmount,
                'totalWinAmount' => $totalWinAmount,
                'action' => 'add_win_and_jackpot_amount',
                'calculation' => "获胜金额({$winAmount}) + 奖池金额({$jackpotAmount}) = {$totalWinAmount}"
            ],
            'needCheck' => false,
            'requiredBalance' => 0
        ];

        Log::info('Wallet handleWinResult - WIN结果处理');
        Log::info('Wallet handleWinResult - 获胜金额: ' . $winAmount);
        Log::info('Wallet handleWinResult - 奖池金额: ' . $jackpotAmount);
        Log::info('Wallet handleWinResult - 总变化: ' . $result['totalChange']);

        return $result;
    }

    /**
     * 处理 BET_WIN 结果类型
     * BET_WIN - 下注+赢，需要先扣除下注额度，再加奖金
     */
    private function handleBetWinResult(float $betAmount, float $winAmount, float $jackpotAmount, float $winLoss, int $isFreespin, $existingBetTransaction = null, float $currentBalance = 0)
    {
        $totalWinAmount = $winAmount + $jackpotAmount; // 累加奖池金额
        
        if ($existingBetTransaction) {
            // 已经扣过款，只加奖金
            $result = [
                'totalChange' => $totalWinAmount,
                'description' => 'BET_WIN结果(已扣款)：仅增加获胜金额和奖池金额',
                'details' => [
                    'resultType' => 'BET_WIN',
                    'betAmount' => $betAmount,
                    'winAmount' => $winAmount,
                    'jackpotAmount' => $jackpotAmount,
                    'totalWinAmount' => $totalWinAmount,
                    'action' => 'add_win_and_jackpot_amount_only',
                    'calculation' => "获胜金额({$winAmount}) + 奖池金额({$jackpotAmount}) = {$totalWinAmount} - 已通过/bet接口扣过款",
                    'existingBet' => true
                ],
                'needCheck' => false,
                'requiredBalance' => 0
            ];

            Log::info('Wallet handleBetWinResult - BET_WIN结果(已扣款)');
            Log::info('Wallet handleBetWinResult - 仅增加总获胜金额: ' . $totalWinAmount);
        } else {
            // 未扣款，需要扣除下注金额再加奖金
            $netChange = $totalWinAmount - $betAmount;
            
            $result = [
                'totalChange' => $netChange,
                'description' => 'BET_WIN结果(未扣款)：扣除下注金额+增加获胜金额和奖池金额',
                'details' => [
                    'resultType' => 'BET_WIN',
                    'betAmount' => $betAmount,
                    'winAmount' => $winAmount,
                    'jackpotAmount' => $jackpotAmount,
                    'totalWinAmount' => $totalWinAmount,
                    'netChange' => $netChange,
                    'action' => 'deduct_bet_add_win_and_jackpot',
                    'calculation' => "获胜金额({$winAmount}) + 奖池金额({$jackpotAmount}) - 下注金额({$betAmount}) = {$netChange}",
                    'existingBet' => false
                ],
                'needCheck' => true,
                'requiredBalance' => $betAmount
            ];

            Log::info('Wallet handleBetWinResult - BET_WIN结果(未扣款)');
            Log::info('Wallet handleBetWinResult - 扣除下注: ' . $betAmount);
            Log::info('Wallet handleBetWinResult - 增加获胜: ' . $winAmount);
            Log::info('Wallet handleBetWinResult - 增加奖池: ' . $jackpotAmount);
            Log::info('Wallet handleBetWinResult - 净变化: ' . $netChange);
        }

        return $result;
    }

    /**
     * 处理 BET_LOSE 结果类型
     * BET_LOSE - 下注+输，只扣除下注额度，但可能有奖池金额
     */
    private function handleBetLoseResult(float $betAmount, float $jackpotAmount, int $isFreespin, $existingBetTransaction = null, float $currentBalance = 0)
    {
        if ($existingBetTransaction) {
            // 已经扣过款，只看是否有奖池金额
            $result = [
                'totalChange' => $jackpotAmount,
                'description' => 'BET_LOSE结果(已扣款)：' . ($jackpotAmount > 0 ? '仅增加奖池金额' : '无需操作'),
                'details' => [
                    'resultType' => 'BET_LOSE',
                    'betAmount' => $betAmount,
                    'jackpotAmount' => $jackpotAmount,
                    'action' => $jackpotAmount > 0 ? 'add_jackpot_amount_only' : 'no_action',
                    'calculation' => $jackpotAmount > 0 ? 
                        "奖池金额({$jackpotAmount}) - 已通过/bet接口扣过款" : 
                        "已通过/bet接口扣过款({$betAmount})，无需再操作",
                    'existingBet' => true
                ],
                'needCheck' => false,
                'requiredBalance' => 0
            ];

            Log::info('Wallet handleBetLoseResult - BET_LOSE结果(已扣款)');
            Log::info('Wallet handleBetLoseResult - 下注金额已扣: ' . $betAmount);
            Log::info('Wallet handleBetLoseResult - 奖池金额: ' . $jackpotAmount);
            Log::info('Wallet handleBetLoseResult - 总变化: ' . $jackpotAmount);
        } else {
            // 未扣款，需要扣除下注金额，但加上奖池金额
            $netChange = $jackpotAmount - $betAmount;
            
            $result = [
                'totalChange' => $netChange,
                'description' => 'BET_LOSE结果(未扣款)：扣除下注金额' . ($jackpotAmount > 0 ? '+增加奖池金额' : ''),
                'details' => [
                    'resultType' => 'BET_LOSE',
                    'betAmount' => $betAmount,
                    'jackpotAmount' => $jackpotAmount,
                    'netChange' => $netChange,
                    'action' => $jackpotAmount > 0 ? 'deduct_bet_add_jackpot' : 'deduct_bet_amount',
                    'calculation' => $jackpotAmount > 0 ? 
                        "奖池金额({$jackpotAmount}) - 下注金额({$betAmount}) = {$netChange}" :
                        "扣除下注金额({$betAmount})",
                    'existingBet' => false
                ],
                'needCheck' => true,
                'requiredBalance' => $betAmount
            ];

            Log::info('Wallet handleBetLoseResult - BET_LOSE结果(未扣款)');
            Log::info('Wallet handleBetLoseResult - 扣除下注金额: ' . $betAmount);
            Log::info('Wallet handleBetLoseResult - 奖池金额: ' . $jackpotAmount);
            Log::info('Wallet handleBetLoseResult - 净变化: ' . $netChange);
        }

        return $result;
    }

    /**
     * 处理 LOSE 结果类型
     * LOSE - 输了，但可能有奖池金额
     */
    private function handleLoseResult(float $jackpotAmount, int $isFreespin, $existingBetTransaction = null)
    {
        $result = [
            'totalChange' => $jackpotAmount,
            'description' => 'LOSE结果：' . ($jackpotAmount > 0 ? '仅增加奖池金额' : '无需任何操作'),
            'details' => [
                'resultType' => 'LOSE',
                'jackpotAmount' => $jackpotAmount,
                'action' => $jackpotAmount > 0 ? 'add_jackpot_amount' : 'no_action',
                'calculation' => $jackpotAmount > 0 ? "奖池金额({$jackpotAmount})" : '输了，无需加减额度'
            ],
            'needCheck' => false,
            'requiredBalance' => 0
        ];

        Log::info('Wallet handleLoseResult - LOSE结果');
        Log::info('Wallet handleLoseResult - 奖池金额: ' . $jackpotAmount);
        Log::info('Wallet handleLoseResult - 总变化: ' . $jackpotAmount);

        return $result;
    }

    /**
     * 处理 END 结果类型
     * END - 结算通知，无需加减额度（包括jackpotAmount）
     */
    private function handleEndResult(float $jackpotAmount)
    {
        $result = [
            'totalChange' => 0,  // 修复：END类型不进行任何余额变动
            'description' => 'END结果：结算通知，无需操作',
            'details' => [
                'resultType' => 'END',
                'jackpotAmount' => $jackpotAmount,
                'action' => 'no_action',
                'calculation' => '结算通知，无需加减额度（忽略jackpotAmount）'
            ],
            'needCheck' => false,
            'requiredBalance' => 0
        ];

        Log::info('Wallet handleEndResult - END结果');
        Log::info('Wallet handleEndResult - 奖池金额: ' . $jackpotAmount);
        Log::info('Wallet handleEndResult - 总变化: 0 (END类型不变动余额)');

        return $result;
    }

    /**
     * 检查交易是否已存在（幂等性检查）
     * @param string $transactionId 交易ID
     * @return array|null 已存在的交易记录
     */
    private function checkTransactionExists(string $transactionId)
    {
        Log::debug('Wallet checkTransactionExists - 检查交易幂等性');
        Log::debug('Wallet checkTransactionExists - 交易ID: ' . $transactionId);

        try {
            $transaction = Db::name('ntp_api_game_transactions')
                ->where('transaction_id', $transactionId)
                ->where('type', 'bet_result')
                ->where('status', 'completed')
                ->find();

            if ($transaction) {
                Log::debug('Wallet checkTransactionExists - 找到已处理的交易');
                Log::debug('Wallet checkTransactionExists - 交易ID: ' . $transactionId);
                Log::debug('Wallet checkTransactionExists - 处理时间: ' . $transaction['created_at']);
            } else {
                Log::debug('Wallet checkTransactionExists - 未找到重复交易');
                Log::debug('Wallet checkTransactionExists - 交易ID: ' . $transactionId);
            }

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Wallet checkTransactionExists - 检查交易异常: ' . $e->getMessage());
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
        return in_array(strtoupper($currency), $supportedCurrencies);
    }

    /**
     * 验证用户token
     * @param string $username 用户名
     * @param string $token 用户token
     * @return bool 验证结果
     */
    private function validateUserToken(string $username, string $token): bool
    {
        Log::debug('Wallet validateUserToken - 验证用户Token');
        Log::debug('Wallet validateUserToken - 用户名: ' . $username);
        Log::debug('Wallet validateUserToken - Token前缀: ' . substr($token, 0, 8) . '...');

        // 暂时默认返回true，后续可扩展为实际token验证逻辑
        return true;
    }

    /**
     * 验证结果类型是否有效
     * @param string $resultType 结果类型
     * @return bool 验证结果
     */
    private function validateResultType(string $resultType): bool
    {
        Log::debug('Wallet validateResultType - 验证结果类型');
        Log::debug('Wallet validateResultType - 结果类型: ' . $resultType);

        $validResultTypes = ['WIN', 'BET_WIN', 'BET_LOSE', 'LOSE', 'END'];
        return in_array(strtoupper($resultType), $validResultTypes);
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