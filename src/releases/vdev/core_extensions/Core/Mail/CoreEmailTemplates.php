<?php

namespace YTassy\Tycms\CoreExts\Core\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;

/** Modèles métier livrés par Core ; leurs textes peuvent être surchargés par Space. */
final class CoreEmailTemplates
{
    /** @return list<EmailTemplateDefinition> */
    public static function all(): array
    {
        return [
            new EmailTemplateDefinition(
                id: 'password_reset',
                label: 'Mot de passe oublié',
                description: 'Lien permettant de choisir un nouveau mot de passe.',
                customizable: true,
                renderMode: 'default',
                variants: [
                    'fr' => [
                        'subject' => 'Réinitialiser votre mot de passe',
                        'bodyHtml' => '<p>Bonjour,</p><p>Pour choisir un nouveau mot de passe, '
                            . 'cliquez ici: <a href="%reset_url%">%reset_url%</a>.</p>',
                    ],
                    'en' => [
                        'subject' => 'Reset your password',
                        'bodyHtml' => '<p>Hello,</p><p>To choose a new password, '
                            . 'Click here: <a href="%reset_url%">%reset_url%</a>.</p>',
                    ],
                ],
                variables: [new EmailTemplateVariable(
                    'reset_url', 'url', true, 'https://example.test/reset/example',
                )],
            ),
            new EmailTemplateDefinition(
                id: 'registration_welcome',
                label: 'Bienvenue après inscription',
                description: 'Message de bienvenue pour un nouveau compte.',
                customizable: true,
                renderMode: 'default',
                variants: [
                    'fr' => [
                        'subject' => 'Bienvenue sur %space_title%',
                        'bodyHtml' => '<p>Bonjour %first_name%,</p><p>Votre inscription est enregistrée.</p>',
                    ],
                    'en' => [
                        'subject' => 'Welcome to %space_title%',
                        'bodyHtml' => '<p>Hello %first_name%,</p><p>Your registration is complete.</p>',
                    ],
                ],
                variables: [
                    new EmailTemplateVariable('space_title', 'text', true, 'Exemple de site'),
                    new EmailTemplateVariable('first_name', 'text', true, 'Alice'),
                ],
            ),
            new EmailTemplateDefinition(
                id: 'registration_sso_welcome',
                label: 'Bienvenue après inscription SSO',
                description: 'Message de bienvenue pour un compte créé avec un fournisseur SSO.',
                customizable: true,
                renderMode: 'default',
                variants: [
                    'fr' => [
                        'subject' => 'Bienvenue sur %space_title%',
                        'bodyHtml' => '<p>Bonjour,</p><p>Votre compte sur %space_title% a été créé avec votre fournisseur de connexion.</p>',
                    ],
                    'en' => [
                        'subject' => 'Welcome to %space_title%',
                        'bodyHtml' => '<p>Hello,</p><p>Your account on %space_title% was created with your sign-in provider.</p>',
                    ],
                ],
                variables: [new EmailTemplateVariable('space_title', 'text', true, 'Exemple de site')],
            ),
            new EmailTemplateDefinition(
                id: 'admin_account_welcome',
                label: 'Compte créé par un administrateur',
                description: 'Bienvenue avec le mot de passe initial du compte.',
                customizable: true,
                renderMode: 'default',
                variants: [
                    'fr' => [
                        'subject' => 'Votre compte sur %space_title%',
                        'bodyHtml' => '<p>Bonjour %first_name%,</p><p>Votre compte a été créé sur %space_title%.</p>'
                            . '<p>Votre mot de passe initial : <strong>%initial_password%</strong></p>'
                            . '<p>Connectez-vous et changez ce mot de passe dès que possible.</p>',
                    ],
                    'en' => [
                        'subject' => 'Your account on %space_title%',
                        'bodyHtml' => '<p>Hello %first_name%,</p><p>Your account has been created on %space_title%.</p>'
                            . '<p>Your initial password: <strong>%initial_password%</strong></p>'
                            . '<p>Sign in and change this password as soon as possible.</p>',
                    ],
                ],
                variables: [
                    new EmailTemplateVariable('space_title', 'text', true, 'Exemple de site'),
                    new EmailTemplateVariable('first_name', 'text', true, 'Alice'),
                    new EmailTemplateVariable(
                        'initial_password', 'text', true, 'ExempleSeulement123!',
                        allowedInSubject: false, requiredInBody: true,
                    ),
                ],
            ),
        ];
    }
}
