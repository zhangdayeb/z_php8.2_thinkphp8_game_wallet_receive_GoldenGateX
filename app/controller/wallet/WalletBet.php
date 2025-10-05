<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBet extends BaseController
{
    /**
     * 下注交易，从钱包余额中扣除金额
     * POST /wallet/bet
     * 由游戏厂商调用，当用户在游戏中下注时扣除余额
     */
    public function set_bet()
    {
        Log::info('Wallet ==> WalletBet::set_bet 开始');
        Log::info('Wallet set_bet - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_bet - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_bet - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_bet - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_bet - 请求数据获取完成');
            Log::debug('Wallet set_bet - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_bet - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_bet - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_bet - Content-Type不正确');
                Log::warning('Wallet set_bet - 当前Content-Type: ' . $contentType);
                
                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_bet - JSON解析失败');
                Log::error('Wallet set_bet - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_bet - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            Log::debug('Wallet set_bet - JSON解析成功');
            Log::debug('Wallet set_bet - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_bet - 签名验证失败');
                Log::error('Wallet set_bet - TraceId: ' . $traceId);
                Log::error('Wallet set_bet - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数
            $requiredParams = [
                'traceId', 'username', 'transactionId', 'betId', 'externalTransactionId',
                'amount', 'currency', 'token', 'gameCode', 'roundId', 'timestamp'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || $requestData[$param] === '') {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_bet - 缺少必需参数');
                Log::warning('Wallet set_bet - 缺少的参数: ' . implode(', ', $missingParams));
                
                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            // 提取并验证参数
            $username = $requestData['username'];
            $transactionId = $requestData['transactionId'];
            $betId = $requestData['betId'];
            $externalTransactionId = $requestData['externalTransactionId'];
            $amount = floatval($requestData['amount']);
            $currency = $requestData['currency'];
            $token = $requestData['token'];
            $gameCode = $requestData['gameCode'];
            $roundId = $requestData['roundId'];
            $timestamp = intval($requestData['timestamp']);

            Log::info('Wallet set_bet - 参数验证通过');
            Log::info('Wallet set_bet - TraceId: ' . $traceId);
            Log::info('Wallet set_bet - 用户名: ' . $username);
            Log::info('Wallet set_bet - 交易ID: ' . $transactionId);
            Log::info('Wallet set_bet - 下注ID: ' . $betId);
            Log::info('Wallet set_bet - 下注金额: ' . $amount);
            Log::info('Wallet set_bet - 游戏代码: ' . $gameCode);
            Log::info('Wallet set_bet - 回合ID: ' . $roundId);

            // 第六步：业务参数验证
            if ($amount <= 0) {
                Log::warning('Wallet set_bet - 下注金额无效');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 下注金额: ' . $amount);

                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_bet - 不支持的货币');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            if (!$this->validateUserToken($username, $token)) {
                Log::warning('Wallet set_bet - Token验证失败');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_INVALID_TOKEN');
            }

            // 第七步：检查交易幂等性
            $existingTransaction = $this->checkTransactionExists($transactionId);
            if ($existingTransaction) {
                Log::info('Wallet set_bet - 检测到重复交易，返回已处理结果');
                Log::info('Wallet set_bet - TraceId: ' . $traceId);
                Log::info('Wallet set_bet - 交易ID: ' . $transactionId);
                Log::info('Wallet set_bet - 原处理时间: ' . $existingTransaction['created_at']);

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
                Log::warning('Wallet set_bet - 用户不存在');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_bet - 用户已被禁用');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 用户名: ' . $username);
                Log::warning('Wallet set_bet - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            // 第九步：检查余额是否充足
            $currentBalance = floatval($user['money']);
            if (moneyFloor($currentBalance) < moneyFloor($amount)) {
                Log::warning('Wallet set_bet - 用户余额不足');
                Log::warning('Wallet set_bet - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet - 用户名: ' . $username);
                Log::warning('Wallet set_bet - 当前余额: ' . $currentBalance);
                Log::warning('Wallet set_bet - 下注金额: ' . $amount);

                return $this->errorResponse($traceId, 'SC_INSUFFICIENT_FUNDS');
            }

            Log::info('Wallet set_bet - 开始执行扣款操作');
            Log::info('Wallet set_bet - TraceId: ' . $traceId);
            Log::info('Wallet set_bet - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet - 扣款前余额: ' . $currentBalance);
            Log::info('Wallet set_bet - 扣款金额: ' . $amount);

            // 第十步：执行扣款操作（事务）
            Db::startTrans();
            try {
                $newBalance = moneyFloor($currentBalance - $amount);
                $currentDateTime = date('Y-m-d H:i:s');

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

                // 记录交易日志
                $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                    'member_id' => $user['id'],
                    'money' => moneyFloor(abs($amount)),
                    'money_before' => moneyFloor($currentBalance),
                    'money_after' => $newBalance,
                    'money_type' => 'money',
                    'number_type' => -1, // 减少
                    'operate_type' => 10, // 10=游戏下注（需要根据实际业务定义）
                    'admin_id' => 0, // 系统操作
                    'game_code' => $gameCode, // 游戏类型
                    'model_name' => 'GameBet',
                    'model_id' => 0,
                    'description' => '游戏下注扣款',
                    'remark' => json_encode([
                        'transactionId' => $transactionId,
                        'betId' => $betId,
                        'externalTransactionId' => $externalTransactionId,
                        'gameCode' => $gameCode,
                        'roundId' => $roundId,
                        'traceId' => $traceId
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 记录交易处理记录（防重复）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'bet',
                    'amount' => $amount,
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => $betId,
                    'external_transaction_id' => $externalTransactionId,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_bet - 扣款操作成功完成');
                Log::info('Wallet set_bet - TraceId: ' . $traceId);
                Log::info('Wallet set_bet - 用户名: ' . $username);
                Log::info('Wallet set_bet - 扣款后余额: ' . $newBalance);
                Log::info('Wallet set_bet - 日志ID: ' . $logId);

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
                
                Log::error('Wallet set_bet - 扣款操作失败，事务已回滚');
                Log::error('Wallet set_bet - TraceId: ' . $traceId);
                Log::error('Wallet set_bet - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_bet - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_bet - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_bet - 下注扣款发生异常');
            Log::error('Wallet set_bet - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_bet - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_bet - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_bet - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse('', 'SC_INTERNAL_ERROR');
        }
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
                ->where('type', 'bet')
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
        $isSupported = in_array(strtoupper($currency), $supportedCurrencies);
        
        Log::debug('Wallet validateCurrency - 验证结果: ' . ($isSupported ? '支持' : '不支持'));
        
        return $isSupported;
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
        // 例如：检查token是否存在于session表、是否过期等
        return true;
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