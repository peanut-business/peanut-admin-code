<?php

declare(strict_types=1);

namespace PeanutAdmin\Modules\Identity\Console;

use app\common\execution\ContextualCommand;
use app\common\execution\CurrentExecutionContext;
use app\common\execution\ExecutionContextStore;
use PeanutAdmin\Modules\Identity\Audit\AuditService;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** One operator-invoked bounded scan; no HTTP, automatic schedule or Node RPC. */
final class RevocationFactsCommand extends ContextualCommand
{
    public function __construct(
        private readonly AuditService $facts,
        private readonly \Closure $delivery,
        ExecutionContextStore $contexts,
        CurrentExecutionContext $execution,
    ) {
        parent::__construct($contexts, $execution);
    }

    protected function configure(): void
    {
        $this->setName('identity:revocations')->setDescription('Discover committed negative facts, enqueue missing jobs and record unconfirmed ACK candidates')
            ->addOption('tenant', null, Option::VALUE_REQUIRED, 'Exact tenant ID')
            ->addOption('after', null, Option::VALUE_REQUIRED, 'Audit ID cursor', '0')
            ->addOption('limit', null, Option::VALUE_REQUIRED, 'Bounded batch 1-100', '20')
            ->addOption('repair', null, Option::VALUE_NONE, 'Explicitly reopen one further attempt for dead/stale-holder negative jobs within original maximum10');
    }

    protected function handle(Input $input, Output $output): int
    {
        if (!$this->establishedInstanceContext()) {
            throw new \DomainException('REVOCATION_DISCOVERY_ROOT_REQUIRED');
        }
        $tenant = filter_var($input->getOption('tenant'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $after = filter_var($input->getOption('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($input->getOption('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        if ($tenant === false || $after === false || $limit === false) {
            throw new \InvalidArgumentException('REVOCATION_DISCOVERY_INPUT_INVALID');
        }
        $consumer = ($this->delivery)();
        $results = [];
        foreach ($this->facts->revocationFacts($tenant, $after, $limit) as $fact) {
            $state = $consumer === null ? ['status' => 'consumer_unavailable', 'notification_overdue_candidate' => time() - strtotime($fact['occurred_at'] . ' UTC') >= 30]
                : $consumer->reconcileRevocation($fact, (bool) $input->getOption('repair'));
            if ($state['notification_overdue_candidate']) {
                $this->facts->recordRevocationOverdueCandidate($fact);
            }
            $after = $fact['audit_id'];
            $results[] = ['fact_id' => $fact['fact_id'], 'audit_id' => $after] + $state;
        }
        $output->writeln(json_encode(['tenant_id' => $tenant, 'next_after' => $after, 'items' => $results, 'timing_qualification' => 'NOT_QUALIFIED'], JSON_THROW_ON_ERROR));
        return 0;
    }
}
