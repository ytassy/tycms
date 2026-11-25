<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Security;

/**
 * Un contrôle d'analyse de sécurité.
 *
 * Un contrôle est du code pur : il lit la configuration, la base ou l'état du
 * site et renvoie ses constats. Il n'enregistre rien et ne modifie rien, ce qui
 * permet de l'exécuter à tout moment sans effet de bord.
 *
 * Il peut porter sur n'importe quel sujet : réglages d'extension, comptes,
 * rôles, groupes, médias, contenu. L'extension qui le fournit décide de son
 * groupe d'affichage.
 */
interface ISecurityCheck
{
    /**
     * Groupe d'affichage du constat, par exemple « Médias » ou « Comptes ».
     *
     * Les constats d'un même groupe sont réunis sous ce libellé dans le bilan.
     */
    public function group(): string;

    /**
     * Exécute le contrôle.
     *
     * @return list<SecurityFinding> Un ou plusieurs constats ; une liste vide
     *         signifie que le contrôle ne s'applique pas à ce site.
     */
    public function check(): array;
}
