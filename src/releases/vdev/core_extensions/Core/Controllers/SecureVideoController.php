<?php

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

#[Controller(name: 'secure_video_', path: 'secure-video', isApi: false)]
final class SecureVideoController extends BaseController
{
    #[ControllerMethod(name: 'stream', path: 'stream')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'source', required: true, varType: VarType::STRING)]
    #[ControllerParam3(name: 'file', required: true, varType: VarType::STRING)]
    public function stream(int $spaceId, string $source, string $file): Response
    {
        try {
            $asset = $this->coreSecureVideoHelper->asset($spaceId, $source, $file);
        } catch (RuntimeException) {
            // Aucun chemin physique ou détail de déchiffrement dans la réponse publique.
            return new Response(['Cache-Control' => 'no-store', 'Content-Type' => 'text/plain; charset=utf-8'], 'Vidéo indisponible.', 404);
        }

        return new Response([
            'Content-Type' => $asset['mime'],
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $asset['content'], 200);
    }
}
