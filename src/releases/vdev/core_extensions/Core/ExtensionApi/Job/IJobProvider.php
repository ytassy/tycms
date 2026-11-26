<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

use YTassy\Tycms\CoreExts\Core\JobQueue\JobTypeRegistry;

/**
 * Fournisseur de types de jobs d'une extension.
 *
 * L'enregistrement a lieu sur `onPrepareExtensions`, avant toute route et pour
 * tout parcours d'initialisation, comme les autres registres de capacités.
 */
interface IJobProvider
{
    public function coreOnRegisterJobTypes(JobTypeRegistry $registry): void;
}
