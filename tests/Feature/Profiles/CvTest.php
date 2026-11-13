<?php

namespace Tests\Feature\Profiles;

use App\Modules\Identity\Models\User;
use App\Modules\Profiles\Contracts\SessionCvAccess;
use App\Modules\Profiles\Models\Cv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class CvTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // The real local disk, pointed at a throwaway folder. A real disk (not Storage::fake)
        // lets the tests follow a download link all the way to the file.
        $this->root = storage_path('framework/testing/cvs-'.uniqid());
        config(['filesystems.disks.local.root' => $this->root]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    private function pdf(string $name = 'Ahmed_Hassan_CV.pdf', int $bytes = 2048): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, str_pad("%PDF-1.7\n", $bytes, 'x'));
    }

    /** Builds a ZIP with the given entries. A real .docx is a ZIP that contains word/document.xml. */
    private function zip(string $name, array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'cvtest');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($entries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        $file = UploadedFile::fake()->createWithContent($name, file_get_contents($path));
        unlink($path);

        return $file;
    }

    private function docx(string $name = 'cv.docx'): UploadedFile
    {
        return $this->zip($name, ['[Content_Types].xml' => '<Types/>', 'word/document.xml' => '<w:document/>']);
    }

    private function upload(string $token, ?UploadedFile $file): TestResponse
    {
        return $this->asFreshClient($token)->post(
            '/api/v1/me/cvs',
            $file === null ? [] : ['file' => $file],
            ['Accept' => 'application/json'],
        );
    }

    public function test_a_candidate_uploads_a_pdf(): void
    {
        $this->freezeSecond();
        $auth = $this->register();

        $response = $this->upload($auth['accessToken'], $this->pdf())->assertCreated();

        $this->assertSame(['id', 'fileName', 'contentType', 'sizeBytes', 'uploadedAt'], array_keys($response->json()));
        $response->assertJson([
            'fileName' => 'Ahmed_Hassan_CV.pdf',
            'contentType' => 'application/pdf',
            'sizeBytes' => 2048,
            'uploadedAt' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);

        // Stored under the user's folder with a random name, not the name the user gave.
        $key = Cv::findOrFail($response->json('id'))->storage_key;
        $this->assertMatchesRegularExpression("#^cvs/{$auth['user']['id']}/[0-9a-f-]{36}\.pdf$#", $key);
        Storage::disk('local')->assertExists($key);
    }

    public function test_a_candidate_uploads_a_docx(): void
    {
        $token = $this->register()['accessToken'];

        $this->upload($token, $this->docx())->assertCreated()->assertJson(['contentType' => self::DOCX]);
    }

    public function test_the_type_comes_from_the_content_not_from_the_file_name(): void
    {
        $token = $this->register()['accessToken'];

        // A real PDF with a misleading name is still a PDF.
        $this->upload($token, $this->pdf('notes.txt'))->assertCreated()->assertJson(['contentType' => 'application/pdf']);
    }

    #[DataProvider('disguisedFiles')]
    public function test_files_that_only_pretend_to_be_a_cv_are_rejected(string $name, string $kind): void
    {
        $token = $this->register()['accessToken'];
        $file = match ($kind) {
            'exe' => UploadedFile::fake()->createWithContent($name, "MZ\x90\x00".str_repeat("\x00", 200)),
            'text' => UploadedFile::fake()->createWithContent($name, 'Just some text, not a PDF.'),
            'zip' => $this->zip($name, ['xl/workbook.xml' => '<workbook/>']),
            'empty' => UploadedFile::fake()->createWithContent($name, ''),
        };

        $this->assertProblem($this->upload($token, $file), 415, 'UNSUPPORTED_FILE_TYPE');
        $this->assertDatabaseCount('cvs', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    /** @return array<string, array{string, string}> */
    public static function disguisedFiles(): array
    {
        return [
            'a program renamed to .pdf' => ['cv.pdf', 'exe'],
            'plain text renamed to .pdf' => ['cv.pdf', 'text'],
            'a spreadsheet renamed to .docx' => ['cv.docx', 'zip'],
            'an empty file' => ['cv.pdf', 'empty'],
        ];
    }

    public function test_a_file_of_exactly_5_mb_is_accepted_and_one_byte_more_is_not(): void
    {
        $token = $this->register()['accessToken'];
        $limit = 5 * 1024 * 1024;

        $this->upload($token, $this->pdf(bytes: $limit))->assertCreated();
        $this->assertProblem($this->upload($token, $this->pdf(bytes: $limit + 1)), 413, 'FILE_TOO_LARGE');
        $this->assertDatabaseCount('cvs', 1);
    }

    public function test_a_file_that_php_itself_refused_as_too_big_gets_the_same_error(): void
    {
        $token = $this->register()['accessToken'];
        // What PHP hands us when the file is above upload_max_filesize: an error code, no content.
        $refused = new UploadedFile(__FILE__, 'cv.pdf', 'application/pdf', UPLOAD_ERR_INI_SIZE, true);

        $this->assertProblem($this->upload($token, $refused), 413, 'FILE_TOO_LARGE');
    }

    public function test_uploading_without_a_file_returns_400(): void
    {
        $token = $this->register()['accessToken'];

        $response = $this->upload($token, null);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame(['file'], array_keys($response->json('errors')));
    }

    public function test_a_dangerous_file_name_is_cleaned_and_never_used_as_a_path(): void
    {
        $token = $this->register()['accessToken'];

        $response = $this->upload($token, $this->pdf('../../etc/passwd.pdf'))->assertCreated();

        $this->assertSame('passwd.pdf', $response->json('fileName'));
        $this->assertStringStartsWith('cvs/', Cv::findOrFail($response->json('id'))->storage_key);
    }

    public function test_a_candidate_can_keep_at_most_five_cvs(): void
    {
        $token = $this->register()['accessToken'];
        $ids = [];
        for ($i = 1; $i <= 5; $i++) {
            $ids[] = $this->upload($token, $this->pdf("cv-$i.pdf"))->assertCreated()->json('id');
        }

        $this->assertProblem($this->upload($token, $this->pdf('cv-6.pdf')), 409, 'CV_LIMIT_REACHED');

        // Deleting one makes room again.
        $this->api('DELETE', "/me/cvs/{$ids[0]}", token: $token)->assertNoContent();
        $this->upload($token, $this->pdf('cv-6.pdf'))->assertCreated();
    }

    public function test_the_list_shows_only_my_cvs_newest_first(): void
    {
        $ahmed = $this->register()['accessToken'];
        $omar = $this->register()['accessToken'];

        $this->upload($ahmed, $this->pdf('first.pdf'))->assertCreated();
        $this->travel(1)->minutes();
        $this->upload($ahmed, $this->pdf('second.pdf'))->assertCreated();
        $this->upload($omar, $this->pdf('omar.pdf'))->assertCreated();

        $list = $this->api('GET', '/me/cvs', token: $ahmed)->assertOk()->json();

        $this->assertSame(['second.pdf', 'first.pdf'], array_column($list, 'fileName'));
    }

    public function test_deleting_hides_the_cv_but_keeps_the_file_for_past_sessions(): void
    {
        $token = $this->register()['accessToken'];
        $id = $this->upload($token, $this->pdf())->json('id');
        $key = Cv::findOrFail($id)->storage_key;

        $this->api('DELETE', "/me/cvs/$id", token: $token)->assertNoContent();

        $this->assertSame([], $this->api('GET', '/me/cvs', token: $token)->json());
        $this->assertProblem($this->api('GET', "/cvs/$id/download-url", token: $token), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('DELETE', "/me/cvs/$id", token: $token), 404, 'NOT_FOUND');
        Storage::disk('local')->assertExists($key);
    }

    public function test_a_candidate_cannot_delete_or_download_someone_elses_cv(): void
    {
        $ahmed = $this->register()['accessToken'];
        $omar = $this->register()['accessToken'];
        $id = $this->upload($ahmed, $this->pdf())->json('id');

        $this->assertProblem($this->api('DELETE', "/me/cvs/$id", token: $omar), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', "/cvs/$id/download-url", token: $omar), 404, 'NOT_FOUND');

        $this->assertCount(1, $this->api('GET', '/me/cvs', token: $ahmed)->json());
    }

    public function test_an_id_that_is_not_a_uuid_is_a_plain_404(): void
    {
        $token = $this->register()['accessToken'];

        $this->assertProblem($this->api('DELETE', '/me/cvs/123', token: $token), 404, 'NOT_FOUND');
        $this->assertProblem($this->api('GET', '/cvs/abc/download-url', token: $token), 404, 'NOT_FOUND');
    }

    public function test_the_download_link_works_for_five_minutes_and_cannot_be_altered(): void
    {
        $this->freezeSecond();
        $token = $this->register()['accessToken'];
        $id = $this->upload($token, $this->pdf())->json('id');

        $link = $this->api('GET', "/cvs/$id/download-url", token: $token)->assertOk();

        $this->assertSame(now()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s\Z'), $link->json('expiresAt'));
        $url = $link->json('url');

        // The link itself carries the permission: no token is sent with it.
        $this->asFreshClient()->get($url)->assertOk();

        // Pointing the same signature at another file is refused.
        $this->asFreshClient()->get(str_replace('.pdf', '.docx', $url))->assertForbidden();

        $this->travel(5)->minutes();
        $this->travel(1)->seconds();
        $this->asFreshClient()->get($url)->assertForbidden();
    }

    public function test_a_recruiter_cannot_read_a_candidates_cv_without_a_session(): void
    {
        $candidate = $this->register()['accessToken'];
        $recruiter = $this->register(['role' => 'recruiter'])['accessToken'];
        $id = $this->upload($candidate, $this->pdf())->json('id');

        $this->assertProblem($this->api('GET', "/cvs/$id/download-url", token: $recruiter), 404, 'NOT_FOUND');
    }

    public function test_the_recruiter_of_a_session_can_read_the_cv_even_after_the_candidate_deleted_it(): void
    {
        $candidate = $this->register()['accessToken'];
        $recruiter = $this->register(['role' => 'recruiter']);
        $id = $this->upload($candidate, $this->pdf())->json('id');
        $this->api('DELETE', "/me/cvs/$id", token: $candidate)->assertNoContent();

        // Stand-in for the Scheduling module (M4), which will say who shares a session.
        $this->app->bind(SessionCvAccess::class, fn () => new class($recruiter['user']['id']) implements SessionCvAccess
        {
            public function __construct(private readonly string $recruiterId) {}

            public function canRead(User $user, Cv $cv): bool
            {
                return $user->id === $this->recruiterId;
            }
        });

        $this->api('GET', "/cvs/$id/download-url", token: $recruiter['accessToken'])->assertOk();
        // The candidate deleted it, so for them it stays gone.
        $this->assertProblem($this->api('GET', "/cvs/$id/download-url", token: $candidate), 404, 'NOT_FOUND');
    }

    public function test_recruiters_cannot_upload_or_list_cvs(): void
    {
        $recruiter = $this->register(['role' => 'recruiter'])['accessToken'];

        $this->assertProblem($this->upload($recruiter, $this->pdf()), 403, 'FORBIDDEN_ROLE');
        $this->assertProblem($this->api('GET', '/me/cvs', token: $recruiter), 403, 'FORBIDDEN_ROLE');
    }

    public function test_the_endpoints_need_a_token(): void
    {
        $this->assertProblem($this->api('GET', '/me/cvs'), 401, 'TOKEN_INVALID');
        $this->assertProblem($this->api('GET', '/cvs/01a1c737-b065-7130-8d08-f913763e916c/download-url'), 401, 'TOKEN_INVALID');
    }
}
