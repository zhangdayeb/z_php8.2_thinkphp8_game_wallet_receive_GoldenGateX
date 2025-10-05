<?php
// 应用公共文件
use think\facade\Log;
use think\facade\Db;

/*
 * 生成 UUID v4
 * @return string
 */
function generateUuid(): string
{
    $data = random_bytes(16);

    // 设置版本号（4）和变体（10xxxxxx）
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant

    $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    
    Log::debug('generateUuid - UUID生成');
    Log::debug('generateUuid - UUID: ' . $uuid);
    Log::debug('generateUuid - 时间戳: ' . date('Y-m-d H:i:s'));
    
    return $uuid;
}


/**
 * 翻译API状态码为中文描述
 * @param string $statusCode 状态码
 * @return string 中文描述
 */
function translateStatusCode(string $statusCode): string
{
    Log::debug('translateStatusCode - 开始翻译状态码');
    Log::debug('translateStatusCode - 状态码: ' . $statusCode);
    Log::debug('translateStatusCode - 时间戳: ' . date('Y-m-d H:i:s'));
    
    // 状态码映射表
    $statusCodeMap = [
        'SC_OK' => '成功响应',
        'SC_UNKNOWN_ERROR' => '未知错误的通用状态代码',
        'SC_INVALID_REQUEST' => '请求体中发送的参数有错误/缺失',
        'SC_AUTHENTICATION_FAILED' => '验证失败。X-API-Key 丢失或无效',
        'SC_INVALID_SIGNATURE' => 'X-Signature 验证失败',
        'SC_INVALID_TOKEN' => '运营商系统中的令牌无效',
        'SC_INVALID_GAME' => '游戏无效',
        'SC_DUPLICATE_REQUEST' => '重复的请求',
        'SC_CURRENCY_NOT_SUPPORTED' => '不支持该货币',
        'SC_WRONG_CURRENCY' => '交易的货币与用户的钱包货币不同',
        'SC_INSUFFICIENT_FUNDS' => '用户的钱包资金不足',
        'SC_USER_NOT_EXISTS' => '在运营商系统中用户不存在',
        'SC_USER_DISABLED' => '用户已被禁用，不允许下注',
        'SC_TRANSACTION_DUPLICATED' => '发送了重复的交易ID',
        'SC_TRANSACTION_NOT_EXISTS' => '找不到相应的投注交易',
        'SC_VENDOR_ERROR' => '游戏供应商遇到错误',
        'SC_UNDER_MAINTENANCE' => '游戏正在维护中',
        'SC_MISMATCHED_DATA_TYPE' => '数据类型无效',
        'SC_INVALID_RESPONSE' => '响应无效',
        'SC_INVALID_VENDOR' => '不支持该供应商',
        'SC_INVALID_LANGUAGE' => '不支持该语言',
        'SC_GAME_DISABLED' => '游戏已被禁用',
        'SC_INVALID_PLATFORM' => '不支持该平台',
        'SC_GAME_LANGUAGE_NOT_SUPPORTED' => '不支持该游戏语言',
        'SC_GAME_PLATFORM_NOT_SUPPORTED' => '不支持该游戏平台',
        'SC_GAME_CURRENCY_NOT_SUPPORTED' => '不支持该游戏货币',
        'SC_VENDOR_LINE_DISABLED' => '游戏供应商线路已被禁用',
        'SC_VENDOR_CURRENCY_NOT_SUPPORTED' => '不支持该游戏供应商货币',
        'SC_VENDOR_LANGUAGE_NOT_SUPPORTED' => '不支持该游戏供应商语言',
        'SC_VENDOR_PLATFORM_NOT_SUPPORTED' => '不支持该游戏供应商平台',
        'SC_TRANSACTION_STILL_PROCESSING' => '交易仍在处理中，请稍后重试',
        'SC_EXCEEDED_NUMBER_OF_RETRIES' => '超出重试次数',
        'SC_OPERATOR_TIMEOUT' => '运营商已超时',
        'SC_INVALID_FROM_TIME' => '数据仅可获取最近60天',
        'SC_INVALID_DATE_RANGE' => '日期范围应该在一天之内',
        'SC_REFERENCE_ID_DUPLICATED' => '已发送重复的参考编号 Reference ID',
        'SC_TRANSACTION_DOES_NOT_EXIST' => '无法找到相应的参考编号 Reference ID',
        'SC_INTERNAL_ERROR' => '内部错误。请在相关客服渠道进行检查',
        'SC_WALLET_NOT_SUPPORTED' => '不支持该钱包类型'
    ];
    
    // 查找对应的中文描述
    $translation = $statusCodeMap[$statusCode] ?? null;
    
    if ($translation !== null) {
        Log::debug('translateStatusCode - 状态码翻译成功');
        Log::debug('translateStatusCode - 状态码: ' . $statusCode);
        Log::debug('translateStatusCode - 翻译: ' . $translation);
        
        return $translation;
    } else {
        Log::warning('translateStatusCode - 未找到状态码对应的翻译');
        Log::warning('translateStatusCode - 状态码: ' . $statusCode);
        Log::warning('translateStatusCode - 可用状态码数量: ' . count($statusCodeMap));
        
        return '未知状态码: ' . $statusCode;
    }
}

