<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBetDebit extends BaseController
{
    /**
     * 扣款转账：入场时的扣除余额操作
     * POST /wallet/bet_debit
     * 由游戏厂商调用，当用户进入游戏房间时扣除押金或入场费
     */
    public function set_bet_debit()
    {
        Log::info('Wallet ==> WalletBetDebit::set_bet_debit 开始处理游戏入场扣款');
        Log::info('Wallet set_bet_debit - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet set_bet_debit - 请求方法: ' . $this->request->method());
        Log::info('Wallet set_bet_debit - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet set_bet_debit - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet set_bet_debit - 请求数据获取完成');
            Log::debug('Wallet set_bet_debit - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet set_bet_debit - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet set_bet_debit - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet set_bet_debit - Content-Type不正确');
                Log::warning('Wallet set_bet_debit - 当前Content-Type: ' . $contentType);
                Log::warning('Wallet set_bet_debit - 期望Content-Type: application/json');
                
                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet set_bet_debit - JSON解析失败');
                Log::error('Wallet set_bet_debit - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet set_bet_debit - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            Log::debug('Wallet set_bet_debit - JSON解析成功');
            Log::debug('Wallet set_bet_debit - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet set_bet_debit - 签名验证失败');
                Log::error('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_debit - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数（移除takeAll的必填要求）
            $requiredParams = [
                'traceId', 'username', 'transactionId', 'roundId',
                'amount', 'currency', 'gameCode', 'timestamp'
            ];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || $requestData[$param] === '') {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet set_bet_debit - 缺少必需参数');
                Log::warning('Wallet set_bet_debit - 缺少的参数: ' . implode(', ', $missingParams));
                Log::warning('Wallet set_bet_debit - 所有参数: ' . json_encode($requestData ?? []));
                
                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            // 提取参数（takeAll设置默认值）
            $username = $requestData['username'];
            $transactionId = $requestData['transactionId'];
            $roundId = $requestData['roundId'];
            $takeAll = isset($requestData['takeAll']) ? intval($requestData['takeAll']) : 0; // 默认值为0（部分扣款）
            $amount = floatval($requestData['amount']); // 请求扣款金额
            $currency = $requestData['currency'];
            $gameCode = $requestData['gameCode'];
            $timestamp = intval($requestData['timestamp']);

            Log::info('Wallet set_bet_debit - 参数验证通过');
            Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_debit - 用户名: ' . $username);
            Log::info('Wallet set_bet_debit - 交易ID: ' . $transactionId);
            Log::info('Wallet set_bet_debit - 回合ID: ' . $roundId);
            Log::info('Wallet set_bet_debit - 扣款模式: ' . ($takeAll ? '全额扣款' : '部分扣款'));
            Log::info('Wallet set_bet_debit - 请求金额: ' . $amount);
            Log::info('Wallet set_bet_debit - 游戏代码: ' . $gameCode);

            // 第六步：业务参数验证
            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet set_bet_debit - 不支持的货币');
                Log::warning('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_debit - 用户名: ' . $username);
                Log::warning('Wallet set_bet_debit - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            // 验证takeAll和amount的组合（修复：只在部分扣款时验证amount）
            if ($takeAll == 0 && $amount <= 0) {
                Log::warning('Wallet set_bet_debit - 部分扣款时金额必须大于0');
                Log::warning('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_debit - takeAll: ' . $takeAll);
                Log::warning('Wallet set_bet_debit - amount: ' . $amount);

                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            // 第七步：检查扣款幂等性
            $existingDebit = $this->checkDebitExists($transactionId);
            if ($existingDebit) {
                Log::info('Wallet set_bet_debit - 检测到重复扣款，返回已处理结果');
                Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_debit - 交易ID: ' . $transactionId);
                Log::info('Wallet set_bet_debit - 原处理时间: ' . $existingDebit['created_at']);

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
                Log::warning('Wallet set_bet_debit - 用户不存在');
                Log::warning('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_debit - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            if ($user['status'] != 1) {
                Log::warning('Wallet set_bet_debit - 用户已被禁用');
                Log::warning('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::warning('Wallet set_bet_debit - 用户名: ' . $username);
                Log::warning('Wallet set_bet_debit - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            $currentBalance = floatval($user['money']);

            Log::info('Wallet set_bet_debit - 用户验证通过');
            Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_debit - 用户名: ' . $username);
            Log::info('Wallet set_bet_debit - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_debit - 用户余额: ' . $currentBalance);

            // 第九步：计算实际扣款金额
            if ($takeAll == 1) {
                // 全额扣款模式：扣除用户钱包的全部余额
                $debitAmount = $currentBalance;
                $debitType = 'full';
                
                Log::info('Wallet set_bet_debit - 全额扣款模式');
                Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_debit - 用户余额: ' . $currentBalance);
                Log::info('Wallet set_bet_debit - 实际扣款: ' . $debitAmount);
            } else {
                // 部分扣款模式：根据提供的amount扣除（默认模式）
                $debitAmount = $amount;
                $debitType = 'partial';
                
                // 检查余额是否充足
                if ($currentBalance < $debitAmount) {
                    Log::warning('Wallet set_bet_debit - 用户余额不足');
                    Log::warning('Wallet set_bet_debit - TraceId: ' . $traceId);
                    Log::warning('Wallet set_bet_debit - 用户名: ' . $username);
                    Log::warning('Wallet set_bet_debit - 当前余额: ' . $currentBalance);
                    Log::warning('Wallet set_bet_debit - 需要扣除: ' . $debitAmount);

                    return $this->errorResponse($traceId, 'SC_INSUFFICIENT_FUNDS');
                }
                
                Log::info('Wallet set_bet_debit - 部分扣款模式（默认）');
                Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_debit - 请求扣款: ' . $amount);
                Log::info('Wallet set_bet_debit - 实际扣款: ' . $debitAmount);
            }

            // 第十步：准备扣款元数据
            $debitData = [
                'traceId' => $traceId,
                'transactionId' => $transactionId,
                'roundId' => $roundId,
                'takeAll' => $takeAll,
                'requestAmount' => $amount,          // 原始请求金额
                'actualAmount' => $debitAmount,      // 实际扣款金额
                'debitType' => $debitType,           // 扣款类型：full/partial
                'currency' => $currency,
                'gameCode' => $gameCode,
                'username' => $username,
                'timestamp' => $timestamp
            ];

            Log::info('Wallet set_bet_debit - 开始执行扣款操作');
            Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
            Log::info('Wallet set_bet_debit - 用户ID: ' . $user['id']);
            Log::info('Wallet set_bet_debit - 扣款前余额: ' . $currentBalance);
            Log::info('Wallet set_bet_debit - 扣款金额: ' . $debitAmount);

            // 第十一步：执行扣款操作（事务）
            Db::startTrans();
            try {
                $newBalance = $currentBalance - $debitAmount;
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

                // 记录扣款资金日志
                $logId = Db::name('ntp_game_user_money_logs')->insertGetId([
                    'member_id' => $user['id'],
                    'money' => $debitAmount, // 扣款金额
                    'money_before' => moneyFloor($currentBalance),
                    'money_after' => $newBalance,
                    'money_type' => 'money',
                    'number_type' => -1, // 扣款操作
                    'operate_type' => 14, // 14=游戏入场扣款（需要根据实际业务定义）
                    'admin_id' => 0, // 系统操作
                    'game_code' => $gameCode, // 游戏类型
                    'model_name' => 'GameBetDebit',
                    'model_id' => 0,
                    'description' => '游戏入场扣款',
                    'remark' => json_encode($debitData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 记录扣款交易记录（防重复）
                Db::name('ntp_api_game_transactions')->insert([
                    'transaction_id' => $transactionId,
                    'member_id' => $user['id'],
                    'type' => 'bet_debit',
                    'amount' => -$debitAmount, // 负数表示扣款
                    'status' => 'completed',
                    'trace_id' => $traceId,
                    'bet_id' => '', // 入场操作没有betId
                    'external_transaction_id' => '',
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'money_log_id' => $logId,
                    'remark' => json_encode($debitData, JSON_UNESCAPED_UNICODE),
                    'created_at' => $currentDateTime,
                    'updated_at' => $currentDateTime
                ]);

                // 提交事务
                Db::commit();

                Log::info('Wallet set_bet_debit - 扣款操作成功完成');
                Log::info('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::info('Wallet set_bet_debit - 用户名: ' . $username);
                Log::info('Wallet set_bet_debit - 扣款后余额: ' . $newBalance);
                Log::info('Wallet set_bet_debit - 扣款金额: ' . $debitAmount);
                Log::info('Wallet set_bet_debit - 扣款类型: ' . $debitType);
                Log::info('Wallet set_bet_debit - 资金日志ID: ' . $logId);

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
                
                Log::error('Wallet set_bet_debit - 扣款操作失败，事务已回滚');
                Log::error('Wallet set_bet_debit - TraceId: ' . $traceId);
                Log::error('Wallet set_bet_debit - 错误信息: ' . $e->getMessage());
                Log::error('Wallet set_bet_debit - 错误文件: ' . $e->getFile());
                Log::error('Wallet set_bet_debit - 错误行号: ' . $e->getLine());

                return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
            }

        } catch (\Exception $e) {
            Log::error('Wallet set_bet_debit - 游戏入场扣款发生异常');
            Log::error('Wallet set_bet_debit - 错误信息: ' . $e->getMessage());
            Log::error('Wallet set_bet_debit - 错误文件: ' . $e->getFile());
            Log::error('Wallet set_bet_debit - 错误行号: ' . $e->getLine());
            Log::error('Wallet set_bet_debit - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse('', 'SC_INTERNAL_ERROR');
        }
    }

    /**
     * 检查扣款是否已存在（幂等性检查）
     * @param string $transactionId 扣款交易ID
     * @return array|null 已存在的扣款记录
     */
    private function checkDebitExists(string $transactionId)
    {
        Log::debug('Wallet checkDebitExists - 检查扣款幂等性');
        Log::debug('Wallet checkDebitExists - 交易ID: ' . $transactionId);

        try {
            $debit = Db::name('ntp_api_game_transactions')
                ->where('transaction_id', $transactionId)
                ->where('type', 'bet_debit')
                ->where('status', 'completed')
                ->find();

            if ($debit) {
                Log::debug('Wallet checkDebitExists - 找到已处理的扣款');
                Log::debug('Wallet checkDebitExists - 交易ID: ' . $transactionId);
                Log::debug('Wallet checkDebitExists - 处理时间: ' . $debit['created_at']);
            } else {
                Log::debug('Wallet checkDebitExists - 未找到重复扣款');
                Log::debug('Wallet checkDebitExists - 交易ID: ' . $transactionId);
            }

            return $debit;

        } catch (\Exception $e) {
            Log::error('Wallet checkDebitExists - 检查扣款异常: ' . $e->getMessage());
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