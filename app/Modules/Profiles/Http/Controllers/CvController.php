<?php

namespace App\Modules\Profiles\Http\Controllers;

use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Profiles\Http\Resources\CvResource;
use App\Modules\Profiles\Models\Cv;
use App\Modules\Profiles\Services\CvFileInspector;
use App\Support\Http\ApiDate;
use App\Support\Http\ApiError;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class CvController
{
    /** GET /me/cvs: my CVs, newest first. */
    public function index(Request $request): AnonymousResourceCollection
    {
        return CvResource::collection(
            Cv::where('user_id', $request->user()->id)->latest()->latest('id')->get(),
        );
    }

    /** POST /me/cvs (multipart, field `file`) */
    public function store(Request $request, CvFileInspector $inspector): JsonResponse
    {
        $user = $request->user();
        $file = $this->uploadedFile($request);

        if ($file->getSize() > config('careeredge.cv.max_bytes')) {
            throw $this->tooLarge();
        }

        $contentType = $inspector->detect($file->getRealPath());
        if ($contentType === null) {
            throw new ApiError(415, 'UNSUPPORTED_FILE_TYPE', 'Only PDF and DOCX files are accepted');
        }

        if (Cv::where('user_id', $user->id)->count() >= config('careeredge.cv.max_per_candidate')) {
            throw ApiError::conflict('CV_LIMIT_REACHED', 'Delete a CV before uploading another one');
        }

        // The stored name is random. The user's file name is kept only as text in the database,
        // so it can never be used to write outside the folder or overwrite another file.
        $disk = Storage::disk(config('careeredge.cv.disk'));
        $key = "cvs/{$user->id}/".Str::uuid().'.'.$inspector->extensionFor($contentType);
        $disk->putFileAs(dirname($key), $file, basename($key));

        try {
            $cv = Cv::create([
                'user_id' => $user->id,
                'file_name' => $this->displayName($file),
                'content_type' => $contentType,
                'size_bytes' => $file->getSize(),
                'storage_key' => $key,
            ]);
        } catch (Throwable $e) {
            // Do not leave a file behind that no database row points to.
            $disk->delete($key);

            throw $e;
        }

        return (new CvResource($cv))->response()->setStatusCode(201);
    }

    /** DELETE /me/cvs/{cv} */
    public function destroy(Request $request, string $cv): Response
    {
        // Filtering by owner inside the query means someone else's CV is simply "not found".
        Cv::where('user_id', $request->user()->id)->findOrFail($cv)->delete();

        return response()->noContent();
    }

    /** GET /cvs/{cv}/download-url */
    public function downloadUrl(Request $request, string $cv, SessionCvAccess $sessions): JsonResponse
    {
        $user = $request->user();

        // The owner sees only CVs they have not deleted. A session's recruiter may still read
        // a deleted one, so that lookup includes soft-deleted rows.
        $record = Cv::withTrashed()->findOrFail($cv);
        $isOwner = $record->user_id === $user->id && ! $record->trashed();

        if (! $isOwner && ! $sessions->canRead($user, $record)) {
            // 404, not 403: do not confirm that a CV with this id exists.
            throw ApiError::notFound();
        }

        $expiresAt = now()->addMinutes(config('careeredge.cv.download_url_minutes'));

        return response()->json([
            'url' => Storage::disk(config('careeredge.cv.disk'))->temporaryUrl($record->storage_key, $expiresAt, [
                // Used by S3-compatible storage so the download gets the original file name.
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($record->file_name, '"\\').'"',
            ]),
            'expiresAt' => ApiDate::format($expiresAt),
        ]);
    }

    private function uploadedFile(Request $request): UploadedFile
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile) {
            throw ValidationException::withMessages(['file' => ['A file is required.']]);
        }

        // PHP itself refuses files above its upload_max_filesize setting, before our own
        // size check can run. The client must get the same answer in both cases.
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw $this->tooLarge();
        }

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => ['The upload did not complete. Try again.']]);
        }

        return $file;
    }

    private function tooLarge(): ApiError
    {
        return new ApiError(413, 'FILE_TOO_LARGE', 'The file must be 5 MB or smaller');
    }

    /** The name shown back to the user: no folders, no control characters, at most 255 characters. */
    private function displayName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));

        return Str::limit($name ?: 'cv', 255, '');
    }
}
