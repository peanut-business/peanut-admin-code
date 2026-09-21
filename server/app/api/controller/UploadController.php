<?php
declare(strict_types=1);

namespace app\api\controller;

use app\modules\official\file\contracts\FileUploads;
use app\modules\official\file\contracts\dto\UploadFile;
use app\common\enum\FileEnum;
use think\file\UploadedFile;
use app\common\exception\BusinessException;

/**
 * 用户端上传
 */
class UploadController extends BaseApiController
{
    protected function uploads(): FileUploads
    {
        return $this->app->get(FileUploads::class);
    }

    public function image()
    {
        $cidValue = $this->request->post('cid', 0);
        if (!is_int($cidValue) && !(is_string($cidValue) && preg_match('/^-?\d+$/D', $cidValue) === 1)) {
            throw new \InvalidArgumentException('目标分类无效');
        }
        $uploaded = $this->request->file('file');
        if (!$uploaded instanceof UploadedFile) {
            throw BusinessException::invalid('UPLOAD_FILE_REQUIRED', '未接收到上传文件');
        }
        $result = $this->uploads()->image(
            $this->memberContext(),
            new UploadFile(
                (string)$uploaded->getPathname(),
                (string)$uploaded->getOriginalName(),
                (int)$uploaded->getSize(),
                (string)($uploaded->getMime() ?: 'application/octet-stream'),
                (string)$uploaded->getOriginalExtension(),
            ),
            (int)$cidValue,
            $this->memberId,
            FileEnum::SOURCE_USER,
        );
        return $this->success('上传成功', $result);
    }
}
