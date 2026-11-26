<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use Throwable;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;

/** Envoie les messages de bienvenue sans rendre l'inscription dépendante du SMTP. */
final class UserWelcomeMailHelper extends BaseHelper
{
    /** Inscription locale : aucun secret, donc diffusable par la file. */
    public const TEMPLATE_LOCAL = 'plg_core__registration_welcome';

    /** Première inscription SSO : aucun secret, donc diffusable par la file. */
    public const TEMPLATE_SSO = 'plg_core__registration_sso_welcome';

    /** Compte créé par un administrateur : porte un mot de passe, jamais mis en file. */
    public const TEMPLATE_ADMIN = 'plg_core__admin_account_welcome';

    public function sendLocalRegistration(Space $space, string $email, string $firstName, ?string $locale): bool
    {
        return $this->send(
            self::TEMPLATE_LOCAL, $space, $email, $locale,
            ['space_title' => $space->title, 'first_name' => $firstName],
        );
    }

    public function sendSsoRegistration(Space $space, string $email, ?string $locale): bool
    {
        return $this->send(
            self::TEMPLATE_SSO, $space, $email, $locale,
            ['space_title' => $space->title],
        );
    }

    public function sendAdminCreatedAccount(
        Space $space, string $email, string $firstName, string $password, ?string $locale = null,
    ): bool {
        return $this->send(
            self::TEMPLATE_ADMIN, $space, $email, $locale,
            ['space_title' => $space->title, 'first_name' => $firstName, 'initial_password' => $password],
        );
    }

    /** @param array<string,string> $values */
    private function send(string $key, Space $space, string $email, ?string $locale, array $values): bool
    {
        // La clé vient des trois méthodes métier ci-dessus, jamais du contenu du message.
        $context = ['space_id' => $space->id, 'kind' => $key];

        try {
            // Une route technique SSO peut conserver la langue d'un autre espace.
            $activeLocales = array_map(
                static fn ($language): string => $language->locale,
                $this->coreSpaceLanguageHelper->listActiveForSpace($space->id),
            );
            if ($locale === null || !in_array($locale, $activeLocales, true)) {
                $locale = $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
            }

            if ($this->coreMailHelper->sendTemplate($key, $email, $values, $space, $locale)) {
                return true;
            }

            $this->logWarning('L’e-mail de bienvenue n’a pas été envoyé.', $context, 'WELCOME_MAIL_NOT_SENT', 'mail');
        } catch (Throwable $exception) {
            // Le compte reste créé ; conserver les causes sans destinataire, valeurs ou mot de passe initial.
            $this->logException(new LogEventException('WELCOME_MAIL_FAILED',
                'La préparation ou l’envoi de l’e-mail de bienvenue a échoué.', 'mail', $context, 'ERROR', $exception));
        }

        $this->pendingMessageQueue->warning(
            "Le compte a été créé, mais l'e-mail de bienvenue n'a pas pu être envoyé.",
        );
        return false;
    }
}
