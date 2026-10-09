<?php

namespace App\Enums;

enum AiProcessingStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case ERROR = 'ERROR';
    case COMPLETED = 'COMPLETED';
}
