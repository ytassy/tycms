<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\CompilationResult;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionSignature;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;

final class ScenarioCompilerTest extends LanguageTestCase
{
    #[Test]
    public function compilesTheDesignSampleWithExtensionActions(): void
    {
        $result = (new ScenarioCompiler($this->designSampleCatalog()))->compile(<<<'DSL'
            function displayName($user) {
                return $user->firstName . " " . $user->lastName;
            }

            function main() {
                if ($input?->teamId == null) {
                    fail("teamId manquant");
                }

                $teamInfo = workteam::getTeamInfos($input->teamId);
                $users = workteam::getUsersOfTeam($input->teamId);

                foreach ($users as $user) {
                    core::sendMail("meeting-invite", $user->email, [
                        "firstName"  => $user->firstName,
                        "senderName" => $principal->firstName
                    ]);
                }

                automation::appendSheetRow($teamInfo->params->reportGoogleSheetId, [
                    date::now(),
                    "Envoi d'un mail à l'équipe : " . arr::length($users) . " personnes"
                ]);
            }
            DSL);

        $this->assertSuccessful($result);
        self::assertInstanceOf(Program::class, $result->program);
        self::assertCount(2, $result->program->functions);
    }

    #[Test]
    public function parseErrorsPreventSemanticValidation(): void
    {
        $result = (new ScenarioCompiler())->compile('$x = 1;');

        self::assertSame(
            [DiagnosticCode::TOP_LEVEL_STATEMENT],
            array_map(static fn (Diagnostic $diagnostic): string => $diagnostic->code, $result->diagnostics),
        );
        self::assertNotNull($result->program);
        self::assertCount(0, $result->program->functions);
    }

    #[Test]
    public function customCatalogIsUsedByTheValidator(): void
    {
        $source = 'function main() { custom::action(); }';

        $unknown = (new ScenarioCompiler())->compile($source);
        self::assertSame(DiagnosticCode::UNKNOWN_FUNCTION, $unknown->diagnostics[0]->code);

        $catalog = new class implements FunctionCatalog {
            /** @var array<string, FunctionSignature> */
            private array $functions;

            public function __construct()
            {
                $this->functions = [
                    'custom::action' => new FunctionSignature('custom::action', 0, 0),
                ];
            }

            public function find(string $identifier): ?FunctionSignature
            {
                return $this->functions[$identifier] ?? null;
            }
        };

        $result = (new ScenarioCompiler($catalog))->compile($source);
        $this->assertSuccessful($result);
    }

    #[Test]
    public function successfulCompilationReturnsTheProgram(): void
    {
        $result = (new ScenarioCompiler())->compile('function main() { }');

        $this->assertSuccessful($result);
        self::assertInstanceOf(Program::class, $result->program);
        self::assertCount(1, $result->program->functions);
    }

    private function assertSuccessful(CompilationResult $result): void
    {
        self::assertSame(
            [],
            array_map(static fn (Diagnostic $diagnostic): string => (string) $diagnostic, $result->diagnostics),
        );
        self::assertNotNull($result->program);
    }

    private function designSampleCatalog(): FunctionCatalog
    {
        return new class implements FunctionCatalog {
            /** @var array<string, FunctionSignature> */
            private array $functions;

            public function __construct()
            {
                $this->functions = BuiltinFunctionCatalog::create()->all();
                $this->functions['workteam::getTeamInfos'] = new FunctionSignature(
                    'workteam::getTeamInfos',
                    1,
                    1,
                    FunctionEffect::READ,
                );
                $this->functions['workteam::getUsersOfTeam'] = new FunctionSignature(
                    'workteam::getUsersOfTeam',
                    1,
                    1,
                    FunctionEffect::READ,
                );
                $this->functions['automation::appendSheetRow'] = new FunctionSignature(
                    'automation::appendSheetRow',
                    2,
                    2,
                    FunctionEffect::EXTERNAL,
                );
            }

            public function find(string $identifier): ?FunctionSignature
            {
                return $this->functions[$identifier] ?? null;
            }
        };
    }
}
