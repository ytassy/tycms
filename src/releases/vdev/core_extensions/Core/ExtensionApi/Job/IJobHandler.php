<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

/**
 * Contrat d'exécution d'un type de job.
 *
 * Contraintes que le worker ne peut pas imposer à la place du handler :
 * l'idempotence (la file livre au moins une fois), les délais des appels
 * distants (une entrée-sortie bloquante n'est pas interrompue), et la relecture
 * des données protégées plutôt que la confiance dans le payload.
 */
interface IJobHandler
{
    /**
     * @param array<string, mixed> $payload Payload décodé, référence seulement.
     */
    public function handle(JobContext $context, array $payload): JobResult;
}
