<?php

namespace YTassy\Tycms\CoreExts\Test\Controllers;

use YTassy\Tycms\CoreExts\Test\Views\Test;
use YTassy\Tycms\CoreExts\Test\Views\FormDemo;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

#[Controller(name: "Test", isApi: false, path: "test")]
class TestController extends BaseController
{

    #[ControllerMethod(name: "Forms", path: "forms")]
    public function forms()
    {
        return $this->getViewResponse(new FormDemo($this));
    }

    #[ControllerMethod(name: "UrlParams", path: "{id}/test", where: ["id" => "\d+"])]
    public function urlParams(int $id)
    {
        //dd($id, $this->request);

        return $this->getViewResponse(new Test($this, $id));
    }

    #[ControllerMethod(name: "UrlParams2", path: "{id}/{id2}/{id3}/test", where: ["id" => "\d+", 'id2' => '[a-zA-Z]+'])]
    public function urlParams2($id, $id2, $id3, $request)
    {
        //dd($id, $id2, $id3, $request);

        

        return $this->getViewResponse(new Test($this, $id) );
    }

}
