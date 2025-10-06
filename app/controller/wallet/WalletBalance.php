<?php
namespace app\controller\wallet;

use app\BaseController;
use think\facade\Db;
use think\facade\Log;
use think\Request;
use think\Response;

class WalletBalance extends BaseController
{
    /**
     * 获取用户余额接口
     * POST /api/balance
     */
    public function get_balance()
    {
        try {
            // 1. 获取请求对象
            $request = $this->request;
            
            // 2. 记录请求日志
            Log::info('WalletBalance::get_balance - 收到余额查询请求');
            Log::info('WalletBalance::get_balance - 请求IP: ' . $request->ip());
            Log::info('WalletBalance::get_balance - 请求时间: ' . date('Y-m-d H:i:s'));
            
            // 3. 验证请求方法
            if (!$request->isPost()) {
                Log::warning('WalletBalance::get_balance - 请求方法错误: ' . $request->method());
                return $this->errorResponse(false, 'Method Not Allowed', 405);
            }
            
            // 4. 获取并验证 Authorization 头
            $authHeader = $request->header('Authorization');
            Log::debug('WalletBalance::get_balance - Authorization头: ' . substr($authHeader ?? '', 0, 20) . '...');
            
            if (empty($authHeader)) {
                Log::warning('WalletBalance::get_balance - 缺少Authorization头');
                return $this->errorResponse(false, 'Unauthorized', 401);
            }
            
            // 5. 解析 Basic Auth
            if (!preg_match('/^Basic\s+(.+)$/i', $authHeader, $matches)) {
                Log::warning('WalletBalance::get_balance - Authorization格式错误');
                return $this->errorResponse(false, 'Invalid Authorization Format', 401);
            }
            
            $encodedCredentials = $matches[1];
            $decodedCredentials = base64_decode($encodedCredentials);
            
            if ($decodedCredentials === false) {
                Log::warning('WalletBalance::get_balance - Base64解码失败');
                return $this->errorResponse(false, 'Invalid Credentials', 401);
            }
            
            // 6. 分离 clientId 和 clientSecret
            $credentials = explode(':', $decodedCredentials, 2);
            if (count($credentials) !== 2) {
                Log::warning('WalletBalance::get_balance - 认证凭据格式错误');
                return $this->errorResponse(false, 'Invalid Credentials Format', 401);
            }
            
            $clientId = $credentials[0];
            $clientSecret = $credentials[1];
            
            Log::debug('WalletBalance::get_balance - ClientId: ' . $clientId);
            Log::debug('WalletBalance::get_balance - ClientSecret前缀: ' . substr($clientSecret, 0, 8) . '...');
            
            // 7. 获取请求域名
            $requestDomain = $this->getCurrentRequestDomain();
            Log::info('WalletBalance::get_balance - 请求域名: ' . $requestDomain);
            
            // 8. 验证API配置
            $apiConfig = Db::name('api_code_set')
                ->where('qianbao_url', $requestDomain)
                ->where('is_enabled', 1)
                ->field('api_key, api_secret, code_name, api_code_set')
                ->find();
            
            if (empty($apiConfig)) {
                Log::warning('WalletBalance::get_balance - 未找到对应域名的API配置');
                Log::warning('WalletBalance::get_balance - 查询域名: ' . $requestDomain);
                return $this->errorResponse(false, 'Invalid API Configuration', 403);
            }
            
            Log::info('WalletBalance::get_balance - 找到API配置: ' . $apiConfig['code_name']);
            Log::info('WalletBalance::get_balance - API代码: ' . $apiConfig['api_code_set']);
            
            // 9. 验证认证信息
            if ($clientId !== $apiConfig['api_key'] || $clientSecret !== $apiConfig['api_secret']) {
                Log::warning('WalletBalance::get_balance - API认证失败');
                Log::warning('WalletBalance::get_balance - 期望ClientId: ' . $apiConfig['api_key']);
                Log::warning('WalletBalance::get_balance - 实际ClientId: ' . $clientId);
                return $this->errorResponse(false, 'Authentication Failed', 401);
            }
            
            Log::info('WalletBalance::get_balance - API认证成功');
            
            // 10. 获取请求参数
            $params = $request->param();
            Log::debug('WalletBalance::get_balance - 请求参数: ' . json_encode($params));
            
            // 11. 验证必需参数
            if (empty($params['userCode'])) {
                Log::warning('WalletBalance::get_balance - 缺少userCode参数');
                return $this->errorResponse(false, 'Missing userCode parameter', 400);
            }
            
            $userCode = trim($params['userCode']);
            Log::info('WalletBalance::get_balance - 查询用户: ' . $userCode);
            
            // 12. 查询用户信息
            $user = Db::name('common_user')
                ->where('name', $userCode)
                ->field('id, name, money, status')
                ->find();
            
            if (empty($user)) {
                Log::warning('WalletBalance::get_balance - 用户不存在: ' . $userCode);
                return $this->errorResponse(false, 'User not found', 404);
            }
            
            Log::info('WalletBalance::get_balance - 找到用户');
            Log::info('WalletBalance::get_balance - 用户ID: ' . $user['id']);
            Log::info('WalletBalance::get_balance - 用户状态: ' . $user['status']);
            
            // 13. 检查用户状态
            if ($user['status'] != 1) {
                Log::warning('WalletBalance::get_balance - 用户账号已冻结');
                return $this->errorResponse(false, 'User account is disabled', 403);
            }
            
            // 14. 获取用户余额
            $balance = (float)$user['money'];
            
            Log::info('WalletBalance::get_balance - 用户余额: ' . $balance);
            
            // 15. 记录查询日志（可选）
            $this->logBalanceQuery($user['id'], $userCode, $balance, $apiConfig['api_code_set']);
            
            // 16. 返回成功响应
            $response = [
                'success' => true,
                'message' => $balance,
                'errorCode' => 0
            ];
            
            Log::info('WalletBalance::get_balance - 余额查询成功');
            Log::debug('WalletBalance::get_balance - 响应数据: ' . json_encode($response));
            
            return json($response);
            
        } catch (\Exception $e) {
            // 异常处理
            Log::error('WalletBalance::get_balance - 发生异常');
            Log::error('WalletBalance::get_balance - 错误信息: ' . $e->getMessage());
            Log::error('WalletBalance::get_balance - 错误文件: ' . $e->getFile());
            Log::error('WalletBalance::get_balance - 错误行号: ' . $e->getLine());
            Log::error('WalletBalance::get_balance - 堆栈跟踪: ' . $e->getTraceAsString());
            
            return $this->errorResponse(false, 'Internal Server Error', 500);
        }
    }
    
