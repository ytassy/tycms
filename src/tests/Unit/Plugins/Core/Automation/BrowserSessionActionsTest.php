<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationActions;
use YTassy\Tycms\CoreExts\Core\Helper\Session\BrowserSessionPropertyHelper;
use YTassy\Tycms\Session\RunSession;
use YTassy\Tycms\Application;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/** Contrat des actions de session du navigateur. */
final class BrowserSessionActionsTest extends FrameworkTestCase
{
    #[Test]
    public function sessionActionsExposeTheirContract(): void
    {
        $definitions = array_column(CoreAutomationActions::definitions(), null, 'id');

        self::assertSame('read', $definitions['core::sessionRead']->effect);
        self::assertSame('write', $definitions['core::sessionWrite']->effect);
        self::assertSame('write', $definitions['core::sessionRemove']->effect);

        // La valeur par défaut précède le chemin : le langage n'ayant pas
        // d'arguments nommés, elle doit rester atteignable en appel positionnel.
        self::assertSame(['key', 'default', 'path'], $this->parameterNames($definitions['core::sessionRead']));
        self::assertSame(['key', 'value'], $this->parameterNames($definitions['core::sessionWrite']));
        self::assertSame(['key'], $this->parameterNames($definitions['core::sessionRemove']));
    }

    #[Test]
    public function everyKeyIsNamespacedBySpaceThenPrefixed(): void
    {
        $helper = new BrowserSessionPropertyHelper($this->createApplication());

        self::assertSame('automation_7_parcours', $helper->storageKey(7, 'parcours', $this->context()));

        // Un nom proche d'une clé interne reste une clé de travail préfixée.
        self::assertSame('automation_1_user_id', $helper->storageKey(1, 'user_id', $this->context()));
        self::assertNotSame(
            $helper->storageKey(1, 'parcours', $this->context()),
            $helper->storageKey(2, 'parcours', $this->context()),
        );
    }

    #[Test]
    public function malformedKeysAreRejected(): void
    {
        $helper = new BrowserSessionPropertyHelper($this->createApplication());

        foreach (['Parcours', 'a.b', '', '-debut', 'é', str_repeat('a', 49)] as $key) {
            try {
                $helper->storageKey(1, $key, $this->context());
                self::fail('Clé invalide acceptée : ' . $key);
            } catch (ExecutionException $exception) {
                self::assertSame(DiagnosticCode::INVALID_ARGUMENT, $exception->diagnostic->code);
            }
        }
    }

    #[Test]
    public function pathReadsAMapAndFallsBackToNull(): void
    {
        $helper = new BrowserSessionPropertyHelper($this->createApplication());

        self::assertSame('ada@example.test', $helper->path(['contact' => ['email' => 'ada@example.test']], 'contact.email'));
        self::assertNull($helper->path(['contact' => []], 'contact.email'));
        self::assertNull($helper->path('texte', 'contact.email'));
        self::assertNull($helper->path(['a' => ['b' => []]], 'a.b.c.d.e.f.g.h.i'));
    }

    #[Test]
    public function runSessionIsReadableButNeverWritten(): void
    {
        $app = $this->createApplication();
        $session = $this->inMemorySession($app);
        $helper = new BrowserSessionPropertyHelper($app);

        // Une donnée déjà rangée par un scénario du même Space reste lisible,
        // même depuis un run en session mémoire.
        $session->set('automation_1_etape', ['nom' => 'Ada']);
        self::assertSame(['nom' => 'Ada'], $helper->read(1, 'etape', null, $this->context()));

        // L'écriture et l'effacement restent réservés à la session du navigateur.
        foreach ([
            fn () => $helper->write(1, 'etape', 'autre', $this->context()),
            fn () => $helper->remove(1, 'etape', $this->context()),
        ] as $modification) {
            try {
                $modification();
                self::fail('Une RunSession ne doit pas être modifiée.');
            } catch (ExecutionException $exception) {
                self::assertSame(DiagnosticCode::ACTION_UNAVAILABLE, $exception->diagnostic->code);
            }
        }

        // Rien n'a été modifié.
        self::assertSame(['nom' => 'Ada'], $session->get('automation_1_etape'));
    }

    private function inMemorySession(Application $app): Session
    {
        $session = new RunSession($app, null, null);
        (new \ReflectionProperty(Application::class, 'session'))->setValue($app, $session);

        return $session;
    }

    /** @return list<string> */
    private function parameterNames(object $definition): array
    {
        return array_map(
            static fn (object $parameter): string => $parameter->name,
            $definition->parameters,
        );
    }

    private function context(): ActionContext
    {
        $position = new SourcePosition(0, 1, 1);

        return new ActionContext(
            AutomationPrincipalType::USER,
            1,
            1,
            null,
            1,
            1,
            new SourceSpan($position, $position),
        );
    }
}
