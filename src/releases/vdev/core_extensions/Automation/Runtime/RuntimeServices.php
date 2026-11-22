<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\CoreExts\Automation\Language\Limits;

final readonly class RuntimeServices
{
    public function __construct(
        public ExecutionContext $context,
        public ExecutionBudget $budget,
        public ExecutionJournal $journal,
        public Limits $limits,
    ) {
    }
}
