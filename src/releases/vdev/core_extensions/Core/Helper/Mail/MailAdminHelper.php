<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\Models\Space;

class MailAdminHelper extends MailHelper
{
    /** @var list<string> */
    private const TEST_SETTING_KEYS = [
        'mail_online', 'mailer', 'mail_from', 'mail_from_name', 'mail_smtp_auth',
        'mail_smtp_user', 'mail_smtp_host', 'mail_smtp_secure', 'mail_smtp_port',
    ];

    /**
     * Protect a submitted SMTP password, or an existing plaintext password on
     * the next Space save. A blank submission preserves the current password.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function protectSmtpPassword(array $params, string $submittedPassword): array
    {
        $submittedPassword = trim($submittedPassword);
        if ($submittedPassword !== '') {
            $params['mail_smtp_pass'] = $this->coreCryptHelper->encrypt($submittedPassword, 'mail-smtp:v1');
            $params['mail_smtp_pass_key_version'] = 1;
        } elseif (($params['mail_smtp_pass'] ?? '') !== ''
            && (int) ($params['mail_smtp_pass_key_version'] ?? 0) === 0) {
            $params['mail_smtp_pass'] = $this->coreCryptHelper->encrypt(
                (string) $params['mail_smtp_pass'], 'mail-smtp:v1'
            );
            $params['mail_smtp_pass_key_version'] = 1;
        }

        return $params;
    }

    /**
     * Try the unsaved SMTP settings without changing the Space. An empty password
     * keeps the password already stored for this Space.
     *
     * @param array<string, mixed> $settings
     */
    public function sendTestHtml(Space $space, string $recipient, array $settings): bool
    {
        $params = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
        foreach (self::TEST_SETTING_KEYS as $key) {
            if (array_key_exists($key, $settings)) {
                $params[$key] = $settings[$key];
            }
        }

        $password = trim((string) ($settings['mail_smtp_pass'] ?? ''));
        if ($password !== '') {
            $params['mail_smtp_pass'] = $password;
            $params['mail_smtp_pass_key_version'] = 0;
        }

        $spaceTitle = htmlspecialchars($space->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return $this->sendHtmlWithParams(
            $recipient,
            'Test d’envoi de mail — ' . $space->title,
            '<p>Ce message confirme que l’envoi de mail fonctionne pour l’espace <strong>' . $spaceTitle . '</strong>.</p>',
            $params,
        );
    }

    /** Send a preview with unsaved template settings and the Space's saved SMTP settings. */
    public function sendTemplateTestHtml(Space $space, string $recipient, object $templateParams, string $mode): bool
    {
        $params = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
        if ($this->app->getConf()->mailonline === false || !($params['mail_online'] ?? true)) {
            return false;
        }

        $siteUrl = $this->siteUrlForEmail($space);
        $spaceTitle = htmlspecialchars($space->title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<h1>Message de test</h1>'
            . '<p>Bonjour,</p>'
            . '<p>Ceci est un essai de l’habillage e-mail de l’espace <strong>' . $spaceTitle . '</strong>.</p>'
            . '<p><a href="' . htmlspecialchars($siteUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Visiter le site</a></p>';

        $renderedHtml = $this->renderHtmlWithTemplateParams($html, $space, $mode, $templateParams);
        return $this->sendHtmlWithParams(
            $recipient,
            'Test du modèle e-mail ' . $mode . ' — ' . $space->title,
            $renderedHtml,
            $params,
        );
    }
}
