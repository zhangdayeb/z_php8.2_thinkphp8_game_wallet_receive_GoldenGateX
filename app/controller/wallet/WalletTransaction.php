<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletTransaction extends BaseController
{
    /**
     * 处理游戏交易
     * POST /api/transaction
     */
    public function set_bet()
    {
        try {
            // 记录请求
            Log::info('交易请求 - 开始', [
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
            $validateResult = $this->validateParams($params);
            if ($validateResult !== true) {
                return $validateResult;
            }

            // 4. 提取参数
            $userCode = trim($params['userCode']);
            $vendorCode = trim($params['vendorCode']);
            $gameCode = trim($params['gameCode']);
            $historyId = $params['historyId'];
            $roundId = trim($params['roundId']);
            $gameType = intval($params['gameType']);
            $transactionCode = trim($params['transactionCode']);
            $isFinished = filter_var($params['isFinished'], FILTER_VALIDATE_BOOLEAN);
            $isCanceled = filter_var($params['isCanceled'], FILTER_VALIDATE_BOOLEAN);
            $amount = floatval($params['amount']);
            $detail = $params['detail'] ?? '{}';
            $createdAt = $params['createdAt'];

            Log::info('交易信息', [
                'transactionCode' => $transactionCode,
                'userCode' => $userCode,
                'amount' => $amount,
                'gameCode' => $gameCode
            ]);

            // 5. 开启事务处理
            Db::startTrans();
            
            try {
                // 6. 检查交易是否重复
                $existingTransaction = Db::name('api_game_transactions')
                    ->where('transaction_id', $transactionCode)
                    ->find();

                if ($existingTransaction) {
                    Log::warning('交易重复', ['transactionCode' => $transactionCode]);
                    Db::rollback();
                    return $this->error(6, 'DUPLICATE_TRANSACTION');
                }

                // 7. 查询并锁定用户（悲观锁）
                $user = Db::name('common_user')
                    ->where('name', $userCode)
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

                // 8. 计算新余额
                $balanceBefore = floatval($user['money']);
                $balanceAfter = $balanceBefore + $amount;

                // 9. 检查余额是否足够
                if ($balanceAfter < 0) {
                    Log::warning('余额不足', [
                        'current' => $balanceBefore,
                        'need' => abs($amount)
                    ]);
                    Db::rollback();
                    return $this->error(4, 'INSUFFICIENT_USER_BALANCE');
                }

                // 10. 更新用户余额
                $updateResult = Db::name('common_user')
                    ->where('id', $user['id'])
                    ->update([
                        'money' => $balanceAfter,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                if (!$updateResult) {
                    Db::rollback();
                    return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                }

                // 11. 记录到交易表（防重复）
                $transactionData = [
                    'transaction_id' => $transactionCode,
                    'member_id' => $user['id'],
                    'type' => $this->getTransactionType($amount, $isCanceled),
                    'amount' => abs($amount),
                    'status' => $isCanceled ? 'cancelled' : 'completed',
                    'trace_id' => generateUuid(),
                    'bet_id' => $historyId,
                    'external_transaction_id' => $transactionCode,
                    'game_code' => $gameCode,
                    'round_id' => $roundId,
                    'remark' => json_encode([
                        'vendor_code' => $vendorCode,
                        'game_type' => $gameType,
                        'is_finished' => $isFinished,
                        'is_canceled' => $isCanceled,
                        'detail' => $detail,
                        'created_at' => $createdAt
                    ]),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ];

                $transactionId = Db::name('api_game_transactions')->insertGetId($transactionData);

                if (!$transactionId) {
                    Db::rollback();
                    return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                }

                // 12. 记录到资金流水表
                $moneyLogData = [
                    'member_id' => $user['id'],
                    'money' => abs($amount),
                    'money_before' => $balanceBefore,
                    'money_after' => $balanceAfter,
                    'money_type' => 'money',
                    'number_type' => $amount < 0 ? -1 : 1,
                    'operate_type' => 501,  // 游戏类型
                    'admin_id' => 0,
                    'model_name' => 'GameTransaction',
                    'model_id' => $transactionId,
                    'game_code' => $gameCode,
                    'description' => $this->getDescription($amount, $gameCode, $isCanceled),
                    'remark' => json_encode([
                        'transaction_code' => $transactionCode,
                        'vendor_code' => $vendorCode,
                        'round_id' => $roundId,
                        'history_id' => $historyId
                    ]),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                    'fanyong_flag' => 0
                ];

                $moneyLogId = Db::name('game_user_money_logs')->insertGetId($moneyLogData);

                if (!$moneyLogId) {
                    Db::rollback();
                    return $this->error(500, 'UNKNOWN_SERVER_ERROR');
                }

                // 13. 更新关联
                Db::name('api_game_transactions')
                    ->where('id', $transactionId)
                    ->update(['money_log_id' => $moneyLogId]);

                // 14. 提交事务
                Db::commit();

                Log::info('交易成功', [
                    'transactionCode' => $transactionCode,
                    'balanceAfter' => $balanceAfter
                ]);

                // 15. 返回成功
                return $this->success($balanceAfter);

            } catch (\Exception $e) {
                Db::rollback();
                throw $e;
            }

        } catch (\Exception $e) {
            Log::error('交易异常', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $this->error(500, 'UNKNOWN_SERVER_ERROR');
        }
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
            return $this->error(401, 'Unauthorized');
        }

        // 验证凭据
        if ($clientId !== $apiConfig['api_key'] || $clientSecret !== $apiConfig['api_secret']) {
            return $this->error(401, 'Unauthorized');
        }

        return true;
    }

    /**
     * 验证参数
     */
    private function validateParams($params)
    {
        $required = [
            'userCode', 'vendorCode', 'gameCode', 'historyId',
            'roundId', 'gameType', 'transactionCode', 'amount', 'createdAt'
        ];

        foreach ($required as $field) {
            if (!isset($params[$field]) || $params[$field] === '') {
                return $this->error(400, "Bad Request - Missing {$field}");
            }
        }

        if (!isset($params['isFinished']) || !isset($params['isCanceled'])) {
            return $this->error(400, 'Bad Request - Missing boolean fields');
        }

        $gameType = intval($params['gameType']);
        if (!in_array($gameType, [1, 2, 3, 4])) {
            return $this->error(400, 'Bad Request - Invalid gameType');
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