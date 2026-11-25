<?php
// +----------------------------------------------------------------------
// | LightPicture [ 图床 ] - S3 协议适配
// +----------------------------------------------------------------------
// | 基于 AWS Signature Version 4 的轻量 S3 客户端，零第三方依赖。
// | 兼容：AWS S3 / Cloudflare R2 / MinIO / Backblaze B2 等 S3 兼容存储。
// | 只需三样：API端点、Access Key、Secret Key。
// | 端点格式：{服务地址}/{bucket}，例如
// |   https://s3.us-east-1.amazonaws.com/mybucket
// |   https://accountid.r2.cloudflarestorage.com/mybucket
// |   https://minio.example.com:9000/mybucket
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace app\services;

class S3Client
{
    private string $endpoint;    // 原始完整端点（含 bucket）
    private string $accessKey;
    private string $secretKey;
    private string $region;      // 签名用 region
    private string $bucket;      // 从端点 path 解析
    private string $host;        // host[:port]
    private string $basePath;    // /bucket（含端点里额外的路径前缀）

    public function __construct(string $endpoint, string $accessKey, string $secretKey)
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new \RuntimeException('API 端点不能为空');
        }
        if (strpos($endpoint, 'http://') !== 0 && strpos($endpoint, 'https://') !== 0) {
            $endpoint = 'https://' . $endpoint;
        }
        $this->endpoint  = rtrim($endpoint, '/');
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;

        $parts = parse_url($this->endpoint);
        $host = $parts['host'] ?? '';
        if (isset($parts['port'])) {
            $host .= ':' . $parts['port'];
        }
        $this->host = $host;
        if ($host === '') {
            throw new \RuntimeException('API 端点格式错误: ' . $this->endpoint);
        }

        // bucket = 端点 path 的首段
        $path = trim($parts['path'] ?? '', '/');
        $segments = $path === '' ? [] : explode('/', $path);
        if (count($segments) < 1 || $segments[0] === '') {
            throw new \RuntimeException('API 端点需包含 bucket，例如 https://s3.us-east-1.amazonaws.com/mybucket');
        }
        $this->bucket = $segments[0];
        $this->basePath = '/' . implode('/', $segments); // /bucket[/extra]

        // region 自动推断
        $this->region = $this->guessRegion($parts['host'] ?? '');
    }

    /**
     * 从 host 推断 region
     */
    private function guessRegion(string $host): string
    {
        $h = strtolower($host);
        // AWS: s3.us-east-1.amazonaws.com / s3-us-west-2.amazonaws.com / s3.cn-north-1.amazonaws.com.cn
        if (preg_match('/s3[.\-]([a-z]{2}-[a-z]+-\d+)/', $h, $m)) {
            return $m[1];
        }
        if (strpos($h, 'r2.cloudflarestorage.com') !== false) {
            return 'auto'; // Cloudflare R2 固定 auto
        }
        return 'us-east-1'; // MinIO / B2 / 其他默认
    }

    /**
     * 上传对象（PUT Object）
     * @param string $key     形如 LightPicture/2026/11/xxx.webp
     * @param string $filePath 本地文件路径
     * @param string $contentType
     * @return array{success:bool,msg:string,code:int}
     */
    public function putObject(string $key, string $filePath, string $contentType = 'application/octet-stream'): array
    {
        $content = @file_get_contents($filePath);
        if ($content === false) {
            return ['success' => false, 'msg' => '读取本地文件失败', 'code' => 0];
        }
        return $this->request('PUT', $key, $content, $contentType);
    }

    /**
     * 删除对象（DELETE Object）
     */
    public function deleteObject(string $key): array
    {
        return $this->request('DELETE', $key, '', 'application/octet-stream');
    }

    /**
     * 对外直链
     */
    public function url(string $key): string
    {
        return $this->endpoint . '/' . ltrim($key, '/');
    }

    /**
     * 发起签名请求
     */
    private function request(string $method, string $key, string $body, string $contentType): array
    {
        $amzDate     = gmdate('Ymd\THis\Z');
        $dateStamp   = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        // canonical URI：/bucket/key（逐段 rawurlencode，保留 '/'）
        $uri = $this->encodeUriPath($this->basePath . '/' . ltrim($key, '/'));
        $url = $this->endpoint . '/' . ltrim($key, '/');

        $canonicalHeaders =
            'content-type:' . $contentType . "\n" .
            'host:' . $this->host . "\n" .
            'x-amz-content-sha256:' . $payloadHash . "\n" .
            'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest =
            $method . "\n" .
            $uri . "\n" .
            "\n" .                 // canonical querystring（空）
            $canonicalHeaders . "\n" .
            $signedHeaders . "\n" .
            $payloadHash;

        $scope = $dateStamp . '/' . $this->region . '/s3/aws4_request';
        $stringToSign =
            "AWS4-HMAC-SHA256\n" .
            $amzDate . "\n" .
            $scope . "\n" .
            hash('sha256', $canonicalRequest);

        $signingKey = $this->getSigningKey($dateStamp);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization =
            'AWS4-HMAC-SHA256 Credential=' . $this->accessKey . '/' . $scope . ', ' .
            'SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: ' . $contentType,
                'Host: ' . $this->host,
                'X-Amz-Content-Sha256: ' . $payloadHash,
                'X-Amz-Date: ' . $amzDate,
                'Authorization: ' . $authorization,
            ],
        ]);
        if ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp     = curl_exec($ch);
        $err      = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($resp === false) {
            return ['success' => false, 'msg' => 'S3 请求失败: ' . $err, 'code' => 0];
        }
        if ($httpCode >= 200 && $httpCode < 300) {
            return ['success' => true, 'msg' => 'ok', 'code' => $httpCode];
        }
        $msg = 'S3 返回 HTTP ' . $httpCode;
        if (preg_match('/<Message>(.*?)<\/Message>/is', $resp, $m)) {
            $msg = html_entity_decode($m[1]);
        } elseif (preg_match('/<Code>(.*?)<\/Code>/is', $resp, $m)) {
            $msg = $m[1];
        }
        return ['success' => false, 'msg' => $msg, 'code' => $httpCode];
    }

    /**
     * 推导签名密钥（HMAC 链）
     */
    private function getSigningKey(string $dateStamp): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    /**
     * 路径逐段编码，保留 '/'
     */
    private function encodeUriPath(string $path): string
    {
        $segments = explode('/', $path);
        $out = [];
        foreach ($segments as $seg) {
            $out[] = rawurlencode($seg);
        }
        return implode('/', $out);
    }

    /**
     * 供调试/测试读取解析结果
     */
    public function info(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'host'     => $this->host,
            'bucket'   => $this->bucket,
            'region'   => $this->region,
            'basePath' => $this->basePath,
        ];
    }
}
