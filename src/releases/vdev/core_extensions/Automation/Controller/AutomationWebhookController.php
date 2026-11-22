<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Controller;

use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookReceiver;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * Point d'entrée des webhooks de scénario.
 *
 * La route est sans CSRF : elle authentifie le demandeur par le mode configuré
 * sur le trigger. Le WAF est désactivé car la charge utile est externe.
 */
#[Controller(name: 'automation_webhook', isApi: true, path: 'automation/webhook', withoutCsrf: true)]
#[SecurityDoNotCheckPatterns]
final class AutomationWebhookController extends BaseController
{
    // Le routeur indexe les routes par nom : GET et POST doivent avoir deux
    // noms distincts, même s'ils partagent le même traitement. Le paramètre
    // vient du chemin, pas de la query : il est reçu en chaîne puis converti.
    #[ControllerMethod(
        name: 'receive_get',
        path: '{scenarioId}',
        methods: [RequestMethod::GET],
        where: ['scenarioId' => '\d+'],
    )]
    public function receiveGet(string $scenarioId): ApiResponse
    {
        return $this->receive((int) $scenarioId);
    }

    #[ControllerMethod(
        name: 'receive_post',
        path: '{scenarioId}',
        methods: [RequestMethod::POST],
        where: ['scenarioId' => '\d+'],
    )]
    public function receivePost(string $scenarioId): ApiResponse
    {
        return $this->receive((int) $scenarioId);
    }

    private function receive(int $scenarioId): ApiResponse
    {
        /** @var WebhookReceiver $receiver */
        $receiver = $this->getSharedExtensionsClass(WebhookReceiver::class);

        return new ApiResponse($receiver->receive($scenarioId, $this->request));
    }
}
