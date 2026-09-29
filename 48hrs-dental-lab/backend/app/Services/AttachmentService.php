<?php

namespace App\Services;

use App\Domain\Catalog;
use App\Exceptions\ApiException;
use App\Models\CaseAttachment;
use App\Models\User;
use App\Support\Activity;
use App\Support\CaseFiles;
use App\Support\FileRules;
use App\Support\Present;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Case files on the private "cases" disk. Only reachable through these methods:
 * the caller must see the case (scope), and the file must belong to it.
 */
class AttachmentService
{
    /** @return array{id: string, case_number: string} */
    public static function visibleCase(User $u, string $idOrNumber): array
    {
        $c = CaseQuery::find($u, $idOrNumber)->first(['cases.id', 'cases.case_number']) ?? throw ApiException::notFound();

        return ['id' => $c->id, 'case_number' => $c->case_number];
    }

    public function upload(User $u, string $caseIdOrNumber, ?UploadedFile $file, mixed $rawCategory): array
    {
        $case = self::visibleCase($u, $caseIdOrNumber);
        if (! $file || ! $file->isValid()) {
            // PHP drops files over upload_max_filesize; report it as the size limit.
            if ($file && in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                throw ApiException::validation(['file' => ['Larger than the '.config('lab.uploads.max_mb').' MB limit.']]);
            }
            throw ApiException::validation(['file' => ['Choose a file to upload.']]);
        }
        $name = FileRules::safeName($file->getClientOriginalName());
        $ext = Catalog::extensionOf($name);
        $head = (string) file_get_contents($file->getRealPath(), false, null, 0, 512);
        $error = Catalog::validateFile($name, (int) $file->getSize(), min(Catalog::MAX_FILE_MB, config('lab.uploads.max_mb'))) ?? FileRules::checkContent($ext, $head, (int) $file->getSize());
        if ($error) {
            throw ApiException::validation(['file' => [$error]]);
        }
        $category = is_string($rawCategory) && $rawCategory !== '' ? $rawCategory : Catalog::categoryForExtension($ext);
        if (! in_array($category, Catalog::attachmentCategories(), true)) {
            throw ApiException::validation(['category' => ['Choose a file category.']]);
        }

        $key = CaseFiles::newKey($ext);
        CaseFiles::disk()->putFileAs(dirname($key), $file, basename($key));
        try {
            $row = DB::transaction(function () use ($u, $case, $name, $key, $ext, $file, $category) {
                $row = CaseAttachment::create(['case_id' => $case['id'], 'name' => $name, 'storage_key' => $key, 'mime_type' => FileRules::mimeFor($ext), 'extension' => $ext, 'size' => (int) $file->getSize(), 'category' => $category, 'uploaded_by_id' => $u->id]);
                Activity::log($u, 'case.file_upload', "Uploaded {$name} to {$case['case_number']}", 'case', $case['id'], $case['case_number']);

                return $row;
            });
        } catch (\Throwable $e) {
            CaseFiles::delete($key);
            throw $e;
        }

        return Present::attachment($row->load('uploadedBy:id,name'));
    }

    public function remove(User $u, string $caseIdOrNumber, string $attachmentId): void
    {
        $case = self::visibleCase($u, $caseIdOrNumber);
        $att = CaseAttachment::where('id', $attachmentId)->where('case_id', $case['id'])->first() ?? throw ApiException::notFound();
        if (! Gate::forUser($u)->allows('delete', $att)) {
            throw ApiException::forbidden();
        }
        DB::transaction(function () use ($u, $case, $att) {
            $att->delete();
            Activity::log($u, 'case.file_delete', "Removed {$att->name} from {$case['case_number']}", 'case', $case['id'], $case['case_number']);
        });
        CaseFiles::delete($att->storage_key);
    }

    public function download(User $u, string $caseIdOrNumber, string $attachmentId): StreamedResponse
    {
        $case = self::visibleCase($u, $caseIdOrNumber);
        $att = CaseAttachment::where('id', $attachmentId)->where('case_id', $case['id'])->first();
        if (! $att || ! CaseFiles::exists($att->storage_key)) {
            throw ApiException::notFound();
        }
        $ascii = str_replace('"', '', preg_replace('/[^\x20-\x7e]/', '_', $att->name));

        return response()->stream(function () use ($att) {
            $stream = CaseFiles::disk()->readStream($att->storage_key);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => FileRules::mimeFor($att->extension),
            'Content-Length' => (string) CaseFiles::disk()->size($att->storage_key),
            'Content-Disposition' => "attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($att->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
