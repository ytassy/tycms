<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;

/**
 * Base des handlers de job.
 *
 * Un handler n'est pas un helper de couche : c'est l'exécution différée d'une
 * opération métier, fournie par une extension et invoquée hors de la requête
 * qui l'a enfilée. Il étend donc `BaseApplicationItem` et compose seulement
 * `TraitCoreSharedHelpers`, ce qui lui donne la base, le journal et les accès
 * partagés sans revendiquer le rôle d'un helper.
 *
 * Règles que la file ne peut pas imposer à la place du handler :
 *
 * - **Idempotence** : la file livre au moins une fois. Un job interrompu entre
 *   un effet externe et l'écriture du statut sera rejoué.
 * - **Ses propres délais** : le worker ne peut pas interrompre une
 *   entrée-sortie bloquante ; chaque appel distant porte son timeout.
 * - **Payload de références** : les données protégées sont relues à
 *   l'exécution, jamais prises pour une autorisation dans le payload.
 * - **`min_time` honnête** : une valeur sous-estimée ne fait pas échouer le job
 *   proprement, elle fait dépasser le budget du tick.
 */
abstract class BaseJobHandler extends BaseApplicationItem implements IJobHandler
{
    use TraitCoreSharedHelpers;
}
