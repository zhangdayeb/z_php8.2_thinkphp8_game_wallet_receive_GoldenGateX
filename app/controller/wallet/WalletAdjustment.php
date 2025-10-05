<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletAdjustment extends BaseController
{
    /**
     * 对游戏回合中赢取金额的调整
     * POST /wallet/adjustment
     * 由游戏厂商调用，对已完成的游戏回合进行金额调整
     */
    public function set_adjustment()
    {
        Log::info('Wallet ==> WalletAdjustment::set_adjustment 开始处理游戏金额调整');
        Log::info('Wallet set_adjustment - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_adjustment - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_adjustment - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_adjustment - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_adjustment - 请求数据获取完成');
            Log::debug('Wallet set_adjustment - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_adjustment - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_adjustment - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_adjustment - Content-Type不正确');
                Log::warning('Wallet set_adjustment - 当前Content-Type: ' . $contentType);
                Log::warning('Wallet set_adjustment - 期望Content-Type: application/json');
                
                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_adjustment - JSON解析失败');
                Log::error('Wallet set_adjustment - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_adjustment - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            Log::debug('Wallet set_adjustment - JSON解析成功');
            Log::debug('Wallet set_adjustment - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_adjustment - 签名验证失败');
                Log::error('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::error('Wallet set_adjustment - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数
            $requiredParams = [
                'traceId', 'username', 'transactionId', 'externalTransactionId', 
                'roundId', 'amount', 'currency', 'gameCode', 'timestamp'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || $requestData[$param] === '') {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_adjustment - 缺少必需参数');
                Log::warning('Wallet set_adjustment - 缺少的参数: ' . implode(', ', $missingParams));
                Log::warning('Wallet set_adjustment - 所有参数: ' . json_encode($requestData ?? []));
                
                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            // 提取参数
            $username = $requestData['username'];
            $transactionId = $requestData['transactionId'];
            $externalTransactionId = $requestData['externalTransactionId'];
            $roundId = $requestData['roundId'];
            $amount = floatval($requestData['amount']); // 关键字段：调整金额
            $currency = $requestData['currency'];
            $gameCode = $requestData['gameCode'];
            $timestamp = intval($requestData['timestamp']);

            Log::info('Wallet set_adjustment - 参数验证通过');
            Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
            Log::info('Wallet set_adjustment - 用户名: ' . $username);
            Log::info('Wallet set_adjustment - 交易ID: ' . $transactionId);
            Log::info('Wallet set_adjustment - 回合ID: ' . $roundId);
            Log::info('Wallet set_adjustment - 调整金额: ' . $amount);
            Log::info('Wallet set_adjustment - 游戏代码: ' . $gameCode);
            Log::info('Wallet set_adjustment - 外部交易ID: ' . $externalTransactionId);

            // 第六步：业务参数验证
            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_adjustment - 不支持的货币');
                Log::warning('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::warning('Wallet set_adjustment - 用户名: ' . $username);
                Log::warning('Wallet set_adjustment - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            // 第七步：检查调整幂等性
            $existingAdjustment = $this->checkAdjustmentExists($transactionId);
            if ($existingAdjustment) {
                Log::info('Wallet set_adjustment - 检测到重复调整，返回已处理结果');
                Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::info('Wallet set_adjustment - 交易ID: ' . $transactionId);
                Log::info('Wallet set_adjustment - 原处理时间: ' . $existingAdjustment['created_at']);

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
                Log::warning('Wallet set_adjustment - 用户不存在');
                Log::warning('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::warning('Wallet set_adjustment - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_adjustment - 用户已被禁用');
                Log::warning('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::warning('Wallet set_adjustment - 用户名: ' . $username);
                Log::warning('Wallet set_adjustment - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            $currentBalance = floatval($user['money']);

            Log::info('Wallet set_adjustment - 用户验证通过');
            Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
            Log::info('Wallet set_adjustment - 用户名: ' . $username);
            Log::info('Wallet set_adjustment - 用户ID: ' . $user['id']);
            Log::info('Wallet set_adjustment - 用户余额: ' . $currentBalance);

            // 第九步：验证roundId是否存在（新增：必须存在才能调整）
            $roundTransaction = $this->validateRoundIdExists($roundId, $user['id'], $gameCode);
            
            if (!$roundTransaction) {
                Log::warning('Wallet set_adjustment - roundId对应的交易不存在');
                Log::warning('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::warning('Wallet set_adjustment - roundId: ' . $roundId);
                Log::warning('Wallet set_adjustment - 用户ID: ' . $user['id']);
                Log::warning('Wallet set_adjustment - 游戏代码: ' . $gameCode);

                return $this->errorResponse($traceId, 'SC_TRANSACTION_NOT_EXISTS');
            }

            Log::info('Wallet set_adjustment - roundId验证通过');
            Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
            Log::info('Wallet set_adjustment - roundId: ' . $roundId);
            Log::info('Wallet set_adjustment - 关联交易详情');
            Log::info('Wallet set_adjustment - 交易ID: ' . $roundTransaction['id']);
            Log::info('Wallet set_adjustment - 交易类型: ' . $roundTransaction['type']);
            Log::info('Wallet set_adjustment - 交易金额: ' . $roundTransaction['amount']);
            Log::info('Wallet set_adjustment - 创建时间: ' . $roundTransaction['created_at']);

            // 第十步：检查余额是否充足（仅当需要扣款时）
            if ($amount < 0 && $currentBalance < abs($amount)) {
                Log::warning('Wallet set_adjustment - 用户余额不足');
                Log::warning('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::warning('Wallet set_adjustment - 用户名: ' . $username);
                Log::warning('Wallet set_adjustment - 当前余额: ' . $currentBalance);
                Log::warning('Wallet set_adjustment - 需要扣除: ' . abs($amount));

                return $this->errorResponse($traceId, 'SC_INSUFFICIENT_FUNDS');
            }

            // 第十一步：准备调整元数据
            $adjustmentData = [
                'traceId' => $traceId,
                'transactionId' => $transactionId,
                'externalTransactionId' => $externalTransactionId,
                'roundId' => $roundId,
                'amount' => $amount,
                'currency' => $currency,
                'gameCode' => $gameCode,
                'username' => $username,
                'timestamp' => $timestamp,
                'roundTransaction' => [
                    'id' => $roundTransaction['id'],
                    'type' => $roundTransaction['type'],
                    'amount' => $roundTransaction['amount'],
                    'created_at' => $roundTransaction['created_at']
                ]
            ];

            Log::info('Wallet set_adjustment - 开始执行调整操作');
            Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
            Log::info('Wallet set_adjustment - 用户ID: ' . $user['id']);
            Log::info('Wallet set_adjustment - 调整前余额: ' . $currentBalance);
            Log::info('Wallet set_adjustment - 调整金额: ' . $amount);

            // 第十二步：执行调整操作（事务）
            Db::startTrans();
            try {
                $newBalance = moneyFloor($currentBalance + $amount); // 直接相加
                $currentDateTime = date('Y-m-d H:i:s');
                $logId = null;

                // 如果有金额变动，才更新余额和记录日志
                if ($amount != 0) {
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

                    // 记录调整资金日志
                    $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                        'member_id' => $user['id'],
                        'money' => abs($amount), // 取绝对值
                        'money_before' => moneyFloor($currentBalance),
                        'money_after' => $newBalance,
                        'money_type' => 'money',
                        'number_type' => $amount > 0 ? 1 : -1, // 正数为加，负数为减
                        'operate_type' => 13, // 13=游戏调整（需要根据实际业务定义）
                        'admin_id' => 0, // 系统操作
                        'game_code' => $gameCode, // 游戏类型
                        'model_name' => 'GameAdjustment',
                        'model_id' => $roundTransaction['id'],
                        'description' => '游戏金额调整',
                        'remark' => json_encode($adjustmentData, JSON_UNESCAPED_UNICODE),
                        'created_at' => $currentDateTime,
                        'updated_at' => $currentDateTime
                    ]);

                    Log::info('Wallet set_adjustment - 余额调整完成');
                    Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
                    Log::info('Wallet set_adjustment - 调整后余额: ' . $newBalance);
                    Log::info('Wallet set_adjustment - 资金日志ID: ' . $logId);
                } else {
                    // 调整金额为0，不更新余额，只记录交易
                    Log::info('Wallet set_adjustment - 调整金额为0，仅记录交易');
                    Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
                }

                // 记录调整交易记录（防重复）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'adjustment',
                    'amount' => $amount,
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => '', // 调整操作没有betId
                    'external_transaction_id' => $externalTransactionId,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'remark' => json_encode($adjustmentData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_adjustment - 调整操作成功完成');
                Log::info('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::info('Wallet set_adjustment - 用户名: ' . $username);
                Log::info('Wallet set_adjustment - 最终余额: ' . $newBalance);
                Log::info('Wallet set_adjustment - 调整金额: ' . $amount);

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
                
                Log::error('Wallet set_adjustment - 调整操作失败，事务已回滚');
                Log::error('Wallet set_adjustment - TraceId: ' . $traceId);
                Log::error('Wallet set_adjustment - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_adjustment - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_adjustment - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_adjustment - 游戏调整发生异常');
            Log::error('Wallet set_adjustment - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_adjustment - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_adjustment - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_adjustment - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse('', 'SC_INTERNAL_ERROR');
        }
    }

    /**
     * 验证roundId是否存在（新增方法）
     * @param string $roundId 回合ID
     * @param int $userId 用户ID
     * @param string $gameCode 游戏代码
     * @return array|null 交易记录
     */
    private function validateRoundIdExists(string $roundId, int $userId, string $gameCode)
    {
        Log::debug('Wallet validateRoundIdExists - 验证roundId是否存在');
        Log::debug('Wallet validateRoundIdExists - roundId: ' . $roundId);
        Log::debug('Wallet validateRoundIdExists - 用户ID: ' . $userId);
        Log::debug('Wallet validateRoundIdExists - 游戏代码: ' . $gameCode);

        try {
            $transaction = Db::name('ntp_api_game_transactions')
                ->where('round_id', $roundId)
                ->where('member_id', $userId)
                ->where('game_code', $gameCode)
                ->where('status', 'completed')
                ->whereIn('type', ['bet', 'bet_result', 'bet_credit'])
                ->order('created_at', 'desc')
                ->find();

            if ($transaction) {
                Log::debug('Wallet validateRoundIdExists - 找到对应的回合交易');
                Log::debug('Wallet validateRoundIdExists - 交易ID: ' . $transaction['id']);
                Log::debug('Wallet validateRoundIdExists - 交易类型: ' . $transaction['type']);
                Log::debug('Wallet validateRoundIdExists - 交易金额: ' . $transaction['amount']);
                Log::debug('Wallet validateRoundIdExists - 创建时间: ' . $transaction['created_at']);
            } else {
                Log::debug('Wallet validateRoundIdExists - 未找到对应的回合交易');
                Log::debug('Wallet validateRoundIdExists - roundId: ' . $roundId);
            }

            return $transaction;

        } catch (\Exception $e) {
            Log::error('Wallet validateRoundIdExists - 验证roundId异常: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * 检查调整是否已存在（幂等性检查）
     * @param string $transactionId 调整交易ID
     * @return array|null 已存在的调整记录
     */
    private function checkAdjustmentExists(string $transactionId)
    {
        Log::debug('Wallet checkAdjustmentExists - 检查调整幂等性');
        Log::debug('Wallet checkAdjustmentExists - 交易ID: ' . $transactionId);

        try {
            $adjustment = Db::name('ntp_api_game_transactions')
                ->where('transaction_id', $transactionId)
                ->where('type', 'adjustment')
                ->where('status', 'completed')
                ->find();

            if ($adjustment) {
                Log::debug('Wallet checkAdjustmentExists - 找到已处理的调整');
                Log::debug('Wallet checkAdjustmentExists - 交易ID: ' . $transactionId);
                Log::debug('Wallet checkAdjustmentExists - 处理时间: ' . $adjustment['created_at']);
            } else {
                Log::debug('Wallet checkAdjustmentExists - 未找到重复调整');
                Log::debug('Wallet checkAdjustmentExists - 交易ID: ' . $transactionId);
            }

            return $adjustment;

        } catch (\Exception $e) {
            Log::error('Wallet checkAdjustmentExists - 检查调整异常: ' . $e->getMessage());
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
        
        Log::debug('Wallet validateCurrency - 支持的货币: ' . implode(', ', $supportedCurrencies));
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