/**
 * 验证API请求签名
 * @param string $requestBody 原始请求体JSON字符串
 * @param string $signature 请求头中的X-Signature值
 * @return bool 验证结果
 */
function verifyApiSignature(string $requestBody, string $signature): bool
{
    // 自动获取当前请求域名
    $requestDomain = getCurrentRequestDomain();
    
    Log::debug('verifyApiSignature - 开始验证API请求签名');
    Log::debug('verifyApiSignature - 请求体长度: ' . strlen($requestBody));
    Log::debug('verifyApiSignature - 签名前缀: ' . substr($signature, 0, 8) . '...');
    Log::debug('verifyApiSignature - 请求域名: ' . $requestDomain);
    Log::debug('verifyApiSignature - 时间戳: ' . date('Y-m-d H:i:s'));
    
    try {
        // 检查签名是否为空
        if (empty($signature)) {
            Log::warning('verifyApiSignature - 签名为空');
            return false;
        }
        
        // 检查请求体是否为空
        if (empty($requestBody)) {
            Log::warning('verifyApiSignature - 请求体为空');
            return false;
        }
        
        // 检查请求域名是否为空
        if (empty($requestDomain)) {
            Log::warning('verifyApiSignature - 请求域名为空');
            return false;
        }
        
        // 从数据库获取对应域名的API配置信息
        Log::debug('verifyApiSignature - 开始查询API配置信息');
        
        // 先尝试用我方域名(qianbao_url)查询
        $apiConfig = Db::name('ntp_api_code_set')
            ->where('qianbao_url', $requestDomain)
            ->where('is_enabled', 1)
            ->field('api_key,api_secret,code_name,api_code_set,qianbao_url,api_url')
            ->find();
        
        // 如果我方域名没找到，再尝试用对方域名(api_url)查询
        if (empty($apiConfig)) {
            Log::debug('verifyApiSignature - 我方域名未匹配，尝试对方域名匹配');
            
            $apiConfig = Db::name('ntp_api_code_set')
                ->where('api_url', 'like', '%' . $requestDomain . '%')
                ->where('is_enabled', 1)
                ->field('api_key,api_secret,code_name,api_code_set,qianbao_url,api_url')
                ->find();
        }
        
        if (empty($apiConfig)) {
            Log::warning('verifyApiSignature - 未找到对应域名的API配置');
            Log::warning('verifyApiSignature - 查询域名: ' . $requestDomain);
            
            // 记录当前所有启用的域名配置用于调试
            $allConfigs = Db::name('ntp_api_code_set')
                ->where('is_enabled', 1)
                ->field('qianbao_url,api_url,code_name')
                ->select();
            
            Log::debug('verifyApiSignature - 当前启用的域名配置: ' . json_encode($allConfigs));
            return false;
        }
        
        Log::info('verifyApiSignature - 找到API配置');
        Log::info('verifyApiSignature - 配置名称: ' . $apiConfig['code_name']);
        Log::info('verifyApiSignature - API代码: ' . $apiConfig['api_code_set']);
        Log::info('verifyApiSignature - 我方域名: ' . $apiConfig['qianbao_url']);
        Log::info('verifyApiSignature - 对方域名: ' . $apiConfig['api_url']);
        Log::debug('verifyApiSignature - API密钥前缀: ' . substr($apiConfig['api_secret'], 0, 8) . '...');
        
        // 使用查询到的API密钥
        $apiSecret = $apiConfig['api_secret'];
        
        if (empty($apiSecret)) {
            Log::error('verifyApiSignature - API密钥为空');
            Log::error('verifyApiSignature - 域名: ' . $requestDomain);
            Log::error('verifyApiSignature - 配置ID: ' . $apiConfig['api_code_set']);
            return false;
        }
        
        // 生成预期的签名
        $expectedSignature = hash_hmac('sha256', $requestBody, $apiSecret);
        
        Log::debug('verifyApiSignature - 预期签名生成完成');
        Log::debug('verifyApiSignature - 预期签名前缀: ' . substr($expectedSignature, 0, 8) . '...');
        Log::debug('verifyApiSignature - 接收签名前缀: ' . substr($signature, 0, 8) . '...');
        
        // 使用 hash_equals 进行安全的字符串比较，防止时序攻击
        $isValid = hash_equals($expectedSignature, $signature);
        
        if ($isValid) {
            Log::info('verifyApiSignature - API签名验证成功');
            Log::info('verifyApiSignature - 使用配置: ' . $apiConfig['code_name'] . ' (' . $apiConfig['api_code_set'] . ')');
        } else {
            Log::warning('verifyApiSignature - API签名验证失败');
            Log::warning('verifyApiSignature - 域名: ' . $requestDomain);
            Log::warning('verifyApiSignature - 配置: ' . $apiConfig['code_name']);
            Log::warning('verifyApiSignature - 预期签名前缀: ' . substr($expectedSignature, 0, 8) . '...');
            Log::warning('verifyApiSignature - 接收签名前缀: ' . substr($signature, 0, 8) . '...');
        }
        
        return $isValid;
        
    } catch (\Exception $e) {
        Log::error('verifyApiSignature - 签名验证过程发生异常');
        Log::error('verifyApiSignature - 错误信息: ' . $e->getMessage());
        Log::error('verifyApiSignature - 错误文件: ' . $e->getFile());
        Log::error('verifyApiSignature - 错误行号: ' . $e->getLine());
        Log::error('verifyApiSignature - 请求域名: ' . $requestDomain);
        
        return false;
    }
}

