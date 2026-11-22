<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Maintenance;

/** Seuils et valeurs par défaut de la maintenance manuelle. */
final class MaintenancePolicy
{
    /** Un événement encore pending après ce délai est considéré orphelin. */
    public const PENDING_EVENT_SECONDS = 300;

    /** Un événement processing depuis ce délai est considéré bloqué. */
    public const PROCESSING_EVENT_SECONDS = 600;

    /** Un run queued/running depuis ce délai est considéré bloqué. */
    public const STUCK_RUN_SECONDS = 600;

    /** Nombre maximal de tentatives avant de refuser une reprise automatique. */
    public const MAX_EVENT_ATTEMPTS = 3;

    public const DEFAULT_EVENT_RETENTION_DAYS = 30;
    public const DEFAULT_RUN_RETENTION_DAYS = 90;
    public const MAX_RETENTION_DAYS = 3650;

    /** Nombre de lignes affichées dans l'écran de maintenance. */
    public const LIST_LIMIT = 50;

    /** Taille d'un lot de suppression. */
    public const CLEANUP_BATCH_SIZE = 500;

    /** Garde-fou sur le nombre de lots par nettoyage. */
    public const CLEANUP_MAX_BATCHES = 20;

    private function __construct()
    {
    }
}
