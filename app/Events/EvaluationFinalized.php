<?php

namespace App\Events;

use App\Models\Evaluation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EvaluationFinalized
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Evaluation $evaluation) {}
}
