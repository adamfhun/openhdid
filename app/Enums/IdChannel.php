<?php

namespace App\Enums;

enum IdChannel: string
{
    case Manual = 'manual';
    case Ivr = 'ivr';
    case Api = 'api';
}
