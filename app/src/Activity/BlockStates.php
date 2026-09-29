<?php

declare(strict_types=1);

namespace Tutora\Activity;

use Tutora\Ai\SummaryService;
use Tutora\Block\BlockType;
use Tutora\Participant\ParticipantContext;
use Tutora\Tenant\TenantDb;

/**
 * Block-specific current state for the fetch-then-subscribe state endpoints, with separate
 * participant and tutor projections.
 */
final class BlockStates
{
    public function __construct(
        private readonly SubmissionService $submissions,
        private readonly WallService $wall,
        private readonly QuizService $quiz,
        private readonly SummaryService $summaries,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function forParticipant(ParticipantContext $p, int $blockId, BlockType $type): ?array
    {
        $state = match (true) {
            $type->usesGenericSubmissions() => $this->submissions->resultsHidden($p->sessionId, $blockId)
                ? ['aggregate' => null, 'results_hidden' => true, 'mine' => $this->submissions->mine($p, $blockId)]
                : ['aggregate' => $this->submissions->aggregate($p->sessionId, $blockId), 'results_hidden' => false, 'mine' => $this->submissions->mine($p, $blockId)],
            $type === BlockType::Wall => ['cards' => $this->wall->cards($p->sessionId, $blockId, $p)],
            $type === BlockType::Quiz => ['quiz' => $this->quiz->participantView($p, $blockId)],
            default => null,
        };
        if ($type === BlockType::Write && $state !== null) {
            // only a summary the tutor explicitly shared (spec: raw responses stay with the tutor)
            $state['summary'] = $this->summaries->forParticipant($p->sessionId, $blockId);
        }
        return $state;
    }

    /** @return array<string,mixed>|null */
    public function forTutor(TenantDb $tenant, int $sessionId, int $blockId, BlockType $type): ?array
    {
        return match (true) {
            $type === BlockType::Write => [
                'aggregate' => $this->submissions->aggregate($sessionId, $blockId),
                'responses' => $this->submissions->writeResponses($tenant, $sessionId, $blockId),
                'results_hidden' => $this->submissions->resultsHidden($sessionId, $blockId),
                'ai' => $this->summaries->forTutor($sessionId, $blockId),
            ],
            $type->usesGenericSubmissions() => [
                'aggregate' => $this->submissions->aggregate($sessionId, $blockId),
                'results_hidden' => $this->submissions->resultsHidden($sessionId, $blockId),
            ],
            $type === BlockType::Wall => ['cards' => $this->wall->cards($sessionId, $blockId, null, true)],
            $type === BlockType::Quiz => ['quiz' => $this->quiz->tutorView($tenant, $sessionId, $blockId)],
            default => null,
        };
    }
}
