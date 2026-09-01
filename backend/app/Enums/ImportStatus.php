<?php

namespace App\Enums;

// Import state is persisted so retries and dashboards never infer queue state.
enum ImportStatus: string
{
    case Previewed = 'previewed';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
