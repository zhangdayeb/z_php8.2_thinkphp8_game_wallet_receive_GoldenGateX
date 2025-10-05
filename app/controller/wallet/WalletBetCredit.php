<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBetCredit extends BaseController
{
    /**
     * 加款转账：完成投注结算并更新用户余额操作
     * POST /wallet/bet_credit
     * 由游戏厂商调用，在游戏回合结束时将剩余资金返还给用户
     */
    public function set_bet_credit()
    {
        $traceId = ''; // 初始化 traceId，确保在最外层 catch 块中也能使用

        Log::info('Wallet ==> WalletBetCredit::set_bet_credit 开始处理游戏结束加款');
        Log::info('Wallet set_bet_credit - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_bet_credit - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_bet_credit - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_bet_credit - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_bet_credit - 请求数据获取完成');
            Log::debug('Wallet set_bet_credit - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_bet_credit - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_bet_credit - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_bet_credit - Content-Type不正确');
                Log::warning('Wallet set_bet_credit - 当前Content-Type: ' . $contentType);
                Log::warning('Wallet set_bet_credit - 期望Content-Type: application/json');
                
                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_bet_credit - JSON解析失败');
                Log::error('Wallet set_bet_credit - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_bet_credit - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            Log::debug('Wallet set_bet_credit - JSON解析成功');
            Log::debug('Wallet set_bet_credit - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）- 修复：确保 traceId 能在最外层 catch 中使用
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_bet_credit - 签名验证失败');
                Log::error('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_credit - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数 - 修复：去掉 externalTransactionId
            $requiredParams = [
                'traceId', 'username', 'transactionId', 'betId', 'roundId',
                'isRefund', 'amount', 'betAmount', 'winAmount', 'effectiveTurnover', 'winLoss',
                'currency', 'token', 'gameCode', 'betTime', 'timestamp'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || $requestData[$param] === '') {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_bet_credit - 缺少必需参数');
                Log::warning('Wallet set_bet_credit - 缺少的参数: ' . implode(', ', $missingParams));
                Log::warning('Wallet set_bet_credit - 所有参数: ' . json_encode($requestData ?? []));
                
                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            // 提取参数 - 修复：externalTransactionId 改为可选参数
            $username = $requestData['username'];
            $transactionId = $requestData['transactionId'];
            $betId = $requestData['betId'];
            $externalTransactionId = $requestData['externalTransactionId'] ?? ''; // 可选参数
            $roundId = $requestData['roundId'];
            $isRefund = intval($requestData['isRefund']); // 关键参数：是否退款
            $amount = floatval($requestData['amount']); // 关键参数：加款金额
            $betAmount = floatval($requestData['betAmount']); // 参考参数
            $winAmount = floatval($requestData['winAmount']); // 参考参数
            $effectiveTurnover = floatval($requestData['effectiveTurnover']); // 参考参数
            $winLoss = floatval($requestData['winLoss']); // 参考参数
            $jackpotAmount = floatval($requestData['jackpotAmount'] ?? 0); // 参考参数
            $currency = $requestData['currency'];
            $token = $requestData['token'];
            $gameCode = $requestData['gameCode'];
            $betTime = intval($requestData['betTime']);
            $settledTime = intval($requestData['settledTime'] ?? 0);
            $timestamp = intval($requestData['timestamp']);

            Log::info('Wallet set_bet_credit - 参数验证通过');
            Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_credit - 用户名: ' . $username);
            Log::info('Wallet set_bet_credit - 交易ID: ' . $transactionId);
            Log::info('Wallet set_bet_credit - 下注ID: ' . $betId);
            Log::info('Wallet set_bet_credit - 回合ID: ' . $roundId);
            Log::info('Wallet set_bet_credit - 是否退款: ' . ($isRefund ? '是' : '否'));
            Log::info('Wallet set_bet_credit - 加款金额: ' . $amount);
            Log::info('Wallet set_bet_credit - 游戏代码: ' . $gameCode);
            Log::info('Wallet set_bet_credit - 参考-下注金额: ' . $betAmount);
            Log::info('Wallet set_bet_credit - 参考-获胜金额: ' . $winAmount);
            Log::info('Wallet set_bet_credit - 参考-输赢金额: ' . $winLoss);

            // 第六步：业务参数验证
            if ($amount < 0) {
                Log::warning('Wallet set_bet_credit - 加款金额不能为负数');
                Log::warning('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_credit - 加款金额: ' . $amount);

                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_bet_credit - 不支持的货币');
                Log::warning('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_credit - 用户名: ' . $username);
                Log::warning('Wallet set_bet_credit - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            if (!$this->validateUserToken($username, $token)) {
                Log::warning('Wallet set_bet_credit - Token验证失败');
                Log::warning('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_credit - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_INVALID_TOKEN');
            }

            // 第七步：检查加款幂等性
            $existingCredit = $this->checkCreditExists($transactionId);
            if ($existingCredit) {
                Log::info('Wallet set_bet_credit - 检测到重复加款，返回已处理结果');
                Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_credit - 交易ID: ' . $transactionId);
                Log::info('Wallet set_bet_credit - 原处理时间: ' . $existingCredit['created_at']);

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
                Log::warning('Wallet set_bet_credit - 用户不存在');
                Log::warning('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_credit - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_bet_credit - 用户已被禁用');
                Log::warning('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_credit - 用户名: ' . $username);
                Log::warning('Wallet set_bet_credit - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            $currentBalance = floatval($user['money']);

            Log::info('Wallet set_bet_credit - 用户验证通过');
            Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_credit - 用户名: ' . $username);
            Log::info('Wallet set_bet_credit - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_credit - 用户余额: ' . $currentBalance);

            // 第九步：准备加款元数据（包含所有参考参数）
            $creditData = [
                'traceId' => $traceId,
                'transactionId' => $transactionId,
                'betId' => $betId,
                'externalTransactionId' => $externalTransactionId,
                'roundId' => $roundId,
                'isRefund' => $isRefund,
                'amount' => $amount,                     // 实际加款金额
                'betAmount' => $betAmount,               // 参考：总下注金额
                'winAmount' => $winAmount,               // 参考：总获胜金额
                'effectiveTurnover' => $effectiveTurnover, // 参考：有效投注额
                'winLoss' => $winLoss,                   // 参考：净输赢金额
                'jackpotAmount' => $jackpotAmount,       // 参考：奖池金额
                'currency' => $currency,
                'gameCode' => $gameCode,
                'username' => $username,
                'betTime' => $betTime,                   // 参考：下注时间
                'settledTime' => $settledTime,           // 参考：结算时间
                'timestamp' => $timestamp,
                'creditType' => $isRefund ? 'refund' : 'normal' // 加款类型
            ];

            Log::info('Wallet set_bet_credit - 开始执行加款操作');
            Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_credit - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_credit - 加款前余额: ' . $currentBalance);
            Log::info('Wallet set_bet_credit - 加款金额: ' . $amount);
            Log::info('Wallet set_bet_credit - 加款类型: ' . ($isRefund ? '退款' : '正常结束'));

            // 第十步：执行加款操作（事务）
            Db::startTrans();
            try {
                $newBalance = moneyFloor($currentBalance + $amount); // 直接相加
                $currentDateTime = date('Y-m-d H:i:s');
                $logId = null;

                // 如果有加款金额，才更新余额和记录资金日志
                if ($amount > 0) {
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

                    // 记录加款资金日志
                    $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                        'member_id' => $user['id'],
                        'money' => moneyFloor(abs($amount)), // 加款金额
                        'money_before' => moneyFloor($currentBalance),
                        'money_after' => $newBalance,
                        'money_type' => 'money',
                        'number_type' => 1, // 加款操作
                        'operate_type' => $isRefund ? 15 : 16, // 15=游戏退款，16=游戏结束加款
                        'admin_id' => 0, // 系统操作
                        'game_code' => $gameCode, // 游戏类型
                        'model_name' => 'GameBetCredit',
                        'model_id' => 0,
                        'description' => $isRefund ? '游戏退款' : '游戏结束加款',
                        'remark' => json_encode($creditData, JSON_UNESCAPED_UNICODE),
                        'created_at' => $currentDateTime,
                        'updated_at' => $currentDateTime
                    ]);

                    Log::info('Wallet set_bet_credit - 余额加款完成');
                    Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
                    Log::info('Wallet set_bet_credit - 加款后余额: ' . $newBalance);
                    Log::info('Wallet set_bet_credit - 资金日志ID: ' . $logId);
                } else {
                    // 加款金额为0，不更新余额，只记录交易
                    Log::info('Wallet set_bet_credit - 加款金额为0，仅记录交易');
                    Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
                    Log::info('Wallet set_bet_credit - 用户余额保持: ' . $currentBalance);
                }

                // 记录加款交易记录（防重复，无论金额是否为0都记录）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'bet_credit',
                    'amount' => $amount, // 加款金额（可以为0）
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => $betId,
                    'external_transaction_id' => $externalTransactionId,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'remark' => json_encode($creditData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_bet_credit - 加款操作成功完成');
                Log::info('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_credit - 用户名: ' . $username);
                Log::info('Wallet set_bet_credit - 最终余额: ' . $newBalance);
                Log::info('Wallet set_bet_credit - 加款金额: ' . $amount);
                Log::info('Wallet set_bet_credit - 加款类型: ' . ($isRefund ? '退款' : '正常结束'));

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
                
                Log::error('Wallet set_bet_credit - 加款操作失败，事务已回滚');
                Log::error('Wallet set_bet_credit - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_credit - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_bet_credit - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_bet_credit - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_bet_credit - 游戏加款发生异常');
            Log::error('Wallet set_bet_credit - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_bet_credit - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_bet_credit - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_bet_credit - 错误堆栈: ' . $e->getTraceAsString());

            // 修复：确保最外层 catch 块也能返回正确的 traceId
            return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
        }
    }

    /**
     * 检查加款是否已存在（幂等性检查）
     * @param string $transactionId 加款交易ID
     * @return array|null 已存在的加款记录
     */
    private function checkCreditExists(string $transactionId)
    {
        Log::debug('Wallet checkCreditExists - 检查加款幂等性');
        Log::debug('Wallet checkCreditExists - 交易ID: ' . $transactionId);

        try {
            $credit = Db::name('ntp_api_game_transactions')
                ->where('transaction_id', $transactionId)
                ->where('type', 'bet_credit')
                ->where('status', 'completed')
                ->find();

            if ($credit) {
                Log::debug('Wallet checkCreditExists - 找到已处理的加款');
                Log::debug('Wallet checkCreditExists - 交易ID: ' . $transactionId);
                Log::debug('Wallet checkCreditExists - 处理时间: ' . $credit['created_at']);
            } else {
                Log::debug('Wallet checkCreditExists - 未找到重复加款');
                Log::debug('Wallet checkCreditExists - 交易ID: ' . $transactionId);
            }

            return $credit;

        } catch (\Exception $e) {
            Log::error('Wallet checkCreditExists - 检查加款异常: ' . $e->getMessage());
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