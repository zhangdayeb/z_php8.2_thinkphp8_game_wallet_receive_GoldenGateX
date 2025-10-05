<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;

class WalletBalance extends BaseController
{
    /**
     * 支持的货币列表配置
     * 可根据业务需要扩展
     */
    private $supportedCurrencies = [
        'CNY'  // 目前只支持人民币
        // 'USD', // 美元 - 后续可启用
        // 'EUR', // 欧元 - 后续可启用
        // 'THB', // 泰铢 - 后续可启用
        // 'JPY', // 日元 - 后续可启用
        // 'KRW', // 韩元 - 后续可启用
        // 'VND'  // 越南盾 - 后续可启用
    ];
    /**
     * 检索用户的最新余额
     * POST /wallet/balance
     * 由游戏厂商调用，用于查询用户钱包余额
     */
    public function get_balance()
    {
        Log::info('Wallet ==> WalletBalance::get_balance 开始');
        Log::info('Wallet get_balance - 时间戳: ' . date('Y-m-d H:i:s'));
        Log::info('Wallet get_balance - 请求方法: ' . $this->request->method());
        Log::info('Wallet get_balance - Content-Type: ' . $this->request->header('content-type'));
        Log::info('Wallet get_balance - User-Agent: ' . $this->request->header('user-agent'));

        try {
            // 第一步：获取请求数据
            $requestBody = $this->request->getContent();
            $signature = $this->request->header('X-Signature', '');
            
            Log::debug('Wallet get_balance - 请求数据获取完成');
            Log::debug('Wallet get_balance - 请求体长度: ' . strlen($requestBody));
            Log::debug('Wallet get_balance - 是否有签名: ' . (!empty($signature) ? '是' : '否'));
            Log::debug('Wallet get_balance - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

            // 第二步：验证Content-Type
            $contentType = $this->request->header('content-type', '');
            if (strpos($contentType, 'application/json') === false) {
                Log::warning('Wallet get_balance - Content-Type不正确');
                Log::warning('Wallet get_balance - 当前Content-Type: ' . $contentType);
                Log::warning('Wallet get_balance - 期望Content-Type: application/json');

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            // 第三步：解析JSON数据（先获取traceId）
            $requestData = json_decode($requestBody, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error('Wallet get_balance - JSON解析失败');
                Log::error('Wallet get_balance - JSON错误: ' . json_last_error_msg());
                Log::error('Wallet get_balance - 请求体预览: ' . substr($requestBody, 0, 200));

                return $this->errorResponse('', 'SC_WRONG_PARAMETERS');
            }

            Log::debug('Wallet get_balance - JSON解析成功');
            Log::debug('Wallet get_balance - 请求数据字段: ' . implode(', ', array_keys($requestData ?? [])));

            // 获取traceId（用于后续错误响应）
            $traceId = $requestData['traceId'] ?? '';

            // 第四步：验证签名（现在有traceId了）
            if (!verifyApiSignature($requestBody, $signature)) {
                Log::error('Wallet get_balance - 签名验证失败');
                Log::error('Wallet get_balance - TraceId: ' . $traceId);
                Log::error('Wallet get_balance - 签名前缀: ' . (!empty($signature) ? substr($signature, 0, 8) . '...' : 'empty'));

                return $this->errorResponse($traceId, 'SC_INVALID_SIGNATURE');
            }

            // 第五步：验证必需参数
            $requiredParams = ['traceId', 'username', 'currency', 'token'];
            $missingParams = [];
            
            foreach ($requiredParams as $param) {
                if (!isset($requestData[$param]) || empty($requestData[$param])) {
                    $missingParams[] = $param;
                }
            }

            if (!empty($missingParams)) {
                Log::warning('Wallet get_balance - 缺少必需参数');
                Log::warning('Wallet get_balance - 缺少的参数: ' . implode(', ', $missingParams));
                Log::warning('Wallet get_balance - 所有参数: ' . json_encode($requestData ?? []));

                return $this->errorResponse($traceId, 'SC_WRONG_PARAMETERS');
            }

            $username = $requestData['username'];
            $currency = $requestData['currency'];
            $token = $requestData['token'];

            Log::info('Wallet get_balance - 参数验证通过');
            Log::info('Wallet get_balance - TraceId: ' . $traceId);
            Log::info('Wallet get_balance - 用户名: ' . $username);
            Log::info('Wallet get_balance - 货币: ' . $currency);
            Log::info('Wallet get_balance - Token前缀: ' . substr($token, 0, 8) . '...');

            // 第六步：业务逻辑验证
            // 验证货币（暂时跳过，默认返回true）
            if (!$this->validateCurrency($currency)) {
                Log::warning('Wallet get_balance - 不支持的货币');
                Log::warning('Wallet get_balance - TraceId: ' . $traceId);
                Log::warning('Wallet get_balance - 用户名: ' . $username);
                Log::warning('Wallet get_balance - 货币: ' . $currency);

                return $this->errorResponse($traceId, 'SC_WRONG_CURRENCY');
            }

            // 验证用户token（暂时跳过，默认返回true）
            if (!$this->validateUserToken($username, $token)) {
                Log::warning('Wallet get_balance - Token验证失败');
                Log::warning('Wallet get_balance - TraceId: ' . $traceId);
                Log::warning('Wallet get_balance - 用户名: ' . $username);
                Log::warning('Wallet get_balance - Token前缀: ' . substr($token, 0, 8) . '...');

                return $this->errorResponse($traceId, 'SC_INVALID_TOKEN');
            }

            // 第七步：查询用户信息
            $user = Db::name('ntp_common_user')
                ->field('id,name,money,status,updated_at')
                ->where('name', $username)
                ->find();

            if (!$user) {
                Log::warning('Wallet get_balance - 用户不存在');
                Log::warning('Wallet get_balance - TraceId: ' . $traceId);
                Log::warning('Wallet get_balance - 用户名: ' . $username);

                return $this->errorResponse($traceId, 'SC_USER_NOT_EXISTS');
            }

            // 检查用户状态
            if ($user['status'] != 1) {
                Log::warning('Wallet get_balance - 用户已被禁用');
                Log::warning('Wallet get_balance - TraceId: ' . $traceId);
                Log::warning('Wallet get_balance - 用户名: ' . $username);
                Log::warning('Wallet get_balance - 用户状态: ' . $user['status']);

                return $this->errorResponse($traceId, 'SC_USER_DISABLED');
            }

            Log::info('Wallet get_balance - 用户验证通过');
            Log::info('Wallet get_balance - TraceId: ' . $traceId);
            Log::info('Wallet get_balance - 用户名: ' . $username);
            Log::info('Wallet get_balance - 用户ID: ' . $user['id']);
            Log::info('Wallet get_balance - 用户余额: ' . $user['money']);

            // 第八步：获取用户实际货币
            $actualCurrency = $this->getUserCurrency($currency, $username);

            // 第九步：处理时间戳
            $timestamp = $user['updated_at'] ? strtotime($user['updated_at']) * 1000 : time() * 1000;

            Log::info('Wallet get_balance - 余额查询成功');
            Log::info('Wallet get_balance - TraceId: ' . $traceId);
            Log::info('Wallet get_balance - 用户名: ' . $username);
            Log::info('Wallet get_balance - 余额: ' . floatval($user['money']));
            Log::info('Wallet get_balance - 货币: ' . $actualCurrency);
            Log::info('Wallet get_balance - 时间戳: ' . $timestamp);

            // 第十步：返回成功响应
            return json([
                'traceId' => $traceId,
                'status' => 'SC_OK',
                'data' => [
                    'username' => $username,
                    'currency' => $actualCurrency,
                    'balance' => moneyFloor($user['money']),
                    'timestamp' => $timestamp
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Wallet get_balance - 余额查询发生异常');
            Log::error('Wallet get_balance - 错误信息: ' . $e->getMessage());
            Log::error('Wallet get_balance - 错误文件: ' . $e->getFile());
            Log::error('Wallet get_balance - 错误行号: ' . $e->getLine());
            Log::error('Wallet get_balance - 错误堆栈: ' . $e->getTraceAsString());

            return $this->errorResponse($traceId, 'SC_INTERNAL_ERROR');
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
        Log::debug('Wallet validateCurrency - 支持的货币: ' . implode(', ', $this->supportedCurrencies));

        // 检查货币是否在支持列表中（不区分大小写）
        $isSupported = in_array(strtoupper($currency), array_map('strtoupper', $this->supportedCurrencies));
        
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
        // 例如：从用户表或配置表中获取用户的默认货币
        
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