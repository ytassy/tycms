<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Mail;

use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\UserWelcomeMailHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

/**
 * Envoi différé d'un message de bienvenue.
 *
 * Le payload ne porte que des références : modèle, compte, Space, langue. Le
 * destinataire, son prénom et le contenu sont relus à l'exécution, et le rendu
 * comme l'envoi restent dans `UserWelcomeMailHelper`. Ce message ne contient
 * jamais de mot de passe ; l'accueil d'un compte créé par un administrateur,
 * qui en porte un, n'utilise pas la file.
 */
final class SendMailJobHandler extends BaseJobHandler
{
    use TraitCoreAdditionalHelpers;

    /** Métadonnées du type, lues par `JobType::for()`. */

    /** Identifiant du type enregistré par Core. */
    public const JOB_TYPE = 'core.mail_send';

    public const JOB_LABEL = 'Envoi d’un e-mail de bienvenue';

    /** Durée attendue : un envoi SMTP court sur mutualisé. */
    public const JOB_MIN_TIME = 3;

    /** Un échec SMTP transitoire mérite une seconde chance, au risque d'un doublon. */
    public const JOB_MAX_ATTEMPTS = 2;

    /**
     * Modèles diffusables par la file.
     *
     * L'accueil d'un compte créé par un administrateur n'en fait pas partie :
     * il porte le mot de passe initial, qui ne doit jamais transiter par une
     * ligne de file lisible en base.
     */
    public const TEMPLATES = [
        UserWelcomeMailHelper::TEMPLATE_LOCAL,
        UserWelcomeMailHelper::TEMPLATE_SSO,
    ];

    /**
     * Job prêt à enfiler, pour tous les modèles du type.
     *
     * Le producteur passe des valeurs métier ; les clés du payload et des
     * options restent la propriété du handler. La clé de déduplication dérive
     * du modèle et du compte, ce qui évite deux envois pour une inscription.
     */
    public static function create(
        string $template,
        int $userId,
        int $spaceId,
        ?string $locale = null,
    ): JobRequest {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new InvalidArgumentException(sprintf(
                'Modèle de message non diffusable par la file : "%s".',
                $template,
            ));
        }

        return new JobRequest(self::JOB_TYPE, [
            'template' => $template,
            'user_id' => $userId,
            'space_id' => $spaceId,
            'locale' => $locale,
        ], [
            'space_id' => $spaceId,
            'created_by' => $userId,
            'dedup_key' => 'welcome:' . $template . ':' . $userId,
        ]);
    }

    public function handle(JobContext $context, array $payload): JobResult
    {
        $template = is_string($payload['template'] ?? null) ? $payload['template'] : '';
        $userId = is_int($payload['user_id'] ?? null) ? $payload['user_id'] : 0;
        $spaceId = is_int($payload['space_id'] ?? null) ? $payload['space_id'] : 0;
        $locale = is_string($payload['locale'] ?? null) ? $payload['locale'] : null;

        // Le contrôle est refait à l'exécution : une ligne de file peut avoir
        // été écrite par un autre chemin que la fabrique.
        if (!in_array($template, self::TEMPLATES, true) || $userId <= 0 || $spaceId <= 0) {
            return JobResult::failure('Payload de message de bienvenue invalide.');
        }

        $user = $this->db->selectOneOrNull(
            'SELECT id, type, email, first_name FROM users WHERE id = % LIMIT 1',
            [$userId],
        );

        if ($user === null || (string) $user->type === 'service') {
            return JobResult::failure('Compte introuvable pour ce message.');
        }

        $space = $this->coreSpaceHelper->getAllSpacesByIds([$spaceId])[$spaceId] ?? null;

        if ($space === null) {
            return JobResult::failure('Espace introuvable pour ce message.');
        }

        // Le modèle et la résolution de langue restent dans le helper métier :
        // la file ne duplique ni le contenu ni les règles de langue.
        $sent = match ($template) {
            UserWelcomeMailHelper::TEMPLATE_LOCAL => $this->coreUserWelcomeMailHelper->sendLocalRegistration(
                $space,
                (string) $user->email,
                (string) $user->first_name,
                $locale,
            ),
            UserWelcomeMailHelper::TEMPLATE_SSO => $this->coreUserWelcomeMailHelper->sendSsoRegistration(
                $space,
                (string) $user->email,
                $locale,
            ),
        };

        return $sent
            ? JobResult::success()
            : JobResult::failure('Le message de bienvenue n’a pas pu être envoyé.');
    }
}
