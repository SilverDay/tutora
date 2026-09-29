<?php

declare(strict_types=1);

namespace Tutora\Controller;

use PDO;
use Tutora\Http\HttpException;
use Tutora\Http\Request;
use Tutora\Http\Response;
use Tutora\Participant\ParticipantService;
use Tutora\Slides\SlideImportService;
use Tutora\Slides\SlideStorage;
use Tutora\Support\ValidationException;
use Tutora\Tenant\TenantContext;
use Tutora\Tenant\TenantDb;
use Tutora\Workshop\WorkshopRepository;

/** Slide upload and image delivery. Images are never served from the web root. */
final class SlideController
{
    public function __construct(private readonly SlideStorage $storage)
    {
    }

    public function upload(Request $r, SlideImportService $imports, callable $renderWorkshop): Response
    {
        $file = $r->files['deck'] ?? null;
        $wid = $r->intParam('id');
        if ($file === null || !$file->ok()) {
            return $renderWorkshop(['Please choose a PDF or PowerPoint file (max upload size may apply).']);
        }
        try {
            if ($imports->upload($wid, $file->tmpPath, $file->clientName) === null) {
                throw new HttpException(404, 'Not found');
            }
        } catch (ValidationException $e) {
            return $renderWorkshop($e->errors);
        }
        return Response::redirect('/workshops/' . $wid);
    }

    /** Adds one slide block per page of an import, in page order. */
    public function addAll(Request $r, SlideImportService $imports, WorkshopRepository $workshops): Response
    {
        $wid = $r->intParam('id');
        if ($workshops->find($wid) === null) {
            throw new HttpException(404, 'Not found');
        }
        foreach ($imports->assetIds($r->intParam('import')) as $assetId) {
            $workshops->addBlock($wid, \Tutora\Block\BlockType::Slide, [], $assetId);
        }
        return Response::redirect('/workshops/' . $wid);
    }

    /** Tutor: any asset of the tenant's own imports. */
    public function tutorImage(Request $r, TenantContext $t, TenantDb $db): Response
    {
        $row = $db->one(
            'SELECT a.image_path FROM slide_assets a JOIN slide_imports i ON i.id = a.slide_import_id
             WHERE a.id = :id AND i.tenant_id = :tenant_id',
            ['id' => $r->intParam('asset')],
        );
        return $this->image($row['image_path'] ?? null);
    }

    /** Participant: only assets used by a block of their own session. */
    public function participantImage(Request $r, ParticipantService $participants, PDO $pdo): Response
    {
        $auth = $r->header('authorization');
        $ctx = $participants->authenticate($auth !== null && str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : null, $r->intParam('id'))
            ?? throw new HttpException(401, 'Not authorised for this session');
        $s = $pdo->prepare(
            'SELECT a.image_path FROM slide_assets a
             WHERE a.id = ? AND EXISTS (SELECT 1 FROM session_blocks b WHERE b.session_id = ? AND b.slide_asset_id = a.id)'
        );
        $s->execute([$r->intParam('asset'), $ctx->sessionId]);
        $path = $s->fetchColumn();
        return $this->image($path === false ? null : (string) $path);
    }

    private function image(?string $path): Response
    {
        $real = $path === null ? null : $this->storage->resolveAsset($path);
        if ($real === null) {
            throw new HttpException(404, 'Not found');
        }
        return new Response(200, (string) file_get_contents($real), [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="slide.png"',
            'Cache-Control' => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
