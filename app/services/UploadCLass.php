<?php
// +----------------------------------------------------------------------
// | LightPicture [ 图床 ]
// +----------------------------------------------------------------------
// | 企业团队图片资源管理系统
// +----------------------------------------------------------------------
// | Github: https://github.com/osuuu/LightPicture
// +----------------------------------------------------------------------
// | Copyright © http://picture.h234.cn All rights reserved.
// +----------------------------------------------------------------------
// | Author: Team <admin@osuu.net>
// +----------------------------------------------------------------------
declare(strict_types=1);

namespace app\services;

use app\model\Storage as StorageModel;
use OSS\OssClient;
use OSS\Core\OssException;
use Qiniu\Auth;
use Qiniu\Storage\UploadManager;
use Qcloud\Cos\Client;
use Upyun\Upyun;
use Upyun\Config;
use Obs\ObsClient;

class UploadCLass
{
    /**
     * 当前储存策略参数
     *
     * @var array
     */
    protected $storage = [];

    /**
     * 生成路径
     *
     * @var
     */
    private $getPath;


    public function __construct()
    {
        $year = date("Y");
        $month = date("m");
        $this->getPath = FOLDER . $year . '/' . $month . '/';
    }

    //  生成新名称
    public function getName($name)
    {
        $str_img = explode('.', $name);
        $format = '.' . $str_img[count($str_img) - 1];
        return  substr(md5(date("YmdHis") . rand(1000, 9999)), 8, 16) . $format;
    }

    /**
     * 创建文件
     *
     * @param $file
     * @param $sid
     */
    public function create($file, $sid)
    {
        $this->storage = StorageModel::find($sid);
        switch ($this->storage['type']) {
            case 'local':
                return $this->location_upload($file);
                break;
            case 'cos':
                return $this->tencent_upload($file);
                break;
            case 'oss':
                return $this->aliyuncs_upload($file);
                break;
            case 'uss':
                return $this->upyun_upload($file, $sid);
                break;
            case 'obs':
                return $this->hwyun_upload($file, $sid);
                break;
            case 'kodo':
                return $this->qiniu_upload($file);
                break;
            case 's3':
                return $this->s3_upload($file);
                break;
            default:
        }
    }

    /**
     * 删除文件
     *
     * @param $path
     * @param $sid
     */
    public function delete($path, $sid)
    {
        $this->storage = StorageModel::find($sid);
        switch ($this->storage['type']) {
            case 'local': // 本地
                unlink($path);
                break;
            case 'cos': // 腾讯云
                return $this->tencent_delete($path);
                break;
            case 'obs': // 华为云
                return $this->hwyun_delete($path);
                break;
            case 'oss': // 阿里云
                $ossClient = new OssClient($this->storage['AccessKey'], $this->storage['SecretKey'], $this->storage['region']);
                $ossClient->deleteObject($this->storage['bucket'], $path);
                break;
            case 'uss': // 又拍云
                $serviceConfig = new Config($this->storage['bucket'], $this->storage['AccessKey'], $this->storage['SecretKey']);
                $client = new Upyun($serviceConfig);
                $client->delete($path);
                break;
            case 'kodo': // 七牛云
                $auth = new Auth($this->storage['AccessKey'], $this->storage['SecretKey']);
                $config = new \Qiniu\Config();
                $bucketManager = new \Qiniu\Storage\BucketManager($auth, $config);
                list($Info, $err) = $bucketManager->delete($this->storage['bucket'], $path);
                break;
            case 's3': // S3 兼容协议
                return $this->s3_delete($path);
                break;
            default:
        }
    }

