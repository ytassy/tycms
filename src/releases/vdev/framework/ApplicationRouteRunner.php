<?php

namespace YTassy\Tycms;

use BadMethodCallException;
use Configuration;
use ReflectionMethod;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\Migrations\Migration;
use YTassy\Tycms\ErrorPages\ErrorPages;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Exceptions\NotFoundException;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ApiMessageResponse;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\CustomResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\FileResponse;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Response\JsonResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Response\RespLevel;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Session\DatabaseSession;
use YTassy\Tycms\Session\PhpSession;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tools\StringTools;
use YTassy\Tycms\User\PendingMessageQueue;

class ApplicationRouteRunner extends BaseApplicationItem
{
    public function run(Route $route): ?Response
    {
        $conf = $this->app->getConf();
        $initialContext = $this->app->getLogContext();
        $this->logger->setContext(array_replace($initialContext, ['route' => $route->name]));
  
        $response = null;

        try {
            if ($route->isApi && !$route->withoutCsrf
                && !in_array($this->request->method, [RequestMethod::GET, RequestMethod::HEAD, RequestMethod::OPTIONS], true)
                && !$this->session->verifyCsrfToken($this->request->header('X-CSRF-Token'))) {
                return new ApiResponse([
                    'error' => true,
                    'is_message' => true,
                    'message_level' => RespLevel::DANGER,
                    'message' => 'Jeton CSRF invalide ou absent. Rechargez la page et réessayez.',
                ], 403);
            }

            if ($route->hasErrors()) {
                $message = implode("\n", array_map(function($error) { return $error['message']; }, $route->errors));

                if ($route->isApi) $response = new ApiResponse([
                    ...ApiMessageResponse::getMessageData($message, RespLevel::ERROR),
                    'error' => true,
                ], 400);
                else {
                    ErrorPages::printErrorPageAndExit('Requête invalide',
                        $message, 400, $conf);
                }

            } else {

                if ($route->userLogged && $this->session->getUserId() === null){
                    // User Not Logged
                    $response = new RedirectResponse('/');
                } else {
                    $controller = $route->controller;
                    $controllerMethod = $route->controllerMethod;
                    $params = $route->params;

                    // Add params to Request object
                    foreach ($params as $key => $value) {
                        $this->request->setParam($key, $value);
                    }

                    // Call the controller method with parameters
                    $params['_request'] = $this->request;
                    $params['_route'] = $route;
                    $params['_route_data'] = $route->routeData;

                    // Add Input Def
                    $params = array_merge($params, $route->inputs);

                    // Before Run Controller senc EVENT
                    $this->app->callExtensionEvent('onBeforeExecuteController', [
                        'params' => $params,
                        'controller' => $controller,
                        'controllerMethod' => $controllerMethod,
                        'route' => $route
                    ]);
                    
                    $transactionCommitted = false;

                    if ($route->noTransaction == false)
                    {
                        $this->db->execute('START TRANSACTION');
                    }
                    try {
                        // Execute Controller
                        $response = $this->runController($controller, $controllerMethod, $params);

                        if ($response == null || $response instanceof Response == false)
                        {
                            throw new TechException("Invalid Controller result");
                        }

                        // Une route #[NoTransaction] valide ses écritures sans
                        // transaction explicite : elles sont tout aussi durables.
                        $this->db->execute('COMMIT');
                        $transactionCommitted = true;

                        // Une connexion n'est annoncée qu'après le commit, jamais à chaque requête authentifiée.
                        $connectedContext = $this->app->getLogContext();
                        if (($initialContext['user_id'] ?? null) === null && ($connectedContext['user_id'] ?? null) !== null) {
                            $this->logInfo('Connexion utilisateur réussie.', $connectedContext, 'USER_LOGIN_SUCCEEDED', 'authentication');
                        }
                    } catch (\Throwable $ex) {
                        $this->db->execute('ROLLBACK');
                        throw $ex;
                    }

                    // Point d'ancrage après validation des écritures, avec ou sans
                    // transaction explicite. Un listener ne doit pas annuler une
                    // réponse métier déjà validée : ses erreurs sont journalisées.
                    try {
                        $this->app->callExtensionEvent('onAfterRouteExecuted', [$route, $transactionCommitted]);
                    } catch (\Throwable $ex) {
                        $this->logger->capture($ex);
                    }
                } 
            }

            return $response;

        } catch (ExceptionResponse $ex) {
            $this->logger->capture($ex);

            // If API => Response API
            // IF not API => Check if Get route exists and redirect with EnquieMessage
            $isApi = $route->isApi;

            if ($ex->exception != null && $conf->debug)
            {
                $this->pendingMessageQueue->add($ex->exception->getMessage() . "\n" . $ex->exception->getTraceAsString(), RespLevel::ERROR);
            }
            
            $response = $ex->getResponse();

            if ($response instanceof ApiResponse)
            {
                if ($isApi == false) {
                    
                    if ($this->request->method != RequestMethod::GET)
                    {
                        // 1 Search Get Route
                        $getRequest = clone $this->request;

                        $getRequest->method = RequestMethod::GET;

                        $getRoute = $this->router->resolve($getRequest);

                        if ($getRoute != null)
                        {
                            // 2- Enque Message
                            $this->pendingMessageQueue->add($response->getMessage(), $response->getMessageLevel());

                            // Conserver chemin et query légitimes, sans renvoyer une autorité fournie par le client.
                            $destination = $this->request->uri;
                            if (!RedirectResponse::isInternalUrl($destination)) {
                                $this->logWarning('Destination de redirection interne refusée.', [],
                                    'HTTP_REDIRECT_TARGET_REJECTED', 'security.http');
                                $destination = '/';
                            }
                            $response = RedirectResponse::default($destination);
                        }
                    } else {
                        // 2- Enque Message
                        $this->pendingMessageQueue->add($response->getMessage(), $response->getMessageLevel());

                        $response = RedirectResponse::default('/');
                    }
                }
            }

            return $response;

        } catch (NotFoundException $ex) {
            // Les API conservent leur format JSON ; les pages utilisent l'écran 404 commun.
            if ($route->isApi) {
                return new ApiResponse([
                    'error' => true,
                    'is_message' => true,
                    'message_level' => RespLevel::DANGER,
                    'message' => 'Cette page est introuvable sur le site.',
                ], 404);
            }

            ErrorPages::printErrorPageAndExit('Page introuvable',
                'Cette page est introuvable sur le site.', 404, $conf);
        } catch (AccessDeniedException $ex) {

            $isApi = $route->isApi;

            if ($isApi)
            {
                return new ApiMessageResponse("Vous ne disposez pas des droits suffisants pour effectuer cette action.", RespLevel::DANGER);
            } else {
                ErrorPages::printErrorPageAndExit("Accès interdit", "Vous ne disposez pas des droits suffisants pour effectuer cette action.", 403, $conf, $ex);
            }
        } catch (\Throwable $ex) {
            $this->logger->capture($ex);
            ErrorPages::printErrorPageAndExit("Erreur dans l'application", "Une erreur innatendue est survenue. Contactez le Webmaster", 500, $conf, $ex);    
        }

        return null;
    }

