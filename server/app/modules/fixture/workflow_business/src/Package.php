<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness;

final class Package
{
    public const MODULE_KEY = 'fixture.workflow-business';
    public const SCENES = ['application', 'purchase', 'receipt'];
    public const OPERATIONS = ['read', 'collaborate', 'update', 'confirm', 'start', 'approve', 'reject', 'complete', 'receive', 'terminate', 'restart', 'retry', 'reassign', 'claim', 'release', 'return', 'cancel', 'withdraw'];

    public static function resource(string $scene): string
    {
        if (!in_array($scene, self::SCENES, true)) {
            throw new \InvalidArgumentException('WORKFLOW_BUSINESS_SCENE_INVALID');
        }
        return self::MODULE_KEY . '.' . $scene;
    }

    public static function scene(string $resource): string
    {
        foreach (self::SCENES as $scene) {
            if ($resource === self::resource($scene)) {
                return $scene;
            }
        }
        throw new \InvalidArgumentException('WORKFLOW_BUSINESS_RESOURCE_INVALID');
    }

    public static function action(string $scene): string
    {
        return self::MODULE_KEY . '.' . $scene . ($scene === 'receipt' ? '.receive-stock' : '.record-decision');
    }
}