    /**
     * 本地上传方法
     * @param  \think\Request  $file
     */
    function location_upload($file)
    {

        // 获取网站协议
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $path = './' . $this->getPath;
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }
        $newName = $this->getName($file['name']);
        // 本地上传
        if (move_uploaded_file($file["tmp_name"], $path . $newName)) {
            $url = $protocol . $_SERVER['HTTP_HOST'] . '/' . $this->getPath . $newName;
            return array(
                'path' => $this->getPath . $newName,
                'name' => $newName,
                'url' => $url,
                'state' => 1,
            );
        } else {
            return array(
                'msg' => '上传失败',
                'state' => 0,
            );
        }
    }


    /**
     * 阿里云OSS上传方法
     * @param  \think\Request  $file
     */
    public function aliyuncs_upload($file)
    {
        $name = $this->getName($file['name']);
        $path = $this->getPath . $name;
        $filePath = $file['tmp_name'];
        try {
            $ossClient = new OssClient($this->storage['AccessKey'], $this->storage['SecretKey'], $this->storage['region']);
            $ossClient->uploadFile($this->storage['bucket'], $path, $filePath);
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $this->storage['space_domain'] . '/' . $path,
                'state' => 1,
            );
        } catch (OssException $e) {
            return array(
                'msg' => $e->getMessage(),
                'state' => 0,
            );
        }
    }

    /**
     * 腾讯云cos上传方法
     * @param  \think\Request  $file
     */
    function tencent_upload($file)
    {
        $cosClient = new \Qcloud\Cos\Client(
            array(
                'region' => $this->storage['region'],
                'schema' => 'http', //协议头部，默认为http
                'credentials' => array(
                    'secretId'  => $this->storage['AccessKey'],
                    'secretKey' => $this->storage['SecretKey']
                )
            )
        );
        $local_path = $file['tmp_name'];
        try {
            $name = $this->getName($file['name']);
            $path = $this->getPath . $name;
            $cosClient->upload(
                $bucket = $this->storage['bucket'], //格式：BucketName-APPID
                $key = $path,
                $body = fopen($local_path, 'rb')
            );
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $this->storage['space_domain'] . '/' . $path,
                'state' => 1,
            );
        } catch (\Exception $e) {
            return array(
                'msg' => $e->getMessage(),
                'state' => 0,
            );
        }
    }

    /**
     * 腾讯云cos删除方法
     * @param  \think\Request  $file
     */
    function tencent_delete($path)
    {
        $cosClient = new \Qcloud\Cos\Client(
            array(
                'region' => $this->storage['region'],
                'schema' => 'http', //协议头部，默认为http
                'credentials' => array(
                    'secretId'  => $this->storage['AccessKey'],
                    'secretKey' => $this->storage['SecretKey']
                )
            )
        );
        $cosClient->deleteObject(array(
            'Bucket' => $this->storage['bucket'], //格式：BucketName-APPID
            'Key' => $path,
            // 'VersionId' => 'exampleVersionId' //存储桶未开启版本控制时请勿携带此参数
        ));
    }



    /**
     * 七牛云上传方法
     * @param  \think\Request  $file
     */
    function qiniu_upload($file)
    {
        $auth = new Auth($this->storage['AccessKey'], $this->storage['SecretKey']);
        // 生成上传 Token
        $token = $auth->uploadToken($this->storage['bucket']);
        // 构建 UploadManager 对象
        $uploadMgr = new UploadManager();
        // 要上传文件的本地路径
        $filePath = $file['tmp_name'];
        // 上传到七牛后保存的文件名
        $name = $this->getName($file['name']);
        $path = $this->getPath . $name;
        list($ret, $err) = $uploadMgr->putFile($token, $path, $filePath);

        if ($err !== null) {
            return array(
                'msg' => $err,
                'state' => 0,
            );
        } else {
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $this->storage['space_domain'] . '/' . $path,
                'state' => 1,
            );
        }
    }


    /**
     * 又拍云上传方法
     * @param  \think\Request  $file
     */
    function upyun_upload($file)
    {
        $serviceConfig = new Config($this->storage['bucket'], $this->storage['AccessKey'], $this->storage['SecretKey']);
        $client = new Upyun($serviceConfig);
        $filePath = $file['tmp_name'];
        // 上传后保存的文件名
        $name = $this->getName($file['name']);
        $path = $this->getPath . $name;
        try {
            $client->write($path, fopen($filePath, 'r'));
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $this->storage['space_domain'] . '/' . $path,
                'state' => 1,
            );
        } catch (\Exception $e) {
            return array(
                'msg' => $e->getMessage(),
                'state' => 0,
            );
        }
    }

    /**
     * 华为云上传方法
     * @param  \think\Request  $file
     */
    function hwyun_upload($file)
    {
        $obsClient = new ObsClient([
            'key' => $this->storage['AccessKey'],
            'secret' => $this->storage['SecretKey'],
            'endpoint' => $this->storage['region']
        ]);
        $filePath = $file['tmp_name'];
        // 上传后保存的文件名
        $name = $this->getName($file['name']);
        $path = $this->getPath . $name;
        try {
            $obsClient->putObject([
                'Bucket' => $this->storage['bucket'],
                'Key' => $path,
                'SourceFile' => $filePath  // localfile为待上传的本地文件路径，需要指定到具体的文件名
            ]);
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $this->storage['space_domain'] . '/' . $path,
                'state' => 1,
            );
        } catch (\Exception $e) {
            return array(
                'msg' => $e->getMessage(),
                'state' => 0,
            );
        }
    }

    /**
     * 华为云删除方法
     * @param  \think\Request  $file
     */
    function hwyun_delete($path)
    {
        $obsClient = new ObsClient([
            'key' => $this->storage['AccessKey'],
            'secret' => $this->storage['SecretKey'],
            'endpoint' => $this->storage['region']
        ]);

        $obsClient->deleteObject([
            'Bucket' => $this->storage['bucket'],
            'Key' => $path,
        ]);
    }

    // ================================================================
    // WebP 自动转换（[IMG-WEBP]）—— 统一上传入口调用，所有存储类型生效
    // ================================================================

    /**
     * 上传前把 jpg/png 按真实内容压缩成 WebP（引用修改 $_FILES 项）。
     *
     * 设计原则（踩坑后定稿）：
     *  1. 按 getimagesize()['mime'] 判定，不信扩展名；
     *  2. GIF / 已是 WebP 一律跳过（GIF 动图转 WebP 只剩第一帧）；
     *  3. 转换后变大则放弃，保留原图；
     *  4. 像素 > 2400 万（24MP）跳过，避免 GD 内存溢出；
     *  5. 任何异常都回退原图，不影响上传成功率；
     *  6. 转换产物覆盖回原 tmp 路径（而非 move_uploaded_file 产物），
     *     这样下游各存储方法零改动，move_uploaded_file 依旧认这个 tmp。
     *
     * @param array $file $_FILES['file'] 的引用
     * @return void
     */
    public function toWebp(&$file)
    {
        if (!function_exists('imagewebp') || empty($file['tmp_name']) || !is_file($file['tmp_name'])) {
            return;
        }
        $srcTmp = $file['tmp_name'];
        $sz = @getimagesize($srcTmp);
        if (!$sz || empty($sz['mime'])) {
            return;
        }
        $mime = $sz['mime'];
        // 只处理 jpg/png；gif/webp/其他跳过
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return;
        }
        // 24MP 上限
        if (((int)$sz[0] * (int)$sz[1]) > 24000000) {
            return;
        }
        $origSize = @filesize($srcTmp);

        $im = ($mime === 'image/png') ? @imagecreatefrompng($srcTmp) : @imagecreatefromjpeg($srcTmp);
        if ($im === false) {
            return;
        }
        // 保留 PNG 透明通道三件套
        if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($im);
        @imagealphablending($im, true);
        @imagesavealpha($im, true);

        $webpTmp = $srcTmp . '.webp';
        $ok = @imagewebp($im, $webpTmp, 82); // 质量 82：体积/画质平衡点
        @imagedestroy($im);

        do {
            if (!$ok || !is_file($webpTmp)) {
                break;
            }
            $webpSize = @filesize($webpTmp);
            if ($webpSize <= 0 || ($origSize !== false && $webpSize > $origSize * 1.05)) {
                break; // 变大（超 5% 容差）→ 保留原图
            }
            // 覆盖回原 tmp 路径：rename 在同分区为原子覆盖，失败则 copy 兜底
            if (!@rename($webpTmp, $srcTmp)) {
                if (!@copy($webpTmp, $srcTmp)) {
                    break;
                }
                @unlink($webpTmp);
            }
            // 同步元信息：后缀 / mime / size 全部变更，写库自动一致
            $file['name'] = preg_replace('/\.[^.]+$/', '', $file['name']) . '.webp';
            $file['type'] = 'image/webp';
            $file['size'] = $webpSize;
            return; // 转换成功
        } while (false);

        // 失败/变大：清理临时产物，保持原图不动
        if (is_file($webpTmp)) @unlink($webpTmp);
    }

    // ================================================================
    // S3 兼容协议上传/删除（AWS Signature V4）
    // 字段复用：space_domain=Endpoint、region=Region、bucket、AK/SK
    // ================================================================

    /**
     * 实例化 S3 客户端
     */
    private function s3Client()
    {
        // 三字段：space_domain=API端点(含bucket)、AccessKey、SecretKey
        return new S3Client(
            (string)($this->storage['space_domain'] ?? ''),
            (string)($this->storage['AccessKey'] ?? ''),
            (string)($this->storage['SecretKey'] ?? '')
        );
    }

    /**
     * S3 上传方法
     */
    function s3_upload($file)
    {
        $name = $this->getName($file['name']);
        $path = $this->getPath . $name;
        $mime = $file['type'] ?? 'application/octet-stream';
        try {
            $client = $this->s3Client();
            $res = $client->putObject($path, $file['tmp_name'], $mime);
            if (!$res['success']) {
                return array('msg' => $res['msg'], 'state' => 0);
            }
            return array(
                'path' => $path,
                'name' => $name,
                'url' => $client->url($path),
                'state' => 1,
            );
        } catch (\Throwable $e) {
            return array('msg' => $e->getMessage(), 'state' => 0);
        }
    }

    /**
     * S3 删除方法
     */
    function s3_delete($path)
    {
        try {
            $client = $this->s3Client();
            return $client->deleteObject($path);
        } catch (\Throwable $e) {
            return array('msg' => $e->getMessage(), 'state' => 0);
        }
    }
}
