<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Permissions as AutomationPermissions;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsAppendRowAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsAppendRowsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsClearRangeAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsFindRowsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsInsertRowAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsListSheetsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsReadRangeAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsReadSheetAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsUpdateRangeAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleSheetsUpsertRowAction;

/** Actions Google Sheets exposées au langage de scénarios. */
final class GoogleSheetsAutomationActions
{
    /** @return list<AutomationActionDefinition> */
    public static function definitions(): array
    {
        return [
            new AutomationActionDefinition(
                id: 'google_sheets::appendRow',
                label: 'Ajouter une ligne Google Sheets',
                handlerClass: GoogleSheetsAppendRowAction::class,
                description: 'Ajoute une ligne à la fin d’une feuille Google Sheets.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(false),
                    ...self::rangeParams(false),
                    new AutomationParameterDefinition(
                        'values',
                        'array',
                        true,
                        label: 'Valeurs',
                        description: 'Une ligne de cellules, dans l’ordre des colonnes.',
                    ),
                    ...self::inputOptionParam(),
                ],
                returnType: 'int',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::appendRows',
                label: 'Ajouter des lignes Google Sheets',
                handlerClass: GoogleSheetsAppendRowsAction::class,
                description: 'Ajoute plusieurs lignes à la fin d’une feuille Google Sheets.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(false),
                    ...self::rangeParams(false),
                    new AutomationParameterDefinition(
                        'rows',
                        'array',
                        true,
                        label: 'Lignes',
                        description: 'Liste de lignes, chaque ligne étant une liste de cellules.',
                    ),
                    ...self::inputOptionParam(),
                ],
                returnType: 'int',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::insertRow',
                label: 'Insérer une ligne Google Sheets',
                handlerClass: GoogleSheetsInsertRowAction::class,
                description: 'Insère une ligne à une position précise et décale les suivantes.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(true),
                    new AutomationParameterDefinition(
                        'row_index',
                        'int',
                        true,
                        label: 'Position',
                        description: 'Numéro de ligne à insérer, à partir de 1. La ligne existante est décalée vers le bas.',
                    ),
                    new AutomationParameterDefinition(
                        'values',
                        'array',
                        true,
                        label: 'Valeurs',
                        description: 'Une ligne de cellules. Les chaînes restent littérales, sans formule.',
                    ),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::readRange',
                label: 'Lire une plage Google Sheets',
                handlerClass: GoogleSheetsReadRangeAction::class,
                description: 'Lit les valeurs d’une plage A1.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(false),
                    ...self::rangeParams(true),
                    ...self::renderOptionParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::readSheet',
                label: 'Lire une feuille Google Sheets',
                handlerClass: GoogleSheetsReadSheetAction::class,
                description: 'Lit toutes les valeurs utilisées d’une feuille.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(true),
                    ...self::renderOptionParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::updateRange',
                label: 'Mettre à jour une plage Google Sheets',
                handlerClass: GoogleSheetsUpdateRangeAction::class,
                description: 'Écrit des valeurs dans une plage A1 existante.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(false),
                    ...self::rangeParams(true),
                    new AutomationParameterDefinition(
                        'values',
                        'array',
                        true,
                        label: 'Valeurs',
                        description: 'Une ligne ou une liste de lignes à écrire.',
                    ),
                    ...self::inputOptionParam(),
                ],
                returnType: 'int',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::listSheets',
                label: 'Lister les feuilles Google Sheets',
                handlerClass: GoogleSheetsListSheetsAction::class,
                description: 'Liste les onglets d’un classeur avec leur identifiant et leur titre.',
                parameters: [...self::spreadsheetParams()],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::findRows',
                label: 'Chercher des lignes Google Sheets',
                handlerClass: GoogleSheetsFindRowsAction::class,
                description: 'Cherche une valeur dans une colonne et retourne les lignes correspondantes.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(true),
                    new AutomationParameterDefinition(
                        'key_column',
                        'string',
                        true,
                        label: 'Colonne clé',
                        description: 'Lettre de colonne, par exemple A.',
                    ),
                    new AutomationParameterDefinition(
                        'key_value',
                        'mixed',
                        true,
                        label: 'Valeur clé',
                        description: 'Valeur recherchée.',
                    ),
                    new AutomationParameterDefinition(
                        'limit',
                        'int',
                        false,
                        50,
                        'Limite',
                        'Nombre maximal de lignes retournées, entre 1 et 500.',
                    ),
                    ...self::renderOptionParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::upsertRow',
                label: 'Ajouter ou mettre à jour une ligne Google Sheets',
                handlerClass: GoogleSheetsUpsertRowAction::class,
                description: 'Met à jour la première ligne dont la clé correspond, ou l’ajoute.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(true),
                    new AutomationParameterDefinition(
                        'key_column',
                        'string',
                        true,
                        label: 'Colonne clé',
                        description: 'Lettre de colonne, par exemple A.',
                    ),
                    new AutomationParameterDefinition(
                        'key_value',
                        'mixed',
                        true,
                        label: 'Valeur clé',
                        description: 'Valeur recherchée.',
                    ),
                    new AutomationParameterDefinition(
                        'values',
                        'array',
                        true,
                        label: 'Valeurs',
                        description: 'Une ligne de cellules à écrire.',
                    ),
                    ...self::inputOptionParam(),
                    ...self::renderOptionParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_sheets::clearRange',
                label: 'Effacer une plage Google Sheets',
                handlerClass: GoogleSheetsClearRangeAction::class,
                description: 'Efface les valeurs d’une plage A1. Action destructive.',
                parameters: [
                    ...self::spreadsheetParams(),
                    ...self::sheetParams(false),
                    ...self::rangeParams(true),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function spreadsheetParams(): array
    {
        return [
            new AutomationParameterDefinition(
                'spreadsheet_id',
                'string',
                true,
                label: 'Classeur',
                description: 'Identifiant du classeur Google Sheets.',
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function sheetParams(bool $required): array
    {
        return [
            new AutomationParameterDefinition(
                'sheet_id',
                'int|string|null',
                $required,
                null,
                'Feuille',
                'ID numérique ou titre de l’onglet ; null pour utiliser range.',
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function rangeParams(bool $required): array
    {
        return [
            new AutomationParameterDefinition(
                'range',
                'string',
                $required,
                null,
                'Plage',
                'Plage A1, par exemple Inscriptions!A:Z. Préfixée par le titre si sheet_id est fourni.',
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function inputOptionParam(): array
    {
        return [
            new AutomationParameterDefinition(
                'value_input_option',
                'string',
                false,
                'RAW',
                'Mode de saisie',
                'RAW pour du texte brut, USER_ENTERED pour laisser Google interpréter les valeurs.',
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function renderOptionParam(): array
    {
        return [
            new AutomationParameterDefinition(
                'value_render_option',
                'string',
                false,
                'FORMATTED_VALUE',
                'Rendu',
                'FORMATTED_VALUE, UNFORMATTED_VALUE ou FORMULA.',
            ),
        ];
    }

    private function __construct()
    {
    }
}