    /**
     * 获取当前请求域名
     * @return string
     */
    private function getCurrentRequestDomain(): string
    {
        // 优先使用 HTTP_HOST
        if (isset($_SERVER['HTTP_HOST'])) {
            return $_SERVER['HTTP_HOST'];
        }
        
        // 备用方案使用 SERVER_NAME
        if (isset($_SERVER['SERVER_NAME'])) {
            return $_SERVER['SERVER_NAME'];
        }
        
        // 如果都没有，尝试从请求对象获取
        $request = $this->request;
        $host = $request->host(true);
        
        return $host ?: '';
    }
    
    /**
     * 返回错误响应
     * @param bool $success
     * @param string $message
     * @param int $errorCode
     * @return Response
     */
    private function errorResponse(bool $success, string $message, int $errorCode): Response
    {
        $response = [
            'success' => $success,
            'message' => $message,
            'errorCode' => $errorCode
        ];
        
        Log::warning('WalletBalance::get_balance - 返回错误响应: ' . json_encode($response));
        
        // 根据错误码设置HTTP状态码
        $httpStatusCode = 200; // 按照API规范，始终返回200
        
        return json($response, $httpStatusCode);
    }
    
    /**
     * 记录余额查询日志（可选功能）
     * @param int $userId
     * @param string $userCode
     * @param float $balance
     * @param string $apiCode
     */
    private function logBalanceQuery(int $userId, string $userCode, float $balance, string $apiCode): void
    {
        try {
            // 可以创建一个专门的查询日志表来记录
            // 这里仅作为示例，实际使用时根据需求调整
            
            $logData = [
                'user_id' => $userId,
                'user_code' => $userCode,
                'balance' => $balance,
                'api_code' => $apiCode,
                'query_time' => date('Y-m-d H:i:s'),
                'ip_address' => $this->request->ip(),
                'user_agent' => $this->request->header('User-Agent')
            ];
            
            Log::channel('balance_query')->info('余额查询记录', $logData);
            
        } catch (\Exception $e) {
            // 记录日志失败不应影响主业务
            Log::warning('记录余额查询日志失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 验证请求签名（备用方法，如果需要额外的签名验证）
     * @param array $params
     * @param string $signature
     * @param string $secret
     * @return bool
     */
    private function verifySignature(array $params, string $signature, string $secret): bool
    {
        // 按照参数名排序
        ksort($params);
        
        // 构建签名字符串
        $signStr = '';
        foreach ($params as $key => $value) {
            if ($key !== 'sign' && $key !== 'signature') {
                $signStr .= $key . '=' . $value . '&';
            }
        }
        $signStr = rtrim($signStr, '&');
        
        // 生成签名
        $calculatedSign = hash_hmac('sha256', $signStr, $secret);
        
        // 验证签名
        return hash_equals($calculatedSign, $signature);
    }
    
    /**
     * 格式化金额（确保返回正确的小数位数）
     * @param float $amount
     * @param int $decimals
     * @return float
     */
    private function formatAmount(float $amount, int $decimals = 2): float
    {
        return round($amount, $decimals);
    }
}