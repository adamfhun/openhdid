<?php

namespace App\Enums;

enum StepVerdict: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Undecided = 'undecided';
}
