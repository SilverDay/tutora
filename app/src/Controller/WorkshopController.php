<?php

declare(strict_types=1);

namespace Tutora\Controller;

use Tutora\Audit\AuditLog;
use Tutora\Block\BlockType;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Session\SessionService;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\View\View;
use Tutora\Workshop\WorkshopRepository;

/**
 * Minimal v1 workshop authoring (spec defers the full sequencing authoring UI to v2):
 * blocks are edited as validated JSON configs.
 */
final class WorkshopController
{
    public function __construct(
        private readonly WorkshopRepository $workshops,
        private readonly SessionService $sessions,
        private readonly AuditLog $audit,
        private readonly View $view,
    ) {
    }

    public function dashboard(Request $r, TenantContext $t): Response
    {
        return $this->view->render('dashboard', [
            'title' => 'Dashboard',
            'workshops' => $this->workshops->list(),
            'sessions' => $this->sessions->list(),
            'errors' => [],
        ]);
    }

    public function create(Request $r, TenantContext $t): Response
    {
        try {
            $id = $this->workshops->create((string) $r->input('title'), $r->input('description'));
        } catch (ValidationException $e) {
            return $this->view->render('dashboard', [
                'title' => 'Dashboard', 'workshops' => $this->workshops->list(), 'sessions' => $this->sessions->list(), 'errors' => $e->errors,
            ], 422);
        }
        return Response::redirect('/workshops/' . $id);
    }

    public function show(Request $r, TenantContext $t, array $errors = [], int $status = 200): Response
    {
        $id = $r->intParam('id');
        $workshop = $this->workshops->find($id) ?? throw new HttpException(404, 'Not found');
        return $this->view->render('workshops/show', [
            'title' => $workshop['title'],
            'workshop' => $workshop,
            'blocks' => $this->workshops->blocks($id),
            'types' => array_map(static fn (BlockType $b) => $b->value, BlockType::cases()),
            'errors' => $errors,
        ], $status);
    }

    public function update(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        try {
            if (!$this->workshops->update($id, (string) $r->input('title'), $r->input('description'))) {
                throw new HttpException(404, 'Not found');
            }
        } catch (ValidationException $e) {
            return $this->show($r, $t, $e->errors, 422);
        }
        return Response::redirect('/workshops/' . $id);
    }

    public function delete(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        if (!$this->workshops->delete($id)) {
            throw new HttpException(404, 'Not found');
        }
        $this->audit->record($t->tenantId, AuditLog::WORKSHOP_DELETED, $r->clientIp, ['workshop_id' => $id]);
        return Response::redirect('/dashboard');
    }

    public function addBlock(Request $r, TenantContext $t): Response
    {
        $id = $r->intParam('id');
        $type = BlockType::tryFrom((string) $r->input('block_type')) ?? throw new HttpException(422, 'Unknown block type');
        try {
            $config = self::decodeConfig((string) ($r->input('config') ?? '{}'));
            if ($this->workshops->addBlock($id, $type, $config, self::assetId($r)) === null) {
                throw new HttpException(404, 'Not found');
            }
        } catch (ValidationException $e) {
            return $this->show($r, $t, $e->errors, 422);
        }
        return Response::redirect('/workshops/' . $id);
    }

    public function updateBlock(Request $r, TenantContext $t): Response
    {
        $block = $this->workshops->findBlock($r->intParam('block')) ?? throw new HttpException(404, 'Not found');
        $r->params['id'] = (string) $block['workshop_id'];
        try {
            $this->workshops->updateBlock((int) $block['id'], self::decodeConfig((string) $r->input('config')), self::assetId($r));
        } catch (ValidationException $e) {
            return $this->show($r, $t, $e->errors, 422);
        }
        return Response::redirect('/workshops/' . $block['workshop_id']);
    }

    public function moveBlock(Request $r, TenantContext $t): Response
    {
        $block = $this->workshops->findBlock($r->intParam('block')) ?? throw new HttpException(404, 'Not found');
        $delta = $r->input('direction') === 'up' ? -1 : 1;
        $this->workshops->moveBlock((int) $block['id'], max(0, (int) $block['position'] + $delta));
        return Response::redirect('/workshops/' . $block['workshop_id']);
    }

    public function deleteBlock(Request $r, TenantContext $t): Response
    {
        $block = $this->workshops->findBlock($r->intParam('block')) ?? throw new HttpException(404, 'Not found');
        $this->workshops->deleteBlock((int) $block['id']);
        return Response::redirect('/workshops/' . $block['workshop_id']);
    }

    public function startSession(Request $r, TenantContext $t): Response
    {
        try {
            $sid = $this->sessions->start($r->intParam('id')) ?? throw new HttpException(404, 'Not found');
        } catch (ValidationException $e) {
            return $this->show($r, $t, $e->errors, 422);
        }
        return Response::redirect('/sessions/' . $sid);
    }

    /** @return array<string,mixed> */
    private static function decodeConfig(string $json): array
    {
        try {
            $data = json_decode($json === '' ? '{}' : $json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new ValidationException(['Configuration must be valid JSON.']);
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new ValidationException(['Configuration must be a JSON object.']);
        }
        return $data;
    }

    private static function assetId(Request $r): ?int
    {
        $v = trim((string) $r->input('slide_asset_id'));
        return $v === '' ? null : (ctype_digit($v) ? (int) $v : throw new ValidationException(['Invalid slide image.']));
    }
}
