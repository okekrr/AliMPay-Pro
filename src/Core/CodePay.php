<?php

namespace AliMPay\Core;

use AliMPay\Utils\Logger;
use AliMPay\Utils\QRCodeGenerator;
use AliMPay\Core\AlipayTransfer;

class CodePay
{
    private $logger;
    private $config;
    private $merchantId;
    private $merchantKey;
    private $configFile;
    private $ordersFile;
    private $db;

    public function __construct()
    {
        date_default_timezone_set('Asia/Shanghai');

        $this->logger = Logger::getInstance();

        $this->config = require __DIR__ . '/../../config/alipay.php';
        $this->configFile = __DIR__ . '/../../config/codepay.json';
        $this->ordersFile = __DIR__ . '/../../data/orders.json';

        $dataDir = dirname($this->ordersFile);
        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0755, true);
        }

        $this->initializeMerchant();
    }

    public function getDb()
    {
        return $this->db;
    }

    public function getMerchantInfo()
    {
        return [
            'id' => $this->merchantId,
            'key' => $this->merchantKey,
            'notify_url' => $this->config['codepay_notify_url'] ?? '',
            'query_minutes_back' => $this->config['payment']['query_minutes_back'] ?? 30
        ];
    }

    private function initializeMerchant(): void
    {
        if (file_exists($this->configFile)) {
            $config = json_decode(file_get_contents($this->configFile), true);
            $this->merchantId = $config['merchant_id'];
            $this->merchantKey = $config['merchant_key'];
            $this->logger->info('Loaded existing merchant configuration', ['merchant_id' => $this->merchantId]);
        } else {
            $this->merchantId = '1001' . str_pad(rand(0, 999999999999), 12, '0', STR_PAD_LEFT);
            $this->merchantKey = bin2hex(random_bytes(16));

            $config = [
                'merchant_id' => $this->merchantId,
                'merchant_key' => $this->merchantKey,
                'created_at' => date('Y-m-d H:i:s'),
                'status' => 1,
                'balance' => '0.00',
                'rate' => '96'
            ];

            file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->logger->info('Generated new merchant configuration', ['merchant_id' => $this->merchantId]);
        }

        $this->initializeDatabase();
    }

    private function initializeDatabase()
    {
        $databaseFile = __DIR__ . '/../../data/codepay.db';
        $this->db = new \Medoo\Medoo([
            'database_type' => 'SQLite',
            'database_file' => $databaseFile,
            'database_name' => 'codepay'
        ]);
        $this->logger->info('Database initialized.', ['file' => $databaseFile]);

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS codepay_orders (
                id VARCHAR(32) PRIMARY KEY,
                out_trade_no VARCHAR(64) NOT NULL,
                type VARCHAR(10) NOT NULL,
                pid VARCHAR(20) NOT NULL,
                name VARCHAR(255) NOT NULL,
                price DECIMAL(10, 2) NOT NULL,
                payment_amount DECIMAL(10, 2) DEFAULT 0,
                status TINYINT(1) DEFAULT 0,
                add_time DATETIME NOT NULL,
                pay_time DATETIME,
                notify_url VARCHAR(255),
                return_url VARCHAR(255),
                sitename VARCHAR(255)
            );
        ");

        $columns = $this->db->query("PRAGMA table_info(codepay_orders);")->fetchAll();
        $hasPaymentAmount = false;
        foreach ($columns as $column) {
            if ($column['name'] === 'payment_amount') {
                $hasPaymentAmount = true;
                break;
            }
        }

        if (!$hasPaymentAmount) {
            $this->db->exec("ALTER TABLE codepay_orders ADD COLUMN payment_amount DECIMAL(10, 2) DEFAULT 0");
            $this->logger->info('Added payment_amount column to existing table.');
        }

        $this->logger->info('Database table codepay_orders initialized.', ['has_payment_amount' => $hasPaymentAmount]);
    }

    private function validateSignature(array $params, bool $isNotification = false): bool
    {
        if (!isset($params['sign'])) {
            $this->logger->warning('Signature validation failed: sign parameter missing.');
            return false;
        }

        $sign = $params['sign'];

        // Use raw request data for incoming payment requests
        // to include ALL params (clientip, device, etc.) that Dujiao Next signs
        if (!$isNotification) {
            $signParams = array_merge($_GET, $_POST);
        } else {
            $signParams = $params;
        }
        unset($signParams['sign'], $signParams['sign_type']);

        $signStr = $this->generateSignString($signParams);
        $expectedSign = md5($signStr . $this->merchantKey);

        $this->logger->debug('Validating signature according to CodePay protocol.', [
            'sign_params' => $signParams,
            'string_to_sign' => $signStr,
            'merchant_key' => $this->merchantKey,
            'full_sign_string' => $signStr . $this->merchantKey,
            'expected_sign' => $expectedSign,
            'received_sign' => $sign
        ]);

        return $sign === $expectedSign;
    }

    private function generateSignString(array $params): string
    {
        $params = array_filter($params, function($value) {
            return $value !== '' && $value !== null;
        });

        ksort($params);

        $parts = [];
        foreach ($params as $key => $value) {
            $parts[] = $key . '=' . $value;
        }

        return implode('&', $parts);
    }

    private function generateResponseSignature(array $params): string
    {
        $signStr = $this->generateSignString($params);
        return md5($signStr . $this->merchantKey);
    }

    public function queryMerchant(string $pid, string $key): array
    {
        $this->logger->info('Querying merchant info according to CodePay protocol.', ['pid' => $pid]);
        try {
            if ($pid !== $this->merchantId || $key !== $this->merchantKey) {
                return [
                    'code' => -1,
                    'msg' => 'Invalid merchant credentials'
                ];
            }

            $response = [
                'code' => 1,
                'pid' => (int)$this->merchantId,
                'key' => $this->merchantKey,
                'qq' => null,
                'active' => 1,
                'money' => '0.00',
                'account' => $this->config['transfer_user_id'] ?? 'Not Set',
                'username' => 'Merchant',
                'rate' => '96',
                'issmrz' => 1
            ];

            $this->logger->info('Merchant query successful.', ['pid' => $pid]);
            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to query merchant info.', ['error' => $e->getMessage(), 'pid' => $pid]);
            return [
                'code' => -1,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function createPayment(array $params): array
    {
        $this->logger->info('Creating payment according to CodePay protocol.', ['out_trade_no' => $params['out_trade_no']]);
        try {
            $this->validatePaymentParams($params);

            $tradeNo = $this->generateTradeNo();

            $businessQrMode = $this->config['payment']['business_qr_mode']['enabled'] ?? false;
            $originalAmount = (float)$params['money'];
            $paymentAmount = $originalAmount;

            $this->logger->info('Payment mode check.', [
                'business_qr_mode' => $businessQrMode,
                'original_amount' => $originalAmount,
                'out_trade_no' => $params['out_trade_no']
            ]);

            if ($businessQrMode) {
                $offset = $this->config['payment']['business_qr_mode']['amount_offset'] ?? 0.01;
                $paymentAmount = $this->allocateUniqueAmount($originalAmount, $offset);

                if ($paymentAmount != $originalAmount) {
                    $this->logger->info('Amount adjusted to avoid conflicts.', [
                        'original_amount' => $originalAmount,
                        'adjusted_amount' => $paymentAmount,
                        'offset' => $offset,
                        'out_trade_no' => $params['out_trade_no']
                    ]);
                }
            }

            $this->db->insert('codepay_orders', [
                'id' => $tradeNo,
                'out_trade_no' => $params['out_trade_no'],
                'type' => $params['type'],
                'pid' => $params['pid'],
                'name' => $params['name'],
                'price' => $originalAmount,
                'payment_amount' => $paymentAmount,
                'status' => 0,
                'add_time' => date('Y-m-d H:i:s'),
                'notify_url' => $params['notify_url'],
                'return_url' => $params['return_url'],
                'sitename' => $params['sitename'] ?? ''
            ]);

            $this->logger->info('Order record created in database.', [
                'trade_no' => $tradeNo,
                'original_amount' => $originalAmount,
                'payment_amount' => $paymentAmount
            ]);

            if ($businessQrMode) {
                $qrCodePath = $this->config['payment']['business_qr_mode']['qr_code_path'];

                if (!file_exists($qrCodePath)) {
                    throw new \Exception('经营码二维码文件不存在，请先上传经营码到: ' . $qrCodePath);
                }

                $token = md5('qrcode_access_' . date('Y-m-d'));
                $baseUrl = $this->getBaseUrl();
                $qrCodeUrl = $baseUrl . '/qrcode.php?type=business&token=' . $token;

                $paymentUrl = '经营码收款模式';
                $qrCodeBase64 = null;

                $this->logger->info('Using business QR code for payment.', [
                    'trade_no' => $tradeNo,
                    'payment_amount' => $paymentAmount,
                    'qr_code_path' => $qrCodePath,
                    'qr_code_url' => $qrCodeUrl
                ]);
            } else {
                $alipayTransfer = new AlipayTransfer($this->config);
                $paymentUrl = $alipayTransfer->createOrder(
                    $params['out_trade_no'],
                    $paymentAmount,
                    $params['name']
                );

                $this->logger->info('Using transfer QR code for payment.', [
                    'trade_no' => $tradeNo,
                    'payment_url' => $paymentUrl
                ]);
            }

            // EasyPay V1 standard response
            $response = [
                'code' => 1,
                'msg' => 'SUCCESS',
                'pid' => $params['pid'],
                'trade_no' => $tradeNo,
                'out_trade_no' => $params['out_trade_no'],
                'money' => $params['money'],
                'payment_amount' => $paymentAmount,
                'qrcode' => $paymentUrl,
                'payurl' => $paymentUrl,
            ];

            if ($businessQrMode) {
                // submit.php 期望的字段名
                $response['qr_code_url'] = $qrCodeUrl;
                $response['payment_url'] = '';
                $response['business_qr_mode'] = true;
                $response['payment_instruction'] = "请使用支付宝扫描二维码，支付金额：{$paymentAmount} 元";

                if ($paymentAmount != $originalAmount) {
                    $response['amount_adjusted'] = true;
                    $response['adjustment_note'] = "检测到相同金额订单，实际支付金额已调整为 {$paymentAmount} 元";
                    $response['original_amount'] = $originalAmount;
                }

                $response['payment_tips'] = [
                    "请务必支付准确金额：{$paymentAmount} 元",
                    "支付时无需填写备注信息",
                    "请在5分钟内完成支付，超时订单将被自动删除",
                    "支付完成后系统会自动检测到账",
                    "如长时间未到账，请联系客服"
                ];
            } else {
                // submit.php 期望的字段名（转账模式）
                $response['qr_code_url'] = $paymentUrl;
                $response['payment_url'] = $paymentUrl;
            }

            $this->logger->info('Payment created successfully.', ['trade_no' => $tradeNo]);
            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to create payment.', ['error' => $e->getMessage(), 'out_trade_no' => $params['out_trade_no'] ?? 'N/A']);
            return [
                'code' => -1,
                'msg' => $e->getMessage()
            ];
        }
    }

    private function getBaseUrl(): string
    {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $port = $_SERVER['SERVER_PORT'] ?? '80';

        if (($protocol === 'https' && $port === '443') || ($protocol === 'http' && $port === '80')) {
            return $protocol . '://' . $host;
        }

        return $protocol . '://' . $host . ':' . $port;
    }

    private function validatePaymentParams(array $params): void
    {
        $requiredParams = ['pid', 'type', 'out_trade_no', 'notify_url', 'return_url', 'name', 'money', 'sign'];
        foreach ($requiredParams as $param) {
            if (!isset($params[$param]) || $params[$param] === '') {
                throw new \InvalidArgumentException("Missing required parameter: {$param}");
            }
        }

        if ($params['pid'] !== $this->merchantId) {
            throw new \InvalidArgumentException("Invalid merchant ID. Expected: {$this->merchantId}, Got: {$params['pid']}");
        }

        if ($params['type'] !== 'alipay') {
            throw new \InvalidArgumentException("Only 'alipay' payment type is supported. Got: {$params['type']}");
        }

        if (!$this->validateSignature($params)) {
            throw new \InvalidArgumentException('Invalid signature');
        }
        $this->logger->debug('Payment parameters validated successfully.', ['out_trade_no' => $params['out_trade_no']]);
    }

    private function generateTradeNo(): string
    {
        return date('YmdHis') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function allocateUniqueAmount(float $originalAmount, float $offset): float
    {
        $lockFile = __DIR__ . '/../../data/amount_allocation.lock';
        $lockHandle = fopen($lockFile, 'w');

        if ($lockHandle === false) {
            $this->logger->error('Failed to create lock file for amount allocation.');
            throw new \Exception('无法创建锁文件，请检查目录权限');
        }

        if (!flock($lockHandle, LOCK_EX)) {
            $this->logger->error('Failed to acquire lock for amount allocation.');
            fclose($lockHandle);
            throw new \Exception('无法获取金额分配锁，请稍后重试');
        }

        try {
            $timeoutSeconds = $this->config['payment']['order_timeout'] ?? 300;
            $startTime = date('Y-m-d H:i:s', time() - $timeoutSeconds);

            $this->logger->info('Starting unique amount allocation.', [
                'original_amount' => $originalAmount,
                'offset' => $offset,
                'timeout_seconds' => $timeoutSeconds,
                'start_time' => $startTime
            ]);

            $paymentAmount = $originalAmount;
            $attempts = 0;
            $maxAttempts = 100;

            while ($attempts < $maxAttempts) {
                $attempts++;

                $existingOrder = $this->db->get('codepay_orders', ['id', 'out_trade_no', 'add_time'], [
                    'payment_amount' => $paymentAmount,
                    'status' => 0,
                    'add_time[>=]' => $startTime
                ]);

                if (!$existingOrder) {
                    $this->logger->info('Unique amount allocated successfully.', [
                        'original_amount' => $originalAmount,
                        'final_amount' => $paymentAmount,
                        'attempts' => $attempts,
                        'adjusted' => $paymentAmount != $originalAmount
                    ]);
                    break;
                }

                $this->logger->info('Payment amount conflict detected, adjusting amount.', [
                    'conflicting_amount' => $paymentAmount,
                    'existing_order_id' => $existingOrder['id'],
                    'existing_order_trade_no' => $existingOrder['out_trade_no'],
                    'existing_order_time' => $existingOrder['add_time'],
                    'attempt' => $attempts
                ]);

                $paymentAmount += $offset;
            }

            if ($attempts >= $maxAttempts) {
                $this->logger->error('Failed to allocate unique amount after maximum attempts.', [
                    'original_amount' => $originalAmount,
                    'final_amount' => $paymentAmount,
                    'max_attempts' => $maxAttempts
                ]);
                throw new \Exception('无法分配唯一的支付金额，请稍后重试');
            }

            return $paymentAmount;

        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    public function queryOrder(string $pid, ?string $key, string $outTradeNo, bool $validateKey = true): array
    {
        $this->logger->info('Querying order according to CodePay protocol.', ['out_trade_no' => $outTradeNo, 'pid' => $pid]);
        try {
            if ($validateKey && ($pid !== $this->merchantId || $key !== $this->merchantKey)) {
                return [
                    'code' => -1,
                    'msg' => 'Invalid merchant credentials'
                ];
            }

            if (!$validateKey && $pid !== $this->merchantId) {
                return [
                    'code' => -1,
                    'msg' => 'Invalid merchant ID'
                ];
            }

            $order = $this->db->get('codepay_orders', '*', [
                'out_trade_no' => $outTradeNo,
                'pid' => $pid
            ]);

            if (!$order) {
                return [
                    'code' => -1,
                    'msg' => 'Order not found'
                ];
            }

            $response = [
                'code' => 1,
                'msg' => 'SUCCESS',
                'trade_no' => $order['id'],
                'out_trade_no' => $order['out_trade_no'],
                'type' => $order['type'],
                'pid' => $order['pid'],
                'addtime' => $order['add_time'],
                'endtime' => $order['pay_time'],
                'name' => $order['name'],
                'money' => number_format($order['price'], 2, '.', ''),
                'status' => (int)$order['status']
            ];

            $this->logger->info('Order query successful.', ['out_trade_no' => $outTradeNo, 'status' => $order['status']]);
            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Failed to query order.', ['error' => $e->getMessage(), 'out_trade_no' => $outTradeNo]);
            return [
                'code' => -1,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function queryOrders(string $pid, string $key, int $limit = 20): array
    {
        $this->logger->info('Querying orders according to CodePay protocol.', ['pid' => $pid, 'limit' => $limit]);
        try {
            if ($pid !== $this->merchantId || $key !== $this->merchantKey) {
                return [
                    'code' => -1,
                    'msg' => 'Invalid merchant credentials'
                ];
            }

            $orders = $this->db->select('codepay_orders', '*', [
                'pid' => $pid,
                'ORDER' => ['add_time' => 'DESC'],
                'LIMIT' => max(1, min($limit, 100))
            ]);

            $result = [];
            foreach ($orders as $order) {
                $result[] = [
                    'trade_no' => $order['id'],
                    'out_trade_no' => $order['out_trade_no'],
                    'type' => $order['type'],
                    'pid' => $order['pid'],
                    'addtime' => $order['add_time'],
                    'endtime' => $order['pay_time'],
                    'name' => $order['name'],
                    'money' => number_format($order['price'], 2, '.', ''),
                    'status' => (int)$order['status']
                ];
            }

            $this->logger->info('Orders query successful.', ['count' => count($result)]);
            return $result;

        } catch (\Exception $e) {
            $this->logger->error('Failed to query orders.', ['error' => $e->getMessage(), 'pid' => $pid]);
            return [
                'code' => -1,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function processNotification(array $params): array
    {
        $this->logger->info('Processing payment notification according to CodePay protocol.', ['out_trade_no' => $params['out_trade_no'] ?? 'N/A']);
        try {
            $requiredParams = ['out_trade_no', 'trade_no', 'trade_status', 'name', 'money'];
            foreach ($requiredParams as $param) {
                if (!isset($params[$param])) {
                    throw new \InvalidArgumentException("Missing required parameter: {$param}");
                }
            }

            if (!$this->validateSignature($params, true)) {
                throw new \InvalidArgumentException('Invalid signature');
            }

            $order = $this->db->get('codepay_orders', '*', [
                'out_trade_no' => $params['out_trade_no']
            ]);

            if (!$order) {
                throw new \Exception("Order not found: {$params['out_trade_no']}");
            }

            if ($order['status'] == 1) {
                $this->logger->info('Order already paid, ignoring notification.', ['out_trade_no' => $params['out_trade_no']]);
                return [
                    'code' => 1,
                    'msg' => 'SUCCESS'
                ];
            }

            if ($params['trade_status'] === 'TRADE_SUCCESS') {
                $this->db->update('codepay_orders', [
                    'status' => 1,
                    'pay_time' => date('Y-m-d H:i:s')
                ], ['out_trade_no' => $params['out_trade_no']]);

                $this->logger->info('Order status updated to paid.', ['out_trade_no' => $params['out_trade_no']]);
            }

            return [
                'code' => 1,
                'msg' => 'SUCCESS'
            ];

        } catch (\Exception $e) {
            $this->logger->error('Failed to process notification.', ['error' => $e->getMessage(), 'out_trade_no' => $params['out_trade_no'] ?? 'N/A']);
            return [
                'code' => -1,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function sendNotification(array $orderData): bool
    {
        if (empty($orderData['notify_url'])) {
            $this->logger->warning('No notify_url provided for order.', ['out_trade_no' => $orderData['out_trade_no']]);
            return false;
        }

        try {
            $notifyData = [
                'pid' => $orderData['pid'],
                'trade_no' => $orderData['id'],
                'out_trade_no' => $orderData['out_trade_no'],
                'type' => $orderData['type'],
                'name' => $orderData['name'],
                'money' => number_format($orderData['price'], 2, '.', ''),
                'trade_status' => 'TRADE_SUCCESS'
            ];

            $notifyData['sign'] = $this->generateResponseSignature($notifyData);
            $notifyData['sign_type'] = 'MD5';

            $url = $orderData['notify_url'];
            $queryString = http_build_query($notifyData);
            $fullUrl = $url . (strpos($url, '?') !== false ? '&' : '?') . $queryString;

            $this->logger->info('Sending notification to merchant.', [
                'out_trade_no' => $orderData['out_trade_no'],
                'notify_url' => $orderData['notify_url']
            ]);

            $response = file_get_contents($fullUrl);
            $success = ($response === 'success' || $response === 'SUCCESS');

            if ($success) {
                $this->logger->info('Notification sent successfully.', ['out_trade_no' => $orderData['out_trade_no']]);
            } else {
                $this->logger->warning('Notification failed or invalid response.', [
                    'out_trade_no' => $orderData['out_trade_no'],
                    'response' => $response
                ]);
            }

            return $success;

        } catch (\Exception $e) {
            $this->logger->error('Failed to send notification.', [
                'error' => $e->getMessage(),
                'out_trade_no' => $orderData['out_trade_no']
            ]);
            return false;
        }
    }
}