/**
 * 获取请求域名的辅助函数
 * @return string 当前请求的域名
 */
function getCurrentRequestDomain(): string
{
    // 优先使用 HTTP_HOST
    if (isset($_SERVER['HTTP_HOST'])) {
        return $_SERVER['HTTP_HOST'];
    }
    
    // 备用方案使用 SERVER_NAME
    if (isset($_SERVER['SERVER_NAME'])) {
        return $_SERVER['SERVER_NAME'];
    }
    
    return '';
}


/**
 * 金额向下截取到指定小数位数（不四舍五入）
 * @param float $amount 原始金额
 * @param int $decimals 保留小数位数，默认2位
 * @return float 截取后的金额
 */
function moneyFloor(float $amount, int $decimals = 2): float
{
    // 计算倍数
    $multiplier = pow(10, $decimals);
    
    // 修复：先round到更高精度，再floor，避免浮点数精度问题
    $rounded = round($amount * $multiplier, 0, PHP_ROUND_HALF_DOWN);
    $result = floor($rounded) / $multiplier;
    
    Log::debug('moneyFloor - 金额向下截取');
    Log::debug('moneyFloor - 原始金额: ' . $amount);
    Log::debug('moneyFloor - 保留位数: ' . $decimals);
    Log::debug('moneyFloor - 截取结果: ' . $result);
    
    return $result;
}

/**
 * 格式化金额为字符串（保留2位小数）
 * @param float $amount 金额
 * @return string 格式化后的金额字符串
 */
function formatMoney(float $amount): string
{
    // 先进行精度修正，再格式化
    $correctedAmount = round($amount, 2);
    return number_format($correctedAmount, 2, '.', '');
}
/**
 * ************************************************************************************
 * ************************************************************************************
 * 
 * 伟大的分割线
 * 
 * ************************************************************************************
 * ************************************************************************************ 
 */