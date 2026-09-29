<?php

namespace App\Ai\Tools;

use App\Ai\Severity;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** Lets a scan end with a machine-readable verdict, which decides whether humans get notified. */
class SubmitVerdictTool implements Tool
{
    public function __construct(private AgentRun $run) {}

    public function name(): string
    {
        return 'submit_verdict';
    }

    public function description(): Stringable|string
    {
        return 'Record the overall verdict of this scan. Call it exactly once, at the very end, before your written report. '
            .'severity: none (nothing to report), low, medium (needs attention soon), high (probable compromise or serious exposure), critical (active incident).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'severity' => $schema->string()->enum(Severity::values())->required(),
            'summary' => $schema->string()->description('One or two sentences, plain text, for a notification.')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $severity = Severity::tryFrom((string) $request['severity']);

        if (! $severity) {
            return 'ERROR: severity must be one of '.implode(', ', Severity::values()).'.';
        }

        $this->run->update(['severity' => $severity->value, 'summary' => mb_substr(trim((string) $request['summary']), 0, 1000)]);

        return 'Verdict recorded.';
    }
}
