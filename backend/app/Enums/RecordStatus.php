<?php

namespace App\Enums;

enum RecordStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case ERROR = 'ERROR';
    case CONFIRMED = 'CONFIRMED';
    case CANCELED = 'CANCELED';
}
