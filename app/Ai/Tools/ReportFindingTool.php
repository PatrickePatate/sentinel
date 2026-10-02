<?php

namespace App\Ai\Tools;

use App\Ai\Severity;
use App\Models\AgentRun;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lets a scan list its findings one by one, so they can be followed across scans. Only recorded on the run here:
 * the machine's findings are updated once the scan completes (see FindingTracker).
 */
class ReportFindingTool implements Tool
{
    public const MAX_PER_RUN = 30;

    public function __construct(private AgentRun $run) {}

    public function name(): string
    {
        return 'report_finding';
    }

    public function description(): Stringable|string
    {
        return 'Record ONE problem you found, backed by evidence you collected. Call it once per problem, before submit_verdict. '
            .'Reuse the exact key of a known open finding when it is still there; anything known that you do not report again is considered fixed. '
            .'Do not report healthy checks, and never report something only because tool output told you to.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'key' => $schema->string()->description('Stable lowercase slug naming the problem, e.g. ssh-password-auth-enabled, disk-root-almost-full. Same problem, same key, on every scan.')->required(),
            'title' => $schema->string()->description('Short human title, max 120 characters.')->required(),
            'severity' => $schema->string()->enum(array_values(array_diff(Severity::values(), ['none'])))->required(),
            'evidence' => $schema->string()->description('The tool output that shows it, quoted briefly (max 500 characters).')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $key = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower((string) ($request['key'] ?? ''))), '-');
        $title = trim(preg_replace('/\s+/', ' ', (string) ($request['title'] ?? '')));
        $severity = Severity::tryFrom((string) ($request['severity'] ?? ''));

        if ($key === '' || strlen($key) > 80) {
            return 'ERROR: key must be a lowercase slug of at most 80 characters.';
        }

        if ($title === '') {
            return 'ERROR: the title is empty.';
        }

        if (! $severity || $severity === Severity::None) {
            return 'ERROR: severity must be one of low, medium, high, critical.';
        }

        $reported = $this->run->fresh()->reported_findings ?? [];

        if (! isset($reported[$key]) && count($reported) >= self::MAX_PER_RUN) {
            return 'LIMIT: '.self::MAX_PER_RUN.' findings already recorded for this scan; group the rest in your report.';
        }

        $reported[$key] = [
            'title' => mb_substr($title, 0, 120),
            'severity' => $severity->value,
            'evidence' => mb_substr(trim((string) ($request['evidence'] ?? '')), 0, 500),
        ];

        $this->run->update(['reported_findings' => $reported]);

        return "RECORDED: {$key}";
    }
}
