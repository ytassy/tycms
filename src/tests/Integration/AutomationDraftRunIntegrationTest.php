<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\WF\WFAutomation;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/** Run manuel du brouillon enregistré, distinct du run de la version publiée. */
final class AutomationDraftRunIntegrationTest extends TestCase
{
    private Application $app;
    private Database $db;
    private WFAutomation $wf;
    private string $key;

    protected function setUp(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $this->app = new Application($environment->configuration());

        $this->app->initialiseOrThrow(
            new Request(method: \YTassy\Tycms\Request\RequestMethod::GET, uri: '/'),
            initializeSession: false,
            session: $this->sessionStub(1),
            registerRoutes: false,
        );
        $this->db = $this->app->getDatabase();
        $this->db->execute('START TRANSACTION');
        $this->wf = new WFAutomation($this->app);
        $this->key = 'draft-run-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->execute('ROLLBACK');
        }
    }

    public function testDraftRunUsesTheSavedDraftVersion(): void
    {
        [$scenarioId, $publishedVersionId, $draftVersionId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
        );

        $result = $this->wf->runManual($scenarioId, [], true);

        self::assertSame('success', (string) $result['status']);
        self::assertTrue($result['draft']);
        self::assertIsInt($result['run_id']);

        $run = $this->db->selectOneOrNull(
            'SELECT version_id, status FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($run);
        self::assertSame('success', (string) $run->status);
        self::assertSame($draftVersionId, (int) $run->version_id);
        self::assertNotSame($publishedVersionId, (int) $run->version_id);
    }

    public function testRunWithoutDraftStillUsesThePublishedVersion(): void
    {
        [$scenarioId, $publishedVersionId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
        );

        $result = $this->wf->runManual($scenarioId, []);

        self::assertSame('success', (string) $result['status']);
        self::assertFalse($result['draft']);

        $run = $this->db->selectOneOrNull(
            'SELECT version_id FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($run);
        self::assertSame($publishedVersionId, (int) $run->version_id);
    }

    public function testInvalidDraftIsRefusedWithoutCreatingARun(): void
    {
        [$scenarioId, , $draftVersionId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() {',
            draftCompileStatus: 'error',
        );

        $result = $this->wf->runManual($scenarioId, [], true);

        self::assertSame('invalid', (string) $result['status']);
        self::assertNull($result['run_id']);
        self::assertTrue($result['draft']);
        self::assertNotEmpty($result['diagnostics']);

        $run = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS runs_count FROM automation_runs WHERE scenario_id = % AND version_id = %',
            [$scenarioId, $draftVersionId],
        );
        self::assertNotNull($run);
        self::assertSame(0, (int) $run->runs_count);
    }

    public function testDraftRunRequiresEditPermission(): void
    {
        [$scenarioId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
        );
        $this->actAs($this->restrictedUser(['RUN']));

        $this->expectException(ExceptionResponse::class);
        $this->wf->runManual($scenarioId, [], true);
    }

    public function testAuthorDraftRunRequiresPublishPermission(): void
    {
        [$scenarioId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
            principalMode: 'author',
            executionUserId: 1,
        );
        $this->actAs($this->restrictedUser(['RUN', 'EDIT']));

        $this->expectException(ExceptionResponse::class);
        $this->wf->runManual($scenarioId, [], true);
    }

    public function testAuthorDraftRunUsesTheCurrentUserEvenWithAPublishedAuthor(): void
    {
        // Le brouillon ne doit jamais emprunter l'identité de l'auteur publié :
        // les ACL des actions restent celles de la personne qui exécute.
        [$scenarioId, , $draftVersionId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
            principalMode: 'author',
            executionUserId: 1,
        );
        $editorId = $this->restrictedUser(['RUN', 'EDIT', 'PUBLISH']);
        $this->actAs($editorId);

        $result = $this->wf->runManual($scenarioId, [], true);

        self::assertSame('success', (string) $result['status']);

        $run = $this->db->selectOneOrNull(
            'SELECT version_id, principal_id FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($run);
        self::assertSame($draftVersionId, (int) $run->version_id);
        self::assertSame($editorId, (int) $run->principal_id);
        self::assertNotSame(1, (int) $run->principal_id);
    }

    public function testAuthorDraftRunWithoutPublishedAuthorUsesTheCurrentUser(): void
    {
        // Avant la première publication, il n'existe pas d'auteur : le run
        // utilise les droits de l'utilisateur courant, comme après publication.
        [$scenarioId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
            principalMode: 'author',
            executionUserId: null,
        );
        $editorId = $this->restrictedUser(['RUN', 'EDIT', 'PUBLISH']);
        $this->actAs($editorId);

        $result = $this->wf->runManual($scenarioId, [], true);

        self::assertSame('success', (string) $result['status']);

        $run = $this->db->selectOneOrNull(
            'SELECT principal_id FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($run);
        self::assertSame($editorId, (int) $run->principal_id);
    }

    public function testTriggerDraftRunIsAllowedWithEditAndRun(): void
    {
        [$scenarioId, , $draftVersionId] = $this->story(
            'function main() { return ["source" => "publiée"]; }',
            'function main() { return ["source" => "brouillon"]; }',
        );
        $editorId = $this->restrictedUser(['RUN', 'EDIT']);
        $this->actAs($editorId);

        $result = $this->wf->runManual($scenarioId, [], true);

        self::assertSame('success', (string) $result['status']);

        $run = $this->db->selectOneOrNull(
            'SELECT version_id, principal_type, principal_id FROM automation_runs WHERE id = %',
            [$result['run_id']],
        );
        self::assertNotNull($run);
        self::assertSame($draftVersionId, (int) $run->version_id);
        self::assertSame('user', (string) $run->principal_type);
        self::assertSame($editorId, (int) $run->principal_id);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private function story(
        string $publishedSource,
        string $draftSource,
        string $draftCompileStatus = 'ok',
        string $principalMode = 'trigger',
        ?int $executionUserId = null,
    ): array {
        $this->db->execute(
            'INSERT INTO automation_scenarios
                (space_id, `key`, name, description, status, principal_mode, execution_user_id, created_by, created)
             VALUES (1, %, %, \'\', \'active\', %, %, 1, current_timestamp())',
            [$this->key, 'Brouillon ' . $this->key, $principalMode, $executionUserId],
        );
        $scenarioId = $this->db->getLastInsertId();

        $publishedVersionId = $this->insertVersion($scenarioId, 1, 'published', 'ok', $publishedSource);
        $draftVersionId = $this->insertVersion($scenarioId, 2, 'draft', $draftCompileStatus, $draftSource);

        $this->db->execute(
            'UPDATE automation_scenarios SET published_version_id = %, draft_version_id = % WHERE id = %',
            [$publishedVersionId, $draftVersionId, $scenarioId],
        );
        $this->db->execute(
            'INSERT INTO automation_triggers
                (scenario_id, space_id, trigger_id, entry_function, config, secret_hash, enabled, created)
             VALUES (%, 1, \'core.page.button.clicked\', \'main\', \'{}\', NULL, 1, current_timestamp())',
            [$scenarioId],
        );

        return [$scenarioId, $publishedVersionId, $draftVersionId];
    }

    private function insertVersion(
        int $scenarioId,
        int $versionNumber,
        string $status,
        string $compileStatus,
        string $source,
    ): int {
        $published = $status === 'published';

        $this->db->execute(
            'INSERT INTO automation_versions
                (scenario_id, version_number, status, compile_status, source, source_hash,
                 ast, schema_version, grammar_version, diagnostics, created_by, created, published_by, published_at)
             VALUES (%, %, %, %, %, %, NULL, 1, 1, NULL, 1, current_timestamp(), %, %)',
            [
                $scenarioId,
                $versionNumber,
                $status,
                $compileStatus,
                $source,
                hash('sha256', $source),
                $published ? 1 : null,
                $published ? date('Y-m-d H:i:s') : null,
            ],
        );

        return $this->db->getLastInsertId();
    }

    /**
     * Crée un compte rattaché à un rôle global du Space Root avec les seules
     * permissions demandées, puis invalide le cache ACL de l'Application.
     *
     * @param list<string> $permissions
     */
    private function restrictedUser(array $permissions): int
    {
        $suffix = bin2hex(random_bytes(6));

        $this->db->execute(
            'INSERT INTO users (space_id, first_name, last_name, email, password, params)
             VALUES (1, \'Brouillon\', \'Éditeur\', %, \'unused\', \'{}\')',
            ['draft-run-' . $suffix . '@integration.test'],
        );
        $userId = $this->db->getLastInsertId();

        $this->db->execute(
            'INSERT INTO user_groups (space_id, parent_id, type, name) VALUES (1, NULL, 0, %)',
            ['draft-run-' . $suffix],
        );
        $groupId = $this->db->getLastInsertId();
        $this->db->execute('INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)', [$userId, $groupId]);

        $this->db->execute(
            'INSERT INTO acl_roles (space_id, user_defined, name, description, type) VALUES (1, 1, %, \'\', 0)',
            ['Rôle brouillon ' . $suffix],
        );
        $roleId = $this->db->getLastInsertId();

        foreach ($permissions as $permission) {
            $this->db->execute(
                'INSERT INTO acl_permissions (role_id, extension, name) VALUES (%, \'plg_automation\', %)',
                [$roleId, $permission],
            );
        }

        $this->db->execute('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (%, %, 1)', [$roleId, $groupId]);

        // L'AclHelper charge les rôles à sa première utilisation : l'oublier
        // garantit que les permissions créées pour ce test sont bien lues.
        $property = new \ReflectionProperty(Application::class, 'sharedExtensionsClasses');
        $helpers = $property->getValue($this->app);
        unset($helpers[AclHelper::class]);
        $property->setValue($this->app, $helpers);

        return $userId;
    }

    private function actAs(int $userId): void
    {
        (new \ReflectionProperty(Application::class, 'session'))->setValue($this->app, $this->sessionStub($userId));
    }

    private function sessionStub(int $userId): Session
    {
        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn($userId);
        $session->method('getSpaceId')->willReturn(1);

        return $session;
    }
}
