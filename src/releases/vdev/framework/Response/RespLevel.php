<?php

namespace YTassy\Tycms\Response;


enum RespLevel: string
{
    case INFO = 'INFO';
    case SUCCESS = 'SUCCESS';
    case WARNING = 'WARNING';
    case DANGER = 'DANGER';
    case ERROR = 'ERROR';
}
