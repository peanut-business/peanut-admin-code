<?php

declare(strict_types=1);

namespace PeanutAdmin\Fixtures\WorkflowBusiness\Integration;

use PeanutAdmin\Fixtures\WorkflowBusiness\Package;
use PeanutAdmin\Fixtures\WorkflowBusiness\Service\WorkflowBusinessAccess;
use PeanutAdmin\Modules\Workflow\Integration\WorkflowScenarioRegistry;

final readonly class WorkflowBusinessComposition
{
    public function __construct(private WorkflowBusinessScenarioProvider $provider, private WorkflowBusinessAccess $access) {}

    /** Called by the trusted composition root only when this optional module is selected. */
    public function register(WorkflowScenarioRegistry $registry): void
    {
        foreach (Package::SCENES as $scene) {
            $registry->register(Package::MODULE_KEY, $scene, Package::resource($scene), $this->provider, $this->access, [Package::action($scene)]);
        }
    }
}