    private function runController(BaseController $classObject, string $classMethod, array $params)
    {
       // Vérification de l'existence de la méthode
        if (!method_exists($classObject, $classMethod)) {
            throw new BadMethodCallException(
                sprintf('La méthode %s::%s() n\'existe pas.', is_object($classObject) ? get_class($classObject) : $classObject, $classMethod)
            );
        }

        $reflector = new ReflectionMethod($classObject, $classMethod);
        $argsFiltres = [];

        foreach ($reflector->getParameters() as $param) {
            $nom = $param->getName();

            if (array_key_exists($nom, $params)) {
                // Clé exacte trouvée (arguments nommés)
                $argsFiltres[$nom] = $params[$nom];
            } elseif ($param->isDefaultValueAvailable()) {
                // Valeur par défaut de la méthode si absente du tableau
                $argsFiltres[$nom] = $param->getDefaultValue();
            } elseif (!$param->isOptional()) {
                // Paramètre obligatoire manquant : assigne null pour éviter le crash
                $argsFiltres[$nom] = null;
            }
        }

        // Gestion automatique si $classObject est une classe (statique) ou une instance
        return $reflector->invokeArgs(
            is_object($classObject) ? $classObject : null, 
            $argsFiltres
        );
    }

    public function sendResponse(Response $response)
    {

        // Before Run Controller senc EVENT
        $this->app->callExtensionEvent('onBeforeSendResponse', [
            'response' => $response
        ]);

        if ($response instanceof FileResponse) {
            $filePath = $response->filePath;
            $content = $response->content;

            if ($filePath === null && $content === null) {
                throw new TechException("FileResponse requires a file path or content");
            }

            if ($filePath !== null && (!is_file($filePath) || !is_readable($filePath))) {
                throw new TechException("FileResponse file is not readable");
            }

            $fileName = $response->downloadFileName ?? ($filePath !== null ? basename($filePath) : 'download');
            $fileName = str_replace(["\r", "\n"], '', $fileName);

            header('Content-Type: ' . $response->contentType);
            header('Content-Disposition: attachment; filename="' . addcslashes($fileName, "\\\"") . '"');

            if ($filePath !== null) {
                $fileSize = filesize($filePath);
                if ($fileSize !== false) {
                    header('Content-Length: ' . $fileSize);
                }

                try {
                    readfile($filePath);
                } finally {
                    if ($response->deleteFileAfterDownload) {
                        unlink($filePath);
                    }
                }
            } else {
                header('Content-Length: ' . strlen($content));
                echo $content;
            }

        } else if ($response instanceof Response) {
            foreach ($response->httpHeaders as $headerName => $headers) {
                if (is_array($headers)) {
                    foreach ($headers as $header) {
                        header($headerName . ': ' . $header);
                    }
                } else {
                    header($headerName . ': ' . $headers);
                }
            }

            http_response_code($response->code);
            echo $response->body;

        } else {
            throw new TechException("Invalid Controller result");
        }
    }
}
